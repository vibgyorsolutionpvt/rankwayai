<?php

namespace App\Services\Social;

use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\SocialPublishLog;
use App\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SocialPostAnalyticsService
{
    private const GRAPH = 'https://graph.facebook.com/v19.0';

    private const THREADS_GRAPH = 'https://graph.threads.net/v1.0';

    /**
     * OAuth scopes required per platform for post engagement analytics.
     *
     * @return array<string, list<string>>
     */
    public static function requiredScopes(): array
    {
        return [
            'facebook' => ['pages_show_list', 'pages_manage_posts', 'pages_read_engagement'],
            'instagram' => ['pages_show_list', 'instagram_basic', 'instagram_content_publish', 'instagram_manage_insights'],
            'threads' => ['threads_basic', 'threads_content_publish', 'threads_manage_insights'],
        ];
    }

    public function syncWorkspace(Workspace $workspace, int $limit = 50): int
    {
        $logs = $this->publishedLogsQuery($workspace->id)->limit($limit)->get();

        $synced = 0;
        foreach ($logs as $log) {
            if ($this->syncLog($log)) {
                $synced++;
            }
        }

        return $synced;
    }

    /**
     * @return array{synced:int, failed:int, message:string}
     */
    public function syncPost(SocialPost $post, ?string $onlyPlatform = null): array
    {
        $logs = SocialPublishLog::query()
            ->where('social_post_id', $post->id)
            ->where('status', 'published')
            ->whereNotNull('external_post_id')
            ->whereIn('platform', ['facebook', 'instagram', 'threads'])
            ->when($onlyPlatform, fn ($q) => $q->where('platform', $onlyPlatform))
            ->orderByRaw('metrics_synced_at IS NULL DESC')
            ->get();

        if ($logs->isEmpty()) {
            return [
                'synced' => 0,
                'failed' => 0,
                'message' => 'No live publish record to sync for this post.',
            ];
        }

        $synced = 0;
        $failed = 0;
        $errors = [];

        foreach ($logs as $log) {
            if ($this->syncLog($log)) {
                $synced++;
            } else {
                $failed++;
                $fresh = $log->fresh();
                if (filled($fresh?->metrics_sync_error)) {
                    $errors[] = ucfirst($log->platform).': '.$fresh->metrics_sync_error;
                }
            }
        }

        if ($synced > 0 && $failed === 0) {
            $message = $onlyPlatform
                ? ucfirst($onlyPlatform).' engagement synced.'
                : "Engagement synced for {$synced} platform(s).";
        } elseif ($synced > 0) {
            $message = "Synced {$synced}; {$failed} failed. ".implode(' ', $errors);
        } else {
            $message = $errors !== []
                ? implode(' ', $errors)
                : 'Could not fetch engagement — reconnect accounts with insights permissions.';
        }

        return compact('synced', 'failed', 'message');
    }

    public function syncLog(SocialPublishLog $log): bool
    {
        if ($log->status !== 'published' || blank($log->external_post_id)) {
            $log->update(['metrics_sync_error' => 'Missing live post id from Meta.']);

            return false;
        }

        $account = SocialAccount::query()
            ->where('workspace_id', $log->workspace_id)
            ->where('platform', $log->platform)
            ->where('status', 'connected')
            ->where('connection_mode', 'oauth')
            ->orderByDesc('connected_at')
            ->first();

        if (! $account || blank($account->access_token)) {
            $log->update(['metrics_sync_error' => 'No OAuth account connected — reconnect in SMM → Accounts.']);

            return false;
        }

        $result = match ($log->platform) {
            'facebook' => $this->fetchFacebookMetrics((string) $log->external_post_id, (string) $account->access_token),
            'instagram' => $this->fetchInstagramMetrics((string) $log->external_post_id, (string) $account->access_token),
            'threads' => $this->fetchThreadsMetrics((string) $log->external_post_id, (string) $account->access_token),
            default => ['metrics' => null, 'error' => 'Unsupported platform.'],
        };

        if (($result['metrics'] ?? null) === null) {
            $log->update(['metrics_sync_error' => $result['error'] ?? 'Meta API returned no metrics.']);

            return false;
        }

        $log->update([
            'metrics' => $result['metrics'],
            'metrics_synced_at' => now(),
            'metrics_sync_error' => null,
        ]);

        return true;
    }

    /**
     * @return array{likes:int,comments:int,views:int,reposts:int,shares:int,impressions:?int,reach:?int}
     */
    public function aggregateForPost(int $socialPostId): array
    {
        $logs = SocialPublishLog::query()
            ->where('social_post_id', $socialPostId)
            ->where('status', 'published')
            ->get();

        return $this->aggregateLogs($logs);
    }

    /**
     * @param  Collection<int, SocialPublishLog>  $logs
     * @return array{
     *   likes:int,
     *   comments:int,
     *   views:int,
     *   synced_at:?string,
     *   by_platform:array<string, array{likes:int,comments:int,views:int,reposts:int,shares:int,synced:bool,sync_error:?string}>
     * }
     */
    public function aggregateLogs(Collection $logs): array
    {
        $totals = ['likes' => 0, 'comments' => 0, 'views' => 0];
        $byPlatform = [];
        $latestSync = null;

        foreach ($logs as $log) {
            $m = is_array($log->metrics) ? $log->metrics : [];
            $likes = (int) ($m['likes'] ?? 0);
            $comments = (int) ($m['comments'] ?? 0);
            $views = (int) ($m['views'] ?? 0);
            $synced = $log->metrics_synced_at !== null;

            $byPlatform[$log->platform] = [
                'likes' => $likes,
                'comments' => $comments,
                'views' => $views,
                'reposts' => (int) ($m['reposts'] ?? 0),
                'shares' => (int) ($m['shares'] ?? 0),
                'synced' => $synced,
                'sync_error' => filled($log->metrics_sync_error) ? (string) $log->metrics_sync_error : null,
            ];

            if ($synced) {
                $totals['likes'] += $likes;
                $totals['comments'] += $comments;
                $totals['views'] += $views;

                if ($latestSync === null || $log->metrics_synced_at->gt($latestSync)) {
                    $latestSync = $log->metrics_synced_at;
                }
            }
        }

        return [
            'likes' => $totals['likes'],
            'comments' => $totals['comments'],
            'views' => $totals['views'],
            'synced_at' => $latestSync?->timezone(config('app.timezone'))->format('d M, g:i A'),
            'by_platform' => $byPlatform,
        ];
    }

    private function publishedLogsQuery(int $workspaceId)
    {
        return SocialPublishLog::query()
            ->where('workspace_id', $workspaceId)
            ->where('status', 'published')
            ->whereNotNull('external_post_id')
            ->whereIn('platform', ['facebook', 'instagram', 'threads'])
            ->orderByRaw('metrics_synced_at IS NULL DESC')
            ->orderBy('metrics_synced_at');
    }

    /**
     * @return array{metrics:?array,error:?string}
     */
    private function fetchFacebookMetrics(string $postId, string $token): array
    {
        $parsed = $this->parseFacebookEngagement(self::GRAPH.'/'.rawurlencode($postId), $token);
        if ($parsed['metrics'] !== null) {
            return $parsed;
        }

        if (! str_contains($postId, '_')) {
            $look = Http::timeout(25)->get(self::GRAPH.'/'.rawurlencode($postId), [
                'fields' => 'post_id',
                'access_token' => $token,
            ]);

            if ($look->successful() && filled($look->json('post_id'))) {
                $linked = (string) $look->json('post_id');
                $parsed = $this->parseFacebookEngagement(self::GRAPH.'/'.rawurlencode($linked), $token);
                if ($parsed['metrics'] !== null) {
                    return $parsed;
                }
            }
        }

        return [
            'metrics' => null,
            'error' => $parsed['error'] ?? 'Facebook metrics unavailable — reconnect with pages_read_engagement.',
        ];
    }

    /**
     * @return array{metrics:?array,error:?string}
     */
    private function parseFacebookEngagement(string $url, string $token): array
    {
        $response = Http::timeout(25)->get($url, [
            'fields' => 'reactions.summary(true),likes.summary(true),comments.summary(true),shares',
            'access_token' => $token,
        ]);

        if (! $response->successful()) {
            return [
                'metrics' => null,
                'error' => $this->graphErrorMessage($response->json(), $response->body()),
            ];
        }

        $json = $response->json() ?? [];

        return [
            'metrics' => [
                'likes' => (int) ($json['reactions']['summary']['total_count']
                    ?? $json['likes']['summary']['total_count']
                    ?? 0),
                'comments' => (int) ($json['comments']['summary']['total_count'] ?? 0),
                'views' => 0,
                'shares' => (int) ($json['shares']['count'] ?? 0),
                'reposts' => 0,
                'impressions' => null,
                'reach' => null,
            ],
            'error' => null,
        ];
    }

    /**
     * @return array{metrics:?array,error:?string}
     */
    private function fetchInstagramMetrics(string $mediaId, string $token): array
    {
        $media = Http::timeout(25)->get(self::GRAPH.'/'.rawurlencode($mediaId), [
            'fields' => 'like_count,comments_count',
            'access_token' => $token,
        ]);

        if (! $media->successful()) {
            return [
                'metrics' => null,
                'error' => $this->graphErrorMessage($media->json(), $media->body()),
            ];
        }

        $json = $media->json() ?? [];
        $views = 0;
        $impressions = null;
        $reach = null;

        $insights = Http::timeout(25)->get(self::GRAPH.'/'.rawurlencode($mediaId).'/insights', [
            'metric' => 'impressions,reach,plays',
            'access_token' => $token,
        ]);

        if ($insights->successful()) {
            foreach ($insights->json('data') ?? [] as $row) {
                $name = (string) ($row['name'] ?? '');
                $value = (int) ($row['values'][0]['value'] ?? 0);
                if ($name === 'plays') {
                    $views = $value;
                } elseif ($name === 'impressions') {
                    $impressions = $value;
                } elseif ($name === 'reach') {
                    $reach = $value;
                }
            }
        }

        if ($views === 0 && $impressions !== null) {
            $views = $impressions;
        }

        return [
            'metrics' => [
                'likes' => (int) ($json['like_count'] ?? 0),
                'comments' => (int) ($json['comments_count'] ?? 0),
                'views' => $views,
                'shares' => 0,
                'reposts' => 0,
                'impressions' => $impressions,
                'reach' => $reach,
            ],
            'error' => null,
        ];
    }

    /**
     * @return array{metrics:?array,error:?string}
     */
    private function fetchThreadsMetrics(string $mediaId, string $token): array
    {
        $response = Http::timeout(25)->get(self::THREADS_GRAPH.'/'.rawurlencode($mediaId).'/insights', [
            'metric' => 'likes,replies,views,reposts,quotes,shares',
            'access_token' => $token,
        ]);

        if (! $response->successful()) {
            return [
                'metrics' => null,
                'error' => $this->graphErrorMessage($response->json(), $response->body())
                    ?: 'Threads insights failed — reconnect with threads_manage_insights.',
            ];
        }

        $map = [];
        foreach ($response->json('data') ?? [] as $row) {
            $name = (string) ($row['name'] ?? '');
            $map[$name] = (int) ($row['values'][0]['value'] ?? 0);
        }

        return [
            'metrics' => [
                'likes' => (int) ($map['likes'] ?? 0),
                'comments' => (int) ($map['replies'] ?? 0),
                'views' => (int) ($map['views'] ?? 0),
                'shares' => (int) ($map['shares'] ?? 0),
                'reposts' => (int) ($map['reposts'] ?? 0),
                'impressions' => null,
                'reach' => null,
            ],
            'error' => null,
        ];
    }

    private function graphErrorMessage(?array $json, string $body): string
    {
        $message = (string) ($json['error']['message'] ?? '');

        return $message !== '' ? Str::limit($message, 240) : Str::limit($body, 240);
    }
}
