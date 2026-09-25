<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Images\DeleteImage;
use App\Actions\Images\StoreImage;
use App\Exceptions\MetadataTooLarge;
use App\Exceptions\ThumbnailGenerationFailed;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListImagesRequest;
use App\Http\Requests\StoreImageRequest;
use App\Http\Resources\ImageResource;
use App\Models\Image;
use App\Rules\CompleteJpeg;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response as DocumentedResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

// The #[Endpoint] texts are the public API documentation; docblocks stay notes for developers.
#[Group('Images')]
final class ImageController extends Controller
{
    private const string METADATA_TOO_LARGE = 'The :attribute contains more metadata than can be stored.';

    /**
     * Cursor pagination keeps infinite scroll stable while images are uploaded or deleted,
     * which an offset would turn into duplicated or skipped items.
     *
     * Scramble loses the paginator behind the model scope, so the shape is given explicitly.
     *
     * @response AnonymousResourceCollection<CursorPaginator<ImageResource>>
     */
    #[Endpoint(
        title: 'List images',
        description: 'Newest first, a fixed number per page. Pass `meta.next_cursor` of a page as `cursor` to get the next one; it is `null` on the last page.',
    )]
    public function index(ListImagesRequest $request): AnonymousResourceCollection
    {
        return ImageResource::collection(
            Image::query()->forListing()->cursorPaginate(
                config()->integer('images.page_size'),
                cursor: $request->listingCursor(),
            ),
        );
    }

    #[Endpoint(
        title: 'Upload an image',
        description: 'Stores the file with its EXIF/IPTC metadata and a WebP thumbnail. A truncated or otherwise damaged file, or one with more metadata than can be stored, is rejected with a `422`. `temperature_c` is `null` in the response: it is fetched afterwards.',
    )]
    // Sent by nginx (client_max_body_size) or PHP (post_max_size), before validation can run.
    #[DocumentedResponse(413, 'The request body exceeds the server limit of 10 MB.', type: 'array{message: string}')]
    public function store(StoreImageRequest $request, StoreImage $storeImage): JsonResponse
    {
        try {
            $image = $storeImage->handle($request->toData());
        } catch (ThumbnailGenerationFailed $exception) {
            // A damaged body behind a valid header only shows up when decoding. The client gets
            // a generic message; ImageMagick's text stays in the log, where a server-side cause
            // (a missing delegate, too tight resource limits) would otherwise go unnoticed.
            Log::warning('An uploaded image could not be decoded.', ['reason' => $exception->getMessage()]);

            throw ValidationException::withMessages(['file' => trans(CompleteJpeg::MESSAGE, ['attribute' => 'file'])]);
        } catch (MetadataTooLarge $exception) {
            Log::warning('An uploaded image has too much metadata to store.', ['reason' => $exception->getMessage()]);

            throw ValidationException::withMessages(['file' => trans(self::METADATA_TOO_LARGE, ['attribute' => 'file'])]);
        }

        return ImageResource::make($image)->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[Endpoint(title: 'Delete an image', description: 'Deletes the record, the original file and the thumbnail.')]
    public function destroy(Image $image, DeleteImage $deleteImage): Response
    {
        $deleteImage->handle($image);

        return response()->noContent();
    }
}
