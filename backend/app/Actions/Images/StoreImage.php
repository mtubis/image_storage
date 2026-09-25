<?php

declare(strict_types=1);

namespace App\Actions\Images;

use App\Contracts\ImageMetadataExtractor;
use App\Contracts\ThumbnailGenerator;
use App\Data\StoreImageData;
use App\Exceptions\MetadataTooLarge;
use App\Exceptions\ThumbnailGenerationFailed;
use App\Jobs\FetchImageTemperature;
use App\Models\Image;
use Illuminate\Container\Attributes\Config;
use Illuminate\Container\Attributes\Storage;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Filesystem\Filesystem;
use RuntimeException;
use Throwable;

final readonly class StoreImage
{
    public function __construct(
        private ThumbnailGenerator $thumbnails,
        private ImageMetadataExtractor $metadata,
        #[Storage('originals')] private Filesystem $originalsDisk,
        #[Storage('thumbnails')] private Filesystem $thumbnailsDisk,
        private ExceptionHandler $exceptions,
        private Dispatcher $bus,
        #[Config('images.max_metadata_bytes')] private int $maxMetadataBytes,
    ) {}

    /**
     * @throws ThumbnailGenerationFailed when the contents cannot be decoded; nothing is stored
     * @throws MetadataTooLarge when the metadata would not fit into the database; nothing is stored
     */
    public function handle(StoreImageData $data): Image
    {
        // Everything that can reject the file runs before anything is written, the cheap metadata
        // check first: decoding a large image takes seconds.
        $metadata = $this->metadata->extract($data->contents);

        $image = new Image([
            'original_name' => $data->originalName,
            'extension' => $data->extension,
            'mime_type' => $data->mimeType,
            'size_bytes' => strlen($data->contents),
            'uploader_name' => $data->uploaderName,
            'uploader_email' => $data->uploaderEmail,
            'metadata' => $metadata->isEmpty() ? null : $metadata->toArray(),
        ]);
        $this->assertMetadataFits($image);

        $thumbnail = $this->thumbnails->generate($data->contents);
        $image->width = $thumbnail->sourceWidth;
        $image->height = $thumbnail->sourceHeight;

        // The ID is known before the row exists, so the stored files can be named after it:
        // the client's file name never reaches the filesystem.
        $image->id = $image->newUniqueId();
        $image->original_path = "{$image->id}.{$data->extension}";
        $image->thumbnail_path = "{$image->id}.webp";

        try {
            $this->originalsDisk->put($image->original_path, $data->contents);
            $this->thumbnailsDisk->put($image->thumbnail_path, $thumbnail->contents);

            // A single INSERT is atomic; a transaction around it would add nothing.
            if (! $image->save()) {
                throw new RuntimeException("Image [{$image->id}] was not saved.");
            }
        } catch (Throwable $exception) {
            $this->deleteQuietly($this->originalsDisk, $image->original_path);
            $this->deleteQuietly($this->thumbnailsDisk, $image->thumbnail_path);

            throw $exception;
        }

        // Outside the cleanup above: the row is stored by now, so removing the files would
        // leave it pointing at nothing. The temperature is optional, so a queue outage must
        // not fail the upload either.
        try {
            $this->bus->dispatch(new FetchImageTemperature($image));
        } catch (Throwable $exception) {
            $this->exceptions->report($exception);
        }

        return $image;
    }

    /**
     * Measures the JSON the cast has already produced, i.e. exactly what the INSERT will send.
     */
    private function assertMetadataFits(Image $image): void
    {
        $encoded = $image->getAttributes()['metadata'] ?? null;
        $bytes = is_string($encoded) ? strlen($encoded) : 0;

        if ($bytes > $this->maxMetadataBytes) {
            throw MetadataTooLarge::encodedSize($bytes, $this->maxMetadataBytes);
        }
    }

    /**
     * Best-effort cleanup: a failure here is reported, but must neither hide the error that
     * caused the cleanup nor stop the other file from being removed. Deleting a file that was
     * never written is a no-op.
     */
    private function deleteQuietly(Filesystem $disk, string $path): void
    {
        try {
            $disk->delete($path);
        } catch (Throwable $exception) {
            $this->exceptions->report($exception);
        }
    }
}
