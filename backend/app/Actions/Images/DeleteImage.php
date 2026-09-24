<?php

declare(strict_types=1);

namespace App\Actions\Images;

use App\Models\Image;
use Illuminate\Container\Attributes\Storage;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Filesystem\Filesystem;
use RuntimeException;
use Throwable;

final readonly class DeleteImage
{
    public function __construct(
        #[Storage('originals')] private Filesystem $originalsDisk,
        #[Storage('thumbnails')] private Filesystem $thumbnailsDisk,
        private ExceptionHandler $exceptions,
    ) {}

    /**
     * The row goes first, the files only once that is committed: a failure in between leaves an
     * orphaned file nobody sees, never a listed image whose file is gone. Called inside a
     * transaction, the files wait for its commit and survive its rollback.
     *
     * @throws RuntimeException when the row is not deleted; the files are then left untouched
     */
    public function handle(Image $image): void
    {
        if ($image->delete() !== true) {
            throw new RuntimeException("Image [{$image->id}] was not deleted.");
        }

        // The model's own connection: that is where a surrounding transaction would be open.
        $image->getConnection()->afterCommit(function () use ($image): void {
            $this->deleteQuietly($this->originalsDisk, $image->original_path);
            $this->deleteQuietly($this->thumbnailsDisk, $image->thumbnail_path);
        });
    }

    /**
     * The row is already gone, so the deletion stands either way: a file that cannot be removed
     * is reported for cleanup, and does not keep the other one from being removed. Deleting a
     * missing file is a no-op.
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
