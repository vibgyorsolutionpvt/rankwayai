<?php

namespace App\Services\Social;

use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\SocialPublishLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SocialPublisherService
{
    private const GRAPH = 'https://graph.facebook.com/v19.0';

    private const THREADS_GRAPH = 'https://graph.threads.net/v1.0';

    /**
     * @return array{ok:bool, permalinks: array<string,string>, errors: array<string,string>}
     */
    public function publish(SocialPost $post): array
    {
        $post->loadMissing('media');

        $permalinks = [];
        $errors = [];
        $log = [];

        foreach ($post->platforms ?? [] as $platform) {
            $account = SocialAccount::query()
                ->where('workspace_id', $post->workspace_id)
                ->where('platform', $platform)
                ->where('status', 'connected')
                ->orderByDesc('connected_at')
                ->first();

            if (! $account) {
                $errors[$platform] = 'No connected '.$platform.' account';
                $this->writeLog($post, $platform, 'failed', null, $errors[$platform]);
                continue;
            }

            if ($account->connection_mode !== 'oauth' || blank($account->access_token)) {
                $errors[$platform] = $account->connection_mode === 'sandbox'
                    ? 'Sandbox account — connect for real with Meta keys to publish live.'
                    : ($account->last_error ?: 'Token missing — reconnect account');
                $this->writeLog($post, $platform, 'failed', null, $errors[$platform]);
                $account->update(['health' => 'warning', 'last_error' => $errors[$platform]]);
                continue;
            }

            try {
                $result = match ($platform) {
                    'facebook' => $this->publishFacebook($post, $account),
                    'instagram' => $this->publishInstagram($post, $account),
                    'threads' => $this->publishThreads($post, $account),
                    default => [
                        'ok' => false,
                        'message' => ucfirst($platform).' live publish is not wired yet.',
                    ],
                };
            } catch (\Throwable $e) {
                $result = ['ok' => false, 'message' => $e->getMessage()];
            }

            if (! ($result['ok'] ?? false)) {
                $errors[$platform] = $result['message'] ?? 'Publish failed';
                $this->writeLog($post, $platform, 'failed', null, $errors[$platform]);
                $account->update(['health' => 'error', 'last_error' => $errors[$platform]]);
                continue;
            }

            $permalink = (string) ($result['permalink'] ?? '');
            if ($permalink !== '') {
                $permalinks[$platform] = $permalink;
            }
            $log[] = [
                'platform' => $platform,
                'at' => now()->toIso8601String(),
                'permalink' => $permalink,
                'status' => 'published',
            ];
            $this->writeLog($post, $platform, 'published', $permalink !== '' ? $permalink : null);
            $account->update(['health' => 'healthy', 'last_error' => null]);
        }

        $ok = count($errors) === 0 && count($permalinks) > 0;

        $post->update([
            'permalinks' => $permalinks,
            'publish_log' => $log,
            'status' => $ok ? 'published' : (count($permalinks) ? 'published' : 'failed'),
            'published_at' => $ok || count($permalinks) ? now() : null,
            'failure_reason' => $errors ? implode('; ', $errors) : null,
        ]);

        return ['ok' => $ok, 'permalinks' => $permalinks, 'errors' => $errors];
    }

    /**
     * @return array{ok:bool, permalink?:string, message?:string}
     */
    private function publishFacebook(SocialPost $post, SocialAccount $account): array
    {
        $pageId = (string) $account->external_id;
        $token = (string) $account->access_token;
        if ($pageId === '' || $token === '') {
            return ['ok' => false, 'message' => 'Facebook page id/token missing — reconnect.'];
        }

        $message = trim((string) (($post->title ? $post->title."\n\n" : '').$post->body));
        if (mb_strlen($message) > 5000) {
            $message = mb_substr($message, 0, 4997).'...';
        }

        $imageUrl = $this->publicMediaUrl($post);
        if ($imageUrl) {
            $imageUrl = $this->resolveRedirectUrl($imageUrl) ?: $imageUrl;
        }

        if ($imageUrl) {
            $response = Http::asForm()->timeout(60)->post(self::GRAPH.'/'.rawurlencode($pageId).'/photos', [
                'url' => $imageUrl,
                'caption' => $message,
                'published' => 'true',
                'access_token' => $token,
            ]);

            // Redirecting image hosts (e.g. picsum) sometimes fail photo scrape — fall back to feed + link.
            if (! $response->successful()) {
                $response = Http::asForm()->timeout(60)->post(self::GRAPH.'/'.rawurlencode($pageId).'/feed', [
                    'message' => $message !== '' ? $message : ' ',
                    'link' => $imageUrl,
                    'access_token' => $token,
                ]);
            }
        } else {
            $response = Http::asForm()->timeout(60)->post(self::GRAPH.'/'.rawurlencode($pageId).'/feed', [
                'message' => $message !== '' ? $message : ' ',
                'access_token' => $token,
            ]);
        }

        if (! $response->successful()) {
            return [
                'ok' => false,
                'message' => $this->graphError($response->json(), $response->body()),
            ];
        }

        $id = (string) ($response->json('id') ?? $response->json('post_id') ?? '');
        $permalink = $id !== ''
            ? 'https://www.facebook.com/'.$id
            : null;

        // Prefer Graph permalink lookup when we have a post id.
        if ($id !== '' && str_contains($id, '_')) {
            $look = Http::timeout(30)->get(self::GRAPH.'/'.rawurlencode($id), [
                'fields' => 'permalink_url',
                'access_token' => $token,
            ]);
            if ($look->successful() && filled($look->json('permalink_url'))) {
                $permalink = (string) $look->json('permalink_url');
            }
        }

        return ['ok' => true, 'permalink' => $permalink ?? ('https://facebook.com/'.$pageId)];
    }

    /**
     * Instagram content publish (business account linked to a Page).
     *
     * @return array{ok:bool, permalink?:string, message?:string}
     */
    private function publishInstagram(SocialPost $post, SocialAccount $account): array
    {
        $igUserId = (string) $account->external_id;
        $token = (string) $account->access_token;
        $imageUrl = $this->publicMediaUrl($post);

        if ($igUserId === '' || $token === '') {
            return ['ok' => false, 'message' => 'Instagram account id/token missing — reconnect.'];
        }

        if (! $imageUrl) {
            $raw = $post->media?->url();
            if (is_string($raw) && (str_contains($raw, 'localhost') || str_contains($raw, '127.0.0.1'))) {
                return [
                    'ok' => false,
                    'message' => 'Instagram needs a public https image URL. Localhost media cannot be fetched by Meta — use a public URL or Media → paste https link.',
                ];
            }

            return ['ok' => false, 'message' => 'Instagram requires an image. Attach media (or a public https URL) and retry.'];
        }

        $caption = trim((string) (($post->title ? $post->title."\n\n" : '').$post->body));

        $container = Http::asForm()->timeout(60)->post(self::GRAPH.'/'.rawurlencode($igUserId).'/media', [
            'image_url' => $imageUrl,
            'caption' => $caption,
            'access_token' => $token,
        ]);

        if (! $container->successful() || blank($container->json('id'))) {
            return [
                'ok' => false,
                'message' => $this->graphError($container->json(), $container->body()),
            ];
        }

        $creationId = (string) $container->json('id');
        $ready = $this->waitForIgContainer($creationId, $token);
        if (! ($ready['ok'] ?? false)) {
            return ['ok' => false, 'message' => $ready['message'] ?? 'Instagram media container failed.'];
        }

        $publish = Http::asForm()->timeout(60)->post(self::GRAPH.'/'.rawurlencode($igUserId).'/media_publish', [
            'creation_id' => $creationId,
            'access_token' => $token,
        ]);

        if (! $publish->successful() || blank($publish->json('id'))) {
            return [
                'ok' => false,
                'message' => $this->graphError($publish->json(), $publish->body()),
            ];
        }

        $mediaId = (string) $publish->json('id');
        $permalink = 'https://www.instagram.com/';
        $look = Http::get(self::GRAPH.'/'.rawurlencode($mediaId), [
            'fields' => 'permalink',
            'access_token' => $token,
        ]);
        if ($look->successful() && filled($look->json('permalink'))) {
            $permalink = (string) $look->json('permalink');
        }

        return ['ok' => true, 'permalink' => $permalink];
    }

    /**
     * Threads text/image publish (Threads API via graph.threads.net).
     *
     * @return array{ok:bool, permalink?:string, message?:string}
     */
    private function publishThreads(SocialPost $post, SocialAccount $account): array
    {
        $userId = (string) $account->external_id;
        $token = (string) $account->access_token;

        if ($userId === '' || $token === '') {
            return ['ok' => false, 'message' => 'Threads account id/token missing — reconnect.'];
        }

        $text = trim((string) (($post->title ? $post->title."\n\n" : '').$post->body));
        if (mb_strlen($text) > 500) {
            $text = mb_substr($text, 0, 497).'...';
        }

        $imageUrl = $this->publicMediaUrl($post);
        if ($imageUrl) {
            $imageUrl = $this->resolveRedirectUrl($imageUrl) ?: $imageUrl;
        }

        $payload = [
            'access_token' => $token,
            'text' => $text !== '' ? $text : ' ',
        ];

        if ($imageUrl) {
            $payload['media_type'] = 'IMAGE';
            $payload['image_url'] = $imageUrl;
        } else {
            $payload['media_type'] = 'TEXT';
        }

        $container = Http::asForm()->timeout(60)->post(
            self::THREADS_GRAPH.'/'.rawurlencode($userId).'/threads',
            $payload
        );

        if (! $container->successful() || blank($container->json('id'))) {
            return [
                'ok' => false,
                'message' => $this->graphError($container->json(), $container->body()),
            ];
        }

        $creationId = (string) $container->json('id');

        if ($imageUrl) {
            usleep(1_500_000);
        }

        $publish = Http::asForm()->timeout(60)->post(
            self::THREADS_GRAPH.'/'.rawurlencode($userId).'/threads_publish',
            [
                'creation_id' => $creationId,
                'access_token' => $token,
            ]
        );

        if (! $publish->successful() || blank($publish->json('id'))) {
            return [
                'ok' => false,
                'message' => $this->graphError($publish->json(), $publish->body()),
            ];
        }

        $mediaId = (string) $publish->json('id');
        $permalink = 'https://www.threads.net/';
        $look = Http::timeout(30)->get(self::THREADS_GRAPH.'/'.rawurlencode($mediaId), [
            'fields' => 'permalink',
            'access_token' => $token,
        ]);
        if ($look->successful() && filled($look->json('permalink'))) {
            $permalink = (string) $look->json('permalink');
        } elseif ($account->account_name) {
            $handle = ltrim((string) $account->account_name, '@');
            $permalink = 'https://www.threads.net/@'.$handle;
        }

        return ['ok' => true, 'permalink' => $permalink];
    }

    /**
     * @return array{ok:bool, message?:string}
     */
    private function waitForIgContainer(string $creationId, string $token): array
    {
        // Images are usually quick; still poll a few times before publish.
        for ($i = 0; $i < 12; $i++) {
            if ($i > 0) {
                usleep(500_000);
            }

            $status = Http::timeout(30)->get(self::GRAPH.'/'.rawurlencode($creationId), [
                'fields' => 'status_code,status',
                'access_token' => $token,
            ]);

            if (! $status->successful()) {
                return [
                    'ok' => false,
                    'message' => $this->graphError($status->json(), $status->body()),
                ];
            }

            $code = strtoupper((string) ($status->json('status_code') ?? ''));
            if ($code === 'FINISHED' || $code === 'PUBLISHED' || $code === '') {
                // Empty status_code can happen for simple photo containers that are already ready.
                return ['ok' => true];
            }

            if ($code === 'ERROR' || $code === 'EXPIRED') {
                $detail = (string) ($status->json('status') ?? $code);

                return ['ok' => false, 'message' => 'Instagram container '.$code.($detail ? ': '.$detail : '')];
            }
        }

        return ['ok' => false, 'message' => 'Instagram media still processing — try Publish again in a minute.'];
    }

    private function publicMediaUrl(SocialPost $post): ?string
    {
        $media = $post->media;
        if (! $media) {
            return null;
        }

        $url = $media->url();
        if (! is_string($url) || $url === '') {
            return null;
        }

        // Graph needs a publicly reachable https URL.
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            if (str_contains($url, '127.0.0.1') || str_contains($url, 'localhost')) {
                return null;
            }

            return $url;
        }

        $absolute = url($url);
        if (str_contains($absolute, '127.0.0.1') || str_contains($absolute, 'localhost')) {
            return null;
        }

        return $absolute;
    }

    private function resolveRedirectUrl(string $url): ?string
    {
        try {
            $response = Http::withOptions([
                'allow_redirects' => [
                    'max' => 5,
                    'track_redirects' => true,
                ],
            ])->timeout(20)->head($url);

            $history = $response->header('X-Guzzle-Redirect-History');
            if (is_string($history) && $history !== '') {
                $parts = array_values(array_filter(array_map('trim', explode(',', $history))));
                $last = end($parts);
                if (is_string($last) && str_starts_with($last, 'http')) {
                    return $last;
                }
            }

            // Some CDNs block HEAD — try a ranged GET for the final URL.
            $get = Http::withOptions([
                'allow_redirects' => [
                    'max' => 5,
                    'track_redirects' => true,
                ],
            ])->withHeaders(['Range' => 'bytes=0-0'])->timeout(20)->get($url);

            $history = $get->header('X-Guzzle-Redirect-History');
            if (is_string($history) && $history !== '') {
                $parts = array_values(array_filter(array_map('trim', explode(',', $history))));
                $last = end($parts);
                if (is_string($last) && str_starts_with($last, 'http')) {
                    return $last;
                }
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    private function graphError(mixed $json, string $body): string
    {
        if (is_array($json)) {
            $msg = $json['error']['message']
                ?? $json['error']['error_user_msg']
                ?? null;
            if (is_string($msg) && $msg !== '') {
                return $msg;
            }
        }

        return 'Meta API error: '.Str::limit($body, 180);
    }

    private function writeLog(
        SocialPost $post,
        string $platform,
        string $status,
        ?string $permalink,
        ?string $error = null
    ): void {
        $attempt = SocialPublishLog::query()
            ->where('social_post_id', $post->id)
            ->where('platform', $platform)
            ->count() + 1;

        SocialPublishLog::query()->create([
            'workspace_id' => $post->workspace_id,
            'social_post_id' => $post->id,
            'platform' => $platform,
            'status' => $status,
            'permalink' => $permalink,
            'error' => $error,
            'attempt' => $attempt,
        ]);
    }
}
