<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesWorkspace;
use App\Jobs\GeneratePosterVariantsJob;
use App\Jobs\PublishSocialPostJob;
use App\Models\MediaAsset;
use App\Models\SocialAccount;
use App\Models\SocialComposePromptHistory;
use App\Models\SocialPost;
use App\Services\Ai\AiContentService;
use App\Services\Billing\PlanAccess;
use App\Services\Integrations\WorkspaceIntegrationService;
use App\Services\Social\SocialConnectionService;
use App\Services\Social\SocialPostAnalyticsService;
use App\Services\Social\SocialPublisherService;
use App\Support\SocialPlatforms;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class SocialController extends Controller
{
    use ResolvesWorkspace;

    public function index(
        Request $request,
        SocialConnectionService $connections,
        PlanAccess $plans,
        SocialPostAnalyticsService $analytics,
        WorkspaceIntegrationService $integrations,
    ): Response {
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

        if (! $request->has('view')) {
            return redirect()->route('social.index', array_merge(
                ['view' => 'posts'],
                array_filter([
                    'month' => $request->query('month'),
                    'status' => $request->query('status'),
                    'platform' => $request->query('platform'),
                    'q' => $request->query('q'),
                    'page' => $request->query('page'),
                ], fn ($value) => $value !== null && $value !== '' && $value !== 'all')
            ));
        }

        $status = (string) $request->query('status', 'all');
        if (! in_array($status, ['all', 'draft', 'scheduled', 'publishing', 'published', 'failed'], true)) {
            $status = 'all';
        }

        $platform = (string) $request->query('platform', 'all');
        if (! in_array($platform, ['all', 'facebook', 'instagram', 'threads', 'linkedin', 'x'], true)) {
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
            'accounts' => $workspace->socialAccounts()
                ->orderByRaw("CASE WHEN status = 'connected' THEN 0 ELSE 1 END")
                ->orderBy('platform')
                ->get()
                ->map(fn (SocialAccount $a) => [
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
            'social_providers' => [
                'meta' => [
                    'configured' => $integrations->workspaceSocialOAuthReady($workspace, 'meta'),
                    'settings_url' => route('settings.index', [
                        'tab' => 'providers',
                        'category' => 'social',
                        'configure' => 'meta',
                    ]),
                ],
            ],
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
            'pendingPagePick' => $this->pendingPagePickForWorkspace($request, $workspace->id),
            'enabledPlatforms' => SocialPlatforms::enabled($workspace->enabled_social_platforms),
            'connectedPlatforms' => $workspace->socialAccounts()
                ->where('status', 'connected')
                ->pluck('platform')
                ->unique()
                ->values()
                ->all(),
            'socialPublish' => [
                'isLocal' => app()->environment('local'),
                'simulate' => (bool) config('social.simulate_publish'),
            ],
            'analytics_permissions' => SocialPostAnalyticsService::requiredScopes(),
            'ai_context' => (function () use ($workspace) {
                $ai = app(AiContentService::class);
                $settings = $ai->syncSettingsFromWorkspace($workspace);
                $contact = $workspace->contactDetails();

                return [
                    'industry' => $settings->industry,
                    'location' => $settings->location,
                    'tone' => $settings->tone,
                    'caption_word_limit' => (int) ($settings->caption_word_limit ?? 50),
                    'cta' => $workspace->resolveBrandKit()?->default_cta_label,
                    'phone' => $contact['phone'] ?? null,
                    'email' => $contact['email'] ?? null,
                    'website' => $contact['website'] ?? null,
                    'has_business_profile' => $workspace->hasBusinessProfile(),
                    'settings_url' => route('settings.index', ['tab' => 'workspace']),
                ];
            })(),
            'ai_prompt_history' => SocialComposePromptHistory::query()
                ->where('workspace_id', $workspace->id)
                ->when($request->user()?->id, fn ($q, $userId) => $q->where('user_id', $userId))
                ->latest('updated_at')
                ->limit(12)
                ->get()
                ->map(fn (SocialComposePromptHistory $row) => [
                    'id' => $row->id,
                    'prompt' => $row->prompt,
                    'offer' => $row->offer,
                    'provider' => $row->provider,
                    'api_url' => $row->api_url,
                    'model' => $row->model,
                    'http_status' => $row->http_status,
                    'tokens' => $row->tokens,
                    'ok' => $row->ok,
                    'error' => $row->error,
                    'response_text' => $row->response_text
                        ? Str::limit($row->response_text, 400, '…')
                        : null,
                    'updated_at' => $row->updated_at?->toDateTimeString(),
                ])
                ->values()
                ->all(),
        ]);
    }

    public function syncAnalytics(Request $request, SocialPostAnalyticsService $analytics): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);

        $count = $analytics->syncWorkspace($workspace, 100);

        return back()->with(
            'success',
            $count > 0
                ? "Engagement synced for {$count} published post(s)."
                : 'No published posts to sync yet — publish live posts first, then refresh.'
        );
    }

    public function composeWithAi(Request $request, AiContentService $ai): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);

        $data = $request->validate([
            'prompt' => ['required', 'string', 'min:12', 'max:2000'],
            'offer' => ['nullable', 'string', 'max:200'],
            'platforms' => ['nullable', 'array'],
            'platforms.*' => ['string', 'max:40'],
        ]);

        $prompt = trim($data['prompt']);
        $offer = trim((string) ($data['offer'] ?? ''));

        $result = $ai->composeSocialFromPrompt($workspace, $request->user()?->id, [
            'prompt' => $prompt,
            'offer' => $offer,
            'platforms' => $data['platforms'] ?? [],
        ]);

        // Keep the prompt in the form after redirect; history survives until hard refresh.
        $redirect = redirect()
            ->route('social.index', ['view' => 'compose'])
            ->with('ai_prompt', $prompt)
            ->with('ai_offer', $offer);

        if (! ($result['ok'] ?? false)) {
            return $redirect->with('error', $result['message'] ?? 'Could not generate caption.');
        }

        SocialComposePromptHistory::remember(
            $workspace,
            $request->user()?->id,
            $prompt,
            $offer,
            is_array($result['api'] ?? null) ? $result['api'] : [
                'provider' => $result['provider'] ?? 'template',
                'draft' => $result['draft'] ?? null,
            ],
        );

        return $redirect
            ->with('success', $result['message'])
            ->with('ai_compose', $result['draft']);
    }

    public function clearComposePromptHistory(Request $request): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);

        SocialComposePromptHistory::query()
            ->where('workspace_id', $workspace->id)
            ->when(
                $request->user()?->id,
                fn ($q, $userId) => $q->where('user_id', $userId),
                fn ($q) => $q->whereNull('user_id'),
            )
            ->delete();

        return back();
    }

    /**
     * @return array{platform:string, pages:list<array{id:string,name:string,instagram:?array{id:string,username:string}}>, suggested_id:?string}|null
     */
    private function pendingPagePickForWorkspace(Request $request, int $workspaceId): ?array
    {
        $pending = $request->session()->get('social_pending_page_pick');
        if (! is_array($pending) || (int) ($pending['workspace_id'] ?? 0) !== $workspaceId) {
            return null;
        }

        $pages = collect($pending['pages'] ?? [])->map(fn (array $p) => [
            'id' => (string) $p['id'],
            'name' => (string) $p['name'],
            'instagram' => $p['instagram'] ?? null,
        ])->values()->all();

        if ($pages === []) {
            return null;
        }

        $workspaceName = strtolower((string) (\App\Models\Workspace::query()->find($workspaceId)?->name ?? ''));
        $preferred = strtolower(trim((string) ($pending['preferred_name'] ?? '')));
        $hint = $preferred !== '' ? $preferred : $workspaceName;

        $suggested = null;
        if ($hint !== '') {
            $matched = app(SocialConnectionService::class)->matchMetaPage(
                array_map(fn (array $p) => [
                    'id' => $p['id'],
                    'name' => $p['name'],
                    'access_token' => '',
                    'instagram' => $p['instagram'] ?? null,
                ], $pages),
                $hint,
                (string) ($pending['platform'] ?? 'facebook')
            );
            $suggested = $matched;
        }

        if (! $suggested) {
            $suggested = collect($pages)->first(function (array $p) use ($hint) {
                $name = strtolower($p['name']);

                return $hint !== '' && (str_contains($name, $hint) || str_contains($hint, $name));
            });
        }

        return [
            'platform' => (string) ($pending['platform'] ?? 'facebook'),
            'pages' => $pages,
            'suggested_id' => $suggested['id'] ?? null,
            'preferred_name' => $preferred !== '' ? (string) $pending['preferred_name'] : null,
        ];
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

            PublishSocialPostJob::dispatchSync($post->id);

            return $this->publishFlashResponse($post);
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
            $canEditPartial = $post->status === 'published'
                && app(SocialPublisherService::class)->hasPublishFailures($post);

            if (! $canEditPartial) {
                return back()->with('error', 'Only draft, scheduled, or failed posts can be edited.');
            }
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

        $post->refresh();
        if (! app(SocialPublisherService::class)->hasAttachedMedia($post)) {
            $post->update(['approved_at' => null, 'approved_by' => null]);
        }

        if ($request->boolean('generate_posters')) {
            GeneratePosterVariantsJob::dispatch($post->id);
        }

        if ($data['delivery'] === 'now') {
            if ($requiresApproval) {
                return back()->with('success', 'Post updated — still needs approval before publish.');
            }

            PublishSocialPostJob::dispatchSync($post->id);

            return $this->publishFlashResponse($post);
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
            'platforms.*' => ['in:facebook,instagram,threads,linkedin,x'],
            'scheduled_at' => ['nullable', 'date'],
            'delivery' => ['required', 'in:now,schedule,draft'],
            'requires_approval' => ['boolean'],
            'media_asset_id' => ['nullable', 'integer'],
            'public_media_url' => ['nullable', 'url', 'max:2048'],
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

        $enabledPlatforms = SocialPlatforms::enabled($workspace->enabled_social_platforms);
        $data['platforms'] = array_values(array_intersect($data['platforms'], $enabledPlatforms));
        if ($data['platforms'] === []) {
            abort(422, 'No enabled SMM platforms selected. Turn platforms on under Settings → Workspace.');
        }

        $publicUrl = trim((string) ($data['public_media_url'] ?? ''));
        unset($data['public_media_url']);

        if ($publicUrl !== '') {
            if (! str_starts_with($publicUrl, 'https://')) {
                abort(422, 'Public media URL must be https (Instagram/Meta cannot fetch http/localhost).');
            }

            $asset = MediaAsset::query()->create([
                'workspace_id' => $workspace->id,
                'uploaded_by' => $request->user()->id,
                'disk' => 'public',
                'path' => $publicUrl,
                'original_name' => basename(parse_url($publicUrl, PHP_URL_PATH) ?: 'remote-image.jpg'),
                'mime_type' => 'image/jpeg',
                'size' => 0,
                'folder' => 'Remote',
                'status' => 'ready',
            ]);
            $data['media_asset_id'] = $asset->id;
        } elseif (! empty($data['media_asset_id'])) {
            abort_unless(
                MediaAsset::query()
                    ->where('workspace_id', $workspace->id)
                    ->whereKey($data['media_asset_id'])
                    ->exists(),
                422
            );
        } else {
            $data['media_asset_id'] = null;
        }

        if (SocialPlatforms::requiresImage($data['platforms']) && empty($data['media_asset_id'])) {
            abort(422, 'All social posts need an image — pick media or paste a public https image URL.');
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

    public function approve(Request $request, SocialPost $post, SocialPublisherService $publisher): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);
        abort_unless($post->workspace_id === $workspace->id, 404);

        if (! $publisher->hasAttachedMedia($post)) {
            return back()->with('error', 'Generate or attach an image before approving this post.');
        }

        $post->update([
            'approved_at' => now(),
            'approved_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Post approved — click Publish now when you are ready.');
    }

    public function publishNow(Request $request, SocialPost $post, PlanAccess $plans): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);
        abort_unless($post->workspace_id === $workspace->id, 404);

        if (! $plans->allows($workspace, 'social_publish')) {
            return back()->with('error', $plans->denyMessage('social_publish'));
        }

        if ($post->requires_approval && ! $post->approved_at) {
            return back()->with('error', 'Approve this post before publishing.');
        }

        if (! app(SocialPublisherService::class)->hasAttachedMedia($post)) {
            return back()->with('error', 'Generate or attach an image before publishing.');
        }

        $publisher = app(SocialPublisherService::class);

        if (! $publisher->hasPublicImage($post)) {
            if (app()->environment('local') && config('social.simulate_publish')) {
                $publisher->simulateLocalPublish($post);

                return back()->with(
                    'success',
                    'Simulated local publish — post marked published for testing. Live Meta publish runs on production with https.'
                );
            }

            return back()->with('error', 'Image must be a public https URL before publishing (localhost images cannot reach Meta).');
        }

        PublishSocialPostJob::dispatchSync($post->id);

        return $this->publishFlashResponse($post);
    }

    public function retry(Request $request, SocialPost $post, PlanAccess $plans): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);
        abort_unless($post->workspace_id === $workspace->id, 404);

        if (! in_array($post->status, ['failed', 'published'], true)) {
            return back()->with('error', 'Only failed or partially published posts can be resent.');
        }

        if (! $plans->allows($workspace, 'social_publish')) {
            return back()->with('error', $plans->denyMessage('social_publish'));
        }

        $publisher = app(SocialPublisherService::class);

        $data = $request->validate([
            'platform' => ['nullable', 'in:facebook,instagram,threads,linkedin,x'],
        ]);

        $onlyPlatforms = null;
        if (! empty($data['platform'])) {
            $platform = (string) $data['platform'];
            abort_unless(in_array($platform, $post->platforms ?? [], true), 422);
            abort_if(
                ! empty(($post->permalinks ?? [])[$platform]),
                422,
                ucfirst($platform).' already published for this post.'
            );
            $onlyPlatforms = [$platform];
        } else {
            if (! $publisher->hasPublishFailures($post)) {
                return back()->with('error', 'All platforms published successfully — nothing to resend.');
            }
            $onlyPlatforms = $publisher->failedPlatforms($post);
        }

        if ($post->requires_approval && ! $post->approved_at) {
            return back()->with('error', 'Approve this post before publishing.');
        }

        if (! $publisher->hasAttachedMedia($post)) {
            return back()->with('error', 'Generate or attach an image before publishing.');
        }

        if (! $publisher->hasPublicImage($post)) {
            if (app()->environment('local') && config('social.simulate_publish')) {
                $publisher->simulateLocalPublish($post);

                return back()->with(
                    'success',
                    'Simulated local publish — post marked published for testing. Live Meta publish runs on production with https.'
                );
            }

            return back()->with('error', 'Image must be a public https URL before publishing (localhost images cannot reach Meta).');
        }

        $post->update(['status' => 'publishing', 'failure_reason' => null]);
        $publisher->publish($post, $onlyPlatforms);

        $label = ! empty($data['platform'])
            ? ucfirst((string) $data['platform'])
            : 'Failed platforms';

        $fresh = $post->fresh();
        if ($fresh && empty($publisher->failedPlatforms($fresh))) {
            return back()->with('success', $label.' republished successfully.');
        }

        return $this->publishFlashResponse($post);
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

    public function oauthStart(Request $request, string $platform, SocialConnectionService $connections, PlanAccess $plans): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);

        if (! in_array($platform, ['facebook', 'instagram', 'threads', 'linkedin', 'x'], true)) {
            return redirect()->route('social.index')->with('error', 'Unknown platform.');
        }

        $enabled = SocialPlatforms::enabled($workspace->enabled_social_platforms);
        if (! in_array($platform, $enabled, true)) {
            return redirect()->route('social.index')->with(
                'error',
                ucfirst($platform).' is hidden for this workspace. Enable it under Settings → Workspace → SMM platforms.'
            );
        }

        if (! $plans->allows($workspace, 'social_oauth')) {
            return redirect()->route('social.index')->with('error', $plans->denyMessage('social_oauth'));
        }

        $accountType = $request->string('account_type')->toString() ?: 'page';
        if (! in_array($accountType, ['page', 'profile'], true)) {
            $accountType = 'page';
        }

        $preferredName = trim($request->string('account_name')->toString());
        if ($preferredName === '') {
            $preferredName = $workspace->name;
        }

        $url = $connections->oauthAuthorizeUrl($workspace, $platform, $accountType, $preferredName);
        if (! $url) {
            return redirect()->route('social.index')->with(
                'error',
                'Add Meta / LinkedIn / X API keys under Settings → Providers first.'
            );
        }

        // Plain HTTP redirect (not Inertia) — avoids Facebook CORS/OPTIONS 400.
        return redirect()->away($url);
    }

    public function connectStub(Request $request, SocialConnectionService $connections, PlanAccess $plans): SymfonyResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);

        $data = $request->validate([
            'platform' => ['required', 'in:facebook,instagram,threads,linkedin,x'],
            'account_name' => ['required', 'string', 'max:120'],
            'account_type' => ['nullable', 'in:page,profile'],
            'use_oauth' => ['boolean'],
        ]);

        $enabled = SocialPlatforms::enabled($workspace->enabled_social_platforms);
        if (! in_array($data['platform'], $enabled, true)) {
            return back()->with(
                'error',
                ucfirst($data['platform']).' is hidden for this workspace. Enable it under Settings → Workspace → SMM platforms.'
            );
        }

        $accountType = $data['account_type'] ?? 'page';

        if ($request->boolean('use_oauth')) {
            if (! $plans->allows($workspace, 'social_oauth')) {
                return back()->with('error', $plans->denyMessage('social_oauth'));
            }

            $url = $connections->oauthAuthorizeUrl(
                $workspace,
                $data['platform'],
                $accountType,
                $data['account_name']
            );
            if ($url) {
                // Never 302 to Facebook from an Inertia/Axios visit (CORS). Force top-level navigation.
                return Inertia::location($url);
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
        $this->authorize('update', $workspace);

        if (! $plans->allows($workspace, 'social_oauth')) {
            return redirect()->route('social.index')->with('error', $plans->denyMessage('social_oauth'));
        }

        $code = (string) $request->query('code', '');
        $code = str_replace('#_', '', $code);
        $code = rtrim($code, '#');
        if ($code === '') {
            return redirect()->route('social.index')->with('error', 'OAuth cancelled');
        }

        $result = $connections->handleOAuthCallback(
            $workspace,
            $platform,
            $code,
            $data['account_type'] ?? 'page',
            $data['preferred_name'] ?? null
        );

        if (($result['status'] ?? '') === 'pick_page') {
            $request->session()->put('social_pending_page_pick', [
                'workspace_id' => $workspace->id,
                'platform' => $platform,
                'account_type' => $data['account_type'] ?? 'page',
                'expires_in' => $result['expires_in'] ?? 5184000,
                'pages' => $result['pages'] ?? [],
                'preferred_name' => $result['preferred_name'] ?? ($data['preferred_name'] ?? null),
            ]);

            return redirect()->route('social.index', ['view' => 'accounts'])
                ->with('success', 'Select which Facebook Page to connect for this workspace.');
        }

        $request->session()->forget('social_pending_page_pick');

        if (($result['status'] ?? '') === 'connected') {
            $name = $result['account']->account_name ?? ucfirst($platform);

            return redirect()->route('social.index', ['view' => 'accounts'])
                ->with('success', $name.' connected');
        }

        return redirect()->route('social.index', ['view' => 'accounts'])
            ->with('error', $result['message'] ?? (ucfirst($platform).' OAuth failed'));
    }

    public function selectOAuthPage(Request $request, SocialConnectionService $connections, PlanAccess $plans): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);

        if (! $plans->allows($workspace, 'social_oauth')) {
            return back()->with('error', $plans->denyMessage('social_oauth'));
        }

        $pending = $request->session()->get('social_pending_page_pick');
        if (! is_array($pending) || (int) ($pending['workspace_id'] ?? 0) !== $workspace->id) {
            return redirect()->route('social.index', ['view' => 'accounts'])
                ->with('error', 'Page selection expired — connect Facebook again.');
        }

        $data = $request->validate([
            'page_id' => ['required', 'string', 'max:64'],
        ]);

        $page = collect($pending['pages'] ?? [])->first(
            fn ($p) => is_array($p) && (string) ($p['id'] ?? '') === $data['page_id']
        );

        if (! $page) {
            return back()->with('error', 'Invalid page selection.');
        }

        $platform = (string) ($pending['platform'] ?? 'facebook');
        $account = $connections->connectMetaPage(
            $workspace,
            $platform,
            (string) ($pending['account_type'] ?? 'page'),
            $page,
            (int) ($pending['expires_in'] ?? 5184000)
        );

        $request->session()->forget('social_pending_page_pick');

        return redirect()->route('social.index', ['view' => 'accounts'])
            ->with('success', $account->account_name.' connected — posts will go to this page.');
    }

    public function cancelOAuthPagePick(Request $request): RedirectResponse
    {
        $request->session()->forget('social_pending_page_pick');

        return redirect()->route('social.index', ['view' => 'accounts'])
            ->with('success', 'Page selection cancelled.');
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

    public function destroyAccount(Request $request, SocialAccount $account): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);
        abort_unless($account->workspace_id === $workspace->id, 404);

        $account->delete();

        return back()->with('success', 'Account removed');
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $posts
     * @return list<array{date:string, inMonth:bool, posts:list<array<string,mixed>>}>
     */
    private function publishFlashResponse(SocialPost $post): RedirectResponse
    {
        $fresh = $post->fresh();

        if ($fresh?->status === 'published') {
            $message = 'Published to connected platforms.';
            if (filled($fresh->failure_reason)) {
                $message = 'Partially published — some platforms failed: '.$fresh->failure_reason;
            }

            return back()->with('success', $message);
        }

        if ($fresh?->status === 'failed') {
            return back()->with('error', $fresh->failure_reason ?: 'Publish failed — check connected accounts.');
        }

        if ($fresh?->status === 'publishing') {
            return back()->with('error', 'Publish is still running — refresh the page in a moment.');
        }

        return back()->with(
            'error',
            $fresh?->failure_reason ?: 'Publish did not finish — post is still a draft. Use a public https image URL on localhost.'
        );
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
