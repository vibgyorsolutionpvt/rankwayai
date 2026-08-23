<?php

namespace App\Services\Seo\Providers;

use App\Services\Seo\Contracts\CmsPublisher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class AskefyCmsPublisher implements CmsPublisher
{
    public function name(): string
    {
        return 'askefy';
    }

    public function defaultBaseUrl(): string
    {
        return rtrim((string) config('services.askefy.base_url', ''), '/');
    }

    /**
     * @return string|null Error message when the base URL is invalid for this app.
     */
    public function validateBaseUrl(string $baseUrl): ?string
    {
        $baseUrl = rtrim($baseUrl, '/');
        if ($baseUrl === '') {
            return 'Set ASKEFY_BASE_URL in .env to your Askefy API (e.g. http://127.0.0.1:8001).';
        }

        $appUrl = rtrim((string) config('app.url', ''), '/');
        if ($appUrl === '') {
            return null;
        }

        $baseHost = $this->normalizeHost(parse_url($baseUrl, PHP_URL_HOST));
        $appHost = $this->normalizeHost(parse_url($appUrl, PHP_URL_HOST));
        $basePort = $this->portFromUrl($baseUrl);
        $appPort = $this->portFromUrl($appUrl);

        if ($baseHost !== '' && $baseHost === $appHost && $basePort === $appPort) {
            return 'ASKEFY_BASE_URL points to this app ('.$appUrl.'). Askefy must run on a separate URL/port '
                .'(e.g. http://127.0.0.1:8001). Using the same address causes a timeout on `php artisan serve`.';
        }

        return null;
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
        if ($message = $this->validateBaseUrl($baseUrl)) {
            return ['ok' => false, 'message' => $message];
        }

        $base = $this->apiRoot($baseUrl);

        try {
            $response = $this->http()
                ->asJson()
                ->post($base.'/register', [
                    'name' => $name,
                    'email' => $email,
                    'password' => $password,
                    'password_confirmation' => $passwordConfirmation,
                    'device_name' => $deviceName,
                ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $this->requestErrorMessage('Askefy signup', $e)];
        }

        return $this->tokenResponse($response, $email, 'signup');
    }

    /**
     * @return array{ok:bool,token?:string,user?:array,message:string}
     */
    public function login(string $baseUrl, string $email, string $password, string $deviceName = 'rankwayai'): array
    {
        if ($message = $this->validateBaseUrl($baseUrl)) {
            return ['ok' => false, 'message' => $message];
        }

        $base = $this->apiRoot($baseUrl);

        try {
            $response = $this->http()
                ->asJson()
                ->post($base.'/login', [
                    'email' => $email,
                    'password' => $password,
                    'device_name' => $deviceName,
                ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $this->requestErrorMessage('Askefy login', $e)];
        }

        return $this->tokenResponse($response, $email, 'login');
    }

    /**
     * @return array{ok:bool,token?:string,user?:array,message:string}
     */
    private function tokenResponse(Response $response, string $email, string $action): array
    {
        if (! $response->successful()) {
            $msg = $response->json('message')
                ?? $response->json('errors.email.0')
                ?? collect($response->json('errors') ?? [])->flatten()->first()
                ?? ('HTTP '.$response->status());

            return ['ok' => false, 'message' => 'Askefy '.$action.' failed: '.$msg];
        }

        $token = (string) ($response->json('token') ?? '');
        if ($token === '') {
            return ['ok' => false, 'message' => 'Askefy '.$action.' succeeded but no token was returned.'];
        }

        return [
            'ok' => true,
            'token' => $token,
            'user' => is_array($response->json('user')) ? $response->json('user') : [],
            'message' => 'Askefy '.$action.' ok as '.($response->json('user.email') ?? $email),
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
            return ['ok' => false, 'message' => 'Askefy base URL and token are required.'];
        }

        try {
            $response = $this->http($token)->get($base.'/user');
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $this->requestErrorMessage('Askefy connection', $e)];
        }

        if (! $response->successful()) {
            return ['ok' => false, 'message' => 'Askefy token invalid (HTTP '.$response->status().')'];
        }

        $user = $response->json('data') ?? $response->json();

        return [
            'ok' => true,
            'user' => is_array($user) ? $user : [],
            'message' => 'Askefy connected as '.($user['email'] ?? $user['name'] ?? 'user'),
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

        try {
            $response = $this->http($token)->get($base.'/pages', ['mine' => 1, 'per_page' => 50]);
        } catch (\Throwable $e) {
            return [];
        }

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
            $response = $this->http($token)
                ->asJson()
                ->post($base.'/pages', $payload);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $this->requestErrorMessage('Askefy page create', $e)];
        }

        if (! $response->successful()) {
            $msg = $response->json('message')
                ?? collect($response->json('errors') ?? [])->flatten()->first()
                ?? ('HTTP '.$response->status());

            return ['ok' => false, 'message' => 'Askefy page create failed: '.$msg];
        }

        $page = $response->json('data') ?? [];

        return [
            'ok' => true,
            'page' => is_array($page) ? $page : [],
            'message' => 'Askefy page created: '.($page['slug'] ?? $input['username']),
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
            return ['ok' => false, 'message' => 'Askefy is not connected.'];
        }
        if ($pageSlug === '') {
            return ['ok' => false, 'message' => 'Select or create an Askefy page before publishing.'];
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
            $response = $this->http($token)
                ->asJson()
                ->timeout((int) config('services.askefy.timeout', 12) + 8)
                ->post($base.'/pages/'.rawurlencode($pageSlug).'/posts', $payload);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $this->requestErrorMessage('Askefy publish', $e)];
        }

        if (! $response->successful()) {
            $msg = $response->json('message')
                ?? collect($response->json('errors') ?? [])->flatten()->first()
                ?? ('HTTP '.$response->status().' '.Str::limit($response->body(), 160));

            return ['ok' => false, 'message' => 'Askefy publish failed: '.$msg];
        }

        $data = $response->json('data') ?? [];
        $slug = (string) ($data['slug'] ?? '');
        $publicBase = rtrim((string) ($credentials['public_url'] ?? config('services.askefy.public_url') ?? ''), '/');
        $url = $publicBase !== '' && $slug !== ''
            ? $publicBase.'/p/'.rawurlencode($pageSlug).'/posts/'.rawurlencode($slug)
            : (string) ($data['url'] ?? '');

        return [
            'ok' => true,
            'external_id' => (string) ($data['id'] ?? $slug),
            'url' => $url !== '' ? $url : ('p/'.$pageSlug.'/posts/'.$slug),
            'message' => 'Published to Askefy',
        ];
    }

    private function http(?string $token = null): PendingRequest
    {
        $request = Http::acceptJson()
            ->connectTimeout((int) config('services.askefy.connect_timeout', 5))
            ->timeout((int) config('services.askefy.timeout', 12));

        if ($token) {
            $request = $request->withToken($token);
        }

        return $request;
    }

    private function requestErrorMessage(string $action, \Throwable $e): string
    {
        if ($e instanceof ConnectionException) {
            return $action.' failed: could not reach Askefy. Check ASKEFY_BASE_URL and that Askefy is running on a separate port from this app.';
        }

        $message = trim($e->getMessage());
        if (str_contains(strtolower($message), 'timed out') || str_contains(strtolower($message), 'timeout')) {
            return $action.' timed out. If ASKEFY_BASE_URL is localhost, run Askefy on another port (not '.$this->appOrigin().').';
        }

        return $action.' failed: '.$message;
    }

    private function appOrigin(): string
    {
        $url = rtrim((string) config('app.url', ''), '/');
        if ($url === '') {
            return 'this app';
        }

        $host = $this->normalizeHost(parse_url($url, PHP_URL_HOST));
        $port = $this->portFromUrl($url);

        return $host.':'.$port;
    }

    private function normalizeHost(?string $host): string
    {
        $host = strtolower(trim((string) $host));
        if ($host === 'localhost') {
            return '127.0.0.1';
        }

        return $host;
    }

    private function portFromUrl(string $url): int
    {
        $port = parse_url($url, PHP_URL_PORT);
        if ($port !== null) {
            return (int) $port;
        }

        return str_starts_with(strtolower($url), 'https://') ? 443 : 80;
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
