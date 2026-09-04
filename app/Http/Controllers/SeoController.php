<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesWorkspace;
use App\Jobs\CrawlAndAuditSeoSiteJob;
use App\Jobs\TrackSeoKeywordRanksJob;
use App\Models\SeoBacklink;
use App\Models\SeoIssue;
use App\Models\SeoKeyword;
use App\Models\SeoLocalTarget;
use App\Models\SeoReport;
use App\Models\SeoSite;
use App\Models\SeoSuggestion;
use App\Models\SeoTask;
use App\Services\Billing\PlanAccess;
use App\Services\Seo\GoogleSeoService;
use App\Services\Seo\SeoCrawlerService;
use App\Services\Seo\SeoRankTracker;
use App\Services\Seo\SeoTaskGenerator;
use App\Support\DomainNormalizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SeoController extends Controller
{
    use ResolvesWorkspace;

    public function index(
        Request $request,
        GoogleSeoService $google,
        PlanAccess $plans,
        SeoCrawlerService $crawler,
        \App\Services\Integrations\WorkspaceIntegrationService $integrations,
    ): Response {
        $workspace = $this->workspace($request);
        $sites = $workspace->seoSites()->latest()->get()->map(fn (SeoSite $s) => $this->sitePayload($s));

        $otherWorkspacesWithSites = $request->user()
            ->workspaces()
            ->where('workspaces.id', '!=', $workspace->id)
            ->withCount('seoSites')
            ->orderBy('name')
            ->get()
            ->filter(fn ($w) => (int) $w->seo_sites_count > 0)
            ->map(fn ($w) => [
                'id' => $w->id,
                'name' => $w->name,
                'sites_count' => (int) $w->seo_sites_count,
            ])
            ->values()
            ->all();

        $selectedId = (int) $request->query('site', 0);
        $siteModel = $selectedId
            ? $workspace->seoSites()->whereKey($selectedId)->first()
            : $workspace->seoSites()->latest()->first();

        $site = $siteModel ? $this->sitePayload($siteModel, detailed: true) : null;

        $issues = $siteModel
            ? $siteModel->issues()->with('page:id,url,audit_meta')->latest()->limit(50)->get()->map(fn (SeoIssue $i) => [
                'id' => $i->id,
                'severity' => $i->severity,
                'code' => $i->code,
                'message' => $i->message,
                'suggestion' => $i->suggestion,
                'status' => $i->status,
                'page_url' => $i->page?->url,
                'asset_urls' => $this->issueAssetUrls($i),
            ])
            : collect();

        $openIssues = $issues->where('status', 'open');
        $tasks = $workspace->seoTasks()
            ->with(['issue.page:id,url,audit_meta'])
            ->orderByRaw("CASE status WHEN 'open' THEN 0 ELSE 1 END")
            ->orderByRaw("CASE priority WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END")
            ->limit(40)
            ->get()
            ->map(fn (SeoTask $t) => [
                'id' => $t->id,
                'title' => $t->title,
                'description' => $t->description,
                'priority' => $t->priority,
                'status' => $t->status,
                'source' => $t->source,
                'ai_suggestion' => $t->ai_suggestion,
                'page_url' => $t->issue?->page?->url,
                'asset_urls' => $t->issue ? $this->issueAssetUrls($t->issue) : [],
            ]);

        $keywords = $workspace->seoKeywords()
            ->with(['ranks' => fn ($q) => $q->latest('checked_at')->limit(7)])
            ->orderByRaw('position is null')
            ->orderBy('position')
            ->get()
            ->map(fn (SeoKeyword $kw) => [
                'id' => $kw->id,
                'keyword' => $kw->keyword,
                'group_name' => $kw->group_name,
                'is_local' => $kw->is_local,
                'location' => $kw->location,
                'search_volume' => $kw->search_volume,
                'keyword_difficulty' => $kw->keyword_difficulty,
                'cpc' => $kw->cpc,
                'local_pack_position' => $kw->local_pack_position,
                'rank_provider' => $kw->rank_provider,
                'metrics_fetched_at' => $kw->metrics_fetched_at?->diffForHumans(),
                'position' => $kw->position,
                'position_change' => $kw->position_change,
                'last_checked_at' => $kw->last_checked_at?->diffForHumans(),
                'history' => $kw->ranks->sortBy('checked_at')->values()->map(fn ($r) => [
                    'position' => $r->position,
                    'checked_at' => $r->checked_at?->toDateString(),
                ]),
            ]);

        $pages = $siteModel
            ? $siteModel->pages()->latest()->limit(30)->get(['id', 'url', 'title', 'status_code', 'h1', 'word_count', 'has_schema', 'depth', 'inlink_count', 'outlink_count', 'is_orphan', 'render_mode'])
            : collect();

        $localTargets = SeoLocalTarget::query()
            ->where('workspace_id', $workspace->id)
            ->with(['snapshots' => fn ($q) => $q->latest('checked_at')->limit(1)])
            ->latest()
            ->limit(20)
            ->get()
            ->map(fn (SeoLocalTarget $t) => [
                'id' => $t->id,
                'keyword' => $t->keyword,
                'location_name' => $t->location_name,
                'business_name' => $t->business_name,
                'our_rank' => $t->snapshots->first()?->our_rank,
                'checked_at' => $t->snapshots->first()?->checked_at?->diffForHumans(),
                'pack' => $t->snapshots->first()?->pack_json ?? [],
            ]);

        return Inertia::render('Seo/Index', [
            'workspace' => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'website' => $workspace->website,
                'suggested_domain' => filled($workspace->website)
                    ? \App\Support\DomainNormalizer::normalize((string) $workspace->website)
                    : null,
                'role' => $workspace->roleFor($request->user())?->value,
            ],
            'sites' => $sites,
            'other_workspaces_with_sites' => $otherWorkspacesWithSites,
            'site' => $site,
            'issues' => $issues->values(),
            'keywords' => $keywords,
            'tasks' => $tasks,
            'pages' => $pages,
            'suggestions' => $siteModel
                ? SeoSuggestion::query()->where('seo_site_id', $siteModel->id)->where('status', 'open')->latest()->limit(10)->get()
                : [],
            'competitors' => $workspace->seoCompetitors()->latest()->limit(10)->get(),
            'reports' => SeoReport::query()
                ->where('workspace_id', $workspace->id)
                ->latest()
                ->limit(5)
                ->get()
                ->map(fn (SeoReport $r) => [
                    'id' => $r->id,
                    'period' => $r->period,
                    'period_start' => $r->period_start?->toDateString(),
                    'period_end' => $r->period_end?->toDateString(),
                    'summary' => $r->summary,
                    'status' => $r->status,
                    'created_at' => $r->created_at?->toIso8601String(),
                    'download_pdf' => route('seo.reports.download', ['report' => $r->id, 'format' => 'pdf']),
                    'download_excel' => route('seo.reports.download', ['report' => $r->id, 'format' => 'excel']),
                ]),
            'stats' => [
                'sites' => $sites->count(),
                'critical' => $openIssues->where('severity', 'critical')->count(),
                'warnings' => $openIssues->where('severity', 'warning')->count(),
                'open_tasks' => $tasks->where('status', 'open')->count(),
                'keywords' => $keywords->count(),
                'pages' => $siteModel?->pages()->count() ?? 0,
            ],
            'providers' => [
                'google_oauth' => $siteModel
                    ? $google->gscConfigured($siteModel)
                    : $integrations->googleGscConfig($workspace) !== null,
                'pagespeed' => $siteModel
                    ? $google->pagespeedConfigured($siteModel)
                    : filled($integrations->pagespeedApiKey($workspace)),
                'dataforseo' => \App\Services\Seo\Providers\DataForSeoClient::configured(),
                'browserless' => \App\Services\Integrations\ProviderStatus::snapshot()['browserless'] ?? false,
                'js_render' => \App\Services\Integrations\ProviderStatus::snapshot()['js_render'] ?? false,
                'js_render_driver' => \App\Services\Integrations\ProviderStatus::snapshot()['js_render_driver'] ?? null,
            ],
            'pagespeed_quota' => (function () use ($integrations, $workspace) {
                $key = $integrations->pagespeedApiKey($workspace);
                if (blank($key)) {
                    return null;
                }

                return app(\App\Services\Seo\GooglePagespeedQuota::class)->snapshot($key);
            })(),
            'plan' => $plans->summary($workspace),
            'backlinks' => $siteModel
                ? [
                    'summary' => [
                        'backlinks' => $siteModel->backlinks,
                        'referring_domains' => $siteModel->referring_domains,
                        'dofollow' => $siteModel->dofollow_backlinks,
                        'synced_at' => $siteModel->backlinks_synced_at?->diffForHumans(),
                    ],
                    'items' => SeoBacklink::query()
                        ->where('seo_site_id', $siteModel->id)
                        ->orderByDesc('domain_rank')
                        ->limit(40)
                        ->get(),
                ]
                : ['summary' => null, 'items' => []],
            'local_targets' => $localTargets,
            'rankway' => $this->rankwayPayload($siteModel),
            'architecture' => $siteModel
                ? $crawler->sitemapMap($siteModel, $request->boolean('refresh_sitemap'))
                : ['source' => 'sitemap', 'sitemap_url' => null, 'error' => null, 'nodes' => [], 'edges' => []],
        ]);
    }

    public function refreshRankway(
        Request $request,
        SeoSite $site,
        \App\Services\Rankway\RankwayDomainAnalyzer $analyzer,
    ): RedirectResponse {
        $workspace = $this->workspace($request);
        abort_unless($site->workspace_id === $workspace->id, 404);
        $this->authorize('update', $workspace);

        try {
            $record = $analyzer->analyze($site->domain, force: true);
            $analyzer->linkSeoSite($site, $record);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('seo.index', ['site' => $site->id, 'tab' => 'rank'])
            ->with('success', 'Rankway Score updated for '.$site->domain);
    }

    public function storeSite(Request $request): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);

        $data = $request->validate([
            'domain' => ['required', 'string', 'max:255'],
            'sitemap_url' => ['nullable', 'url', 'max:255'],
            'crawl_frequency' => ['nullable', 'in:daily,weekly,manual'],
        ]);

        $domain = DomainNormalizer::normalize($data['domain']);
        abort_if($domain === '', 422, 'Enter a valid domain.');

        $existing = SeoSite::query()->where('workspace_id', $workspace->id)->first();
        if ($existing && $existing->domain !== $domain) {
            return back()->with(
                'error',
                'This workspace already has '.$existing->domain.'. One workspace = one website. Create another workspace for a new domain.'
            );
        }

        $site = SeoSite::query()->updateOrCreate(
            ['workspace_id' => $workspace->id, 'domain' => $domain],
            [
                'sitemap_url' => $data['sitemap_url'] ?? null,
                'status' => 'connected',
                'crawl_frequency' => $data['crawl_frequency'] ?? 'daily',
                'crawl_status' => 'crawling',
                'next_crawl_at' => now(),
                'gsc_connected' => false,
            ]
        );

        // Run crawl + audit now (not background) so Add site is never "empty dump".
        CrawlAndAuditSeoSiteJob::dispatchSync($site->id);
        $site->refresh();

        $pages = $site->pages()->count();
        $issues = $site->issues()->where('status', 'open')->count();

        if ($site->crawl_status === 'failed' || $pages === 0) {
            return redirect()
                ->route('seo.index', ['site' => $site->id])
                ->with('error', $domain.' saved, but live crawl failed — '.$site->last_crawl_error);
        }

        return redirect()
            ->route('seo.index', ['site' => $site->id])
            ->with('success', $domain.' crawled: '.$pages.' page(s), '.$issues.' open issue(s)');
    }

    public function crawlNow(Request $request, SeoSite $site): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);
        abort_unless($site->workspace_id === $workspace->id, 404);

        $site->update(['crawl_status' => 'crawling']);
        CrawlAndAuditSeoSiteJob::dispatchSync($site->id);
        $site->refresh();

        $pages = $site->pages()->count();
        $issues = $site->issues()->where('status', 'open')->count();

        if ($site->crawl_status === 'failed' || $pages === 0) {
            return back()->with('error', 'Re-audit failed — '.$site->last_crawl_error);
        }

        return back()->with('success', 'Re-audit done: '.$pages.' page(s), '.$issues.' open issue(s)');
    }

    public function connectGsc(Request $request, SeoSite $site, GoogleSeoService $google): RedirectResponse|\Symfony\Component\HttpFoundation\Response
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);
        abort_unless($site->workspace_id === $workspace->id, 404);

        if (! $google->gscConfigured($site)) {
            return redirect()
                ->route('settings.index', [
                    'tab' => 'providers',
                    'category' => 'seo',
                    'configure' => 'google_gsc',
                ])
                ->with('error', 'Connect Google Search Console credentials first, then come back and click Connect GSC.');
        }

        // Full browser navigation to Google (GET — no CSRF; Inertia XHR cannot follow external OAuth).
        return redirect()->away($google->gscAuthorizeUrl($site));
    }

    public function gscCallback(Request $request, GoogleSeoService $google): RedirectResponse
    {
        $state = json_decode(base64_decode((string) $request->query('state', '')), true);
        $siteId = (int) ($state['site_id'] ?? $state['site'] ?? 0);
        $site = SeoSite::query()->findOrFail($siteId);
        $workspace = $site->workspace;
        abort_unless($workspace->hasMember($request->user()), 403);

        $result = $google->connectGsc($site, (string) $request->query('code', ''));

        return redirect()
            ->route('seo.index', ['site' => $site->id, 'tab' => 'keywords'])
            ->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    public function syncGsc(Request $request, SeoSite $site, GoogleSeoService $google): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);
        abort_unless($site->workspace_id === $workspace->id, 404);

        $result = $google->syncSearchAnalytics($site);

        return redirect()
            ->route('seo.index', ['site' => $site->id, 'tab' => 'keywords'])
            ->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    public function disconnectGsc(Request $request, SeoSite $site): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);
        abort_unless($site->workspace_id === $workspace->id, 404);

        $site->update([
            'gsc_connected' => false,
            'gsc_connection_mode' => 'none',
            'gsc_property' => null,
            'gsc_token' => null,
            'gsc_queries' => null,
            'gsc_summary' => null,
            'gsc_synced_at' => null,
            'gsc_last_error' => null,
        ]);

        return redirect()
            ->route('seo.index', ['site' => $site->id, 'tab' => 'keywords'])
            ->with('success', 'Google Search Console disconnected for this site.');
    }

    public function runPageSpeed(Request $request, SeoSite $site, GoogleSeoService $google): RedirectResponse
    {
        // PageSpeed Insights often takes 40–90s; default PHP 30s limit causes a fatal.
        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }

        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);
        abort_unless($site->workspace_id === $workspace->id, 404);

        $data = $request->validate([
            'strategy' => ['nullable', 'in:mobile,desktop'],
        ]);
        $strategy = $data['strategy'] ?? 'mobile';

        $result = $google->runPageSpeed($site, false, $strategy);

        if (! empty($result['needs_setup'])) {
            return redirect()
                ->route('settings.index', [
                    'tab' => 'providers',
                    'category' => 'seo',
                    'configure' => 'google_pagespeed',
                ])
                ->with('error', $result['message']);
        }

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    public function updateCrawlSettings(Request $request, SeoSite $site): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);
        abort_unless($site->workspace_id === $workspace->id, 404);

        $data = $request->validate([
            'crawl_frequency' => ['required', 'in:daily,weekly,manual'],
        ]);

        $site->update([
            'crawl_frequency' => $data['crawl_frequency'],
            'next_crawl_at' => match ($data['crawl_frequency']) {
                'weekly' => now()->addWeek(),
                'manual' => null,
                default => now()->addDay(),
            },
        ]);

        return back()->with('success', 'Crawl schedule updated');
    }

    public function storeKeyword(Request $request): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);

        $data = $request->validate([
            'keyword' => ['required', 'string', 'max:120'],
            'group_name' => ['nullable', 'string', 'max:80'],
            'position' => ['nullable', 'integer', 'min:1', 'max:100'],
            'is_local' => ['boolean'],
            'location' => ['nullable', 'string', 'max:80'],
        ]);

        SeoKeyword::query()->updateOrCreate(
            [
                'workspace_id' => $workspace->id,
                'keyword' => $data['keyword'],
            ],
            [
                'group_name' => $data['group_name'] ?? 'General',
                'position' => $data['position'] ?? null,
                'is_local' => $request->boolean('is_local'),
                'location' => $data['location'] ?? null,
            ]
        );

        return back()->with('success', 'Keyword saved');
    }

    public function researchKeywords(
        Request $request,
        \App\Services\Seo\SeoKeywordResearchService $research,
    ): RedirectResponse {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);

        $data = $request->validate([
            'site_id' => ['nullable', 'integer'],
            'seed' => ['nullable', 'string', 'max:120'],
        ]);

        $site = ! empty($data['site_id'])
            ? $workspace->seoSites()->whereKey($data['site_id'])->first()
            : $workspace->seoSites()->latest()->first();

        if (! $site) {
            return back()->with('error', 'Connect a website first.');
        }

        $result = $research->research(
            $workspace,
            $site,
            $data['seed'] ?? null,
            $request->user()?->id,
        );

        if (! $result['ok']) {
            return back()
                ->with('error', $result['message'])
                ->with('keyword_research', $result['ideas']);
        }

        return back()
            ->with('success', $result['message'])
            ->with('keyword_research', $result['ideas']);
    }

    public function trackRanks(Request $request, SeoRankTracker $tracker): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);

        if (! $tracker->liveReady($workspace) && (string) config('seo.providers.ranks', 'auto') !== 'stub') {
            return back()->with('error', $tracker->unavailableMessage($workspace));
        }

        try {
            TrackSeoKeywordRanksJob::dispatchSync($workspace->id);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        $provider = $tracker->resolveProvider($workspace)?->name() ?? 'unknown';
        if ($provider === 'stub') {
            return back()->with(
                'error',
                'Demo stub ranks updated (SEO_RANK_PROVIDER=stub). Never use stub for customer accounts — configure DataForSEO for live SERP.'
            );
        }

        return back()->with('success', 'Live SERP ranks refreshed via '.$provider.'.');
    }

    public function refreshMetrics(Request $request, \App\Services\Seo\SeoKeywordMetricsService $metrics): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);

        try {
            $result = $metrics->refresh($workspace, $request->boolean('force'));
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $result['message']);
    }

    public function storeCompetitor(Request $request, SeoRankTracker $tracker): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);

        $data = $request->validate([
            'domain' => ['required', 'string', 'max:255'],
        ]);

        $tracker->seedCompetitorStub($workspace, DomainNormalizer::normalize($data['domain']));

        return back()->with('success', 'Competitor stub added');
    }

    public function generateTasks(Request $request, SeoTaskGenerator $generator): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);
        $site = $this->selectedSite($request, $workspace);

        if (! $site) {
            return back()->with('error', 'Connect a site first, then build to-dos.');
        }

        $result = $generator->generate($workspace, $site);
        $generator->generateAiSuggestions($workspace, $site);

        if ($result['issue_count'] === 0 && $result['created'] === 0 && $result['reopened'] === 0) {
            return back()->with(
                'error',
                'No open SEO issues found. Run “Scan again” first, then build to-dos.'
            );
        }

        if ($result['created'] === 0 && $result['reopened'] === 0) {
            return back()->with(
                'success',
                'To-dos already up to date ('.$result['open'].' open).'
            );
        }

        $bits = [];
        if ($result['created'] > 0) {
            $bits[] = $result['created'].' new';
        }
        if ($result['reopened'] > 0) {
            $bits[] = $result['reopened'].' reopened';
        }

        return back()->with(
            'success',
            'Built to-dos: '.implode(', ', $bits).' ('.$result['open'].' open).'
        );
    }

    public function weeklyReport(Request $request, SeoTaskGenerator $generator): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);
        $site = $this->selectedSite($request, $workspace);
        abort_unless($site, 404, 'Connect a site first.');

        $data = $request->validate([
            'period' => ['nullable', 'in:today,weekly,monthly,custom'],
            'period_start' => ['nullable', 'date', 'required_if:period,custom'],
            'period_end' => ['nullable', 'date', 'required_if:period,custom', 'after_or_equal:period_start'],
        ]);

        $period = $data['period'] ?? 'weekly';
        $report = $generator->generateReport($workspace, $site, [
            'period' => $period,
            'start' => $data['period_start'] ?? null,
            'end' => $data['period_end'] ?? null,
        ]);

        $label = $report->summary['period_label'] ?? ucfirst($report->period);

        return back()->with(
            'success',
            $label.' SEO report ready — download PDF or Excel below.'
        );
    }

    public function downloadReport(
        Request $request,
        SeoReport $report,
        string $format,
        \App\Services\Seo\SeoReportExporter $exporter,
    ): \Symfony\Component\HttpFoundation\Response {
        $workspace = $this->workspace($request);
        abort_unless($report->workspace_id === $workspace->id, 404);
        $this->authorize('view', $workspace);

        return match (strtolower($format)) {
            'pdf' => $exporter->downloadPdf($report),
            'excel', 'xls', 'xlsx' => $exporter->downloadExcel($report),
            default => abort(404, 'Unsupported format. Use pdf or excel.'),
        };
    }

    public function exportTab(
        Request $request,
        string $type,
        \App\Services\Seo\SeoTabExporter $exporter,
    ): \Symfony\Component\HttpFoundation\StreamedResponse {
        $workspace = $this->workspace($request);
        $this->authorize('view', $workspace);
        $site = $this->selectedSite($request, $workspace);

        return $exporter->download($workspace, $type, $site);
    }

    public function dismissSuggestion(Request $request, SeoSuggestion $suggestion): RedirectResponse
    {
        $workspace = $this->workspace($request);
        abort_unless($suggestion->workspace_id === $workspace->id, 404);
        $this->authorize('update', $workspace);
        $suggestion->update(['status' => 'dismissed']);

        return back()->with('success', 'Suggestion dismissed');
    }

    public function completeTask(Request $request, SeoTask $task): RedirectResponse
    {
        $workspace = $this->workspace($request);
        abort_unless($task->workspace_id === $workspace->id, 404);
        $this->authorize('update', $workspace);

        $task->update(['status' => 'done']);

        return back()->with('success', 'Task completed');
    }

    public function resolveIssue(Request $request, SeoIssue $issue): RedirectResponse
    {
        $workspace = $this->workspace($request);
        abort_unless($issue->workspace_id === $workspace->id, 404);
        $this->authorize('update', $workspace);
        $issue->update(['status' => 'done']);

        return back()->with('success', 'Issue marked done');
    }

    private function rankwayPayload(?SeoSite $site): ?array
    {
        if (! $site) {
            return null;
        }

        $domain = $site->rankwayDomain;
        if (! $domain) {
            $domain = \App\Models\RankwayDomain::query()
                ->where('domain', $site->domain)
                ->with(['latestMetric', 'rankHistory'])
                ->first();
        } else {
            $domain->loadMissing(['latestMetric', 'rankHistory']);
        }

        if (! $domain) {
            return [
                'connected' => false,
                'domain' => $site->domain,
                'result' => null,
            ];
        }

        return [
            'connected' => true,
            'domain' => $site->domain,
            'result' => $domain->toPublicArray(unlocked: true),
        ];
    }

    private function selectedSite(Request $request, $workspace): ?SeoSite
    {
        $id = (int) $request->input('site_id', $request->query('site', 0));

        return $id
            ? $workspace->seoSites()->whereKey($id)->first()
            : $workspace->seoSites()->latest()->first();
    }

    private function sitePayload(SeoSite $site, bool $detailed = false): array
    {
        $google = app(GoogleSeoService::class);

        $payload = [
            'id' => $site->id,
            'domain' => $site->domain,
            'status' => $site->status,
            'sitemap_url' => $site->sitemap_url,
            'gsc_connected' => (bool) $site->gsc_connected,
            'gsc_connection_mode' => $site->gsc_connection_mode ?? 'none',
            'gsc_property' => $site->gsc_property,
            'gsc_synced_at' => $site->gsc_synced_at?->diffForHumans(),
            'gsc_summary' => $site->gsc_summary,
            'gsc_queries' => $site->gsc_queries ?? [],
            'gsc_last_error' => $site->gsc_last_error,
            'gsc_sync_retry_after' => $google->gscSyncRetryAfterSeconds($site),
            'gsc_sync_cooldown_minutes' => (int) config('seo.google.gsc_sync_cooldown_minutes', 60),
            'pagespeed_retry_after' => $google->pagespeedRetryAfterSeconds($site),
            'pagespeed_cooldown_minutes' => (int) config('seo.google.pagespeed_cooldown_minutes', 30),
            'pagespeed_runs_remaining' => $google->pagespeedRunsRemaining($site),
            'pagespeed_max_runs' => (int) config('seo.google.pagespeed_max_runs_per_window', 2),
            'ga4_property' => $site->ga4_property,
            'crawl_frequency' => $site->crawl_frequency ?? 'daily',
            'crawl_mode' => $site->crawl_mode ?? 'static',
            'crawl_status' => $site->crawl_status ?? 'idle',
            'last_crawl_error' => $site->last_crawl_error,
            'last_crawled_at' => $site->last_crawled_at?->toIso8601String(),
            'last_crawled_label' => $site->last_crawled_at?->diffForHumans(),
            'next_crawl_at' => $site->next_crawl_at?->diffForHumans(),
            'pagespeed_score' => $site->pagespeed_score,
            'pagespeed_strategy' => $site->pagespeed_strategy ?? 'mobile',
            'cwv_lcp' => $site->cwv_lcp,
            'cwv_cls' => $site->cwv_cls,
            'cwv_inp' => $site->cwv_inp,
            'pagespeed_checked_at' => $site->pagespeed_checked_at?->diffForHumans(),
            'pagespeed_error' => $site->pagespeed_error,
            'pagespeed_issues' => $site->pagespeed_issues ?? [],
            'pagespeed_report' => $site->pagespeed_report ?? null,
            'backlinks' => $site->getAttribute('backlinks'),
            'referring_domains' => $site->getAttribute('referring_domains'),
        ];

        if ($detailed) {
            $open = $site->issues()->where('status', 'open')->get();
            $critical = $open->where('severity', 'critical')->count();
            $warnings = $open->where('severity', 'warning')->count();
            $info = $open->where('severity', 'info')->count();
            $health = max(0, min(100, 100 - ($critical * 30) - ($warnings * 12) - ($info * 5)));

            $payload['pages_count'] = $site->pages()->count();
            $payload['open_issues'] = $open->count();
            $payload['critical_issues'] = $critical;
            $payload['health_score'] = $health;
        }

        return $payload;
    }

    /**
     * @return list<string>
     */
    private function issueAssetUrls(SeoIssue $issue): array
    {
        if ($issue->code !== 'images_missing_alt') {
            return [];
        }

        $srcs = $issue->page?->audit_meta['images_missing_alt_srcs'] ?? [];
        if (! is_array($srcs)) {
            return [];
        }

        return array_values(array_filter(
            $srcs,
            fn ($url) => is_string($url) && $url !== ''
        ));
    }
}
