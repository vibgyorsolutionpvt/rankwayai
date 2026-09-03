<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesWorkspace;
use App\Models\CmsConnection;
use App\Models\SeoBlogPost;
use App\Models\SeoContentDraft;
use App\Models\SeoSite;
use App\Services\Billing\PlanAccess;
use App\Services\Seo\Providers\AskefyCmsPublisher;
use App\Services\Seo\Providers\WordpressCmsPublisher;
use App\Services\Seo\SeoBlogDiscoveryService;
use App\Services\Seo\SeoBlogShareService;
use App\Services\Seo\SeoCmsPublishService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class BlogController extends Controller
{
    use ResolvesWorkspace;

    public function index(Request $request, PlanAccess $plans): Response
    {
        $workspace = $this->workspace($request);
        $this->authorize('view', $workspace);

        $sites = SeoSite::query()
            ->where('workspace_id', $workspace->id)
            ->orderBy('domain')
            ->get(['id', 'domain', 'status', 'blog_feed_url', 'blog_posts_synced_at']);

        $siteId = (int) $request->query('site', $sites->first()?->id ?? 0);
        $site = $sites->firstWhere('id', $siteId) ?: $sites->first();
        $perPage = min(24, max(6, (int) $request->integer('per_page', 9)));

        $blogPosts = $site
            ? SeoBlogPost::query()
                ->where('seo_site_id', $site->id)
                ->orderByDesc('published_at')
                ->orderByDesc('id')
                ->paginate($perPage)
                ->withQueryString()
                ->through(fn (SeoBlogPost $post) => $post->toArrayForUi())
            : null;

        return Inertia::render('Blog/Index', [
            'workspace' => ['id' => $workspace->id, 'name' => $workspace->name],
            'plan' => $plans->summary($workspace),
            'sites' => $sites,
            'site' => $site,
            'askefy' => $this->askefyConnectionSummary($workspace->id),
            'cms_connections' => CmsConnection::query()
                ->where('workspace_id', $workspace->id)
                ->latest()
                ->get(['id', 'provider', 'label', 'base_url', 'status', 'last_tested_at']),
            'content_drafts' => $this->paginatedContentDrafts($workspace->id, $request),
            'draft_filters' => [
                'status' => (string) $request->query('draft_status', 'all'),
                'counts' => [
                    'all' => SeoContentDraft::query()->where('workspace_id', $workspace->id)->count(),
                    'needs_review' => SeoContentDraft::query()
                        ->where('workspace_id', $workspace->id)
                        ->whereNull('reviewed_at')
                        ->whereIn('status', ['draft', 'failed'])
                        ->count(),
                    'draft' => SeoContentDraft::query()->where('workspace_id', $workspace->id)->where('status', 'draft')->count(),
                    'approved' => SeoContentDraft::query()->where('workspace_id', $workspace->id)->where('status', 'approved')->count(),
                    'published' => SeoContentDraft::query()->where('workspace_id', $workspace->id)->where('status', 'published')->count(),
                    'failed' => SeoContentDraft::query()->where('workspace_id', $workspace->id)->where('status', 'failed')->count(),
                ],
            ],
            'blog_posts' => $blogPosts ?? [
                'data' => [],
                'links' => [],
                'meta' => [
                    'total' => 0,
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => $perPage,
                ],
            ],
            'blog_share_channels' => config('seo.blog_share_channels', []),
            'blog_synced_at' => $site?->blog_posts_synced_at?->diffForHumans(),
            'blog_feed_url' => $site?->blog_feed_url,
        ]);
    }

    public function storeAskefyConnection(Request $request, AskefyCmsPublisher $askefy): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);

        $user = $request->user();
        $data = $request->validate([
            'mode' => ['required', 'in:signup,login'],
            'base_url' => ['nullable', 'url', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'password_confirmation' => ['required_if:mode,signup', 'nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:40'],
        ]);

        $name = trim((string) ($data['name'] ?? $user?->name ?? $workspace->name));
        $email = trim((string) ($data['email'] ?? $user?->email ?? ''));
        if ($email === '') {
            return back()->with('error', 'Account email is required.');
        }
        if ($data['mode'] === 'signup' && $name === '') {
            return back()->with('error', 'Account name is required.');
        }
        if ($data['mode'] === 'signup' && ($data['password'] ?? '') !== ($data['password_confirmation'] ?? '')) {
            return back()->with('error', 'Password confirmation does not match.');
        }

        $sites = SeoSite::query()
            ->where('workspace_id', $workspace->id)
            ->orderBy('domain')
            ->get(['id', 'domain']);

        if ($sites->isEmpty()) {
            return back()->with('error', 'Add at least one website in SEO first. Each domain becomes an Askefy business page.');
        }

        $baseUrl = rtrim($data['base_url'] ?? $askefy->defaultBaseUrl(), '/');
        if ($baseUrl === '') {
            return back()->with('error', 'Set ASKEFY_BASE_URL in .env (e.g. http://127.0.0.1:8001 for local Askefy).');
        }

        if ($message = $askefy->validateBaseUrl($baseUrl)) {
            return back()->with('error', $message);
        }

        try {
            $device = 'rankwayai-'.$workspace->id;
            $auth = $data['mode'] === 'signup'
                ? $askefy->register(
                    $baseUrl,
                    $name,
                    $email,
                    $data['password'],
                    (string) ($data['password_confirmation'] ?? $data['password']),
                    $device,
                )
                : $askefy->login($baseUrl, $email, $data['password'], $device);

            if (! ($auth['ok'] ?? false)) {
                return back()->with('error', $auth['message'] ?? 'Askefy auth failed');
            }

            $creds = [
                'base_url' => $baseUrl,
                'token' => $auth['token'],
                'email' => $email,
                'user_name' => $auth['user']['name'] ?? $name,
                'public_url' => rtrim((string) config('services.askefy.public_url', $baseUrl), '/'),
                'category' => $data['category'] ?? 'technology',
            ];

            $test = $askefy->testConnection($creds);
            if (! ($test['ok'] ?? false)) {
                return back()->with('error', $test['message'] ?? 'Askefy token check failed');
            }

            $existingPages = $askefy->listMyPages($creds);
            $sitePages = [];
            $createdCount = 0;
            $usedUsernames = [];

            foreach ($sites as $site) {
                $pageName = Str::limit($workspace->name.' — '.$site->domain, 80, '');
                $username = $this->usernameFromDomain((string) $site->domain, (int) $site->id, $usedUsernames);
                $usedUsernames[] = $username;

                $existing = collect($existingPages)->first(
                    fn (array $p) => ($p['username'] ?? null) === $username
                        || ($p['slug'] ?? null) === Str::slug($pageName)
                );

                if ($existing) {
                    $pageSlug = (string) ($existing['slug'] ?? '');
                    $resolvedName = (string) ($existing['name'] ?? $pageName);
                } else {
                    $created = $askefy->createPage($creds, [
                        'type' => 'business',
                        'name' => $pageName,
                        'username' => $username,
                        'description' => $workspace->name.' site: '.$site->domain,
                        'category' => $creds['category'],
                        'visibility' => 'public',
                        'email' => $email,
                        'email_visibility' => 'public',
                    ]);

                    if (! ($created['ok'] ?? false)) {
                        return back()->with('error', ($created['message'] ?? 'Page create failed').' ('.$site->domain.')');
                    }

                    $pageSlug = (string) ($created['page']['slug'] ?? '');
                    $resolvedName = (string) ($created['page']['name'] ?? $pageName);
                    $createdCount++;
                    $existingPages[] = [
                        'slug' => $pageSlug,
                        'name' => $resolvedName,
                        'username' => $username,
                    ];
                }

                if ($pageSlug === '') {
                    return back()->with('error', 'Askefy page missing slug for '.$site->domain);
                }

                $sitePages[(string) $site->id] = [
                    'domain' => $site->domain,
                    'slug' => $pageSlug,
                    'name' => $resolvedName,
                    'username' => $username,
                ];
            }

            $first = reset($sitePages) ?: null;
            $creds['site_pages'] = $sitePages;
            $creds['page_slug'] = $first['slug'] ?? null;
            $creds['page_name'] = $first['name'] ?? null;
            $creds['page_username'] = $first['username'] ?? null;

            CmsConnection::query()->updateOrCreate(
                [
                    'workspace_id' => $workspace->id,
                    'provider' => 'askefy',
                    'base_url' => $baseUrl,
                ],
                [
                    'label' => 'Askefy · '.count($sitePages).' page(s)',
                    'credentials' => $creds,
                    'status' => 'active',
                    'last_tested_at' => now(),
                ]
            );

            return back()->with(
                'success',
                'Askefy ready: account connected, '.count($sitePages).' business page(s)'
                .($createdCount > 0 ? " ({$createdCount} new)" : '').'.'
            );
        } catch (\Throwable $e) {
            report($e);

            return back()->with(
                'error',
                'Askefy connect failed: '.$e->getMessage()
            );
        }
    }

    /**
     * @param  list<string>  $used
     */
    private function usernameFromDomain(string $domain, int $siteId, array $used): string
    {
        $host = strtolower(preg_replace('/^www\./', '', $domain) ?? $domain);
        $slug = preg_replace('/[^a-z0-9]+/', '_', $host) ?? '';
        $slug = trim($slug, '_');
        if (strlen($slug) < 3) {
            $slug = 'site_'.$siteId;
        }
        $slug = substr($slug, 0, 30);

        $candidate = $slug;
        $i = 1;
        while (in_array($candidate, $used, true)) {
            $suffix = '_'.$siteId;
            $candidate = substr($slug, 0, max(1, 30 - strlen($suffix))).$suffix;
            if ($i > 1) {
                $candidate = substr($slug, 0, 24).'_'.$siteId.'_'.$i;
            }
            $i++;
            if ($i > 20) {
                $candidate = 'site_'.$siteId;
                break;
            }
        }

        return $candidate;
    }

    public function disconnectAskefy(Request $request): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);

        CmsConnection::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('provider', ['askefy', 'verba'])
            ->delete();

        return back()->with('success', 'Askefy disconnected.');
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
        )->with('site', $site->id);
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

        $allowed = [...collect($shares->channels())->pluck('id')->all(), 'copy'];
        if (! in_array($data['channel'], $allowed, true)) {
            return back()->with('error', 'Unknown share channel.');
        }

        try {
            $share = $shares->record($workspace, $post, $data['channel']);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($data['channel'] === 'copy') {
            return back()->with('success', 'Link copied.');
        }

        return back()->with([
            'success' => 'Share opened — finish posting on '.$data['channel'].' for the backlink.',
            'share_open_url' => $share->share_url,
        ]);
    }

    public function publishBlogToAskefy(
        Request $request,
        SeoBlogPost $post,
        SeoCmsPublishService $cms,
    ): RedirectResponse {
        $workspace = $this->workspace($request);
        $post->loadMissing('site');
        abort_unless($post->site && $post->site->workspace_id === $workspace->id, 404);
        $this->authorize('update', $workspace);

        $connection = CmsConnection::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('provider', ['askefy', 'verba'])
            ->where('status', 'active')
            ->latest()
            ->first();

        if (! $connection) {
            return back()->with('error', 'Connect Askefy first.');
        }

        try {
            $result = $cms->publishBlogPost($workspace, $post, $connection);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
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
            'keyword' => ['required', 'string', 'min:8', 'max:2000'],
            'seo_keyword_id' => ['nullable', 'integer'],
            'audience' => ['nullable', 'string', 'max:160'],
            'intent' => ['nullable', 'string', 'in:guide,howto,listicle,comparison,local'],
            'length' => ['nullable', 'string', 'in:short,standard,long'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'tone' => ['nullable', 'string', 'in:hinglish,english,hindi'],
        ]);

        try {
            $draft = $cms->createDraftFromKeyword(
                $workspace,
                $data['keyword'],
                $data['seo_keyword_id'] ?? null,
                $request->user()->id,
                [
                    'audience' => $data['audience'] ?? '',
                    'intent' => $data['intent'] ?? 'guide',
                    'length' => $data['length'] ?? 'standard',
                    'notes' => $data['notes'] ?? '',
                    'tone' => $data['tone'] ?? '',
                ],
            );
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('blog.index', ['tab' => 'write'])
            ->with('success', 'AI blog draft “'.$draft->title.'” ready — review, then approve & publish.');
    }

    public function approveDraft(Request $request, SeoContentDraft $draft, SeoCmsPublishService $cms): RedirectResponse
    {
        $workspace = $this->workspace($request);
        abort_unless($draft->workspace_id === $workspace->id, 404);
        $this->authorize('update', $workspace);

        try {
            $cms->approve($draft);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Draft approved — you can publish now.');
    }

    public function updateContentDraft(Request $request, SeoContentDraft $draft): RedirectResponse
    {
        $workspace = $this->workspace($request);
        abort_unless($draft->workspace_id === $workspace->id, 404);
        $this->authorize('update', $workspace);

        if (! in_array($draft->status, ['draft', 'approved', 'failed'], true)) {
            return back()->with('error', 'Published drafts cannot be edited.');
        }

        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'body_html' => ['required', 'string', 'max:500000'],
            'meta_title' => ['nullable', 'string', 'max:180'],
            'meta_description' => ['nullable', 'string', 'max:320'],
            'mark_reviewed' => ['boolean'],
        ]);

        $title = trim($data['title']);
        $wasApproved = $draft->status === 'approved';
        $markReviewed = $request->boolean('mark_reviewed');

        $draft->update([
            'title' => $title,
            'slug' => Str::slug($title) ?: $draft->slug,
            'body_html' => $data['body_html'],
            'meta_title' => Str::limit($data['meta_title'] ?: $title, 70, ''),
            'meta_description' => Str::limit(
                $data['meta_description'] ?: ($draft->meta_description ?: ''),
                180,
                '',
            ) ?: $draft->meta_description,
            'reviewed_at' => $wasApproved && ! $markReviewed
                ? null
                : ($markReviewed ? now() : $draft->reviewed_at),
            'status' => $wasApproved ? 'draft' : $draft->status,
            'last_error' => null,
        ]);

        $message = $wasApproved && ! $markReviewed
            ? 'Changes saved — review again, then approve to publish.'
            : 'Draft saved and marked as reviewed.';

        return back()->with('success', $message);
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

    /**
     * @return array{connected:bool,connection_id:?int,base_url:?string,page_slug:?string,page_name:?string,email:?string,label:?string}
     */
    private function paginatedContentDrafts(int $workspaceId, Request $request)
    {
        $status = (string) $request->query('draft_status', 'all');

        $query = SeoContentDraft::query()->where('workspace_id', $workspaceId);

        if ($status === 'needs_review') {
            $query->whereNull('reviewed_at')->whereIn('status', ['draft', 'failed']);
        } elseif (in_array($status, ['draft', 'approved', 'published', 'failed'], true)) {
            $query->where('status', $status);
        }

        return $query
            ->latest()
            ->paginate(20, ['*'], 'drafts_page')
            ->withQueryString()
            ->through(fn (SeoContentDraft $draft) => [
                'id' => $draft->id,
                'title' => $draft->title,
                'slug' => $draft->slug,
                'status' => $draft->status,
                'reviewed_at' => $draft->reviewed_at?->toDateTimeString(),
                'is_reviewed' => $draft->isReviewed(),
                'meta_title' => $draft->meta_title,
                'meta_description' => $draft->meta_description,
                'body_html' => $draft->body_html,
                'published_url' => $draft->published_url,
                'excerpt' => Str::limit(trim(html_entity_decode(strip_tags((string) $draft->body_html), ENT_QUOTES | ENT_HTML5)), 160),
                'word_count' => str_word_count(trim(strip_tags((string) $draft->body_html))),
                'updated_at' => $draft->updated_at?->toDateTimeString(),
            ]);
    }

    /**
     * @return array{connected:bool,connection_id:?int,base_url:?string,page_slug:?string,page_name:?string,email:?string,label:?string}
     */
    private function askefyConnectionSummary(int $workspaceId): array
    {
        $connection = CmsConnection::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('provider', ['askefy', 'verba'])
            ->where('status', 'active')
            ->latest()
            ->first();

        if (! $connection) {
            return [
                'connected' => false,
                'connection_id' => null,
                'base_url' => config('services.askefy.base_url'),
                'page_slug' => null,
                'page_name' => null,
                'email' => null,
                'label' => null,
                'pages' => [],
            ];
        }

        $creds = is_array($connection->credentials) ? $connection->credentials : [];

        return [
            'connected' => true,
            'connection_id' => $connection->id,
            'base_url' => $connection->base_url,
            'page_slug' => $creds['page_slug'] ?? null,
            'page_name' => $creds['page_name'] ?? null,
            'email' => $creds['email'] ?? null,
            'label' => $connection->label,
            'pages' => array_values(is_array($creds['site_pages'] ?? null) ? $creds['site_pages'] : []),
        ];
    }
}
