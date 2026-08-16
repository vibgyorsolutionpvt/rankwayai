<?php

namespace App\Providers;

use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            \App\Services\Seo\Contracts\KeywordMetricsProvider::class,
            function () {
                if (config('seo.providers.metrics') === 'dataforseo'
                    && \App\Services\Seo\Providers\DataForSeoClient::configured()
                ) {
                    return $this->app->make(\App\Services\Seo\Providers\DataForSeoKeywordMetricsProvider::class);
                }

                return $this->app->make(\App\Services\Seo\Providers\NullKeywordMetricsProvider::class);
            }
        );

        $this->app->bind(
            \App\Services\Seo\Contracts\SerpRankProvider::class,
            function () {
                $driver = (string) config('seo.providers.ranks', 'auto');
                $live = $driver === 'dataforseo'
                    || ($driver === 'auto' && \App\Services\Seo\Providers\DataForSeoClient::configured());

                if ($live && \App\Services\Seo\Providers\DataForSeoClient::configured()) {
                    return $this->app->make(\App\Services\Seo\Providers\DataForSeoSerpRankProvider::class);
                }

                return $this->app->make(\App\Services\Seo\Providers\StubSerpRankProvider::class);
            }
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        $root = rtrim((string) config('app.url'), '/');
        if ($root !== '') {
            \Illuminate\Support\Facades\URL::forceRootUrl($root);
        }
        if (str_starts_with($root, 'https://')) {
            \Illuminate\Support\Facades\URL::forceScheme('https');
        }
    }
}
