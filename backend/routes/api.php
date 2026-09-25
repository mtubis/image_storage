<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\DownloadImageController;
use App\Http\Controllers\Api\V1\ImageController;
use Illuminate\Support\Facades\Route;

// Lowercase ULIDs only, as HasUlids generates them: MariaDB's case-insensitive collation would
// otherwise find an image by an upper-case spelling of its ID, which SQLite doesn't.
Route::pattern('image', '[0-7][0-9a-hjkmnp-tv-z]{25}');

Route::prefix('v1')->name('v1.')->group(function (): void {
    Route::get('images', [ImageController::class, 'index'])->name('images.index');
    Route::post('images', [ImageController::class, 'store'])->name('images.store');
    Route::delete('images/{image}', [ImageController::class, 'destroy'])->name('images.destroy');
    // App\OpenApi\ImageDownloadOperationTransformer documents this route by its name.
    Route::get('images/{image}/download', DownloadImageController::class)->name('images.download');
});
