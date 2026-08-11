<?php

namespace App\Services\Seo;

use App\Models\SeoBlogPost;
use App\Models\SeoSite;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SeoBlogDiscoveryService
{
    public function __construct(
        private readonly SeoCrawlerService $crawler,
    ) {}

    /**
     * @return array{count: int, source: string, feed_url: ?string, message: string}
     */
    public function sync(SeoSite $site): array
    {
        $discovered = $this->discoverFromRss($site);
        $source = 'rss';
        $feedUrl = $discovered['feed_url'] ?? null;

        if ($discovered['posts'] === []) {
            $discovered = ['posts' => $this->discoverFromSitemap($site), 'feed_url' => null];
            $source = 'sitemap';
        }

        if ($discovered['posts'] === []) {
            $discovered = ['posts' => $this->discoverFromCrawledPages($site), 'feed_url' => null];
            $source = 'crawl';
        }

        if ($discovered['posts'] === []) {
            $discovered = ['posts' => $this->demoPosts($site), 'feed_url' => null];
            $source = 'demo';
        }

        $keptHashes = [];
        foreach ($discovered['posts'] as $post) {
            $url = $this->normalizeUrl((string) ($post['url'] ?? ''));
            if ($url === '' || ! $this->sameHost($url, $site->domain)) {
                continue;
            }

            $hash = hash('sha256', $url);
            $keptHashes[] = $hash;

            SeoBlogPost::query()->updateOrCreate(
                ['seo_site_id' => $site->id, 'url_hash' => $hash],
                [
                    'url' => $url,
                    'title' => $this->cleanTitle($post['title'] ?? null, $url),
                    'excerpt' => isset($post['excerpt']) ? Str::limit(strip_tags((string) $post['excerpt']), 400) : null,
                    'published_at' => $post['published_at'] ?? null,
                    'source' => $source,
                ]
            );
        }

        if ($keptHashes !== []) {
            SeoBlogPost::query()
                ->where('seo_site_id', $site->id)
                ->whereNotIn('url_hash', $keptHashes)
                ->delete();
        }

        $site->forceFill([
            'blog_feed_url' => $feedUrl,
            'blog_posts_synced_at' => now(),
        ])->save();

        $count = SeoBlogPost::query()->where('seo_site_id', $site->id)->count();

        return [
            'count' => $count,
            'source' => $source,
            'feed_url' => $feedUrl,
            'message' => $source === 'demo'
                ? "Loaded {$count} demo blog(s) for testing. Real RSS/sitemap posts will replace these later."
                : ($count > 0
                    ? "Found {$count} blog post(s) via {$source}."
                    : 'No blog posts found. Add an RSS feed or /blog URLs in your sitemap.'),
        ];
    }

    /**
     * Temporary dummy posts for UI / share testing until the site has a real feed.
     *
     * @return list<array{url: string, title: string, excerpt: string, published_at: \Carbon\Carbon}>
     */
    public function demoPosts(SeoSite $site): array
    {
        $host = 'https://'.$site->domain;

        return [
            [
                'url' => $host.'/blog/how-to-rank-local-services',
                'title' => 'How to Rank Local Services on Google',
                'excerpt' => 'Demo post — replace with your real blog later.',
                'published_at' => now()->subDays(12),
            ],
            [
                'url' => $host.'/blog/react-seo-checklist',
                'title' => 'React SEO Checklist for 2026',
                'excerpt' => 'Demo post — SSR, meta tags, and sitemap tips.',
                'published_at' => now()->subDays(7),
            ],
            [
                'url' => $host.'/blog/backlinks-that-actually-help',
                'title' => 'Backlinks That Actually Help (Not Spam)',
                'excerpt' => 'Demo post — share this URL to test Reddit and other channels.',
                'published_at' => now()->subDays(3),
            ],
            [
                'url' => $host.'/blog/page-speed-wins-for-leads',
                'title' => 'Page Speed Wins That Grow Leads',
                'excerpt' => 'Demo post — Core Web Vitals in plain language.',
                'published_at' => now()->subDay(),
            ],
        ];
    }

    /**
     * @return array{posts: list<array{url: string, title: ?string, excerpt: ?string, published_at: ?\Carbon\Carbon}>, feed_url: ?string}
     */
    private function discoverFromRss(SeoSite $site): array
    {
        $candidates = array_values(array_filter([
            $site->blog_feed_url,
            'https://'.$site->domain.'/feed',
            'https://'.$site->domain.'/rss',
            'https://'.$site->domain.'/feed.xml',
            'https://'.$site->domain.'/rss.xml',
            'https://'.$site->domain.'/atom.xml',
            'https://'.$site->domain.'/blog/feed',
            'https://'.$site->domain.'/blog/rss.xml',
            'https://'.$site->domain.'/index.xml',
            'https://www.'.$site->domain.'/feed',
        ]));

        foreach ($candidates as $feedUrl) {
            try {
                $response = Http::timeout(12)
                    ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; RankwayAISeoBot/1.0)'])
                    ->accept('application/rss+xml, application/atom+xml, application/xml, text/xml, */*')
                    ->get($feedUrl);

                if (! $response->successful()) {
                    continue;
                }

                $posts = $this->parseFeedXml($response->body());
                if ($posts !== []) {
                    return ['posts' => array_slice($posts, 0, 100), 'feed_url' => $feedUrl];
                }
            } catch (\Throwable) {
                // try next feed
            }
        }

        return ['posts' => [], 'feed_url' => null];
    }

    /**
     * @return list<array{url: string, title: ?string, excerpt: ?string, published_at: ?\Carbon\Carbon}>
     */
    private function discoverFromSitemap(SeoSite $site): array
    {
        $map = $this->crawler->sitemapMap($site, true);
        $posts = [];

        foreach ($map['nodes'] ?? [] as $node) {
            $url = (string) ($node['url'] ?? '');
            if (! $this->looksLikeBlogUrl($url)) {
                continue;
            }

            $posts[] = [
                'url' => $url,
                'title' => $node['title'] ?? null,
                'excerpt' => null,
                'published_at' => filled($node['lastmod'] ?? null) ? $this->parseDate((string) $node['lastmod']) : null,
            ];
        }

        return array_slice($posts, 0, 100);
    }

    /**
     * @return list<array{url: string, title: ?string, excerpt: ?string, published_at: null}>
     */
    private function discoverFromCrawledPages(SeoSite $site): array
    {
        return $site->pages()
            ->get(['url', 'title'])
            ->filter(fn ($page) => $this->looksLikeBlogUrl((string) $page->url))
            ->take(100)
            ->map(fn ($page) => [
                'url' => (string) $page->url,
                'title' => $page->title,
                'excerpt' => null,
                'published_at' => null,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{url: string, title: ?string, excerpt: ?string, published_at: ?\Carbon\Carbon}>
     */
    private function parseFeedXml(string $xml): array
    {
        $xml = trim($xml);
        if ($xml === '' || (! str_contains($xml, '<item') && ! str_contains($xml, '<entry'))) {
            return [];
        }

        libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        if ($doc === false) {
            return [];
        }

        $posts = [];

        // RSS 2.0
        if (isset($doc->channel->item)) {
            foreach ($doc->channel->item as $item) {
                $url = trim((string) ($item->link ?? ''));
                if ($url === '') {
                    continue;
                }
                $posts[] = [
                    'url' => $url,
                    'title' => trim((string) ($item->title ?? '')) ?: null,
                    'excerpt' => trim((string) ($item->description ?? $item->summary ?? '')) ?: null,
                    'published_at' => $this->parseDate(trim((string) ($item->pubDate ?? $item->published ?? ''))),
                ];
            }
        }

        // Atom
        if ($posts === [] && isset($doc->entry)) {
            foreach ($doc->entry as $entry) {
                $url = '';
                if (isset($entry->link)) {
                    foreach ($entry->link as $link) {
                        $attrs = $link->attributes();
                        $rel = (string) ($attrs['rel'] ?? 'alternate');
                        $href = (string) ($attrs['href'] ?? '');
                        if ($href !== '' && ($rel === 'alternate' || $rel === '')) {
                            $url = $href;
                            break;
                        }
                    }
                }
                if ($url === '') {
                    continue;
                }
                $posts[] = [
                    'url' => $url,
                    'title' => trim((string) ($entry->title ?? '')) ?: null,
                    'excerpt' => trim((string) ($entry->summary ?? $entry->content ?? '')) ?: null,
                    'published_at' => $this->parseDate(trim((string) ($entry->published ?? $entry->updated ?? ''))),
                ];
            }
        }

        return $posts;
    }

    private function looksLikeBlogUrl(string $url): bool
    {
        $path = strtolower((string) (parse_url($url, PHP_URL_PATH) ?: '/'));
        if ($path === '/' || $path === '') {
            return false;
        }

        $deny = [
            '/privacy', '/terms', '/contact', '/about', '/login', '/signup', '/cart',
            '/checkout', '/account', '/wp-admin', '/tag/', '/category/', '/author/',
            '/page/', '/search', '/cdn-cgi',
        ];
        foreach ($deny as $fragment) {
            if (str_contains($path, $fragment)) {
                return false;
            }
        }

        $allow = ['/blog', '/blogs', '/posts', '/post/', '/articles', '/article/', '/news', '/insights', '/resources', '/journal'];
        foreach ($allow as $fragment) {
            if (str_contains($path, $fragment)) {
                return true;
            }
        }

        // /yyyy/mm/slug style common on blogs
        if (preg_match('#^/\d{4}/\d{2}/[^/]+#', $path)) {
            return true;
        }

        return false;
    }

    private function sameHost(string $url, string $domain): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $domain = strtolower(preg_replace('/^www\./', '', $domain) ?? $domain);

        return $host === $domain || $host === 'www.'.$domain || str_ends_with($host, '.'.$domain);
    }

    private function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host'])) {
            return '';
        }

        $scheme = $parts['scheme'] ?? 'https';
        $host = strtolower($parts['host']);
        $path = $parts['path'] ?? '/';
        $path = $path === '' ? '/' : $path;

        return $scheme.'://'.$host.rtrim($path, '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    private function cleanTitle(?string $title, string $url): string
    {
        $title = trim((string) $title);
        if ($title !== '') {
            return Str::limit($title, 180);
        }

        $path = trim((string) (parse_url($url, PHP_URL_PATH) ?: ''), '/');
        $slug = Str::afterLast($path, '/') ?: $path;

        return Str::limit(Str::headline(str_replace(['-', '_'], ' ', $slug)), 180);
    }

    private function parseDate(string $value): ?\Carbon\Carbon
    {
        if ($value === '') {
            return null;
        }

        try {
            return \Carbon\Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
