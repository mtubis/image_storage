<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\DownloadImageController;
use App\Http\Controllers\Api\V1\ImageController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('v1.')->group(function (): void {
    Route::get('images', [ImageController::class, 'index'])->name('images.index');
    Route::post('images', [ImageController::class, 'store'])->name('images.store');
    Route::get('images/{image}/download', DownloadImageController::class)->name('images.download');
});
