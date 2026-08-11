<?php

namespace App\Services\Seo;

use App\Models\SeoIssue;
use App\Models\SeoPage;
use App\Models\SeoSite;
use Illuminate\Support\Collection;

class SeoAuditEngine
{
    public function __construct(
        private readonly SeoUrlClassifier $urls,
    ) {}

    /**
     * Rebuild open audit issues only from live-crawled pages.
     *
     * @return Collection<int, SeoIssue>
     */
    public function audit(SeoSite $site): Collection
    {
        SeoIssue::query()
            ->where('seo_site_id', $site->id)
            ->where('status', 'open')
            ->where('code', '!=', 'site_unreachable')
            ->delete();

        $pages = $site->pages()
            ->get()
            ->filter(function (SeoPage $page) {
                $source = $page->audit_meta['source'] ?? 'live';

                return $source === 'live' && $this->urls->shouldAuditForRanking((string) $page->url);
            })
            ->values();

        if ($pages->isEmpty()) {
            // Unreachable flow already created site_unreachable; don't invent more.
            return SeoIssue::query()
                ->where('seo_site_id', $site->id)
                ->where('status', 'open')
                ->get();
        }

        // Successful crawl — clear unreachable flag if any leftover
        SeoIssue::query()
            ->where('seo_site_id', $site->id)
            ->where('code', 'site_unreachable')
            ->delete();

        $created = collect();
        $titles = $pages->groupBy(fn (SeoPage $p) => mb_strtolower(trim((string) $p->title)));
        $metas = $pages
            ->filter(fn (SeoPage $p) => filled($p->meta_description))
            ->groupBy(fn (SeoPage $p) => mb_strtolower(trim((string) $p->meta_description)));

        foreach ($pages as $page) {
            $created = $created->merge($this->auditPage($site, $page, $titles, $metas));
        }

        return $created;
    }

    /**
     * @param  Collection<string, Collection<int, SeoPage>>  $titles
     * @param  Collection<string, Collection<int, SeoPage>>  $metas
     * @return list<SeoIssue>
     */
    private function auditPage(SeoSite $site, SeoPage $page, Collection $titles, Collection $metas): array
    {
        $issues = [];
        $path = parse_url((string) $page->url, PHP_URL_PATH) ?: $page->url;

        if ($page->status_code && $page->status_code >= 400) {
            $issues[] = $this->issue(
                $site,
                $page,
                'critical',
                'http_'.$page->status_code,
                $path.' returns HTTP '.$page->status_code,
                'Fix the URL or redirect to a live page'
            );
        }

        if (blank($page->title)) {
            $issues[] = $this->issue($site, $page, 'critical', 'missing_title', 'Missing <title> on '.$path, 'Add a unique 50–60 character title');
        } elseif (($titles->get(mb_strtolower(trim((string) $page->title)))?->count() ?? 0) > 1) {
            $issues[] = $this->issue($site, $page, 'warning', 'duplicate_title', 'Duplicate title on '.$path.': “'.$page->title.'”', 'Make this title unique across the site');
        }

        if (blank($page->meta_description)) {
            $issues[] = $this->issue($site, $page, 'critical', 'missing_meta_description', 'Missing meta description on '.$path, 'Add a 140–160 character description with primary keyword');
        } elseif (($metas->get(mb_strtolower(trim((string) $page->meta_description)))?->count() ?? 0) > 1) {
            $issues[] = $this->issue($site, $page, 'warning', 'duplicate_meta', 'Duplicate meta description on '.$path, 'Write a unique description for this URL');
        }

        if (blank($page->h1)) {
            $issues[] = $this->issue($site, $page, 'warning', 'missing_h1', 'Missing H1 on '.$path, 'Add one clear H1 that matches the page topic');
        }

        if (($page->images_missing_alt ?? 0) > 0) {
            $srcs = $page->audit_meta['images_missing_alt_srcs'] ?? [];
            $srcHint = is_array($srcs) && $srcs !== []
                ? ' — e.g. '.basename((string) parse_url((string) $srcs[0], PHP_URL_PATH) ?: $srcs[0])
                : '';
            $issues[] = $this->issue(
                $site,
                $page,
                'warning',
                'images_missing_alt',
                $page->images_missing_alt.' image(s) missing ALT attribute on '.$path.$srcHint,
                'Add an alt attribute on each image (use alt="" only for decorative images)'
            );
        }

        if (blank($page->canonical)) {
            $issues[] = $this->issue($site, $page, 'info', 'missing_canonical', 'No canonical on '.$path, 'Add a self-referencing canonical link');
        }

        // Login/register (and private app pages) are meant to be noindex — not a ranking bug.
        if (! $page->indexable && ! $this->urls->expectsNoindex((string) $page->url)) {
            $issues[] = $this->issue($site, $page, 'critical', 'noindex', $path.' is noindex', 'Remove noindex if this page should rank');
        }

        if (! $page->has_schema) {
            $issues[] = $this->issue($site, $page, 'info', 'missing_schema', 'No structured data on '.$path, 'Add Organization/LocalBusiness or Article JSON-LD');
        }

        if (($page->word_count ?? 0) > 0 && $page->word_count < 150) {
            $issues[] = $this->issue($site, $page, 'info', 'thin_content', 'Thin content on '.$path.' ('.$page->word_count.' words)', 'Expand useful content where relevant');
        }

        return $issues;
    }

    private function issue(
        SeoSite $site,
        SeoPage $page,
        string $severity,
        string $code,
        string $message,
        string $suggestion
    ): SeoIssue {
        return SeoIssue::query()->create([
            'workspace_id' => $site->workspace_id,
            'seo_site_id' => $site->id,
            'seo_page_id' => $page->id,
            'severity' => $severity,
            'code' => $code,
            'message' => $message,
            'suggestion' => $suggestion,
            'status' => 'open',
        ]);
    }
}
