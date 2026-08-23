<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Routing\Middleware\ValidateSignature;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;
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
            URL::forceRootUrl($root);
        }
        if (str_starts_with($root, 'https://')) {
            URL::forceScheme('https');
        }

        // Gmail / trackers append query params and break absolute signatures.
        ValidateSignature::except([
            'fbclid',
            'utm_campaign',
            'utm_content',
            'utm_medium',
            'utm_source',
            'utm_term',
        ]);

        VerifyEmail::createUrlUsing(function (object $notifiable): string {
            $public = rtrim((string) (config('seo.public_url') ?: config('app.url')), '/');

            // Sign path only so http/https + APP_URL drift don't invalidate the link.
            $relative = URL::temporarySignedRoute(
                'verification.verify',
                Carbon::now()->addMinutes((int) Config::get('auth.verification.expire', 60)),
                [
                    'id' => $notifiable->getKey(),
                    'hash' => sha1($notifiable->getEmailForVerification()),
                ],
                absolute: false
            );

            return ($public !== '' ? $public : rtrim((string) config('app.url'), '/')).$relative;
        });
    }
}
