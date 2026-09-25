<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\DownloadImageController;
use App\Http\Controllers\Api\V1\ImageController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('v1.')->group(function (): void {
    Route::get('images', [ImageController::class, 'index'])->name('images.index');
    Route::post('images', [ImageController::class, 'store'])->name('images.store');
    Route::delete('images/{image}', [ImageController::class, 'destroy'])->name('images.destroy');
    // App\OpenApi\ImageDownloadOperationTransformer documents this route by its name.
    Route::get('images/{image}/download', DownloadImageController::class)->name('images.download');
});
