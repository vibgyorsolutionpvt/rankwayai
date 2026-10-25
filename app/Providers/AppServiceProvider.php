<?php

namespace App\Providers;

use App\Models\PersonalAccessToken;
use App\Services\Seo\Contracts\KeywordMetricsProvider;
use App\Services\Seo\Contracts\SerpRankProvider;
use App\Services\Seo\Providers\DataForSeoClient;
use App\Services\Seo\Providers\DataForSeoKeywordMetricsProvider;
use App\Services\Seo\Providers\DataForSeoSerpRankProvider;
use App\Services\Seo\Providers\NullKeywordMetricsProvider;
use App\Services\Seo\Providers\StubSerpRankProvider;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Routing\Middleware\ValidateSignature;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            KeywordMetricsProvider::class,
            function () {
                if (config('seo.providers.metrics') === 'dataforseo'
                    && DataForSeoClient::configured()
                ) {
                    return $this->app->make(DataForSeoKeywordMetricsProvider::class);
                }

                return $this->app->make(NullKeywordMetricsProvider::class);
            }
        );

        $this->app->bind(
            SerpRankProvider::class,
            function () {
                $driver = (string) config('seo.providers.ranks', 'auto');
                $live = $driver === 'dataforseo'
                    || ($driver === 'auto' && DataForSeoClient::configured());

                if ($live && DataForSeoClient::configured()) {
                    return $this->app->make(DataForSeoSerpRankProvider::class);
                }

                return $this->app->make(StubSerpRankProvider::class);
            }
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

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
