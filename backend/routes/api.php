<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\ImageController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('v1.')->group(function (): void {
    Route::post('images', [ImageController::class, 'store'])->name('images.store');
});
