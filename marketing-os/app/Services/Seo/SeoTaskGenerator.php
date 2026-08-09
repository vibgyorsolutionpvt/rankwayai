<?php

namespace App\Services\Seo;

use App\Models\SeoIssue;
use App\Models\SeoKeyword;
use App\Models\SeoReport;
use App\Models\SeoSite;
use App\Models\SeoSuggestion;
use App\Models\SeoTask;
use App\Models\Workspace;

class SeoTaskGenerator
{
    /**
     * Build / refresh open to-dos from current open audit issues (+ weak keywords).
     *
     * @return array{created:int,reopened:int,open:int,issue_count:int}
     */
    public function generate(Workspace $workspace, ?SeoSite $site = null): array
    {
        $created = 0;
        $reopened = 0;
        $site ??= $workspace->seoSites()->latest()->first();

        if (! $site) {
            return ['created' => 0, 'reopened' => 0, 'open' => 0, 'issue_count' => 0];
        }

        $issues = SeoIssue::query()
            ->where('seo_site_id', $site->id)
            ->where('status', 'open')
            ->orderByRaw("CASE severity WHEN 'critical' THEN 1 WHEN 'warning' THEN 2 ELSE 3 END")
            ->limit(8)
            ->get();

        foreach ($issues as $issue) {
            $title = match ($issue->code) {
                'missing_meta_description' => 'Write meta description for '.$this->pageLabel($issue),
                'missing_title' => 'Add title tag on '.$this->pageLabel($issue),
                'missing_h1' => 'Add H1 on '.$this->pageLabel($issue),
                'images_missing_alt' => 'Fix image ALT text on '.$this->pageLabel($issue),
                default => 'Fix: '.$issue->message,
            };

            $existing = SeoTask::query()
                ->where('workspace_id', $workspace->id)
                ->where('seo_site_id', $site->id)
                ->where(function ($q) use ($issue, $title) {
                    $q->where('seo_issue_id', $issue->id)
                        ->orWhere(function ($q2) use ($title) {
                            $q2->whereNull('seo_issue_id')->where('title', $title);
                        });
                })
                ->latest('id')
                ->first();

            if ($existing) {
                if ($existing->status !== 'open') {
                    $existing->update([
                        'status' => 'open',
                        'title' => $title,
                        'description' => $issue->suggestion,
                        'priority' => $issue->severity === 'critical' ? 'high' : ($issue->severity === 'warning' ? 'medium' : 'low'),
                        'due_on' => now()->toDateString(),
                        'seo_issue_id' => $issue->id,
                        'source' => 'audit',
                        'ai_suggestion' => $this->suggestionForIssue($issue),
                    ]);
                    $reopened++;
                } else {
                    $existing->update([
                        'title' => $title,
                        'description' => $issue->suggestion,
                        'seo_issue_id' => $issue->id,
                        'source' => 'audit',
                    ]);
                }
                continue;
            }

            SeoTask::query()->create([
                'workspace_id' => $workspace->id,
                'seo_site_id' => $site->id,
                'seo_issue_id' => $issue->id,
                'title' => $title,
                'description' => $issue->suggestion,
                'priority' => $issue->severity === 'critical' ? 'high' : ($issue->severity === 'warning' ? 'medium' : 'low'),
                'status' => 'open',
                'due_on' => now()->toDateString(),
                'source' => 'audit',
                'ai_suggestion' => $this->suggestionForIssue($issue),
            ]);
            $created++;
        }

        $weak = SeoKeyword::query()
            ->where('workspace_id', $workspace->id)
            ->where(function ($q) {
                $q->whereNull('position')->orWhere('position', '>', 10);
            })
            ->limit(3)
            ->get();

        foreach ($weak as $keyword) {
            $title = 'Improve content for “'.$keyword->keyword.'” (rank #'.($keyword->position ?? '—').')';
            $task = SeoTask::query()->firstOrCreate(
                [
                    'workspace_id' => $workspace->id,
                    'title' => $title,
                    'status' => 'open',
                ],
                [
                    'seo_site_id' => $site->id,
                    'description' => 'Add internal links and refresh copy targeting this keyword.',
                    'priority' => ($keyword->position ?? 99) > 20 ? 'high' : 'medium',
                    'due_on' => now()->toDateString(),
                    'source' => 'keyword',
                    'ai_suggestion' => [
                        'type' => 'internal_links',
                        'text' => 'Link 2–3 service pages to a blog post targeting “'.$keyword->keyword.'”.',
                    ],
                ]
            );
            if ($task->wasRecentlyCreated) {
                $created++;
            }
        }

        $open = SeoTask::query()
            ->where('workspace_id', $workspace->id)
            ->where('seo_site_id', $site->id)
            ->where('status', 'open')
            ->count();

        return [
            'created' => $created,
            'reopened' => $reopened,
            'open' => $open,
            'issue_count' => $issues->count(),
        ];
    }

    public function generateAiSuggestions(Workspace $workspace, SeoSite $site): int
    {
        $created = 0;
        $defs = [
            [
                'type' => 'meta',
                'title' => 'Homepage meta draft',
                'body' => $workspace->name.' helps local businesses grow with SEO + social. Get a free audit today.',
            ],
            [
                'type' => 'faq',
                'title' => 'FAQ block ideas',
                'body' => "1) How long until SEO results?\n2) Do you manage Google Business Profile?\n3) What’s included in monthly SEO?",
            ],
            [
                'type' => 'blog_topic',
                'title' => 'Blog topic',
                'body' => '“7 local SEO mistakes '.$site->domain.' visitors make (and how to fix them)”',
            ],
            [
                'type' => 'outline',
                'title' => 'Blog outline',
                'body' => "H1: Local SEO checklist\n- Intro\n- GBP optimization\n- On-page basics\n- Citations\n- CTA",
            ],
        ];

        foreach ($defs as $def) {
            $row = SeoSuggestion::query()->firstOrCreate(
                [
                    'workspace_id' => $workspace->id,
                    'seo_site_id' => $site->id,
                    'type' => $def['type'],
                    'title' => $def['title'],
                    'status' => 'open',
                ],
                ['body' => $def['body']]
            );
            if ($row->wasRecentlyCreated) {
                $created++;
            }
        }

        return $created;
    }

    /**
     * Snapshot SEO health for a period (today / weekly / monthly / custom).
     *
     * @param  array{period?:string, start?:string|null, end?:string|null}  $options
     */
    public function generateReport(Workspace $workspace, SeoSite $site, array $options = []): SeoReport
    {
        $period = $options['period'] ?? 'weekly';
        [$start, $end, $period] = $this->resolveReportPeriod(
            $period,
            $options['start'] ?? null,
            $options['end'] ?? null,
        );

        $open = $site->issues()->where('status', 'open')->count();
        $critical = $site->issues()->where('status', 'open')->where('severity', 'critical')->count();
        $keywords = $workspace->seoKeywords()->count();
        $avg = (int) round((float) $workspace->seoKeywords()->whereNotNull('position')->avg('position'));

        $periodLabel = match ($period) {
            'today' => 'Today',
            'weekly' => 'Weekly',
            'monthly' => 'Monthly',
            'custom' => 'Custom',
            default => ucfirst($period),
        };

        return SeoReport::query()->create([
            'workspace_id' => $workspace->id,
            'seo_site_id' => $site->id,
            'period' => $period,
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'summary' => [
                'domain' => $site->domain,
                'period_label' => $periodLabel,
                'health_score' => max(0, 100 - ($critical * 30) - max(0, $open - $critical) * 8),
                'open_issues' => $open,
                'critical_issues' => $critical,
                'keywords_tracked' => $keywords,
                'avg_position' => $avg ?: null,
                'pages_crawled' => $site->pages()->count(),
                'highlights' => [
                    $periodLabel.' SEO snapshot for '.$site->domain,
                    'Crawl + audit completed',
                    'Tasks generated from issues/keywords',
                ],
            ],
            'status' => 'ready',
        ]);
    }

    /** @deprecated Use generateReport() */
    public function weeklyReport(Workspace $workspace, SeoSite $site): SeoReport
    {
        return $this->generateReport($workspace, $site, ['period' => 'weekly']);
    }

    /**
     * @return array{0:\Carbon\CarbonInterface,1:\Carbon\CarbonInterface,2:string}
     */
    private function resolveReportPeriod(string $period, ?string $start, ?string $end): array
    {
        $period = strtolower($period);
        $today = now()->startOfDay();

        return match ($period) {
            'today' => [$today->copy(), $today->copy(), 'today'],
            'monthly' => [$today->copy()->subMonth(), $today->copy(), 'monthly'],
            'custom' => (function () use ($start, $end, $today) {
                $from = $start ? \Carbon\Carbon::parse($start)->startOfDay() : $today->copy()->subWeek();
                $to = $end ? \Carbon\Carbon::parse($end)->startOfDay() : $today->copy();
                if ($from->greaterThan($to)) {
                    [$from, $to] = [$to, $from];
                }

                return [$from, $to, 'custom'];
            })(),
            default => [$today->copy()->subWeek(), $today->copy(), 'weekly'],
        };
    }

    private function pageLabel(SeoIssue $issue): string
    {
        $url = $issue->page?->url;

        return $url ? parse_url($url, PHP_URL_PATH) ?: '/' : 'page';
    }

    /** @return array<string, string> */
    private function suggestionForIssue(SeoIssue $issue): array
    {
        return [
            'type' => 'meta',
            'text' => $issue->suggestion ?: 'Fix this issue on the page, then re-crawl.',
        ];
    }
}
