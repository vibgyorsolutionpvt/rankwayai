<?php

namespace App\Services\Seo;

use App\Models\SeoIssue;
use App\Models\SeoLink;
use App\Models\SeoPage;
use App\Models\SeoSite;
use App\Services\Seo\Contracts\JsRenderProvider;
use App\Services\Seo\Providers\BrowserlessJsRenderProvider;
use App\Services\Seo\Providers\LocalChromeJsRenderProvider;
use App\Services\Seo\Providers\NullJsRenderProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SeoCrawlerService
{
    private int $maxPages = 12;

    public function __construct(
        private readonly SeoUrlClassifier $urls,
    ) {}

    /**
     * Live crawl only. No fake pages — unreachable sites get a clear failure state.
     *
     * @return list<SeoPage>
     */
    public function crawl(SeoSite $site, ?int $maxPages = null): array
    {
        $this->maxPages = $maxPages ?? (($site->crawl_mode ?? 'static') === 'js' ? 8 : 12);

        $site->update([
            'crawl_status' => 'crawling',
            'last_crawl_error' => null,
        ]);

        $homeCandidates = [
            'https://'.$site->domain.'/',
            'http://'.$site->domain.'/',
            'https://www.'.$site->domain.'/',
        ];

        $homeHtml = null;
        $homeUrl = null;
        $homeParsed = null;
        $errors = [];
        $renderMode = ($site->crawl_mode ?? 'static') === 'js' ? 'js' : 'static';

        foreach ($homeCandidates as $candidate) {
            try {
                $parsed = $this->fetchAndParse($candidate, $renderMode);
                $homeHtml = $parsed['_html'] ?? '';
                unset($parsed['_html']);
                $homeUrl = $candidate;
                $homeParsed = $parsed;
                break;
            } catch (\Throwable $e) {
                $errors[] = $candidate.': '.$e->getMessage();
            }
        }

        if (! $homeUrl || ! $homeParsed) {
            return $this->markUnreachable($site, $errors);
        }

        SeoPage::query()->where('seo_site_id', $site->id)->delete();
        SeoLink::query()->where('seo_site_id', $site->id)->delete();
        SeoIssue::query()->where('seo_site_id', $site->id)->where('status', 'open')->delete();

        $pages = [];
        $htmlByUrl = [$homeUrl => (string) $homeHtml];
        $pages[] = $this->upsertPage($site, $homeUrl, $homeParsed, $renderMode, 0);

        $queue = $this->discoverUrls($site->domain, $homeUrl, (string) $homeHtml);
        if ($site->sitemap_url) {
            $queue = array_values(array_unique(array_merge($queue, $this->urlsFromSitemap($site->sitemap_url))));
        }
        $queue = array_values(array_filter(
            $queue,
            fn (string $url) => $this->urls->shouldCrawl($url)
        ));

        $depthMap = [$homeUrl => 0];
        foreach ($queue as $url) {
            if (count($pages) >= $this->maxPages) {
                break;
            }
            if (! $this->urls->shouldCrawl($url)) {
                continue;
            }
            if (collect($pages)->contains(fn (SeoPage $p) => $p->url === $url)) {
                continue;
            }
            try {
                $parsed = $this->fetchAndParse($url, $renderMode);
                $htmlByUrl[$url] = (string) ($parsed['_html'] ?? '');
                unset($parsed['_html']);
                $depth = ($depthMap[$url] ?? 1);
                $pages[] = $this->upsertPage($site, $url, $parsed, $renderMode, $depth);
                foreach ($this->discoverUrls($site->domain, $url, $htmlByUrl[$url] ?? '') as $child) {
                    if (! isset($depthMap[$child])) {
                        $depthMap[$child] = $depth + 1;
                    }
                }
            } catch (\Throwable $e) {
                $errors[] = $url.': '.$e->getMessage();
            }
        }

        $this->buildLinkGraph($site, $pages, $htmlByUrl);

        $site->update([
            'last_crawled_at' => now(),
            'crawl_status' => 'idle',
            'last_crawl_error' => $errors
                ? Str::limit(implode('; ', array_slice($errors, 0, 3)), 1000)
                : null,
            'next_crawl_at' => match ($site->crawl_frequency) {
                'weekly' => now()->addWeek(),
                'manual' => null,
                default => now()->addDay(),
            },
        ]);

        return $pages;
    }

    /**
     * @param  list<SeoPage>  $pages
     * @param  array<string, string>  $htmlByUrl
     */
    public function buildLinkGraph(SeoSite $site, array $pages, array $htmlByUrl): void
    {
        $byUrl = collect($pages)->keyBy('url');

        foreach ($pages as $page) {
            $html = $htmlByUrl[$page->url] ?? '';
            preg_match_all('/<a\b[^>]*href=["\']([^"\'#]+)["\']/i', $html, $matches);
            $out = 0;
            foreach ($matches[1] ?? [] as $href) {
                $absolute = $this->absolutize($page->url, $href);
                if (! $absolute) {
                    continue;
                }
                $out++;
                $clean = strtok($absolute, '?') ?: $absolute;
                $isExternal = ! $this->sameHost($site->domain, $clean);
                $toPage = $isExternal ? null : $byUrl->get($clean);

                SeoLink::query()->create([
                    'workspace_id' => $site->workspace_id,
                    'seo_site_id' => $site->id,
                    'from_page_id' => $page->id,
                    'to_page_id' => $toPage?->id,
                    'to_url' => $clean,
                    'type' => 'a',
                    'is_external' => $isExternal,
                ]);
            }

            $page->update(['outlink_count' => $out]);
        }

        foreach ($pages as $page) {
            $in = SeoLink::query()
                ->where('seo_site_id', $site->id)
                ->where('to_page_id', $page->id)
                ->count();
            $page->update([
                'inlink_count' => $in,
                'is_orphan' => $page->depth > 0 && $in === 0,
            ]);
        }
    }

    /**
     * Crawl-based internal link map (used by tests / advanced views).
     *
     * @return array{nodes:list<array{id:int,url:string,title:?string,depth:int,inlinks:int,outlinks:int,orphan:bool}>,edges:list<array{from:int,to:?int,external:bool}>}
     */
    public function architectureMap(SeoSite $site): array
    {
        $pages = $site->pages()->get(['id', 'url', 'title', 'depth', 'inlink_count', 'outlink_count', 'is_orphan']);
        $nodes = $pages->map(fn (SeoPage $p) => [
            'id' => $p->id,
            'url' => $p->url,
            'title' => $p->title,
            'depth' => $p->depth,
            'inlinks' => $p->inlink_count,
            'outlinks' => $p->outlink_count,
            'orphan' => $p->is_orphan,
        ])->values()->all();

        $edges = SeoLink::query()
            ->where('seo_site_id', $site->id)
            ->whereNotNull('from_page_id')
            ->limit(500)
            ->get(['from_page_id', 'to_page_id', 'is_external'])
            ->map(fn (SeoLink $l) => [
                'from' => $l->from_page_id,
                'to' => $l->to_page_id,
                'external' => $l->is_external,
            ])->values()->all();

        return ['nodes' => $nodes, 'edges' => $edges];
    }

    /**
     * Site map tab: URLs from sitemap.xml (not crawl invent).
     *
     * @return array{
     *   source:string,
     *   sitemap_url:?string,
     *   error:?string,
     *   nodes:list<array{id:string,url:string,title:?string,lastmod:?string,priority:?string,changefreq:?string,crawled:bool,depth:?int,inlinks:?int,outlinks:?int,orphan:bool}>,
     *   edges:list<array<string,mixed>>
     * }
     */
    public function sitemapMap(SeoSite $site, bool $force = false): array
    {
        $cacheKey = 'seo_sitemap_map:'.$site->id;

        if ($force) {
            \Illuminate\Support\Facades\Cache::forget($cacheKey);
        }

        return \Illuminate\Support\Facades\Cache::remember($cacheKey, now()->addMinutes(30), function () use ($site) {
            $sitemapUrl = $this->resolveSitemapUrl($site);
            if (! $sitemapUrl) {
                return [
                    'source' => 'sitemap',
                    'sitemap_url' => null,
                    'error' => 'No sitemap.xml found. Add sitemap URL when connecting the site, or publish https://'.$site->domain.'/sitemap.xml',
                    'nodes' => [],
                    'edges' => [],
                ];
            }

            $entries = $this->collectSitemapEntries($sitemapUrl, 0);
            if ($entries === []) {
                return [
                    'source' => 'sitemap',
                    'sitemap_url' => $sitemapUrl,
                    'error' => 'Sitemap fetched but no <loc> URLs found.',
                    'nodes' => [],
                    'edges' => [],
                ];
            }

            $crawled = $site->pages()
                ->get(['url', 'title', 'depth', 'inlink_count', 'outlink_count', 'is_orphan'])
                ->keyBy(fn (SeoPage $p) => rtrim((string) $p->url, '/'));

            $nodes = [];
            foreach (array_slice($entries, 0, 500) as $i => $entry) {
                $url = $entry['url'];
                $key = rtrim($url, '/');
                $page = $crawled->get($key) ?? $crawled->get($url);

                $nodes[] = [
                    'id' => 'sm-'.$i,
                    'url' => $url,
                    'title' => $page?->title,
                    'lastmod' => $entry['lastmod'] ?? null,
                    'priority' => $entry['priority'] ?? null,
                    'changefreq' => $entry['changefreq'] ?? null,
                    'crawled' => $page !== null,
                    'depth' => $page?->depth,
                    'inlinks' => $page?->inlink_count,
                    'outlinks' => $page?->outlink_count,
                    'orphan' => (bool) ($page?->is_orphan),
                ];
            }

            return [
                'source' => 'sitemap',
                'sitemap_url' => $sitemapUrl,
                'error' => null,
                'nodes' => $nodes,
                'edges' => [],
            ];
        });
    }

    public function resolveSitemapUrl(SeoSite $site): ?string
    {
        if (filled($site->sitemap_url)) {
            return (string) $site->sitemap_url;
        }

        $candidates = [
            'https://'.$site->domain.'/sitemap.xml',
            'https://www.'.$site->domain.'/sitemap.xml',
            'https://'.$site->domain.'/sitemap_index.xml',
            'https://'.$site->domain.'/sitemap-index.xml',
        ];

        foreach ($candidates as $candidate) {
            try {
                $response = Http::timeout(8)
                    ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; RankwayAISeoBot/1.0)'])
                    ->get($candidate);
                if ($response->successful() && str_contains(strtolower($response->body()), '<loc')) {
                    return $candidate;
                }
            } catch (\Throwable) {
                // try next
            }
        }

        // robots.txt Sitemap: line
        try {
            $robots = Http::timeout(8)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; RankwayAISeoBot/1.0)'])
                ->get('https://'.$site->domain.'/robots.txt');
            if ($robots->successful() && preg_match('/(?im)^sitemap:\s*(\S+)/', $robots->body(), $m)) {
                return trim($m[1]);
            }
        } catch (\Throwable) {
            // ignore
        }

        return null;
    }

    /**
     * @return list<array{url:string,lastmod:?string,priority:?string,changefreq:?string}>
     */
    public function collectSitemapEntries(string $sitemapUrl, int $depth = 0): array
    {
        if ($depth > 2) {
            return [];
        }

        try {
            $response = Http::timeout(15)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; RankwayAISeoBot/1.0)'])
                ->get($sitemapUrl);
            if (! $response->successful()) {
                return [];
            }
            $xml = (string) $response->body();
        } catch (\Throwable) {
            return [];
        }

        // Sitemap index → follow child sitemaps
        if (stripos($xml, '<sitemapindex') !== false) {
            preg_match_all('/<sitemap\b[^>]*>.*?<\/sitemap>/is', $xml, $blocks);
            $childUrls = [];
            foreach (array_slice($blocks[0] ?? [], 0, 10) as $block) {
                if (preg_match('/<loc>\s*(.*?)\s*<\/loc>/i', $block, $m)) {
                    $childUrls[] = html_entity_decode(trim($m[1]));
                }
            }
            $entries = [];
            foreach ($childUrls as $child) {
                $entries = array_merge($entries, $this->collectSitemapEntries($child, $depth + 1));
                if (count($entries) >= 500) {
                    break;
                }
            }

            return array_slice($entries, 0, 500);
        }

        preg_match_all('/<url\b[^>]*>.*?<\/url>/is', $xml, $urlBlocks);
        $entries = [];
        foreach ($urlBlocks[0] ?? [] as $block) {
            if (! preg_match('/<loc>\s*(.*?)\s*<\/loc>/i', $block, $m)) {
                continue;
            }
            $url = html_entity_decode(trim($m[1]));
            if ($url === '') {
                continue;
            }
            $lastmod = preg_match('/<lastmod>\s*(.*?)\s*<\/lastmod>/i', $block, $lm)
                ? trim($lm[1])
                : null;
            $priority = preg_match('/<priority>\s*(.*?)\s*<\/priority>/i', $block, $pr)
                ? trim($pr[1])
                : null;
            $changefreq = preg_match('/<changefreq>\s*(.*?)\s*<\/changefreq>/i', $block, $cf)
                ? trim($cf[1])
                : null;
            $entries[] = [
                'url' => $url,
                'lastmod' => $lastmod,
                'priority' => $priority,
                'changefreq' => $changefreq,
            ];
            if (count($entries) >= 500) {
                break;
            }
        }

        // Fallback: bare <loc> list (some generators omit <url> wrappers cleanly)
        if ($entries === []) {
            preg_match_all('/<loc>\s*(.*?)\s*<\/loc>/i', $xml, $locs);
            foreach (array_slice($locs[1] ?? [], 0, 500) as $loc) {
                $url = html_entity_decode(trim($loc));
                if ($url !== '' && ! str_ends_with(strtolower($url), '.xml')) {
                    $entries[] = [
                        'url' => $url,
                        'lastmod' => null,
                        'priority' => null,
                        'changefreq' => null,
                    ];
                }
            }
        }

        return $entries;
    }

    private function jsRenderer(): JsRenderProvider
    {
        // Prefer free local Chrome; Browserless only if explicitly configured.
        if (app(LocalChromeJsRenderProvider::class)->configured()) {
            return app(LocalChromeJsRenderProvider::class);
        }

        if (app(BrowserlessJsRenderProvider::class)->configured()) {
            return app(BrowserlessJsRenderProvider::class);
        }

        return app(NullJsRenderProvider::class);
    }

    /** @param list<string> $errors */
    private function markUnreachable(SeoSite $site, array $errors): array
    {
        SeoPage::query()->where('seo_site_id', $site->id)->delete();
        SeoLink::query()->where('seo_site_id', $site->id)->delete();
        SeoIssue::query()->where('seo_site_id', $site->id)->where('status', 'open')->delete();

        SeoIssue::query()->create([
            'workspace_id' => $site->workspace_id,
            'seo_site_id' => $site->id,
            'seo_page_id' => null,
            'severity' => 'critical',
            'code' => 'site_unreachable',
            'message' => 'Could not reach '.$site->domain,
            'suggestion' => 'Check the domain is live and publicly reachable over HTTPS. rankwayAI only audits pages it can fetch — no fake issues are invented.',
            'status' => 'open',
        ]);

        $site->update([
            'crawl_status' => 'failed',
            'last_crawled_at' => now(),
            'last_crawl_error' => Str::limit(
                'Site unreachable. '.implode('; ', array_slice($errors, 0, 2)),
                1000
            ),
            'next_crawl_at' => match ($site->crawl_frequency) {
                'weekly' => now()->addWeek(),
                'manual' => null,
                default => now()->addDay(),
            },
        ]);

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchAndParse(string $url, string $renderMode = 'static'): array
    {
        $started = microtime(true);
        $html = '';
        $status = 200;

        if ($renderMode === 'js') {
            $rendered = $this->jsRenderer()->fetch($url);
            $html = $rendered['html'];
            $status = $rendered['status'];
            $loadMs = $rendered['load_time_ms'];
        } else {
            try {
                $response = Http::timeout(12)
                    ->withHeaders([
                        'User-Agent' => 'Mozilla/5.0 (compatible; RankwayAISeoBot/1.0)',
                        'Accept' => 'text/html,application/xhtml+xml',
                    ])
                    ->withOptions(['allow_redirects' => ['max' => 5]])
                    ->get($url);
            } catch (ConnectionException $e) {
                throw $e;
            }

            if ($response->status() === 0) {
                throw new \RuntimeException('Empty response from '.$url);
            }

            $html = (string) $response->body();
            $status = $response->status();
            $loadMs = (int) round((microtime(true) - $started) * 1000);
        }

        $parsed = $this->parseHtml($html, $url);
        $parsed['status_code'] = $status;
        $parsed['indexable'] = ! str_contains(strtolower($html), 'noindex');
        $parsed['redirect_to'] = null;
        $parsed['load_time_ms'] = $loadMs ?? (int) round((microtime(true) - $started) * 1000);
        $parsed['_html'] = $html;
        $parsed['audit_meta'] = array_merge($parsed['audit_meta'] ?? [], [
            'source' => 'live',
            'render_mode' => $renderMode,
            'fetched_at' => now()->toIso8601String(),
            'final_url' => $url,
        ]);

        return $parsed;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseHtml(string $html, ?string $baseUrl = null): array
    {
        $title = null;
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
            $title = html_entity_decode(trim(strip_tags($m[1])));
        }

        $meta = null;
        if (preg_match('/<meta[^>]+name=["\']description["\'][^>]+content=["\'](.*?)["\']/is', $html, $m)
            || preg_match('/<meta[^>]+content=["\'](.*?)["\'][^>]+name=["\']description["\']/is', $html, $m)) {
            $meta = html_entity_decode(trim($m[1]));
        }

        $h1 = null;
        if (preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $html, $m)) {
            $h1 = html_entity_decode(trim(strip_tags($m[1])));
        }

        $canonical = null;
        if (preg_match('/<link[^>]+rel=["\']canonical["\'][^>]+href=["\'](.*?)["\']/is', $html, $m)
            || preg_match('/<link[^>]+href=["\'](.*?)["\'][^>]+rel=["\']canonical["\']/is', $html, $m)) {
            $canonical = trim($m[1]);
        }

        $hasSchema = (bool) preg_match('/application\/ld\+json/i', $html);

        $imgTotal = 0;
        $missingAlt = 0;
        $missingAltSrcs = [];
        if (preg_match_all('/<img\b[^>]*>/i', $html, $imgs)) {
            $imgTotal = count($imgs[0]);
            foreach ($imgs[0] as $tag) {
                // alt="" is valid for decorative images — only flag when alt attribute is absent.
                if (preg_match('/\balt\s*=/i', $tag)) {
                    continue;
                }

                $missingAlt++;

                $src = null;
                if (preg_match('/\bsrc\s*=\s*["\']([^"\']+)["\']/i', $tag, $srcMatch)) {
                    $src = trim(html_entity_decode($srcMatch[1]));
                } elseif (preg_match('/\bdata-src\s*=\s*["\']([^"\']+)["\']/i', $tag, $srcMatch)) {
                    $src = trim(html_entity_decode($srcMatch[1]));
                }

                if ($src === null || $src === '' || str_starts_with(strtolower($src), 'data:')) {
                    continue;
                }

                $absolute = $baseUrl ? ($this->absolutize($baseUrl, $src) ?? $src) : $src;
                if ($absolute) {
                    $missingAltSrcs[] = $absolute;
                }
            }
        }
        $missingAltSrcs = array_values(array_slice(array_unique($missingAltSrcs), 0, 20));

        $internal = preg_match_all('/<a\b[^>]*href=["\'][^"\']+["\']/i', $html) ?: 0;
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($html)) ?? '');
        $words = str_word_count($text);

        return [
            'title' => $title,
            'meta_description' => $meta,
            'h1' => $h1,
            'canonical' => $canonical,
            'has_schema' => $hasSchema,
            'images_missing_alt' => $missingAlt,
            'internal_links' => $internal,
            'word_count' => $words,
            'audit_meta' => [
                'img_total' => $imgTotal,
                'images_missing_alt_srcs' => $missingAltSrcs,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function discoverUrls(string $domain, string $baseUrl, string $html): array
    {
        preg_match_all('/<a\b[^>]*href=["\']([^"\'#]+)["\']/i', $html, $matches);
        $found = [];

        foreach ($matches[1] ?? [] as $href) {
            $absolute = $this->absolutize($baseUrl, $href);
            if (! $absolute) {
                continue;
            }
            if (! $this->sameHost($domain, $absolute)) {
                continue;
            }
            $path = parse_url($absolute, PHP_URL_PATH) ?: '/';
            if (preg_match('/\.(pdf|jpg|jpeg|png|gif|webp|svg|zip|css|js)$/i', $path)) {
                continue;
            }
            $clean = strtok($absolute, '?') ?: $absolute;
            if (! $this->urls->shouldCrawl($clean)) {
                continue;
            }
            $found[] = $clean;
        }

        return array_values(array_unique($found));
    }

    /**
     * @return list<string>
     */
    private function urlsFromSitemap(string $sitemapUrl): array
    {
        return array_values(array_unique(array_map(
            fn (array $e) => $e['url'],
            array_slice($this->collectSitemapEntries($sitemapUrl), 0, 40)
        )));
    }

    private function absolutize(string $base, string $href): ?string
    {
        $href = trim($href);
        if ($href === '' || str_starts_with($href, 'mailto:') || str_starts_with($href, 'tel:') || str_starts_with($href, 'javascript:')) {
            return null;
        }
        if (str_starts_with($href, '//')) {
            return 'https:'.$href;
        }
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }
        $parts = parse_url($base);
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '');
        if (str_starts_with($href, '/')) {
            return $origin.$href;
        }

        return rtrim($origin, '/').'/'.ltrim($href, '/');
    }

    private function sameHost(string $domain, string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $domain = strtolower($domain);

        return $host === $domain || $host === 'www.'.$domain || str_ends_with($host, '.'.$domain);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function upsertPage(SeoSite $site, string $url, array $data, string $renderMode = 'static', int $depth = 0): SeoPage
    {
        return SeoPage::query()->updateOrCreate(
            ['seo_site_id' => $site->id, 'url' => $url],
            [
                'workspace_id' => $site->workspace_id,
                'title' => $data['title'] ?? null,
                'meta_description' => $data['meta_description'] ?? null,
                'h1' => $data['h1'] ?? null,
                'canonical' => $data['canonical'] ?? null,
                'indexable' => $data['indexable'] ?? true,
                'has_schema' => $data['has_schema'] ?? false,
                'images_missing_alt' => $data['images_missing_alt'] ?? 0,
                'internal_links' => $data['internal_links'] ?? 0,
                'redirect_to' => $data['redirect_to'] ?? null,
                'word_count' => $data['word_count'] ?? 0,
                'status_code' => $data['status_code'] ?? 200,
                'audit_meta' => $data['audit_meta'] ?? null,
                'render_mode' => $renderMode,
                'depth' => $depth,
                'load_time_ms' => $data['load_time_ms'] ?? null,
            ]
        );
    }
}
