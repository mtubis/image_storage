<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreImageRequest;
use Symfony\Component\HttpFoundation\Response;

final class ImageController extends Controller
{
    public function store(StoreImageRequest $request): never
    {
        // Validation only for now; persisting the image lands in step 1.8 (StoreImage action).
        abort(Response::HTTP_NOT_IMPLEMENTED);
    }
}
