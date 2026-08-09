<?php

namespace App\Services\Social;

use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\SocialPublishLog;
use Illuminate\Support\Str;

class SocialPublisherService
{
    /**
     * Stub publisher — stores platform permalinks without real OAuth APIs.
     * Swap platform adapters when Meta/LinkedIn/X apps are approved.
     *
     * @return array{ok:bool, permalinks: array<string,string>, errors: array<string,string>}
     */
    public function publish(SocialPost $post): array
    {
        $permalinks = $post->permalinks ?? [];
        $errors = [];
        $log = $post->publish_log ?? [];

        foreach ($post->platforms ?? [] as $platform) {
            $account = SocialAccount::query()
                ->where('workspace_id', $post->workspace_id)
                ->where('platform', $platform)
                ->where('status', 'connected')
                ->first();

            if (! $account) {
                $errors[$platform] = 'No connected '.$platform.' account';
                $this->writeLog($post, $platform, 'failed', null, $errors[$platform]);
                continue;
            }

            if ($account->health === 'error' || blank($account->access_token)) {
                $errors[$platform] = $account->last_error ?: 'Token missing — reconnect account';
                $this->writeLog($post, $platform, 'failed', null, $errors[$platform]);
                continue;
            }

            // Simulated successful publish
            $permalink = 'https://'.$platform.'.example/posts/'.Str::lower(Str::random(10));
            $permalinks[$platform] = $permalink;
            $log[] = [
                'platform' => $platform,
                'at' => now()->toIso8601String(),
                'permalink' => $permalink,
                'status' => 'published',
            ];
            $this->writeLog($post, $platform, 'published', $permalink);

            $account->update([
                'health' => 'healthy',
                'last_error' => null,
            ]);
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
