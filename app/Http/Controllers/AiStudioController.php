<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesWorkspace;
use App\Models\AiGeneration;
use App\Models\FestivalEvent;
use App\Models\SocialPost;
use App\Models\Workspace;
use App\Models\WorkspaceAiSetting;
use App\Services\Ai\AiContentService;
use App\Services\Billing\CreditWalletService;
use App\Services\Billing\PlanAccess;
use App\Services\Festivals\FestivalCalendarService;
use App\Support\SocialPlatforms;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AiStudioController extends Controller
{
    use ResolvesWorkspace;

    private function setupComplete(Workspace $workspace, WorkspaceAiSetting $settings): bool
    {
        if ($workspace->hasBusinessProfile()) {
            return true;
        }

        $industry = trim((string) ($settings->industry ?? ''));
        $location = trim((string) ($settings->location ?? ''));

        if ($industry === '' || $location === '') {
            return false;
        }

        return ! ($industry === 'local business' && $location === 'India');
    }

    /**
     * @return array{id:int,name:string,industry:?string,city:?string,has_business_profile:bool,phone:?string,email:?string,website:?string,has_contact:bool}
     */
    private function workspacePayload(Workspace $workspace): array
    {
        $contact = $workspace->contactDetails();

        return [
            'id' => $workspace->id,
            'name' => $workspace->name,
            'industry' => $workspace->resolvedIndustry(),
            'city' => $workspace->resolvedCity(),
            'has_business_profile' => $workspace->hasBusinessProfile(),
            'phone' => $contact['phone'],
            'email' => $contact['email'],
            'website' => $contact['website'],
            'has_contact' => $workspace->hasContactDetails(),
        ];
    }

    public function index(
        Request $request,
        AiContentService $ai,
        PlanAccess $plans,
        CreditWalletService $wallet,
        FestivalCalendarService $festivals
    ): Response {
        $workspace = $this->workspace($request);
        $settings = $ai->syncSettingsFromWorkspace($workspace);
        $credits = $wallet->snapshot($workspace);
        $nextForPosts = $festivals->nextForPosts();
        $upcoming = $festivals->upcoming();

        return Inertia::render('Ai/Index', [
            'workspace' => $this->workspacePayload($workspace),
            'settings' => $settings,
            'setup_complete' => $this->setupComplete($workspace, $settings),
            'credits' => $credits,
            'draft_count' => SocialPost::query()
                ->where('workspace_id', $workspace->id)
                ->where('status', 'draft')
                ->count(),
            'next_festival' => $nextForPosts
                ? $festivals->toListItem($nextForPosts, $nextForPosts)
                : null,
            'festivals' => $upcoming
                ->map(fn (FestivalEvent $f) => $festivals->toListItem($f, $nextForPosts))
                ->values()
                ->all(),
            'generations' => AiGeneration::query()
                ->where('workspace_id', $workspace->id)
                ->where('type', 'today_pack')
                ->latest()
                ->limit(8)
                ->get()
                ->map(fn (AiGeneration $g) => [
                    'id' => $g->id,
                    'title' => $g->title,
                    'at' => $g->created_at?->timezone(config('app.timezone'))->format('d M, g:i A'),
                    'post_count' => count(data_get($g->payload, 'post_ids', [])),
                ]),
            'seo_drafts' => AiGeneration::query()
                ->where('workspace_id', $workspace->id)
                ->whereIn('type', ['blog', 'seo_meta'])
                ->latest()
                ->limit(10)
                ->get()
                ->map(function (AiGeneration $g) {
                    $payload = $g->payload ?? [];

                    return [
                        'id' => $g->id,
                        'type' => $g->type,
                        'title' => $g->title,
                        'at' => $g->created_at?->timezone(config('app.timezone'))->format('d M, g:i A'),
                        'h1' => data_get($payload, 'h1'),
                        'sections' => array_values((array) data_get($payload, 'sections', [])),
                        'meta_description' => data_get($payload, 'meta_description'),
                        'metas' => array_values((array) data_get($payload, 'metas', [])),
                    ];
                }),
            'plan' => $plans->summary($workspace),
            'publish_platforms' => app(AiContentService::class)->publishPlatforms($workspace),
            'publish_platform_labels' => collect(
                app(AiContentService::class)->publishPlatforms($workspace)
            )
                ->map(fn (string $key) => SocialPlatforms::catalog()[$key]['label'] ?? $key)
                ->values()
                ->all(),
        ]);
    }

    public function updateSettings(Request $request, AiContentService $ai): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);

        $data = $request->validate([
            'tone' => ['required', 'in:hindi,english,mixed'],
            'industry' => ['required', 'string', 'max:80'],
            'location' => ['required', 'string', 'max:80'],
        ]);

        $settings = $ai->settings($workspace);
        $settings->update([
            'tone' => $data['tone'],
            'industry' => trim($data['industry']),
            'location' => trim($data['location']),
        ]);

        return back()->with('success', 'Business details saved. You can create posts now.');
    }

    public function generateToday(Request $request, AiContentService $ai): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);

        $settings = $ai->settings($workspace);
        $needsSetup = ! $this->setupComplete($workspace, $settings);

        $rules = [
            'brief' => ['nullable', 'string', 'max:500', 'required_without:festival_id', 'min:10'],
            'offer' => ['nullable', 'string', 'max:120'],
            'festival_id' => ['nullable', 'integer', 'exists:festival_events,id'],
            'tone' => ['nullable', 'in:hindi,english,mixed'],
            'word_limit' => ['nullable', 'integer', 'min:25', 'max:150'],
            'draft_count' => ['nullable', 'integer', 'min:1', 'max:5'],
        ];

        if ($needsSetup) {
            $rules['industry'] = ['required', 'string', 'max:80', 'not_in:local business'];
            $rules['location'] = ['required', 'string', 'max:80', 'not_in:India'];
            $rules['tone'] = ['required', 'in:hindi,english,mixed'];
        }

        $data = $request->validate($rules, [
            'brief.required' => 'Tell us what topic or offer you want to post about today.',
            'brief.min' => 'Add a bit more detail — at least 10 characters.',
            'industry.required' => 'Save business type in Settings → Workspace.',
            'industry.not_in' => 'Use a real business type — not the generic “local business”.',
            'location.required' => 'Save city in Settings → Workspace.',
            'location.not_in' => 'Use a real city — not just “India”.',
        ]);

        if ($needsSetup) {
            $industry = trim($data['industry']);
            $location = trim($data['location']);

            $workspace->update([
                'industry' => $industry,
                'city' => $location,
            ]);

            $settings->update([
                'tone' => $data['tone'],
                'industry' => $industry,
                'location' => $location,
            ]);
        } elseif ($request->filled('tone') || $request->filled('word_limit')) {
            $preferenceUpdates = [];
            if ($request->filled('tone')) {
                $preferenceUpdates['tone'] = $data['tone'];
            }
            if ($request->filled('word_limit')) {
                $preferenceUpdates['caption_word_limit'] = (int) $data['word_limit'];
            }
            if ($preferenceUpdates !== []) {
                $settings->update($preferenceUpdates);
            }
        }

        $festivalId = $request->input('festival_id');
        $festivalId = is_numeric($festivalId) && (int) $festivalId > 0 ? (int) $festivalId : null;

        $result = $ai->generateTodaysPosts($workspace, $request->user()->id, [
            'brief' => trim((string) ($data['brief'] ?? '')),
            'offer' => trim((string) ($data['offer'] ?? '')),
            'festival_id' => $festivalId,
            'word_limit' => isset($data['word_limit']) ? (int) $data['word_limit'] : null,
            'draft_count' => isset($data['draft_count']) ? (int) $data['draft_count'] : 1,
        ]);

        if (! $result['ok']) {
            return back()->with('error', $result['message']);
        }

        return redirect()
            ->route('social.index', ['status' => 'draft'])
            ->with(
                'success',
                count($result['posts']).' draft posts ready. Edit them in the list below, then publish.'
            );
    }

    public function previewToday(Request $request, AiContentService $ai): JsonResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);

        $settings = $ai->syncSettingsFromWorkspace($workspace);
        if (! $this->setupComplete($workspace, $settings)) {
            return response()->json([
                'ok' => false,
                'message' => 'Save business type and city in Settings → Workspace first.',
            ], 422);
        }

        $data = $request->validate([
            'brief' => ['nullable', 'string', 'max:500'],
            'offer' => ['nullable', 'string', 'max:120'],
            'festival_id' => ['nullable', 'integer', 'exists:festival_events,id'],
            'tone' => ['nullable', 'in:hindi,english,mixed'],
            'word_limit' => ['nullable', 'integer', 'min:25', 'max:150'],
            'draft_count' => ['nullable', 'integer', 'min:1', 'max:5'],
        ]);

        $preferenceUpdates = [];
        if ($request->filled('tone')) {
            $preferenceUpdates['tone'] = $data['tone'];
        }
        if ($request->filled('word_limit')) {
            $preferenceUpdates['caption_word_limit'] = (int) $data['word_limit'];
        }
        if ($preferenceUpdates !== []) {
            $settings->update($preferenceUpdates);
            $settings->refresh();
        }

        $festivalId = $request->input('festival_id');
        $festivalId = is_numeric($festivalId) && (int) $festivalId > 0 ? (int) $festivalId : null;

        $result = $ai->previewTodaysPosts($workspace, [
            'brief' => trim((string) ($data['brief'] ?? '')),
            'offer' => trim((string) ($data['offer'] ?? '')),
            'festival_id' => $festivalId,
            'word_limit' => isset($data['word_limit']) ? (int) $data['word_limit'] : null,
            'draft_count' => isset($data['draft_count']) ? (int) $data['draft_count'] : 1,
        ]);

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    public function blogOutline(Request $request, AiContentService $ai): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);

        $settings = $ai->syncSettingsFromWorkspace($workspace);
        if (! $this->setupComplete($workspace, $settings)) {
            return back()->with('error', 'Save business type and city in Settings → Workspace first.');
        }

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

        $settings = $ai->syncSettingsFromWorkspace($workspace);
        if (! $this->setupComplete($workspace, $settings)) {
            return back()->with('error', 'Save business type and city in Settings → Workspace first.');
        }

        $data = $request->validate([
            'page_title' => ['required', 'string', 'max:160'],
        ]);

        $result = $ai->seoMetaSuggestions($workspace, $data['page_title'], $request->user()->id);

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }
}
