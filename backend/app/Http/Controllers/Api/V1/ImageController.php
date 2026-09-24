<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Images\StoreImage;
use App\Exceptions\ThumbnailGenerationFailed;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreImageRequest;
use App\Http\Resources\ImageResource;
use App\Rules\CompleteJpeg;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class ImageController extends Controller
{
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
        }

        return ImageResource::make($image)->response()->setStatusCode(Response::HTTP_CREATED);
    }
}
