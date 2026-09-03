<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesWorkspace;
use App\Jobs\CrawlAndAuditSeoSiteJob;
use App\Models\SeoLocalTarget;
use App\Models\SeoSite;
use App\Services\Seo\SeoBacklinkService;
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

        $site->update([
            'crawl_mode' => $data['crawl_mode'],
            'crawl_status' => 'crawling',
            'last_crawl_error' => null,
        ]);

        if ($data['crawl_mode'] === 'js') {
            set_time_limit(0);

            if (config('queue.default') === 'sync') {
                CrawlAndAuditSeoSiteJob::dispatchSync($site->id);

                return back()->with('success', 'JS crawl finished.');
            }

            CrawlAndAuditSeoSiteJob::dispatch($site->id);

            return back()->with(
                'success',
                'JS crawl started in the background. Refresh in a minute — React pages take longer to render.'
            );
        }

        CrawlAndAuditSeoSiteJob::dispatchSync($site->id);

        return back()->with('success', 'Crawl mode set to '.$data['crawl_mode'].' and re-ran');
    }
}
