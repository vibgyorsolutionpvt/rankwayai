<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesWorkspace;
use App\Jobs\GeneratePosterVariantsJob;
use App\Jobs\PublishSocialPostJob;
use App\Models\MediaAsset;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Services\Billing\PlanAccess;
use App\Services\Social\SocialConnectionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class SocialController extends Controller
{
    use ResolvesWorkspace;

    public function index(Request $request, SocialConnectionService $connections, PlanAccess $plans): Response
    {
        $workspace = $this->workspace($request);

        $month = $request->query('month', now()->format('Y-m'));
        try {
            $cursor = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        } catch (\Throwable) {
            $cursor = now()->startOfMonth();
            $month = $cursor->format('Y-m');
        }

        $view = (string) $request->query('view', 'posts');
        if (! in_array($view, ['posts', 'calendar', 'accounts', 'compose'], true)) {
            $view = 'posts';
        }

        $status = (string) $request->query('status', 'all');
        if (! in_array($status, ['all', 'draft', 'scheduled', 'publishing', 'published', 'failed'], true)) {
            $status = 'all';
        }

        $platform = (string) $request->query('platform', 'all');
        if (! in_array($platform, ['all', 'facebook', 'instagram', 'linkedin', 'x'], true)) {
            $platform = 'all';
        }

        $q = trim((string) $request->query('q', ''));
        if (strlen($q) > 120) {
            $q = substr($q, 0, 120);
        }

        $calendarPosts = $workspace->socialPosts()
            ->whereNotNull('scheduled_at')
            ->whereBetween('scheduled_at', [
                $cursor->copy()->startOfMonth()->startOfDay(),
                $cursor->copy()->endOfMonth()->endOfDay(),
            ])
            ->latest('scheduled_at')
            ->limit(200)
            ->get()
            ->map->toLibraryArray();

        $postsQuery = $workspace->socialPosts()->with('media');

        if ($status !== 'all') {
            $postsQuery->where('status', $status);
        }

        if ($platform !== 'all') {
            $postsQuery->whereJsonContains('platforms', $platform);
        }

        if ($q !== '') {
            $like = '%'.$q.'%';
            $postsQuery->where(function ($query) use ($like) {
                $query->where('title', 'like', $like)
                    ->orWhere('body', 'like', $like);
            });
        }

        $posts = $postsQuery
            ->latest('scheduled_at')
            ->latest()
            ->paginate(12)
            ->withQueryString()
            ->through(fn (SocialPost $post) => $post->toLibraryArray());

        $statusCounts = $workspace->socialPosts()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($n) => (int) $n)
            ->all();

        $filters = [
            'view' => $view,
            'status' => $status,
            'platform' => $platform,
            'q' => $q,
            'counts' => [
                'all' => array_sum($statusCounts),
                'draft' => (int) ($statusCounts['draft'] ?? 0),
                'scheduled' => (int) ($statusCounts['scheduled'] ?? 0),
                'publishing' => (int) ($statusCounts['publishing'] ?? 0),
                'published' => (int) ($statusCounts['published'] ?? 0),
                'failed' => (int) ($statusCounts['failed'] ?? 0),
            ],
        ];

        $mediaOptions = $workspace->mediaAssets()
            ->latest()
            ->limit(40)
            ->get()
            ->map(fn (MediaAsset $asset) => [
                'id' => $asset->id,
                'name' => $asset->original_name,
                'url' => $asset->url() ?: $asset->url('thumb'),
                'thumb_url' => $asset->url('thumb') ?: $asset->url(),
                'mime_type' => $asset->mime_type,
            ]);

        $activeBrand = $workspace->resolveBrandKit();
        $brandKits = $workspace->brandKits()
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get()
            ->map(fn ($kit) => [
                'id' => $kit->id,
                'name' => $kit->name,
                'is_active' => (bool) $kit->is_active,
                'primary_color' => $kit->primary_color,
                'default_cta_label' => $kit->default_cta_label,
            ]);

        return Inertia::render('Social/Index', [
            'workspace' => ['id' => $workspace->id, 'name' => $workspace->name],
            'accounts' => $workspace->socialAccounts()->orderBy('platform')->get()->map(fn (SocialAccount $a) => [
                'id' => $a->id,
                'platform' => $a->platform,
                'account_type' => $a->account_type ?? 'page',
                'connection_mode' => $a->connection_mode ?? 'sandbox',
                'account_name' => $a->account_name,
                'status' => $a->status,
                'health' => $a->health,
                'last_error' => $a->last_error,
                'connected_at' => $a->connected_at?->toDateTimeString(),
                'token_expires_at' => $a->token_expires_at?->toDateTimeString(),
            ]),
            'connectionModes' => $connections->modes($workspace),
            'posts' => $posts,
            'filters' => $filters,
            'mediaOptions' => $mediaOptions,
            'brandKits' => $brandKits,
            'defaultBrandKitId' => $activeBrand?->id,
            'calendar' => [
                'month' => $month,
                'label' => $cursor->format('F Y'),
                'days' => $this->calendarDays($cursor, $calendarPosts),
            ],
            'posterSizes' => array_keys(\App\Services\Social\PosterTemplateService::SIZES),
            'plan' => $plans->summary($workspace),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);

        $data = $this->validatedPostPayload($request);

        $requiresApproval = $request->boolean('requires_approval');
        $status = match ($data['delivery']) {
            'now' => $requiresApproval ? 'draft' : 'publishing',
            'schedule' => 'scheduled',
            default => 'draft',
        };

        $post = SocialPost::query()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $request->user()->id,
            'title' => $data['title'] ?? null,
            'body' => $data['body'],
            'platforms' => $data['platforms'],
            'scheduled_at' => $data['scheduled_at'] ?? null,
            'status' => $status,
            'requires_approval' => $requiresApproval,
            'media_asset_id' => $data['media_asset_id'] ?? null,
            'brand_kit_id' => $data['brand_kit_id'] ?? null,
            'approved_at' => ($data['delivery'] === 'now' && ! $requiresApproval) ? now() : null,
            'approved_by' => ($data['delivery'] === 'now' && ! $requiresApproval) ? $request->user()->id : null,
        ]);

        if ($request->boolean('generate_posters')) {
            GeneratePosterVariantsJob::dispatch($post->id);
        }

        if ($data['delivery'] === 'now') {
            if ($requiresApproval) {
                return back()->with('success', 'Post saved as draft — approval required before publish.');
            }

            PublishSocialPostJob::dispatch($post->id);

            return back()->with('success', 'Publishing started.');
        }

        if ($data['delivery'] === 'schedule') {
            return back()->with(
                'success',
                'Post scheduled for '.$post->scheduled_at?->timezone(config('app.timezone'))->format('d M Y, g:i A').'.'
            );
        }

        return back()->with('success', 'Draft saved');
    }

    public function update(Request $request, SocialPost $post): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);
        abort_unless($post->workspace_id === $workspace->id, 404);

        if (! in_array($post->status, ['draft', 'scheduled', 'failed'], true)) {
            return back()->with('error', 'Only draft, scheduled, or failed posts can be edited.');
        }

        $data = $this->validatedPostPayload($request);
        $requiresApproval = $request->boolean('requires_approval');

        $status = match ($data['delivery']) {
            'now' => $requiresApproval ? 'draft' : 'publishing',
            'schedule' => 'scheduled',
            default => 'draft',
        };

        $post->update([
            'title' => $data['title'] ?? null,
            'body' => $data['body'],
            'platforms' => $data['platforms'],
            'scheduled_at' => $data['scheduled_at'] ?? null,
            'status' => $status,
            'requires_approval' => $requiresApproval,
            'media_asset_id' => $data['media_asset_id'] ?? null,
            'brand_kit_id' => $data['brand_kit_id'] ?? null,
            'failure_reason' => null,
            'approved_at' => ($data['delivery'] === 'now' && ! $requiresApproval) ? now() : ($requiresApproval ? null : $post->approved_at),
            'approved_by' => ($data['delivery'] === 'now' && ! $requiresApproval) ? $request->user()->id : ($requiresApproval ? null : $post->approved_by),
        ]);

        if ($request->boolean('generate_posters')) {
            GeneratePosterVariantsJob::dispatch($post->id);
        }

        if ($data['delivery'] === 'now') {
            if ($requiresApproval) {
                return back()->with('success', 'Post updated — still needs approval before publish.');
            }

            PublishSocialPostJob::dispatch($post->id);

            return back()->with('success', 'Post updated and publishing started.');
        }

        if ($data['delivery'] === 'schedule') {
            return back()->with(
                'success',
                'Schedule updated for '.$post->fresh()->scheduled_at?->timezone(config('app.timezone'))->format('d M Y, g:i A').'.'
            );
        }

        return back()->with('success', 'Draft updated');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedPostPayload(Request $request): array
    {
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:5000'],
            'platforms' => ['required', 'array', 'min:1'],
            'platforms.*' => ['in:facebook,instagram,linkedin,x'],
            'scheduled_at' => ['nullable', 'date'],
            'delivery' => ['required', 'in:now,schedule,draft'],
            'requires_approval' => ['boolean'],
            'media_asset_id' => ['nullable', 'integer'],
            'brand_kit_id' => ['nullable', 'integer'],
            'generate_posters' => ['boolean'],
        ]);

        if (blank($data['scheduled_at'] ?? null)) {
            $data['scheduled_at'] = null;
        }

        if ($data['delivery'] === 'schedule') {
            $request->validate([
                'scheduled_at' => ['required', 'date', 'after:now'],
            ], [
                'scheduled_at.required' => 'Pick a schedule date & time.',
                'scheduled_at.after' => 'Schedule time must be in the future.',
            ]);
        } else {
            $data['scheduled_at'] = null;
        }

        $workspace = $this->workspace($request);
        if (! empty($data['media_asset_id'])) {
            abort_unless(
                MediaAsset::query()
                    ->where('workspace_id', $workspace->id)
                    ->whereKey($data['media_asset_id'])
                    ->exists(),
                422
            );
        }

        if (! empty($data['brand_kit_id'])) {
            abort_unless(
                \App\Models\BrandKit::query()
                    ->where('workspace_id', $workspace->id)
                    ->whereKey($data['brand_kit_id'])
                    ->exists(),
                422
            );
        } else {
            $data['brand_kit_id'] = $workspace->resolveBrandKit()?->id;
        }

        return $data;
    }

    public function approve(Request $request, SocialPost $post): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);
        abort_unless($post->workspace_id === $workspace->id, 404);

        $post->update([
            'approved_at' => now(),
            'approved_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Post approved');
    }

    public function publishNow(Request $request, SocialPost $post): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);
        abort_unless($post->workspace_id === $workspace->id, 404);

        PublishSocialPostJob::dispatch($post->id);

        return back()->with('success', 'Publish job queued');
    }

    public function retry(Request $request, SocialPost $post): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);
        abort_unless($post->workspace_id === $workspace->id, 404);

        $post->update(['status' => 'scheduled', 'failure_reason' => null]);
        PublishSocialPostJob::dispatch($post->id);

        return back()->with('success', 'Retry queued');
    }

    public function generatePosters(Request $request, SocialPost $post): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);
        abort_unless($post->workspace_id === $workspace->id, 404);

        GeneratePosterVariantsJob::dispatchSync($post->id);

        return back()->with('success', 'Poster variants generated');
    }

    public function destroyPost(Request $request, SocialPost $post): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);
        abort_unless($post->workspace_id === $workspace->id, 404);

        $post->delete();

        return back()->with('success', 'Post deleted');
    }

    public function connectStub(Request $request, SocialConnectionService $connections, PlanAccess $plans): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);

        $data = $request->validate([
            'platform' => ['required', 'in:facebook,instagram,linkedin,x'],
            'account_name' => ['required', 'string', 'max:120'],
            'account_type' => ['nullable', 'in:page,profile'],
            'use_oauth' => ['boolean'],
        ]);

        $accountType = $data['account_type'] ?? 'page';

        if ($request->boolean('use_oauth')) {
            if (! $plans->allows($workspace, 'social_oauth')) {
                return back()->with('error', $plans->denyMessage('social_oauth'));
            }

            $url = $connections->oauthAuthorizeUrl($workspace, $data['platform'], $accountType);
            if ($url) {
                return redirect()->away($url);
            }
        }

        $connections->connectSandbox($workspace, $data['platform'], $data['account_name'], $accountType);

        $mode = $connections->modes($workspace)[$data['platform']] ?? 'sandbox';

        return back()->with(
            'success',
            ucfirst($data['platform']).' '.$accountType.' connected ('.$mode.' — OAuth when provider keys are set)'
        );
    }

    public function oauthCallback(Request $request, string $platform, SocialConnectionService $connections, PlanAccess $plans): RedirectResponse
    {
        $data = json_decode(base64_decode((string) $request->query('state', '')), true);
        if (! is_array($data) || empty($data['workspace_id'])) {
            return redirect()->route('social.index')->with('error', 'Invalid OAuth state');
        }

        $workspace = \App\Models\Workspace::query()->findOrFail($data['workspace_id']);
        abort_unless($workspace->hasMember($request->user()), 403);

        if (! $plans->allows($workspace, 'social_oauth')) {
            return redirect()->route('social.index')->with('error', $plans->denyMessage('social_oauth'));
        }

        $code = (string) $request->query('code', '');
        if ($code === '') {
            return redirect()->route('social.index')->with('error', 'OAuth cancelled');
        }

        $connections->handleOAuthCallback(
            $workspace,
            $platform,
            $code,
            $data['account_type'] ?? 'page'
        );

        return redirect()->route('social.index')->with('success', ucfirst($platform).' OAuth connected');
    }

    public function reconnect(Request $request, SocialAccount $account): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);
        abort_unless($account->workspace_id === $workspace->id, 404);

        $account->markConnected();

        return back()->with('success', 'Account reconnected');
    }

    public function disconnect(Request $request, SocialAccount $account): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);
        abort_unless($account->workspace_id === $workspace->id, 404);

        $account->markDisconnected();

        return back()->with('success', 'Account disconnected');
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $posts
     * @return list<array{date:string, inMonth:bool, posts:list<array<string,mixed>>}>
     */
    private function calendarDays(Carbon $cursor, $posts): array
    {
        $start = $cursor->copy()->startOfMonth()->startOfWeek(Carbon::MONDAY);
        $end = $cursor->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY);
        $byDay = $posts->groupBy('calendar_day');

        $days = [];
        for ($day = $start->copy(); $day <= $end; $day->addDay()) {
            $key = $day->toDateString();
            $days[] = [
                'date' => $key,
                'label' => $day->day,
                'inMonth' => $day->month === $cursor->month,
                'posts' => ($byDay->get($key) ?? collect())->values()->all(),
            ];
        }

        return $days;
    }
}
