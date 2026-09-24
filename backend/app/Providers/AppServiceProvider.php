<?php

declare(strict_types=1);

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
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
    }
}
