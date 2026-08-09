<?php

namespace App\Services\Seo;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Enforces Google PageSpeed Insights free quotas so the app never burns
 * through project limits (default: 25,000/day, 240/minute).
 */
class GooglePagespeedQuota
{
    public function dailyLimit(): int
    {
        return max(1, (int) config('seo.google.pagespeed_queries_per_day', 25000));
    }

    public function minuteLimit(): int
    {
        return max(1, (int) config('seo.google.pagespeed_queries_per_minute', 240));
    }

    /**
     * Soft ceiling as % of Google quota so we fail in-app before Google 429s.
     */
    public function safetyPercent(): int
    {
        return max(1, min(100, (int) config('seo.google.pagespeed_quota_safety_percent', 90)));
    }

    public function effectiveDailyLimit(): int
    {
        return max(1, (int) floor($this->dailyLimit() * $this->safetyPercent() / 100));
    }

    public function effectiveMinuteLimit(): int
    {
        return max(1, (int) floor($this->minuteLimit() * $this->safetyPercent() / 100));
    }

    /**
     * @return array{ok: bool, message?: string, snapshot: array<string, int|string>}
     */
    public function allow(string $apiKey): array
    {
        $snapshot = $this->snapshot($apiKey);

        if ($snapshot['used_minute'] >= $snapshot['limit_minute']) {
            return [
                'ok' => false,
                'message' => 'PageSpeed minute quota reached (Google limit '.$this->minuteLimit().'/min). Try again in about a minute.',
                'snapshot' => $snapshot,
            ];
        }

        if ($snapshot['used_day'] >= $snapshot['limit_day']) {
            return [
                'ok' => false,
                'message' => 'PageSpeed daily quota reached (Google free limit '.$this->dailyLimit().'/day). Try again tomorrow.',
                'snapshot' => $snapshot,
            ];
        }

        return ['ok' => true, 'snapshot' => $snapshot];
    }

    public function record(string $apiKey): void
    {
        Cache::add($this->dayKey($apiKey), 0, now()->endOfDay());
        Cache::increment($this->dayKey($apiKey));

        Cache::add($this->minuteKey($apiKey), 0, now()->addMinutes(2));
        Cache::increment($this->minuteKey($apiKey));
    }

    /**
     * @return array{
     *   used_day: int,
     *   limit_day: int,
     *   remaining_day: int,
     *   used_minute: int,
     *   limit_minute: int,
     *   remaining_minute: int,
     *   google_day: int,
     *   google_minute: int
     * }
     */
    public function snapshot(string $apiKey): array
    {
        $usedDay = (int) Cache::get($this->dayKey($apiKey), 0);
        $usedMinute = (int) Cache::get($this->minuteKey($apiKey), 0);
        $limitDay = $this->effectiveDailyLimit();
        $limitMinute = $this->effectiveMinuteLimit();

        return [
            'used_day' => $usedDay,
            'limit_day' => $limitDay,
            'remaining_day' => max(0, $limitDay - $usedDay),
            'used_minute' => $usedMinute,
            'limit_minute' => $limitMinute,
            'remaining_minute' => max(0, $limitMinute - $usedMinute),
            'google_day' => $this->dailyLimit(),
            'google_minute' => $this->minuteLimit(),
        ];
    }

    private function dayKey(string $apiKey): string
    {
        return 'pagespeed_quota:day:'.now()->format('Ymd').':'.$this->fingerprint($apiKey);
    }

    private function minuteKey(string $apiKey): string
    {
        return 'pagespeed_quota:min:'.now()->format('YmdHi').':'.$this->fingerprint($apiKey);
    }

    private function fingerprint(string $apiKey): string
    {
        return Str::substr(hash('sha256', $apiKey), 0, 16);
    }
}
