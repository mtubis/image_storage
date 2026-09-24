<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\ImageMetadataExtractor;
use App\Contracts\ThumbnailGenerator;
use App\Services\Images\InterventionThumbnailGenerator;
use App\Services\Images\NativeImageMetadataExtractor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\ServiceProvider;
use Imagick;

final class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ImageMetadataExtractor::class, NativeImageMetadataExtractor::class);

        $this->app->singleton(function (): ThumbnailGenerator {
            /** @var array<string, list<string>> $allowedTypes */
            $allowedTypes = config()->array('images.allowed_types');

            return new InterventionThumbnailGenerator(
                maxEdge: config()->integer('images.thumbnail_max_edge'),
                quality: config()->integer('images.thumbnail_quality'),
                allowedExtensions: array_merge(...array_values($allowedTypes)),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Fail fast on lazy loading and on silently discarded or missing attributes;
        // relaxed in production so a missed case degrades instead of throwing.
        Model::shouldBeStrict(! $this->app->isProduction());

        Date::use(CarbonImmutable::class);

        $this->limitImagickResources();
    }

    /**
     * Validation reads dimensions from the header of the first page only, so these limits are
     * the real bound on what ImageMagick decodes: later TIFF pages and the total pixel area.
     * They are process-wide, so they also cover any other Imagick use (metadata extraction).
     */
    private function limitImagickResources(): void
    {
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_WIDTH, config()->integer('images.max_width'));
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_HEIGHT, config()->integer('images.max_height'));
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_MEMORY, config()->integer('images.imagick_limits.memory'));
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_MAP, config()->integer('images.imagick_limits.map'));
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_DISK, config()->integer('images.imagick_limits.disk'));
    }
}
