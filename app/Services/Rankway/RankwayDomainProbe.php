<?php

namespace App\Services\Rankway;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Lightweight homepage probe for technical / content / performance signals.
 */
class RankwayDomainProbe
{
    /**
     * @return array{
     *   ok:bool,
     *   url:?string,
     *   title:?string,
     *   status_code:?int,
     *   response_ms:?int,
     *   https:bool,
     *   has_title:bool,
     *   has_meta_description:bool,
     *   has_h1:bool,
     *   has_canonical:bool,
     *   has_robots:bool,
     *   has_viewport:bool,
     *   has_schema:bool,
     *   image_count:int,
     *   images_missing_alt:int,
     *   word_count:int,
     *   technical_score:int,
     *   content_score:int,
     *   performance_score:int,
     *   message:?string
     * }
     */
    public function probe(string $domain): array
    {
        $url = 'https://'.$domain;
        $started = microtime(true);

        try {
            $response = Http::timeout(12)
                ->connectTimeout(5)
                ->withHeaders([
                    'User-Agent' => 'RankwayAI-RankChecker/1.0 (+https://rankwayai.com)',
                    'Accept' => 'text/html,application/xhtml+xml',
                ])
                ->get($url);
        } catch (\Throwable $e) {
            // Fallback http once
            try {
                $url = 'http://'.$domain;
                $response = Http::timeout(12)
                    ->connectTimeout(5)
                    ->withHeaders([
                        'User-Agent' => 'RankwayAI-RankChecker/1.0 (+https://rankwayai.com)',
                        'Accept' => 'text/html,application/xhtml+xml',
                    ])
                    ->get($url);
            } catch (\Throwable $inner) {
                return $this->failed($inner->getMessage());
            }
        }

        $ms = (int) round((microtime(true) - $started) * 1000);
        $html = (string) $response->body();
        $status = $response->status();
        $https = str_starts_with($url, 'https://');

        if ($status >= 400 || $html === '') {
            return array_merge($this->failed('HTTP '.$status.' from '.$url), [
                'url' => $url,
                'status_code' => $status,
                'response_ms' => $ms,
                'https' => $https,
            ]);
        }

        $title = $this->matchOne($html, '/<title[^>]*>(.*?)<\/title>/is');
        $metaDesc = $this->matchOne($html, '/<meta[^>]+name=["\']description["\'][^>]+content=["\'](.*?)["\']/is')
            ?: $this->matchOne($html, '/<meta[^>]+content=["\'](.*?)["\'][^>]+name=["\']description["\']/is');
        $h1 = $this->matchOne($html, '/<h1[^>]*>(.*?)<\/h1>/is');
        $canonical = $this->matchOne($html, '/<link[^>]+rel=["\']canonical["\'][^>]+href=["\'](.*?)["\']/is');
        $viewport = (bool) preg_match('/<meta[^>]+name=["\']viewport["\']/i', $html);
        $schema = str_contains(strtolower($html), 'application/ld+json') || str_contains($html, 'itemtype=');
        $robotsMeta = (bool) preg_match('/<meta[^>]+name=["\']robots["\']/i', $html);

        preg_match_all('/<img\b[^>]*>/i', $html, $imgs);
        $imageCount = count($imgs[0] ?? []);
        $missingAlt = 0;
        foreach ($imgs[0] ?? [] as $img) {
            if (! preg_match('/\balt\s*=/i', $img)) {
                $missingAlt++;
            }
        }

        $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5));
        $wordCount = str_word_count($text);

        $techPoints = 0;
        $techPoints += $https ? 20 : 0;
        $techPoints += $status >= 200 && $status < 400 ? 15 : 0;
        $techPoints += filled($title) ? 15 : 0;
        $techPoints += filled($metaDesc) ? 15 : 0;
        $techPoints += filled($h1) ? 10 : 0;
        $techPoints += filled($canonical) ? 10 : 0;
        $techPoints += $viewport ? 5 : 0;
        $techPoints += $schema ? 5 : 0;
        $techPoints += $robotsMeta ? 5 : 0;
        $technical = min(100, $techPoints);

        $content = 20;
        $content += min(40, (int) floor($wordCount / 80));
        $content += filled($title) && Str::length($title) >= 20 ? 15 : 0;
        $content += filled($metaDesc) && Str::length($metaDesc) >= 50 ? 15 : 0;
        $content += $imageCount > 0 && $missingAlt === 0 ? 10 : ($imageCount > 0 ? 4 : 0);
        $content = min(100, $content);

        // Rough perf from TTFB-ish response time (lab-ish, not CWV).
        $performance = match (true) {
            $ms <= 400 => 92,
            $ms <= 800 => 82,
            $ms <= 1500 => 70,
            $ms <= 3000 => 55,
            $ms <= 6000 => 40,
            default => 25,
        };

        return [
            'ok' => true,
            'url' => $url,
            'title' => $title ? Str::limit(strip_tags($title), 180, '') : null,
            'status_code' => $status,
            'response_ms' => $ms,
            'https' => $https,
            'has_title' => filled($title),
            'has_meta_description' => filled($metaDesc),
            'has_h1' => filled($h1),
            'has_canonical' => filled($canonical),
            'has_robots' => $robotsMeta,
            'has_viewport' => $viewport,
            'has_schema' => $schema,
            'image_count' => $imageCount,
            'images_missing_alt' => $missingAlt,
            'word_count' => $wordCount,
            'technical_score' => $technical,
            'content_score' => $content,
            'performance_score' => $performance,
            'message' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function failed(string $message): array
    {
        return [
            'ok' => false,
            'url' => null,
            'title' => null,
            'status_code' => null,
            'response_ms' => null,
            'https' => false,
            'has_title' => false,
            'has_meta_description' => false,
            'has_h1' => false,
            'has_canonical' => false,
            'has_robots' => false,
            'has_viewport' => false,
            'has_schema' => false,
            'image_count' => 0,
            'images_missing_alt' => 0,
            'word_count' => 0,
            'technical_score' => 15,
            'content_score' => 15,
            'performance_score' => 20,
            'message' => $message,
        ];
    }

    private function matchOne(string $html, string $pattern): ?string
    {
        if (! preg_match($pattern, $html, $m)) {
            return null;
        }

        $value = trim(html_entity_decode(strip_tags($m[1] ?? ''), ENT_QUOTES | ENT_HTML5));

        return $value !== '' ? $value : null;
    }
}
