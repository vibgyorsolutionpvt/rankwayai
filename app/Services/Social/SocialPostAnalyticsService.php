<?php

namespace App\Services\Social;

use App\Models\SocialAccount;
use App\Models\SocialPublishLog;
use App\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

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
        $logs = SocialPublishLog::query()
            ->where('workspace_id', $workspace->id)
            ->where('status', 'published')
            ->whereNotNull('external_post_id')
            ->whereIn('platform', ['facebook', 'instagram', 'threads'])
            ->orderByRaw('metrics_synced_at IS NULL DESC')
            ->orderBy('metrics_synced_at')
            ->limit($limit)
            ->get();

        $synced = 0;
        foreach ($logs as $log) {
            if ($this->syncLog($log)) {
                $synced++;
            }
        }

        return $synced;
    }

    public function syncLog(SocialPublishLog $log): bool
    {
        if ($log->status !== 'published' || blank($log->external_post_id)) {
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
            return false;
        }

        $metrics = match ($log->platform) {
            'facebook' => $this->fetchFacebookMetrics((string) $log->external_post_id, (string) $account->access_token),
            'instagram' => $this->fetchInstagramMetrics((string) $log->external_post_id, (string) $account->access_token),
            'threads' => $this->fetchThreadsMetrics((string) $log->external_post_id, (string) $account->access_token),
            default => null,
        };

        if ($metrics === null) {
            return false;
        }

        $log->update([
            'metrics' => $metrics,
            'metrics_synced_at' => now(),
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
            ->whereNotNull('metrics')
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
     *   by_platform:array<string, array{likes:int,comments:int,views:int,reposts:int,shares:int}>
     * }
     */
    public function aggregateLogs(Collection $logs): array
    {
        $totals = ['likes' => 0, 'comments' => 0, 'views' => 0];
        $byPlatform = [];
        $latestSync = null;

        foreach ($logs as $log) {
            $m = $log->metrics ?? [];
            if ($m === []) {
                continue;
            }

            $likes = (int) ($m['likes'] ?? 0);
            $comments = (int) ($m['comments'] ?? 0);
            $views = (int) ($m['views'] ?? 0);

            $totals['likes'] += $likes;
            $totals['comments'] += $comments;
            $totals['views'] += $views;

            $byPlatform[$log->platform] = [
                'likes' => $likes,
                'comments' => $comments,
                'views' => $views,
                'reposts' => (int) ($m['reposts'] ?? 0),
                'shares' => (int) ($m['shares'] ?? 0),
            ];

            if ($log->metrics_synced_at && ($latestSync === null || $log->metrics_synced_at->gt($latestSync))) {
                $latestSync = $log->metrics_synced_at;
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

    /**
     * @return ?array{likes:int,comments:int,views:int,shares:int,reposts:int,impressions:?int,reach:?int}
     */
    private function fetchFacebookMetrics(string $postId, string $token): ?array
    {
        $response = Http::timeout(25)->get(self::GRAPH.'/'.rawurlencode($postId), [
            'fields' => 'likes.summary(true),comments.summary(true),shares',
            'access_token' => $token,
        ]);

        if (! $response->successful()) {
            return null;
        }

        $json = $response->json() ?? [];

        return [
            'likes' => (int) ($json['likes']['summary']['total_count'] ?? 0),
            'comments' => (int) ($json['comments']['summary']['total_count'] ?? 0),
            'views' => 0,
            'shares' => (int) ($json['shares']['count'] ?? 0),
            'reposts' => 0,
            'impressions' => null,
            'reach' => null,
        ];
    }

    /**
     * @return ?array{likes:int,comments:int,views:int,shares:int,reposts:int,impressions:?int,reach:?int}
     */
    private function fetchInstagramMetrics(string $mediaId, string $token): ?array
    {
        $media = Http::timeout(25)->get(self::GRAPH.'/'.rawurlencode($mediaId), [
            'fields' => 'like_count,comments_count',
            'access_token' => $token,
        ]);

        if (! $media->successful()) {
            return null;
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
            'likes' => (int) ($json['like_count'] ?? 0),
            'comments' => (int) ($json['comments_count'] ?? 0),
            'views' => $views,
            'shares' => 0,
            'reposts' => 0,
            'impressions' => $impressions,
            'reach' => $reach,
        ];
    }

    /**
     * @return ?array{likes:int,comments:int,views:int,shares:int,reposts:int,impressions:?int,reach:?int}
     */
    private function fetchThreadsMetrics(string $mediaId, string $token): ?array
    {
        $response = Http::timeout(25)->get(self::THREADS_GRAPH.'/'.rawurlencode($mediaId).'/insights', [
            'metric' => 'likes,replies,views,reposts,quotes,shares',
            'access_token' => $token,
        ]);

        if (! $response->successful()) {
            return null;
        }

        $map = [];
        foreach ($response->json('data') ?? [] as $row) {
            $name = (string) ($row['name'] ?? '');
            $map[$name] = (int) ($row['values'][0]['value'] ?? 0);
        }

        return [
            'likes' => (int) ($map['likes'] ?? 0),
            'comments' => (int) ($map['replies'] ?? 0),
            'views' => (int) ($map['views'] ?? 0),
            'shares' => (int) ($map['shares'] ?? 0),
            'reposts' => (int) ($map['reposts'] ?? 0),
            'impressions' => null,
            'reach' => null,
        ];
    }
}
