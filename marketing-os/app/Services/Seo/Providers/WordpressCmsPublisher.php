<?php

namespace App\Services\Seo\Providers;

use App\Services\Seo\Contracts\CmsPublisher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class WordpressCmsPublisher implements CmsPublisher
{
    public function name(): string
    {
        return 'wordpress';
    }

    public function testConnection(array $credentials): array
    {
        $base = rtrim($credentials['base_url'], '/');
        $response = Http::withBasicAuth($credentials['username'], $credentials['app_password'])
            ->acceptJson()
            ->timeout(20)
            ->get($base.'/wp-json/wp/v2/users/me');

        if ($response->successful()) {
            return ['ok' => true, 'message' => 'WordPress connected as '.($response->json('name') ?? 'user')];
        }

        return ['ok' => false, 'message' => 'WordPress auth failed (HTTP '.$response->status().')'];
    }

    public function publish(array $credentials, array $post): array
    {
        $base = rtrim($credentials['base_url'], '/');
        $payload = [
            'title' => $post['title'],
            'content' => $post['body_html'],
            'status' => $post['status'] ?? 'publish',
            'slug' => $post['slug'] ?? Str::slug($post['title']),
        ];

        $response = Http::withBasicAuth($credentials['username'], $credentials['app_password'])
            ->acceptJson()
            ->timeout(45)
            ->post($base.'/wp-json/wp/v2/posts', $payload);

        if (! $response->successful()) {
            return [
                'ok' => false,
                'message' => 'Publish failed: HTTP '.$response->status().' '.Str::limit($response->body(), 180),
            ];
        }

        $json = $response->json();

        return [
            'ok' => true,
            'external_id' => (string) ($json['id'] ?? ''),
            'url' => (string) ($json['link'] ?? ''),
            'message' => 'Published to WordPress',
        ];
    }
}
