<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesWorkspace;
use App\Models\AiGeneration;
use App\Models\AiUsageLog;
use App\Models\FestivalEvent;
use App\Services\Ai\AiContentService;
use App\Services\Ai\AiProviderRouter;
use App\Services\Billing\CreditWalletService;
use App\Services\Billing\PlanAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AiStudioController extends Controller
{
    use ResolvesWorkspace;

    public function index(
        Request $request,
        AiContentService $ai,
        PlanAccess $plans,
        AiProviderRouter $router,
        CreditWalletService $wallet
    ): Response {
        $workspace = $this->workspace($request);
        $settings = $ai->settings($workspace);
        $credits = $wallet->snapshot($workspace);

        return Inertia::render('Ai/Index', [
            'workspace' => ['id' => $workspace->id, 'name' => $workspace->name],
            'settings' => $settings,
            'budget' => [
                'monthly' => (float) $settings->monthly_budget_usd,
                'spent' => (float) $settings->spent_usd,
                'remaining' => $settings->remainingBudget(),
            ],
            'credits' => $credits,
            'festivals' => FestivalEvent::query()
                ->whereBetween('occurs_on', [now()->subDay()->toDateString(), now()->addDays(45)->toDateString()])
                ->orderBy('occurs_on')
                ->limit(12)
                ->get(),
            'generations' => AiGeneration::query()
                ->where('workspace_id', $workspace->id)
                ->latest()
                ->limit(20)
                ->get(),
            'usage' => AiUsageLog::query()
                ->where('workspace_id', $workspace->id)
                ->latest()
                ->limit(20)
                ->get(),
            'ai_providers' => $router->status(),
            'active_ai_provider' => $router->activeName(),
            'plan' => $plans->summary($workspace),
        ]);
    }

    public function updateSettings(Request $request, AiContentService $ai): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);

        $data = $request->validate([
            'monthly_budget_usd' => ['required', 'numeric', 'min:0', 'max:1000'],
            'template_first' => ['boolean'],
            'tone' => ['required', 'in:hindi,english,mixed'],
            'industry' => ['nullable', 'string', 'max:80'],
            'location' => ['nullable', 'string', 'max:80'],
            'auto_daily_posts' => ['boolean'],
        ]);

        $settings = $ai->settings($workspace);
        $settings->update([
            'monthly_budget_usd' => $data['monthly_budget_usd'],
            'template_first' => $request->boolean('template_first'),
            'tone' => $data['tone'],
            'industry' => $data['industry'] ?? null,
            'location' => $data['location'] ?? null,
            'auto_daily_posts' => $request->boolean('auto_daily_posts'),
        ]);

        return back()->with('success', 'AI settings saved');
    }

    public function generateToday(Request $request, AiContentService $ai): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);

        $result = $ai->generateTodaysPosts($workspace, $request->user()->id);

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    public function blogOutline(Request $request, AiContentService $ai): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);

        $data = $request->validate([
            'topic' => ['required', 'string', 'max:160'],
        ]);

        $result = $ai->blogOutline($workspace, $data['topic'], $request->user()->id);

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    public function seoMetas(Request $request, AiContentService $ai): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);

        $data = $request->validate([
            'page_title' => ['required', 'string', 'max:160'],
        ]);

        $result = $ai->seoMetaSuggestions($workspace, $data['page_title'], $request->user()->id);

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }
}
