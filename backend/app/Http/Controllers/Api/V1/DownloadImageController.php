<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Image;
use App\Support\DownloadFilename;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Container\Attributes\Storage;
use Illuminate\Filesystem\FilesystemAdapter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Not a resource action, so a single-action controller rather than an extra ImageController method.
 *
 * Its response is documented by App\OpenApi\ImageDownloadOperationTransformer.
 */
#[Group('Images')]
final class DownloadImageController extends Controller
{
    /**
     * The original, byte for byte (EXIF/IPTC included), streamed rather than read into memory.
     *
     * A row whose file is missing is a storage inconsistency and surfaces as a reported 500, not
     * a 404 that would contradict the listing. It fails before any header is sent only because
     * the disk's size() provides Content-Length, so that must not come from the stored size_bytes.
     */
    #[Endpoint(title: 'Download an image', description: 'The original file as uploaded, as an attachment named by its original file name.')]
    public function __invoke(Image $image, #[Storage('originals')] FilesystemAdapter $originals): StreamedResponse
    {
        /** @var array<string, list<string>> $types */
        $types = config()->array('images.allowed_types');
        $filename = DownloadFilename::from($image->original_name, $image->extension, $types[$image->mime_type] ?? [$image->extension]);

        return $originals->download($image->original_path, headers: [
            // The type detected and stored at upload, not re-sniffed on every download.
            'Content-Type' => $image->mime_type,
            'Content-Disposition' => $filename->contentDisposition(),
            'X-Content-Type-Options' => 'nosniff',
            // Should the file ever be rendered inline (e.g. a crafted polyglot), it runs nothing.
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
