<?php

namespace App\Services\Social;

use App\Jobs\SyncSocialPostEngagementJob;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\SocialPublishLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

        $needsImage = in_array('instagram', $post->platforms ?? [], true);

        if (! $retryMode && $needsImage && ! $this->hasPublicImage($post)) {
            $message = 'Instagram requires a public https image — attach media or generate a poster before publishing.';
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

            if ($platform === 'instagram' && ! $this->hasPublicImage($post)) {
                $errors[$platform] = 'Instagram requires a public https image.';
                $this->writeLog($post, $platform, 'failed', null, $errors[$platform]);
                continue;
            }

            if ($this->hasAttachedMedia($post) && ! $this->hasPublicImage($post)) {
                $errors[$platform] = 'Attached image must be a public https URL for '.$platform.' (Meta cannot reach localhost).';
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
            $externalPostId = filled($result['external_post_id'] ?? null)
                ? (string) $result['external_post_id']
                : null;
            if ($permalink !== '') {
                $newPermalinks[$platform] = $permalink;
            }
            if (! empty($result['story_permalink'])) {
                $newPermalinks[$platform.'_story'] = (string) $result['story_permalink'];
                $this->writeLog($post, $platform.'_story', 'published', (string) $result['story_permalink']);
            } elseif (! empty($result['story_error'])) {
                $this->writeLog($post, $platform.'_story', 'failed', null, (string) $result['story_error']);
            }
            $log[] = [
                'platform' => $platform,
                'at' => now()->toIso8601String(),
                'permalink' => $permalink,
                'external_post_id' => $externalPostId,
                'status' => 'published',
                'story_permalink' => $result['story_permalink'] ?? null,
                'story_error' => $result['story_error'] ?? null,
            ];
            $this->writeLog($post, $platform, 'published', $permalink !== '' ? $permalink : null, null, $externalPostId);
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

        if (count($permalinks) > 0) {
            $delay = max(1, (int) config('social.metrics_sync_delay_minutes', 3));
            SyncSocialPostEngagementJob::dispatch($post->id)
                ->delay(now()->addMinutes($delay));
        }

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
            } elseif ($attempted) {
                $statuses[] = [
                    'platform' => $platform,
                    'label' => $label,
                    'status' => 'failed',
                    'permalink' => null,
                    'error' => $this->lastAttemptError($post, $platform),
                    'can_resend' => $this->canResendPlatform($post, $platform),
                ];
            } else {
                $statuses[] = [
                    'platform' => $platform,
                    'label' => $label,
                    'status' => 'pending',
                    'permalink' => null,
                    'error' => null,
                    'can_resend' => false,
                ];
            }

            // Include Story status pill if publish_to_story was enabled
            if (! empty($post->publish_to_story) && in_array($platform, ['facebook', 'instagram'], true)) {
                $storyPlatform = $platform.'_story';
                $storyLabel = $platform === 'facebook' ? 'FB Story' : 'IG Story';

                if (! empty($permalinks[$storyPlatform])) {
                    $statuses[] = [
                        'platform' => $storyPlatform,
                        'label' => $storyLabel,
                        'status' => 'published',
                        'permalink' => $permalinks[$storyPlatform],
                        'error' => null,
                        'can_resend' => false,
                    ];
                } elseif ($attempted) {
                    $statuses[] = [
                        'platform' => $storyPlatform,
                        'label' => $storyLabel,
                        'status' => 'failed',
                        'permalink' => null,
                        'error' => $this->lastAttemptError($post, $storyPlatform),
                        'can_resend' => false,
                    ];
                }
            }
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

        $response = null;

        if ($imageUrl) {
            // Step 1: Upload photo as unpublished to obtain a media_fbid
            $photoUpload = Http::asForm()->timeout(60)->post(self::GRAPH.'/'.rawurlencode($pageId).'/photos', [
                'url' => $imageUrl,
                'published' => 'false',
                'access_token' => $token,
            ]);

            $photoFbid = (string) ($photoUpload->json('id') ?? '');

            if ($photoUpload->successful() && $photoFbid !== '') {
                // Step 2: Publish a Feed post (timeline story) with the uploaded photo attached.
                // This ensures the post appears on the Page Feed / Timeline with Like, Comment, and SHARE buttons.
                $response = Http::asForm()->timeout(60)->post(self::GRAPH.'/'.rawurlencode($pageId).'/feed', [
                    'message' => $message !== '' ? $message : ' ',
                    'attached_media' => [
                        json_encode(['media_fbid' => $photoFbid]),
                    ],
                    'access_token' => $token,
                ]);
            }

            // Fallback 1: If feed with attached_media failed or photo upload failed, try feed with link
            if (! $response || ! $response->successful()) {
                $fallbackFeed = Http::asForm()->timeout(60)->post(self::GRAPH.'/'.rawurlencode($pageId).'/feed', [
                    'message' => $message !== '' ? $message : ' ',
                    'link' => $imageUrl,
                    'access_token' => $token,
                ]);

                if ($fallbackFeed->successful()) {
                    $response = $fallbackFeed;
                }
            }

            // Fallback 2: Direct published photo in album if feed calls failed
            if (! $response || ! $response->successful()) {
                $response = Http::asForm()->timeout(60)->post(self::GRAPH.'/'.rawurlencode($pageId).'/photos', [
                    'url' => $imageUrl,
                    'caption' => $message,
                    'published' => 'true',
                    'access_token' => $token,
                ]);
            }
        } else {
            if ($message === '') {
                return ['ok' => false, 'message' => 'Facebook posts need text or an image.'];
            }

            $response = Http::asForm()->timeout(60)->post(self::GRAPH.'/'.rawurlencode($pageId).'/feed', [
                'message' => $message,
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
        $postId = (string) ($response->json('post_id') ?? '');
        if ($postId === '' && $id !== '' && str_contains($id, '_')) {
            $postId = $id;
        } elseif ($postId === '' && $id !== '') {
            $postId = $pageId.'_'.$id;
        }

        $permalink = $postId !== ''
            ? 'https://www.facebook.com/'.$postId
            : null;

        // Prefer Graph permalink lookup when we have a post id.
        if ($postId !== '' && str_contains($postId, '_')) {
            $look = Http::timeout(30)->get(self::GRAPH.'/'.rawurlencode($postId), [
                'fields' => 'permalink_url',
                'access_token' => $token,
            ]);
            if ($look->successful() && filled($look->json('permalink_url'))) {
                $permalink = (string) $look->json('permalink_url');
            }
        }

        $storyPermalink = null;
        $storyError = null;
        if (! empty($post->publish_to_story)) {
            if ($imageUrl) {
                $storyImageUrl = $this->publicStoryImageUrl($post) ?: $imageUrl;
                $storyResult = $this->publishFacebookStory($pageId, $storyImageUrl, $token);
                if ($storyResult['ok'] ?? false) {
                    $storyPermalink = $storyResult['permalink'] ?? null;
                } else {
                    $storyError = $storyResult['message'] ?? 'Facebook story publish failed.';
                    Log::warning('Facebook story publishing failed', [
                        'post_id' => $post->id,
                        'page_id' => $pageId,
                        'error' => $storyError,
                    ]);
                }
            } else {
                $storyError = 'Facebook Story requires an image. Text-only stories are not supported by Meta API.';
                Log::info('Facebook story skipped: no image attached', ['post_id' => $post->id]);
            }
        }

        return [
            'ok' => true,
            'permalink' => $permalink ?? ('https://facebook.com/'.$pageId),
            'external_post_id' => $postId !== '' ? $postId : null,
            'story_permalink' => $storyPermalink,
            'story_error' => $storyError,
        ];
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

        $storyPermalink = null;
        $storyError = null;
        if (! empty($post->publish_to_story)) {
            if ($imageUrl) {
                $storyImageUrl = $this->publicStoryImageUrl($post) ?: $imageUrl;
                $storyResult = $this->publishInstagramStory($igUserId, $storyImageUrl, $token);
                if ($storyResult['ok'] ?? false) {
                    $storyPermalink = $storyResult['permalink'] ?? null;
                } else {
                    $storyError = $storyResult['message'] ?? 'Instagram story publish failed.';
                    Log::warning('Instagram story publishing failed', [
                        'post_id' => $post->id,
                        'ig_user_id' => $igUserId,
                        'error' => $storyError,
                    ]);
                }
            } else {
                $storyError = 'Instagram Story requires an image.';
                Log::info('Instagram story skipped: no image attached', ['post_id' => $post->id]);
            }
        }

        return [
            'ok' => true,
            'permalink' => $permalink,
            'external_post_id' => $mediaId,
            'story_permalink' => $storyPermalink,
            'story_error' => $storyError,
        ];
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
            if ($text === '') {
                return ['ok' => false, 'message' => 'Threads posts need text or an image.'];
            }
            $payload['media_type'] = 'TEXT';
        }

        $container = Http::asForm()->timeout(60)->post(
            self::THREADS_GRAPH.'/'.rawurlencode($userId).'/threads',
            $payload
        );

        // If user ID was stale and gave 404/Error 24, attempt once with /me/threads
        if (! $container->successful() && blank($container->json('id'))) {
            $errJson = $container->json();
            $errCode = (int) ($errJson['error']['code'] ?? 0);
            if ($errCode === 24 || str_contains((string) ($errJson['error']['message'] ?? ''), 'does not exist')) {
                $retryMe = Http::asForm()->timeout(60)->post(
                    self::THREADS_GRAPH.'/me/threads',
                    $payload
                );
                if ($retryMe->successful() && filled($retryMe->json('id'))) {
                    $container = $retryMe;
                    $meInfo = Http::timeout(15)->get(self::THREADS_GRAPH.'/me', [
                        'fields' => 'id,username',
                        'access_token' => $token,
                    ]);
                    if ($meInfo->successful() && filled($meInfo->json('id'))) {
                        $userId = (string) $meInfo->json('id');
                        $account->update(['external_id' => $userId]);
                    }
                }
            }
        }

        if (! $container->successful() || blank($container->json('id'))) {
            return [
                'ok' => false,
                'message' => $this->graphError($container->json(), $container->body()),
            ];
        }

        $creationId = (string) $container->json('id');

        // Always wait for container ready status (for both image and text), as Threads servers
        // process the container asynchronously across their cluster. Calling threads_publish too
        // quickly returns code 24 ("The requested resource does not exist" / Media Not Found).
        $wait = $this->waitForThreadsContainer($creationId, $token);
        if (! ($wait['ok'] ?? false)) {
            return $wait;
        }

        // Retry publish up to 4 times with exponential backoff if Meta reports transient
        // resource not ready / does not exist (code 24 / error_subcode 4279009 / Media Not Found).
        $publish = null;
        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $publish = Http::asForm()->timeout(60)->post(
                self::THREADS_GRAPH.'/'.rawurlencode($userId).'/threads_publish',
                [
                    'creation_id' => $creationId,
                    'access_token' => $token,
                ]
            );

            if ($publish->successful() && filled($publish->json('id'))) {
                break;
            }

            $errJson = $publish->json();
            $errCode = (int) ($errJson['error']['code'] ?? 0);
            $errSubcode = (int) ($errJson['error']['error_subcode'] ?? 0);
            $errMsg = (string) ($errJson['error']['message'] ?? '');

            $isResourceNotFound = $errCode === 24 || $errSubcode === 4279009 || str_contains($errMsg, 'requested resource does not exist');
            if ($isResourceNotFound) {
                // If the user ID was rejected on publish, attempt publish via /me/threads_publish
                if ($attempt === 2) {
                    $publishMe = Http::asForm()->timeout(60)->post(
                        self::THREADS_GRAPH.'/me/threads_publish',
                        [
                            'creation_id' => $creationId,
                            'access_token' => $token,
                        ]
                    );
                    if ($publishMe->successful() && filled($publishMe->json('id'))) {
                        $publish = $publishMe;
                        break;
                    }
                }

                if ($attempt < 4) {
                    // Sleep 2.5s, 4s, 6s across retries while container finishes propagating on Meta's cluster
                    sleep($attempt * 2);
                    continue;
                }
            }

            break;
        }

        if (! $publish || ! $publish->successful() || blank($publish->json('id'))) {
            return [
                'ok' => false,
                'message' => $this->graphError($publish?->json(), $publish?->body() ?? 'Threads publish failed'),
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

        return [
            'ok' => true,
            'permalink' => $permalink,
            'external_post_id' => $mediaId,
        ];
    }

    /**
     * @return array{ok:bool, message?:string}
     */
    private function waitForThreadsContainer(string $creationId, string $token): array
    {
        for ($i = 0; $i < 15; $i++) {
            if ($i > 0) {
                // Wait 1.2s between polls (Meta takes 1.5s - 5s on average to register container across cluster)
                usleep(1_200_000);
            }

            $status = Http::timeout(30)->get(self::THREADS_GRAPH.'/'.rawurlencode($creationId), [
                'fields' => 'id,status,error_message',
                'access_token' => $token,
            ]);

            if (! $status->successful()) {
                // If container is still propagating (code 24), don't fail immediately — keep waiting
                $errJson = $status->json();
                $errCode = (int) ($errJson['error']['code'] ?? 0);
                if ($errCode === 24 && $i < 14) {
                    continue;
                }

                return [
                    'ok' => false,
                    'message' => $this->graphError($status->json(), $status->body()),
                ];
            }

            $code = strtoupper((string) ($status->json('status') ?? ''));
            if ($code === 'FINISHED' || $code === 'PUBLISHED') {
                return ['ok' => true];
            }

            if ($code === 'ERROR' || $code === 'EXPIRED') {
                $detail = (string) ($status->json('error_message') ?? $code);

                return ['ok' => false, 'message' => 'Threads media processing '.$code.($detail ? ': '.$detail : '')];
            }

            // If empty status or IN_PROGRESS, continue polling
        }

        // After loop, return ok true to proceed with publish (the retry mechanism in publish will catch any transient delay)
        return ['ok' => true];
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

    public function publicStoryImageUrl(SocialPost $post): ?string
    {
        $posters = $post->poster_variants ?? [];
        if (! empty($posters['ig_story']) && is_string($posters['ig_story'])) {
            $url = $this->normalizePublicUrl($posters['ig_story']);
            if ($url) {
                return $this->resolveRedirectUrl($url) ?: $url;
            }
        }

        return $this->publicMediaUrl($post);
    }

    /**
     * Publish photo to Facebook Page Story.
     *
     * @return array{ok:bool, permalink?:string, message?:string, story_id?:string}
     */
    private function publishFacebookStory(string $pageId, string $imageUrl, string $token): array
    {
        try {
            $imageUrl = $this->resolveRedirectUrl($imageUrl) ?: $imageUrl;

            // Step 1: Upload a fresh unpublished photo specifically for the Story.
            // Meta Page Stories API requires photo_id of an UNPUBLISHED photo.
            // Do NOT reuse a photo that was already attached/published to a feed post,
            // as Meta marks attached photos published and rejects them on /photo_stories.
            $photoUpload = Http::asForm()->timeout(60)->post(self::GRAPH.'/'.rawurlencode($pageId).'/photos', [
                'url' => $imageUrl,
                'published' => 'false',
                'access_token' => $token,
            ]);

            if (! $photoUpload->successful() || blank($photoUpload->json('id'))) {
                $err = $this->graphError($photoUpload->json(), $photoUpload->body());
                Log::warning('Facebook story photo upload failed', ['page_id' => $pageId, 'error' => $err]);

                return ['ok' => false, 'message' => 'Facebook story photo upload failed: '.$err];
            }

            $photoId = (string) $photoUpload->json('id');

            // Step 2: Publish to Page Stories endpoint (POST /{page-id}/photo_stories)
            $url = self::GRAPH.'/'.rawurlencode($pageId).'/photo_stories';

            $storyResponse = Http::asForm()->timeout(60)->post($url, [
                'photo_id' => $photoId,
                'access_token' => $token,
            ]);

            // If transient delay or format fallback, retry with query params after brief delay
            if (! $storyResponse->successful()) {
                usleep(1_500_000);
                $storyResponse = Http::asForm()->timeout(60)->post($url.'?'.http_build_query([
                    'photo_id' => $photoId,
                    'access_token' => $token,
                ]), [
                    'photo_id' => $photoId,
                    'access_token' => $token,
                ]);
            }

            if (! $storyResponse->successful()) {
                $err = $this->graphError($storyResponse->json(), $storyResponse->body());
                Log::warning('Facebook photo_stories API failed', ['page_id' => $pageId, 'photo_id' => $photoId, 'error' => $err]);

                return ['ok' => false, 'message' => 'Facebook photo_stories failed: '.$err];
            }

            $storyId = (string) ($storyResponse->json('post_id') ?? $storyResponse->json('id') ?? '');

            return [
                'ok' => true,
                'permalink' => $storyId !== '' ? 'https://facebook.com/stories/'.$storyId : 'https://facebook.com/'.$pageId,
                'story_id' => $storyId,
            ];
        } catch (\Throwable $e) {
            Log::warning('Facebook story exception', ['page_id' => $pageId, 'exception' => $e->getMessage()]);

            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Publish photo to Instagram Story.
     *
     * @return array{ok:bool, permalink?:string, message?:string, story_id?:string}
     */
    private function publishInstagramStory(string $igUserId, string $imageUrl, string $token): array
    {
        try {
            // Step 1: Create Story container
            $container = Http::asForm()->timeout(60)->post(self::GRAPH.'/'.rawurlencode($igUserId).'/media', [
                'image_url' => $imageUrl,
                'media_type' => 'STORIES',
                'access_token' => $token,
            ]);

            if (! $container->successful() || blank($container->json('id'))) {
                return ['ok' => false, 'message' => $this->graphError($container->json(), $container->body())];
            }

            $creationId = (string) $container->json('id');
            $ready = $this->waitForIgContainer($creationId, $token);
            if (! ($ready['ok'] ?? false)) {
                return ['ok' => false, 'message' => $ready['message'] ?? 'Instagram story media container failed.'];
            }

            // Step 2: Publish Story container
            $publish = Http::asForm()->timeout(60)->post(self::GRAPH.'/'.rawurlencode($igUserId).'/media_publish', [
                'creation_id' => $creationId,
                'access_token' => $token,
            ]);

            if (! $publish->successful() || blank($publish->json('id'))) {
                return ['ok' => false, 'message' => $this->graphError($publish->json(), $publish->body())];
            }

            $storyMediaId = (string) $publish->json('id');

            return [
                'ok' => true,
                'permalink' => 'https://www.instagram.com/stories/',
                'story_id' => $storyMediaId,
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
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
        $permalinks = [];
        foreach ($post->platforms ?? [] as $platform) {
            $log[] = [
                'platform' => $platform,
                'at' => now()->toIso8601String(),
                'permalink' => null,
                'status' => 'simulated',
            ];

            if (! empty($post->publish_to_story) && in_array($platform, ['facebook', 'instagram'], true)) {
                $permalinks[$platform.'_story'] = 'https://'.$platform.'.com/stories/simulated';
            }
        }

        $post->update([
            'status' => 'published',
            'published_at' => now(),
            'failure_reason' => null,
            'permalinks' => $permalinks,
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
            $code = (int) ($json['error']['code'] ?? 0);
            $subcode = (int) ($json['error']['error_subcode'] ?? 0);
            $msg = $json['error']['message']
                ?? $json['error']['error_user_msg']
                ?? null;

            if ($code === 190) {
                return 'Meta session expired or password changed (Error 190). Please reconnect this account under SMM → Accounts.';
            }

            if ($code === 200) {
                return 'Meta permissions error (Error 200). Ensure the Meta account has Page admin/editor access and required permissions.';
            }

            if ($code === 9007) {
                return 'Threads media is still processing (Error 9007). Please retry in a few moments.';
            }

            if ($code === 24 || $subcode === 4279009) {
                return 'Threads container was still propagating on Meta servers. The system retries automatically; please try publishing again.';
            }

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
        ?string $error = null,
        ?string $externalPostId = null,
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
            'external_post_id' => $externalPostId,
            'error' => $error,
            'attempt' => $attempt,
        ]);
    }
}
