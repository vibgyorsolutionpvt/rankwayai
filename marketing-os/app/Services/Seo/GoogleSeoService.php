<?php

namespace App\Services\Seo;

use App\Models\SeoSite;
use App\Services\Integrations\WorkspaceIntegrationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class GoogleSeoService
{
    public function __construct(private WorkspaceIntegrationService $integrations) {}

    public function gscConfigured(SeoSite $site): bool
    {
        return $this->integrations->googleGscConfig($site->workspace) !== null;
    }

    public function pagespeedConfigured(SeoSite $site): bool
    {
        return filled($this->integrations->pagespeedApiKey($site->workspace));
    }

    public function connectGsc(SeoSite $site, ?string $code = null): array
    {
        if (! $this->gscConfigured($site)) {
            return [
                'ok' => false,
                'message' => 'Google Search Console is not configured. Add OAuth Client ID & secret under Settings → Providers → SEO / Google.',
                'needs_setup' => true,
            ];
        }

        if (blank($code)) {
            return [
                'ok' => false,
                'message' => 'Missing OAuth code.',
                'authorize_url' => $this->gscAuthorizeUrl($site),
            ];
        }

        $tokens = $this->exchangeGoogleToken($site, $code);
        if (! $tokens) {
            return [
                'ok' => false,
                'message' => 'Google token exchange failed. Check redirect URI and credentials.',
            ];
        }

        $site->update([
            'gsc_connected' => true,
            'gsc_connection_mode' => 'oauth',
            'gsc_property' => 'sc-domain:'.$site->domain,
            'gsc_token' => json_encode($tokens),
            'gsc_last_error' => null,
            'status' => 'connected',
        ]);

        $sync = $this->syncSearchAnalytics($site->fresh(), force: true);

        return [
            'ok' => true,
            'message' => $sync['ok']
                ? 'Google Search Console connected — '.$sync['message']
                : 'Google Search Console connected. '.$sync['message'],
        ];
    }

    public function gscAuthorizeUrl(SeoSite $site): string
    {
        $config = $this->integrations->googleGscConfig($site->workspace);
        $state = base64_encode(json_encode(['site_id' => $site->id, 'nonce' => Str::random(12)]));

        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => $config['client_id'],
            'redirect_uri' => $this->gscRedirectUri(),
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/webmasters.readonly',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);
    }

    /**
     * Pull last-28-day Search Console queries into the site snapshot.
     *
     * @return array{ok: bool, message: string, rows?: int, cooldown?: bool, retry_after_seconds?: int}
     */
    public function syncSearchAnalytics(SeoSite $site, bool $force = false): array
    {
        if (! $site->gsc_connected || blank($site->gsc_token)) {
            return ['ok' => false, 'message' => 'Connect GSC first.'];
        }

        if (! $force) {
            $wait = $this->gscSyncRetryAfterSeconds($site);
            if ($wait > 0) {
                return [
                    'ok' => false,
                    'cooldown' => true,
                    'retry_after_seconds' => $wait,
                    'message' => 'GSC was synced recently. Next sync available in '.$this->humanMinutes($wait).' (saves Google free quota).',
                ];
            }
        }

        $accessToken = $this->accessTokenFor($site);
        if (! $accessToken) {
            $site->update([
                'gsc_last_error' => 'Token expired — reconnect GSC.',
                'gsc_connected' => false,
            ]);

            return ['ok' => false, 'message' => 'GSC token expired. Click Connect GSC again.'];
        }

        $property = $this->resolveGscProperty($site, $accessToken);
        if (! $property) {
            $message = 'No matching Search Console property for '.$site->domain.'. Add the site in Google Search Console, then sync again.';
            $site->update(['gsc_last_error' => $message]);

            return ['ok' => false, 'message' => $message];
        }

        $end = now()->subDay()->toDateString();
        $start = now()->subDays(28)->toDateString();

        $response = Http::withToken($accessToken)
            ->timeout(45)
            ->post('https://www.googleapis.com/webmasters/v3/sites/'.rawurlencode($property).'/searchAnalytics/query', [
                'startDate' => $start,
                'endDate' => $end,
                'dimensions' => ['query'],
                'rowLimit' => 50,
                'dataState' => 'final',
            ]);

        if (! $response->successful()) {
            $message = 'GSC API error: '.Str::limit($response->json('error.message') ?? $response->body(), 180);
            $site->update(['gsc_last_error' => $message]);

            return ['ok' => false, 'message' => $message];
        }

        $rows = collect($response->json('rows') ?? [])
            ->map(function (array $row) {
                $clicks = (float) ($row['clicks'] ?? 0);
                $impressions = (float) ($row['impressions'] ?? 0);

                return [
                    'query' => (string) ($row['keys'][0] ?? ''),
                    'clicks' => (int) round($clicks),
                    'impressions' => (int) round($impressions),
                    'ctr' => round(((float) ($row['ctr'] ?? ($impressions > 0 ? $clicks / $impressions : 0))) * 100, 2),
                    'position' => round((float) ($row['position'] ?? 0), 1),
                ];
            })
            ->filter(fn (array $row) => $row['query'] !== '')
            ->values()
            ->all();

        $summary = [
            'clicks' => (int) collect($rows)->sum('clicks'),
            'impressions' => (int) collect($rows)->sum('impressions'),
            'avg_position' => $rows
                ? round(collect($rows)->avg('position'), 1)
                : null,
            'avg_ctr' => $rows
                ? round(collect($rows)->avg('ctr'), 2)
                : null,
            'start' => $start,
            'end' => $end,
            'property' => $property,
        ];

        $site->update([
            'gsc_property' => $property,
            'gsc_queries' => $rows,
            'gsc_summary' => $summary,
            'gsc_synced_at' => now(),
            'gsc_last_error' => null,
        ]);

        $count = count($rows);

        return [
            'ok' => true,
            'message' => $count > 0
                ? "Synced {$count} GSC queries ({$start} → {$end})."
                : "GSC connected but no query data yet for {$start} → {$end}.",
            'rows' => $count,
        ];
    }

    public function runPageSpeed(SeoSite $site, bool $force = false): array
    {
        $apiKey = $this->integrations->pagespeedApiKey($site->workspace);
        if (blank($apiKey)) {
            return [
                'ok' => false,
                'message' => 'PageSpeed Insights is not configured. Add an API key under Settings → Providers → SEO / Google.',
                'needs_setup' => true,
            ];
        }

        if (! $force) {
            $wait = $this->pagespeedRetryAfterSeconds($site);
            if ($wait > 0) {
                return [
                    'ok' => false,
                    'cooldown' => true,
                    'retry_after_seconds' => $wait,
                    'message' => 'Speed check limit reached (2 per 30 minutes). Next check in '.$this->humanMinutes($wait).'.',
                ];
            }
        }

        $quota = app(GooglePagespeedQuota::class);
        $gate = $quota->allow($apiKey);
        if (! $gate['ok']) {
            return [
                'ok' => false,
                'quota' => true,
                'message' => $gate['message'],
                'quota_snapshot' => $gate['snapshot'],
            ];
        }

        $url = 'https://'.$site->domain;
        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }

        try {
            $response = Http::timeout(90)
                ->connectTimeout(20)
                ->get('https://www.googleapis.com/pagespeedonline/v5/runPagespeed', [
                    'url' => $url,
                    'key' => $apiKey,
                    'category' => 'performance',
                    'strategy' => 'mobile',
                ]);

            // Count only after we actually hit Google (success or API error both consume quota).
            $quota->record($apiKey);
            $this->recordPagespeedRun($site);

            if (! $response->successful()) {
                $site->update([
                    'pagespeed_error' => Str::limit($response->body(), 240),
                    'pagespeed_checked_at' => now(),
                ]);

                return ['ok' => false, 'message' => 'PageSpeed API error'];
            }

            $lighthouse = $response->json('lighthouseResult');
            $audits = $lighthouse['audits'] ?? [];
            $score = (int) round(($lighthouse['categories']['performance']['score'] ?? 0) * 100);
            $issues = $this->extractPagespeedIssues($lighthouse);

            $site->update([
                'pagespeed_score' => $score,
                'cwv_lcp' => isset($audits['largest-contentful-paint']['numericValue'])
                    ? round($audits['largest-contentful-paint']['numericValue'] / 1000, 2)
                    : null,
                'cwv_cls' => isset($audits['cumulative-layout-shift']['numericValue'])
                    ? round($audits['cumulative-layout-shift']['numericValue'], 3)
                    : null,
                'cwv_inp' => isset($audits['interaction-to-next-paint']['numericValue'])
                    ? round($audits['interaction-to-next-paint']['numericValue'], 2)
                    : null,
                'pagespeed_checked_at' => now(),
                'pagespeed_error' => null,
                'pagespeed_issues' => $issues,
            ]);

            $issueCount = count($issues);

            return [
                'ok' => true,
                'message' => $issueCount > 0
                    ? "PageSpeed score {$score} — {$issueCount} fix".($issueCount === 1 ? '' : 'es').' suggested'
                    : 'PageSpeed score '.$score.' — no major speed fixes suggested',
                'score' => $score,
                'issues' => $issues,
            ];
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $site->update([
                'pagespeed_error' => 'Timed out waiting for Google PageSpeed',
                'pagespeed_checked_at' => now(),
            ]);

            return [
                'ok' => false,
                'message' => 'Speed check timed out — Google took too long. Wait a minute and try once more.',
            ];
        } catch (\Throwable $e) {
            $site->update([
                'pagespeed_error' => Str::limit($e->getMessage(), 240),
                'pagespeed_checked_at' => now(),
            ]);

            return ['ok' => false, 'message' => 'PageSpeed request failed'];
        }
    }

    public function gscSyncRetryAfterSeconds(SeoSite $site): int
    {
        return $this->retryAfterSeconds(
            $site->gsc_synced_at,
            (int) config('seo.google.gsc_sync_cooldown_minutes', 60),
        );
    }

    public function pagespeedRetryAfterSeconds(SeoSite $site): int
    {
        $windowMinutes = (int) config('seo.google.pagespeed_cooldown_minutes', 30);
        $maxRuns = (int) config('seo.google.pagespeed_max_runs_per_window', 2);

        if ($windowMinutes <= 0 || $maxRuns <= 0) {
            return 0;
        }

        $runs = $this->pagespeedRunTimestamps($site, $windowMinutes);
        if (count($runs) < $maxRuns) {
            return 0;
        }

        $latest = max($runs);
        $availableAt = $latest + ($windowMinutes * 60);
        $wait = $availableAt - now()->timestamp;

        return $wait > 0 ? $wait : 0;
    }

    public function pagespeedRunsRemaining(SeoSite $site): int
    {
        $windowMinutes = (int) config('seo.google.pagespeed_cooldown_minutes', 30);
        $maxRuns = (int) config('seo.google.pagespeed_max_runs_per_window', 2);
        $used = count($this->pagespeedRunTimestamps($site, $windowMinutes));

        return max(0, $maxRuns - $used);
    }

    private function recordPagespeedRun(SeoSite $site): void
    {
        $windowMinutes = (int) config('seo.google.pagespeed_cooldown_minutes', 30);
        $runs = $this->pagespeedRunTimestamps($site, $windowMinutes);
        $runs[] = now()->timestamp;

        Cache::put(
            $this->pagespeedRunsCacheKey($site),
            array_values($runs),
            now()->addMinutes(max($windowMinutes * 2, 60)),
        );
    }

    /**
     * @return list<int>
     */
    private function pagespeedRunTimestamps(SeoSite $site, int $windowMinutes): array
    {
        $cutoff = now()->subMinutes($windowMinutes)->timestamp;
        $runs = Cache::get($this->pagespeedRunsCacheKey($site), []);

        if (! is_array($runs)) {
            return [];
        }

        return array_values(array_filter(
            array_map('intval', $runs),
            fn (int $ts) => $ts >= $cutoff,
        ));
    }

    private function pagespeedRunsCacheKey(SeoSite $site): string
    {
        return 'pagespeed_runs:site:'.$site->id;
    }

    /**
     * Pull Lighthouse opportunities + weak diagnostics people can act on.
     *
     * @param  array<string, mixed>  $lighthouse
     * @return list<array{id: string, title: string, detail: string, group: string, savings_ms: int|null, display_value: string|null}>
     */
    private function extractPagespeedIssues(array $lighthouse): array
    {
        $audits = $lighthouse['audits'] ?? [];
        $refs = collect($lighthouse['categories']['performance']['auditRefs'] ?? []);

        $wanted = $refs
            ->filter(fn ($ref) => in_array($ref['group'] ?? null, ['opportunities', 'diagnostics'], true))
            ->keyBy('id');

        $items = [];

        foreach ($wanted as $id => $ref) {
            $audit = $audits[$id] ?? null;
            if (! is_array($audit)) {
                continue;
            }

            $score = $audit['score'] ?? null;
            // Perfect audits (score === 1) are not actionable.
            if ($score === 1 || $score === 1.0) {
                continue;
            }

            $title = trim((string) ($audit['title'] ?? ''));
            if ($title === '') {
                continue;
            }

            $description = trim(strip_tags((string) ($audit['description'] ?? '')));
            // Drop long "Learn more" tails; keep first sentence-ish chunk.
            $description = preg_replace('/\s*Learn more.*/i', '', $description) ?? $description;
            $description = Str::limit($description, 220);

            $savingsMs = isset($audit['details']['overallSavingsMs'])
                ? (int) round((float) $audit['details']['overallSavingsMs'])
                : null;

            $items[] = [
                'id' => (string) $id,
                'title' => $title,
                'detail' => $description,
                'group' => (string) ($ref['group'] ?? 'diagnostics'),
                'savings_ms' => $savingsMs,
                'display_value' => isset($audit['displayValue']) ? (string) $audit['displayValue'] : null,
            ];
        }

        usort($items, function (array $a, array $b): int {
            $groupA = $a['group'] === 'opportunities' ? 0 : 1;
            $groupB = $b['group'] === 'opportunities' ? 0 : 1;
            if ($groupA !== $groupB) {
                return $groupA <=> $groupB;
            }

            return ($b['savings_ms'] ?? -1) <=> ($a['savings_ms'] ?? -1);
        });

        return array_slice($items, 0, 12);
    }

    private function retryAfterSeconds(mixed $lastAt, int $cooldownMinutes): int
    {
        if ($cooldownMinutes <= 0 || ! $lastAt) {
            return 0;
        }

        $availableAt = $lastAt->copy()->addMinutes($cooldownMinutes);
        if ($availableAt->lte(now())) {
            return 0;
        }

        return max(1, now()->diffInSeconds($availableAt));
    }

    private function humanMinutes(int $seconds): string
    {
        $minutes = (int) max(1, ceil($seconds / 60));

        return $minutes === 1 ? '1 minute' : "{$minutes} minutes";
    }

    /**
     * @return array{access_token: string, refresh_token?: string, expires_at?: int}|null
     */
    private function exchangeGoogleToken(SeoSite $site, string $code): ?array
    {
        $config = $this->integrations->googleGscConfig($site->workspace);
        if (! $config) {
            return null;
        }

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'code' => $code,
            'client_id' => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'redirect_uri' => $this->gscRedirectUri(),
            'grant_type' => 'authorization_code',
        ]);

        if (! $response->successful() || blank($response->json('access_token'))) {
            return null;
        }

        $payload = [
            'access_token' => (string) $response->json('access_token'),
            'expires_at' => now()->addSeconds((int) ($response->json('expires_in') ?? 3600) - 60)->timestamp,
        ];

        if (filled($response->json('refresh_token'))) {
            $payload['refresh_token'] = (string) $response->json('refresh_token');
        }

        return $payload;
    }

    private function accessTokenFor(SeoSite $site): ?string
    {
        $tokens = $this->parseTokenPayload($site->gsc_token);
        if (! $tokens || blank($tokens['access_token'] ?? null)) {
            return null;
        }

        $expiresAt = (int) ($tokens['expires_at'] ?? 0);
        if ($expiresAt > 0 && $expiresAt > now()->timestamp) {
            return $tokens['access_token'];
        }

        if (blank($tokens['refresh_token'] ?? null)) {
            // Legacy bare access_token string — try it once; may still be valid.
            if ($expiresAt === 0) {
                return $tokens['access_token'];
            }

            return null;
        }

        $config = $this->integrations->googleGscConfig($site->workspace);
        if (! $config) {
            return null;
        }

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'refresh_token' => $tokens['refresh_token'],
            'grant_type' => 'refresh_token',
        ]);

        if (! $response->successful() || blank($response->json('access_token'))) {
            return null;
        }

        $tokens['access_token'] = (string) $response->json('access_token');
        $tokens['expires_at'] = now()->addSeconds((int) ($response->json('expires_in') ?? 3600) - 60)->timestamp;
        $site->update(['gsc_token' => json_encode($tokens)]);

        return $tokens['access_token'];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseTokenPayload(?string $raw): ?array
    {
        if (blank($raw)) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded) && isset($decoded['access_token'])) {
            return $decoded;
        }

        // Older connects stored a bare access_token string.
        return ['access_token' => $raw, 'expires_at' => 0];
    }

    private function resolveGscProperty(SeoSite $site, string $accessToken): ?string
    {
        $domain = Str::lower(preg_replace('/^www\./', '', $site->domain) ?? $site->domain);

        $candidates = array_values(array_unique(array_filter([
            $site->gsc_property,
            'sc-domain:'.$domain,
            'https://'.$domain.'/',
            'http://'.$domain.'/',
            'https://www.'.$domain.'/',
            'http://www.'.$domain.'/',
        ])));

        $listed = Http::withToken($accessToken)
            ->timeout(30)
            ->get('https://www.googleapis.com/webmasters/v3/sites');

        if ($listed->successful()) {
            $entries = collect($listed->json('siteEntry') ?? [])
                ->pluck('siteUrl')
                ->filter()
                ->values();

            foreach ($candidates as $candidate) {
                if ($entries->contains($candidate)) {
                    return $candidate;
                }
            }

            $match = $entries->first(function (string $url) use ($domain) {
                $normalized = Str::lower($url);

                return str_contains($normalized, $domain);
            });

            if ($match) {
                return $match;
            }
        }

        // Fall back to first candidate and let searchAnalytics report the real error.
        return $candidates[0] ?? null;
    }

    /**
     * Must match the browser host AND an Authorized redirect URI in Google Cloud Console.
     */
    private function gscRedirectUri(): string
    {
        if (app()->runningInConsole()) {
            return route('seo.gsc.callback');
        }

        return url('/seo/gsc/callback');
    }
}
