<?php

namespace App\Services\Seo\Providers;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class DataForSeoClient
{
    public static function configured(): bool
    {
        return filled(config('services.dataforseo.login'))
            && filled(config('services.dataforseo.password'));
    }

    /**
     * @param  list<array<string, mixed>>  $tasks
     * @return array{tasks:list<array<string, mixed>>, cost:float}
     */
    public function post(string $path, array $tasks): array
    {
        if (! self::configured()) {
            throw new RuntimeException('DataForSEO is not configured (DATAFORSEO_LOGIN / DATAFORSEO_PASSWORD).');
        }

        $url = rtrim((string) config('services.dataforseo.base_url'), '/').'/'.ltrim($path, '/');

        $response = Http::withBasicAuth(
            (string) config('services.dataforseo.login'),
            (string) config('services.dataforseo.password')
        )
            ->acceptJson()
            ->timeout(60)
            ->post($url, $tasks);

        if ($response->failed()) {
            throw new RequestException($response);
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new RuntimeException('DataForSEO returned an empty body.');
        }

        $status = (int) ($json['status_code'] ?? 0);
        if ($status !== 20000) {
            throw new RuntimeException(
                'DataForSEO error: '.Str::limit((string) ($json['status_message'] ?? 'unknown'), 200)
            );
        }

        return [
            'tasks' => $json['tasks'] ?? [],
            'cost' => (float) ($json['cost'] ?? 0),
        ];
    }
}
