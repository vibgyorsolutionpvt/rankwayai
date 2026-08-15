<?php

namespace App\Services\Seo\Providers;

use App\Services\Seo\Contracts\CmsPublisher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class VerbaCmsPublisher implements CmsPublisher
{
    public function name(): string
    {
        return 'verba';
    }

    public function defaultBaseUrl(): string
    {
        return rtrim((string) config('services.verba.base_url', ''), '/');
    }

    /**
     * @return array{ok:bool,token?:string,user?:array,message:string}
     */
    public function register(
        string $baseUrl,
        string $name,
        string $email,
        string $password,
        string $passwordConfirmation,
        string $deviceName = 'rankwayai',
    ): array {
        $base = $this->apiRoot($baseUrl);

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->timeout(30)
                ->post($base.'/register', [
                    'name' => $name,
                    'email' => $email,
                    'password' => $password,
                    'password_confirmation' => $passwordConfirmation,
                    'device_name' => $deviceName,
                ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Verba signup failed: '.$e->getMessage()];
        }

        return $this->tokenResponse($response, $email, 'signup');
    }

    /**
     * @return array{ok:bool,token?:string,user?:array,message:string}
     */
    public function login(string $baseUrl, string $email, string $password, string $deviceName = 'rankwayai'): array
    {
        $base = $this->apiRoot($baseUrl);

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->timeout(30)
                ->post($base.'/login', [
                    'email' => $email,
                    'password' => $password,
                    'device_name' => $deviceName,
                ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Verba login failed: '.$e->getMessage()];
        }

        return $this->tokenResponse($response, $email, 'login');
    }

    /**
     * @return array{ok:bool,token?:string,user?:array,message:string}
     */
    private function tokenResponse(\Illuminate\Http\Client\Response $response, string $email, string $action): array
    {
        if (! $response->successful()) {
            $msg = $response->json('message')
                ?? $response->json('errors.email.0')
                ?? collect($response->json('errors') ?? [])->flatten()->first()
                ?? ('HTTP '.$response->status());

            return ['ok' => false, 'message' => 'Verba '.$action.' failed: '.$msg];
        }

        $token = (string) ($response->json('token') ?? '');
        if ($token === '') {
            return ['ok' => false, 'message' => 'Verba '.$action.' succeeded but no token was returned.'];
        }

        return [
            'ok' => true,
            'token' => $token,
            'user' => is_array($response->json('user')) ? $response->json('user') : [],
            'message' => 'Verba '.$action.' ok as '.($response->json('user.email') ?? $email),
        ];
    }

    /**
     * @param  array{base_url?:string,token?:string}  $credentials
     * @return array{ok:bool,message:string,user?:array}
     */
    public function testConnection(array $credentials): array
    {
        $token = (string) ($credentials['token'] ?? '');
        $base = $this->apiRoot((string) ($credentials['base_url'] ?? $this->defaultBaseUrl()));

        if ($token === '' || $base === '') {
            return ['ok' => false, 'message' => 'Verba base URL and token are required.'];
        }

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout(20)
                ->get($base.'/user');
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Verba connection failed: '.$e->getMessage()];
        }

        if (! $response->successful()) {
            return ['ok' => false, 'message' => 'Verba token invalid (HTTP '.$response->status().')'];
        }

        $user = $response->json('data') ?? $response->json();

        return [
            'ok' => true,
            'user' => is_array($user) ? $user : [],
            'message' => 'Verba connected as '.($user['email'] ?? $user['name'] ?? 'user'),
        ];
    }

    /**
     * @param  array{base_url?:string,token?:string}  $credentials
     * @return list<array{id:int|string,name:string,slug:string,username?:string}>
     */
    public function listMyPages(array $credentials): array
    {
        $token = (string) ($credentials['token'] ?? '');
        $base = $this->apiRoot((string) ($credentials['base_url'] ?? $this->defaultBaseUrl()));

        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout(30)
            ->get($base.'/pages', ['mine' => 1, 'per_page' => 50]);

        if (! $response->successful()) {
            return [];
        }

        $rows = $response->json('data') ?? [];
        if (! is_array($rows)) {
            return [];
        }

        return collect($rows)
            ->filter(fn ($row) => is_array($row) && filled($row['slug'] ?? null))
            ->map(fn (array $row) => [
                'id' => $row['id'] ?? '',
                'name' => (string) ($row['name'] ?? $row['slug']),
                'slug' => (string) $row['slug'],
                'username' => isset($row['username']) ? (string) $row['username'] : null,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array{base_url?:string,token?:string}  $credentials
     * @return array{ok:bool,page?:array,message:string}
     */
    public function createPage(array $credentials, array $input): array
    {
        $token = (string) ($credentials['token'] ?? '');
        $base = $this->apiRoot((string) ($credentials['base_url'] ?? $this->defaultBaseUrl()));

        $payload = array_filter([
            'type' => $input['type'] ?? 'business',
            'name' => $input['name'],
            'username' => $input['username'],
            'description' => $input['description'] ?? null,
            'category' => $input['category'] ?? 'technology',
            'visibility' => $input['visibility'] ?? 'public',
            'email' => $input['email'] ?? null,
            'email_visibility' => $input['email_visibility'] ?? null,
            'mobile' => $input['mobile'] ?? null,
            'mobile_visibility' => $input['mobile_visibility'] ?? null,
            'whatsapp' => $input['whatsapp'] ?? null,
            'whatsapp_visibility' => $input['whatsapp_visibility'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->asJson()
                ->timeout(30)
                ->post($base.'/pages', $payload);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Verba page create failed: '.$e->getMessage()];
        }

        if (! $response->successful()) {
            $msg = $response->json('message')
                ?? collect($response->json('errors') ?? [])->flatten()->first()
                ?? ('HTTP '.$response->status());

            return ['ok' => false, 'message' => 'Verba page create failed: '.$msg];
        }

        $page = $response->json('data') ?? [];

        return [
            'ok' => true,
            'page' => is_array($page) ? $page : [],
            'message' => 'Verba page created: '.($page['slug'] ?? $input['username']),
        ];
    }

    /**
     * @param  array{base_url?:string,token?:string,page_slug?:string}  $credentials
     * @param  array{title:string,slug?:string,body_html:string,status?:string,meta_title?:string,meta_description?:string,topics?:array,new_topics?:array}  $post
     * @return array{ok:bool,external_id?:string,url?:string,message:string}
     */
    public function publish(array $credentials, array $post): array
    {
        $token = (string) ($credentials['token'] ?? '');
        $base = $this->apiRoot((string) ($credentials['base_url'] ?? $this->defaultBaseUrl()));
        $pageSlug = (string) ($credentials['page_slug'] ?? $post['page_slug'] ?? '');

        if ($token === '' || $base === '') {
            return ['ok' => false, 'message' => 'Verba is not connected.'];
        }
        if ($pageSlug === '') {
            return ['ok' => false, 'message' => 'Select or create a Verba page before publishing.'];
        }

        $body = trim((string) ($post['body_html'] ?? ''));
        if ($body === '' || strip_tags($body) === '') {
            return ['ok' => false, 'message' => 'Post body is empty.'];
        }

        $newTopics = $post['new_topics'] ?? ['SEO', 'Marketing'];
        if (! is_array($newTopics) || $newTopics === []) {
            $newTopics = ['SEO'];
        }
        $newTopics = array_values(array_slice(array_filter(array_map('strval', $newTopics)), 0, 5));

        $payload = [
            'title' => (string) $post['title'],
            'body' => $body,
            'new_topics' => $newTopics,
        ];

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->asJson()
                ->timeout(45)
                ->post($base.'/pages/'.rawurlencode($pageSlug).'/posts', $payload);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Verba publish failed: '.$e->getMessage()];
        }

        if (! $response->successful()) {
            $msg = $response->json('message')
                ?? collect($response->json('errors') ?? [])->flatten()->first()
                ?? ('HTTP '.$response->status().' '.Str::limit($response->body(), 160));

            return ['ok' => false, 'message' => 'Verba publish failed: '.$msg];
        }

        $data = $response->json('data') ?? [];
        $slug = (string) ($data['slug'] ?? '');
        $publicBase = rtrim((string) ($credentials['public_url'] ?? config('services.verba.public_url') ?? ''), '/');
        $url = $publicBase !== '' && $slug !== ''
            ? $publicBase.'/'.$pageSlug.'/'.$slug
            : (string) ($data['url'] ?? '');

        return [
            'ok' => true,
            'external_id' => (string) ($data['id'] ?? $slug),
            'url' => $url !== '' ? $url : ($pageSlug.'/'.$slug),
            'message' => 'Published to Verba',
        ];
    }

    private function apiRoot(string $baseUrl): string
    {
        $base = rtrim($baseUrl !== '' ? $baseUrl : $this->defaultBaseUrl(), '/');
        if ($base === '') {
            return '';
        }

        return str_ends_with($base, '/api') ? $base : $base.'/api';
    }
}
