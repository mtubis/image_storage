<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\ImageMetadataExtractor;
use App\Contracts\ThumbnailGenerator;
use App\Contracts\WeatherProvider;
use App\OpenApi\DimensionsRuleTransformer;
use App\OpenApi\FileSizeRuleTransformer;
use App\OpenApi\ImageDownloadOperationTransformer;
use App\OpenApi\MimesRuleTransformer;
use App\Services\Images\InterventionThumbnailGenerator;
use App\Services\Images\NativeImageMetadataExtractor;
use App\Services\Weather\FakeWeatherProvider;
use App\Services\Weather\OpenMeteoWeatherProvider;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Scramble;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Imagick;
use InvalidArgumentException;

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

        $this->app->singleton(function (): WeatherProvider {
            $provider = config()->string('services.weather.provider');

            return match ($provider) {
                'open-meteo' => new OpenMeteoWeatherProvider(
                    url: config()->string('services.open_meteo.url'),
                    latitude: config()->float('services.open_meteo.latitude'),
                    longitude: config()->float('services.open_meteo.longitude'),
                    timezone: config()->string('services.open_meteo.timezone'),
                    timeoutSeconds: config()->integer('services.open_meteo.timeout'),
                    connectTimeoutSeconds: config()->integer('services.open_meteo.connect_timeout'),
                    attempts: config()->integer('services.open_meteo.attempts'),
                    retrySleepMilliseconds: config()->integer('services.open_meteo.retry_sleep_ms'),
                ),
                'fake' => new FakeWeatherProvider,
                // A typo must not silently fall back to real network calls (or to fake data).
                default => throw new InvalidArgumentException("Unknown weather provider [{$provider}]."),
            };
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

        // Uploads are the expensive requests (decoding, thumbnails) and nothing authenticates
        // the client, so they are limited per IP address.
        RateLimiter::for('uploads', static fn (Request $request): Limit => Limit::perMinute(config()->integer('images.uploads_per_minute'))
            ->by((string) $request->ip()));

        // Scramble shows the docs outside "local" only to whom this gate allows. The API has no
        // authentication, so the document reveals nothing the API itself doesn't: allow everyone.
        Gate::define('viewApiDocs', static fn (?object $user = null): bool => true);

        Scramble::configure()
            ->withRuleTransformers([MimesRuleTransformer::class, DimensionsRuleTransformer::class, FileSizeRuleTransformer::class])
            ->withOperationTransformers(ImageDownloadOperationTransformer::class);
    }

    /**
     * Validation reads dimensions from the header of the first page only, so these limits are
     * the real bound on what ImageMagick decodes: later TIFF pages and the total pixel area.
     * They are process-wide, so they also cover any later Imagick use.
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
