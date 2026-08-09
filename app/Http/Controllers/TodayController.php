<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesWorkspace;
use App\Models\ChannelCampaign;
use App\Models\CrmLead;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TodayController extends Controller
{
    use ResolvesWorkspace;

    public function __invoke(Request $request): Response
    {
        $workspace = $this->workspace($request);
        $today = now()->toDateString();

        $seoTasks = $workspace->seoTasks()
            ->where('status', 'open')
            ->orderByRaw("CASE priority WHEN 'high' THEN 1 WHEN 'medium' THEN 2 WHEN 'low' THEN 3 ELSE 4 END")
            ->limit(5)
            ->get();

        $posts = $workspace->socialPosts()
            ->where(function ($q) use ($today) {
                $q->whereDate('scheduled_at', $today)
                    ->orWhere(function ($q2) {
                        $q2->where('status', 'draft');
                    });
            })
            ->orderBy('scheduled_at')
            ->limit(8)
            ->get();

        $brand = $workspace->resolveBrandKit();
        $site = $workspace->seoSites()->withCount(['pages', 'issues'])->first();
        $keywords = $workspace->seoKeywords()->orderBy('position')->limit(5)->get();

        return Inertia::render('Today/Index', [
            'workspace' => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'role' => $workspace->roleFor($request->user())?->value,
            ],
            'brand' => $brand,
            'site' => $site,
            'seoTasks' => $seoTasks,
            'posts' => $posts,
            'keywords' => $keywords,
            'counts' => [
                'open_seo_tasks' => $workspace->seoTasks()->where('status', 'open')->count(),
                'scheduled_posts' => $workspace->socialPosts()->where('status', 'scheduled')->count(),
                'media' => $workspace->mediaAssets()->count(),
                'issues' => $site?->issues()->where('status', 'open')->count() ?? 0,
                'open_leads' => CrmLead::query()
                    ->where('workspace_id', $workspace->id)
                    ->whereIn('stage', ['new', 'contacted', 'qualified'])
                    ->count(),
                'channel_campaigns' => ChannelCampaign::query()
                    ->where('workspace_id', $workspace->id)
                    ->whereIn('status', ['draft', 'scheduled', 'sending'])
                    ->count(),
            ],
        ]);
    }
}
