<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesWorkspace;
use App\Jobs\CrawlAndAuditSeoSiteJob;
use App\Models\CmsConnection;
use App\Models\SeoBacklink;
use App\Models\SeoBlogPost;
use App\Models\SeoContentDraft;
use App\Models\SeoLocalTarget;
use App\Models\SeoSite;
use App\Services\Seo\Providers\WordpressCmsPublisher;
use App\Services\Seo\SeoBacklinkService;
use App\Services\Seo\SeoBlogDiscoveryService;
use App\Services\Seo\SeoBlogShareService;
use App\Services\Seo\SeoCmsPublishService;
use App\Services\Seo\SeoLocalPackService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SeoV2Controller extends Controller
{
    use ResolvesWorkspace;

    public function syncBacklinks(Request $request, SeoSite $site, SeoBacklinkService $service): RedirectResponse
    {
        $workspace = $this->workspace($request);
        abort_unless($site->workspace_id === $workspace->id, 404);
        $this->authorize('update', $workspace);

        try {
            $result = $service->sync($workspace, $site);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $result['message']);
    }

    public function storeLocalTarget(Request $request): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);

        $data = $request->validate([
            'keyword' => ['required', 'string', 'max:120'],
            'location_name' => ['required', 'string', 'max:120'],
            'business_name' => ['nullable', 'string', 'max:160'],
            'site_id' => ['nullable', 'integer'],
        ]);

        SeoLocalTarget::query()->updateOrCreate(
            [
                'workspace_id' => $workspace->id,
                'keyword' => $data['keyword'],
                'location_name' => $data['location_name'],
            ],
            [
                'business_name' => $data['business_name'] ?? $workspace->name,
                'seo_site_id' => $data['site_id'] ?? $workspace->seoSites()->latest()->value('id'),
            ]
        );

        return back()->with('success', 'Local target saved');
    }

    public function trackLocal(Request $request, SeoLocalTarget $target, SeoLocalPackService $service): RedirectResponse
    {
        $workspace = $this->workspace($request);
        abort_unless($target->workspace_id === $workspace->id, 404);
        $this->authorize('update', $workspace);

        try {
            $snap = $service->track($workspace, $target);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        $msg = $snap->our_rank
            ? 'Local pack rank #'.$snap->our_rank
            : 'Local pack checked (business not found in top results)';

        return back()->with('success', $msg);
    }

    public function setCrawlMode(Request $request, SeoSite $site): RedirectResponse
    {
        $workspace = $this->workspace($request);
        abort_unless($site->workspace_id === $workspace->id, 404);
        $this->authorize('update', $workspace);

        $data = $request->validate([
            'crawl_mode' => ['required', 'in:static,js'],
        ]);

        if ($data['crawl_mode'] === 'js' && ! app(\App\Services\Billing\PlanAccess::class)->allows($workspace, 'seo_js_crawl')) {
            return back()->with('error', app(\App\Services\Billing\PlanAccess::class)->denyMessage('seo_js_crawl'));
        }

        $site->update(['crawl_mode' => $data['crawl_mode']]);
        CrawlAndAuditSeoSiteJob::dispatchSync($site->id);

        return back()->with('success', 'Crawl mode set to '.$data['crawl_mode'].' and re-ran');
    }

    public function storeCmsConnection(Request $request, WordpressCmsPublisher $publisher): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);

        $data = $request->validate([
            'base_url' => ['required', 'url', 'max:255'],
            'username' => ['required', 'string', 'max:120'],
            'app_password' => ['required', 'string', 'max:255'],
            'label' => ['nullable', 'string', 'max:80'],
        ]);

        $creds = [
            'base_url' => rtrim($data['base_url'], '/'),
            'username' => $data['username'],
            'app_password' => $data['app_password'],
        ];

        $test = $publisher->testConnection($creds);
        if (! $test['ok']) {
            return back()->with('error', $test['message']);
        }

        CmsConnection::query()->updateOrCreate(
            [
                'workspace_id' => $workspace->id,
                'provider' => 'wordpress',
                'base_url' => $creds['base_url'],
            ],
            [
                'label' => $data['label'] ?? 'WordPress',
                'credentials' => $creds,
                'status' => 'active',
                'last_tested_at' => now(),
            ]
        );

        return back()->with('success', $test['message']);
    }

    public function createContentDraft(Request $request, SeoCmsPublishService $cms): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);

        $data = $request->validate([
            'keyword' => ['required', 'string', 'max:160'],
            'seo_keyword_id' => ['nullable', 'integer'],
        ]);

        try {
            $draft = $cms->createDraftFromKeyword(
                $workspace,
                $data['keyword'],
                $data['seo_keyword_id'] ?? null
            );
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Draft “'.$draft->title.'” ready for review');
    }

    public function approveDraft(Request $request, SeoContentDraft $draft, SeoCmsPublishService $cms): RedirectResponse
    {
        $workspace = $this->workspace($request);
        abort_unless($draft->workspace_id === $workspace->id, 404);
        $this->authorize('update', $workspace);
        $cms->approve($draft);

        return back()->with('success', 'Draft approved');
    }

    public function publishDraft(Request $request, SeoContentDraft $draft, SeoCmsPublishService $cms): RedirectResponse
    {
        $workspace = $this->workspace($request);
        abort_unless($draft->workspace_id === $workspace->id, 404);
        $this->authorize('update', $workspace);

        $data = $request->validate([
            'cms_connection_id' => ['required', 'integer'],
        ]);

        $connection = CmsConnection::query()
            ->where('workspace_id', $workspace->id)
            ->whereKey($data['cms_connection_id'])
            ->firstOrFail();

        try {
            $draft = $cms->publish($workspace, $draft, $connection);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with(
            $draft->status === 'published' ? 'success' : 'error',
            $draft->status === 'published'
                ? 'Published: '.$draft->published_url
                : ($draft->last_error ?: 'Publish failed')
        );
    }

    public function syncBlogPosts(Request $request, SeoSite $site, SeoBlogDiscoveryService $blogs): RedirectResponse
    {
        $workspace = $this->workspace($request);
        abort_unless($site->workspace_id === $workspace->id, 404);
        $this->authorize('update', $workspace);

        try {
            $result = $blogs->sync($site);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with(
            $result['count'] > 0 ? 'success' : 'error',
            $result['message']
        );
    }

    public function shareBlogPost(
        Request $request,
        SeoBlogPost $post,
        SeoBlogShareService $shares,
    ): RedirectResponse {
        $workspace = $this->workspace($request);
        $post->loadMissing('site');
        abort_unless($post->site && $post->site->workspace_id === $workspace->id, 404);
        $this->authorize('update', $workspace);

        $data = $request->validate([
            'channel' => ['required', 'string', 'max:40'],
        ]);

        $allowed = collect($shares->channels())->pluck('id')->all();
        if (! in_array($data['channel'], $allowed, true)) {
            return back()->with('error', 'Unknown share channel.');
        }

        try {
            $share = $shares->record($workspace, $post, $data['channel']);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with([
            'success' => 'Share opened — finish posting on '.$data['channel'].' for the backlink.',
            'share_open_url' => $share->share_url,
        ]);
    }
}
