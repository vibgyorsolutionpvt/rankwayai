<?php

namespace App\Services\Seo\Providers;

use App\Services\Seo\Contracts\JsRenderProvider;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class BrowserlessJsRenderProvider implements JsRenderProvider
{
    public function configured(): bool
    {
        return filled(config('services.browserless.token'))
            || filled(config('services.browserless.url'));
    }

    public function name(): string
    {
        return 'browserless';
    }

    public function fetch(string $url): array
    {
        if (! $this->configured()) {
            throw new RuntimeException('Browserless is not configured (BROWSERLESS_TOKEN or BROWSERLESS_URL).');
        }

        $started = microtime(true);
        $endpoint = rtrim((string) (config('services.browserless.url') ?: 'https://chrome.browserless.io'), '/');
        $token = config('services.browserless.token');
        $requestUrl = $endpoint.'/content'.($token ? '?token='.urlencode((string) $token) : '');

        $response = Http::timeout(60)
            ->accept('text/html')
            ->asJson()
            ->post($requestUrl, [
                'url' => $url,
                'gotoOptions' => ['waitUntil' => 'networkidle2', 'timeout' => 45000],
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Browserless render failed: HTTP '.$response->status());
        }

        return [
            'html' => (string) $response->body(),
            'status' => 200,
            'load_time_ms' => (int) round((microtime(true) - $started) * 1000),
        ];
    }
}
