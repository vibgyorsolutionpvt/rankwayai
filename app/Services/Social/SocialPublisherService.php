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
     * @param  list<string>|null  $onlyPlatforms  When set, (re)publish only these platforms; skips ones with permalinks.
     * @return array{ok:bool, permalinks: array<string,string>, errors: array<string,string>}
     */
    public function publish(SocialPost $post, ?array $onlyPlatforms = null): array
    {
        $post->loadMissing('media');

        $existingPermalinks = $post->permalinks ?? [];
        $existingLog = $post->publish_log ?? [];
        $retryMode = $onlyPlatforms !== null;

        if (! $retryMode && ! $this->hasPublicImage($post)) {
            $message = 'An image is required — attach media or generate a poster before publishing.';
            $post->update([
                'status' => 'failed',
                'failure_reason' => $message,
            ]);

            return ['ok' => false, 'permalinks' => [], 'errors' => ['all' => $message]];
        }

        if ($retryMode && $onlyPlatforms === []) {
            return ['ok' => true, 'permalinks' => $existingPermalinks, 'errors' => []];
        }

        $newPermalinks = [];
        $errors = [];
        $log = [];

        foreach ($post->platforms ?? [] as $platform) {
            if ($onlyPlatforms !== null && ! in_array($platform, $onlyPlatforms, true)) {
                continue;
            }

            if ($retryMode && ! empty($existingPermalinks[$platform])) {
                continue;
            }

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

            if (! $this->hasPublicImage($post)) {
                $errors[$platform] = 'A public https image is required for '.$platform.'.';
                $this->writeLog($post, $platform, 'failed', null, $errors[$platform]);
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
                $newPermalinks[$platform] = $permalink;
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

        $permalinks = array_merge($existingPermalinks, $newPermalinks);
        $remainingErrors = $this->remainingPlatformErrors($post, $permalinks, $errors);
        $allPublished = $this->failedPlatforms(new SocialPost([
            'platforms' => $post->platforms,
            'permalinks' => $permalinks,
        ])) === [];

        $ok = $allPublished && count($permalinks) > 0;

        $post->update([
            'permalinks' => $permalinks,
            'publish_log' => array_merge($existingLog, $log),
            'status' => $ok ? 'published' : (count($permalinks) > 0 ? 'published' : 'failed'),
            'published_at' => $ok || count($permalinks) > 0 ? ($post->published_at ?? now()) : null,
            'failure_reason' => $remainingErrors !== [] ? $this->formatPlatformErrors($remainingErrors) : null,
        ]);

        return ['ok' => $ok, 'permalinks' => $permalinks, 'errors' => $remainingErrors];
    }

    /**
     * @return list<string>
     */
    public function failedPlatforms(SocialPost $post): array
    {
        $failed = [];
        foreach ($post->platforms ?? [] as $platform) {
            if (empty(($post->permalinks ?? [])[$platform])) {
                $failed[] = $platform;
            }
        }

        return $failed;
    }

    public function hasPublishFailures(SocialPost $post): bool
    {
        if (! in_array($post->status, ['published', 'failed'], true)) {
            return false;
        }

        return $this->failedPlatforms($post) !== [];
    }

    /**
     * @return list<array{platform:string,label:string,status:string,permalink:?string,error:?string,can_resend:bool}>
     */
    public function platformStatuses(SocialPost $post): array
    {
        $labels = [
            'facebook' => 'FB',
            'instagram' => 'IG',
            'threads' => 'TH',
            'linkedin' => 'LI',
            'x' => 'X',
        ];

        $permalinks = $post->permalinks ?? [];
        $attempted = in_array($post->status, ['published', 'failed'], true);
        $statuses = [];

        foreach ($post->platforms ?? [] as $platform) {
            $label = $labels[$platform] ?? strtoupper(substr($platform, 0, 2));

            if (! empty($permalinks[$platform])) {
                $statuses[] = [
                    'platform' => $platform,
                    'label' => $label,
                    'status' => 'published',
                    'permalink' => $permalinks[$platform],
                    'error' => null,
                    'can_resend' => false,
                ];

                continue;
            }

            if ($attempted) {
                $statuses[] = [
                    'platform' => $platform,
                    'label' => $label,
                    'status' => 'failed',
                    'permalink' => null,
                    'error' => $this->lastAttemptError($post, $platform),
                    'can_resend' => $this->canResendPlatform($post, $platform),
                ];

                continue;
            }

            $statuses[] = [
                'platform' => $platform,
                'label' => $label,
                'status' => 'pending',
                'permalink' => null,
                'error' => null,
                'can_resend' => false,
            ];
        }

        return $statuses;
    }

    public function canResendPlatform(SocialPost $post, string $platform): bool
    {
        if (! in_array($platform, $post->platforms ?? [], true)) {
            return false;
        }

        if (! in_array($post->status, ['published', 'failed'], true)) {
            return false;
        }

        if (! empty(($post->permalinks ?? [])[$platform])) {
            return false;
        }

        if ($post->requires_approval && ! $post->approved_at) {
            return false;
        }

        return $this->hasAttachedMedia($post);
    }

    /**
     * @param  array<string, string>  $permalinks
     * @param  array<string, string>  $attemptErrors
     * @return array<string, string>
     */
    private function remainingPlatformErrors(SocialPost $post, array $permalinks, array $attemptErrors): array
    {
        $remaining = [];
        foreach ($post->platforms ?? [] as $platform) {
            if (! empty($permalinks[$platform])) {
                continue;
            }
            $remaining[$platform] = $attemptErrors[$platform]
                ?? $this->lastAttemptError($post, $platform)
                ?? 'Publish failed';
        }

        return $remaining;
    }

    private function lastAttemptError(SocialPost $post, string $platform): ?string
    {
        $entry = SocialPublishLog::query()
            ->where('social_post_id', $post->id)
            ->where('platform', $platform)
            ->where('status', 'failed')
            ->orderByDesc('id')
            ->first();

        return filled($entry?->error) ? (string) $entry->error : null;
    }

    /**
     * @param  array<string, string>  $errors
     */
    private function formatPlatformErrors(array $errors): string
    {
        return implode('; ', array_map(
            fn (string $platform, string $message) => ucfirst($platform).': '.$message,
            array_keys($errors),
            array_values($errors),
        ));
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

        if (! $imageUrl) {
            return ['ok' => false, 'message' => 'Facebook posts need an image. Attach media or generate a poster.'];
        }

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

            return ['ok' => false, 'message' => 'Instagram requires an image. Attach media or generate a poster.'];
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

        if (! $imageUrl) {
            return ['ok' => false, 'message' => 'Threads posts need an image. Attach media or generate a poster.'];
        }

        $payload = [
            'access_token' => $token,
            'text' => $text !== '' ? $text : ' ',
            'media_type' => 'IMAGE',
            'image_url' => $imageUrl,
        ];

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
        if ($media) {
            $url = $this->normalizePublicUrl($media->url());
            if ($url) {
                return $url;
            }
        }

        $posters = $post->poster_variants ?? [];
        foreach (['ig_feed', 'link_share', 'ig_story'] as $key) {
            $candidate = $posters[$key] ?? null;
            if (! is_string($candidate) || $candidate === '') {
                continue;
            }

            $url = $this->normalizePublicUrl($candidate);
            if ($url) {
                return $url;
            }
        }

        return null;
    }

    public function hasAttachedMedia(SocialPost $post): bool
    {
        if ($post->media_asset_id) {
            return true;
        }

        foreach ($post->poster_variants ?? [] as $url) {
            if (is_string($url) && $url !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Dev-only: mark published locally without calling Meta (localhost images).
     */
    public function simulateLocalPublish(SocialPost $post): void
    {
        $log = [];
        foreach ($post->platforms ?? [] as $platform) {
            $log[] = [
                'platform' => $platform,
                'at' => now()->toIso8601String(),
                'permalink' => null,
                'status' => 'simulated',
            ];
        }

        $post->update([
            'status' => 'published',
            'published_at' => now(),
            'failure_reason' => null,
            'permalinks' => [],
            'publish_log' => $log,
        ]);
    }

    public function hasPublicImage(SocialPost $post): bool
    {
        $post->loadMissing('media');

        return $this->publicMediaUrl($post) !== null;
    }

    private function normalizePublicUrl(?string $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        $url = $this->rewritePublicMediaBase($url);

        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            if (str_contains($url, '127.0.0.1') || str_contains($url, 'localhost')) {
                return null;
            }

            return $url;
        }

        $absolute = $this->rewritePublicMediaBase(url($url));
        if (str_contains($absolute, '127.0.0.1') || str_contains($absolute, 'localhost')) {
            return null;
        }

        return $absolute;
    }

    private function rewritePublicMediaBase(string $url): string
    {
        $override = rtrim((string) config('social.public_media_base_url', ''), '/');
        if ($override === '') {
            return $url;
        }

        if (! str_contains($url, '127.0.0.1') && ! str_contains($url, 'localhost')) {
            return $url;
        }

        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            return $url;
        }

        return $override.$path;
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
