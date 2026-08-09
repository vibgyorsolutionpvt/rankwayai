<?php

namespace App\Services\Ai;

use App\Jobs\GeneratePosterVariantsJob;
use App\Models\AiGeneration;
use App\Models\AiUsageLog;
use App\Models\FestivalEvent;
use App\Models\SocialPost;
use App\Models\Workspace;
use App\Models\WorkspaceAiSetting;
use App\Services\Billing\CreditWalletService;
use Illuminate\Support\Str;

class AiContentService
{
    public function __construct(
        private AiProviderRouter $router,
        private CreditWalletService $credits,
    ) {}

    public function settings(Workspace $workspace): WorkspaceAiSetting
    {
        return WorkspaceAiSetting::query()->firstOrCreate(
            ['workspace_id' => $workspace->id],
            [
                'monthly_budget_usd' => 20,
                'template_first' => true,
                'tone' => 'mixed',
                'industry' => 'local business',
                'location' => 'India',
                'hashtag_packs' => [
                    'general' => ['#Marketing', '#Growth', '#SmallBusiness'],
                    'local' => ['#LocalBusiness', '#India', '#SupportLocal'],
                ],
            ]
        );
    }

    /**
     * @return array{ok:bool, message:string, posts:list<SocialPost>, generation:?AiGeneration, cost:float}
     */
    public function generateTodaysPosts(Workspace $workspace, ?int $userId = null): array
    {
        $settings = $this->settings($workspace);
        $useLive = $this->shouldUseLive($settings);

        $festival = FestivalEvent::query()
            ->whereBetween('occurs_on', [now()->toDateString(), now()->addDays(14)->toDateString()])
            ->orderBy('occurs_on')
            ->first();

        $providerName = 'template';
        $variants = $this->captionVariants($workspace, $settings, $festival);

        if ($useLive) {
            $live = $this->liveCaptionVariants($workspace, $settings, $festival);
            if ($live) {
                $variants = $live['variants'];
                $providerName = $live['provider'];
            }
        }

        $cost = $this->router->costFor($providerName);

        if (! $this->credits->canSpend($workspace, $cost)) {
            return [
                'ok' => false,
                'message' => 'AI credits exhausted. Recharge credits from Billing to continue.',
                'posts' => [],
                'generation' => null,
                'cost' => 0,
            ];
        }

        $posts = [];
        $scheduledBase = now()->setTime(10, 0);

        foreach ($variants as $i => $variant) {
            $post = SocialPost::query()->create([
                'workspace_id' => $workspace->id,
                'created_by' => $userId,
                'title' => $variant['title'],
                'body' => $variant['body'],
                'platforms' => $variant['platforms'],
                'status' => 'draft',
                'scheduled_at' => $scheduledBase->copy()->addHours($i * 3),
                'requires_approval' => true,
            ]);
            GeneratePosterVariantsJob::dispatch($post->id);
            $posts[] = $post;
        }

        $generation = AiGeneration::query()->create([
            'workspace_id' => $workspace->id,
            'type' => 'today_pack',
            'title' => 'Today’s post pack — '.now()->toDateString(),
            'payload' => [
                'tone' => $settings->tone,
                'provider' => $providerName,
                'festival' => $festival?->only(['name', 'occurs_on', 'suggested_angles']),
                'variants' => $variants,
                'post_ids' => collect($posts)->pluck('id'),
            ],
            'status' => 'ready',
        ]);

        $this->logUsage($workspace, $userId, 'generate_today', $cost, $providerName, [
            'posts' => count($posts),
            'festival' => $festival?->name,
        ]);

        $creditsUsed = \App\Services\Billing\CreditPackCatalog::costToCredits($cost);

        return [
            'ok' => true,
            'message' => count($posts).' draft posts created via '.$providerName.' (approval required). '.$creditsUsed.' credits used.',
            'posts' => $posts,
            'generation' => $generation,
            'cost' => $cost,
        ];
    }

    /**
     * @return array{ok:bool, message:string, outline?:array<string,mixed>, cost:float}
     */
    public function blogOutline(Workspace $workspace, string $topic, ?int $userId = null): array
    {
        $settings = $this->settings($workspace);
        $useLive = $this->shouldUseLive($settings);

        $providerName = 'template';
        $outline = $this->templateBlogOutline($workspace, $settings, $topic);

        if ($useLive) {
            $live = $this->liveBlogOutline($workspace, $settings, $topic);
            if ($live) {
                $outline = $live['outline'];
                $providerName = $live['provider'];
            }
        }

        $cost = $this->router->costFor($providerName);

        if (! $this->credits->canSpend($workspace, $cost)) {
            return ['ok' => false, 'message' => 'AI credits exhausted. Recharge from Billing.', 'cost' => 0];
        }

        AiGeneration::query()->create([
            'workspace_id' => $workspace->id,
            'type' => 'blog',
            'title' => $topic,
            'payload' => array_merge($outline, ['provider' => $providerName]),
            'status' => 'ready',
        ]);

        $this->logUsage($workspace, $userId, 'blog_outline', $cost, $providerName, ['topic' => $topic]);

        return ['ok' => true, 'message' => 'Blog outline ready ('.$providerName.')', 'outline' => $outline, 'cost' => $cost];
    }

    /**
     * @return array{ok:bool, message:string, metas?:list<string>, cost:float}
     */
    public function seoMetaSuggestions(Workspace $workspace, string $pageTitle, ?int $userId = null): array
    {
        $settings = $this->settings($workspace);
        $useLive = $this->shouldUseLive($settings);

        $providerName = 'template';
        $metas = $this->templateSeoMetas($workspace, $settings, $pageTitle);

        if ($useLive) {
            $live = $this->liveSeoMetas($workspace, $settings, $pageTitle);
            if ($live) {
                $metas = $live['metas'];
                $providerName = $live['provider'];
            }
        }

        $cost = $this->router->costFor($providerName);

        if (! $this->credits->canSpend($workspace, $cost)) {
            return ['ok' => false, 'message' => 'AI credits exhausted. Recharge from Billing.', 'cost' => 0];
        }

        AiGeneration::query()->create([
            'workspace_id' => $workspace->id,
            'type' => 'seo_meta',
            'title' => $pageTitle,
            'payload' => ['metas' => $metas, 'provider' => $providerName],
            'status' => 'ready',
        ]);

        $this->logUsage($workspace, $userId, 'seo_meta', $cost, $providerName, ['page' => $pageTitle]);

        return ['ok' => true, 'message' => 'Meta variants ready ('.$providerName.')', 'metas' => $metas, 'cost' => $cost];
    }

    private function shouldUseLive(WorkspaceAiSetting $settings): bool
    {
        return ! $settings->template_first && $this->router->anyConfigured();
    }

    /**
     * @return array{variants:list<array{title:string, body:string, platforms:list<string>}>, provider:string}|null
     */
    private function liveCaptionVariants(Workspace $workspace, WorkspaceAiSetting $settings, ?FestivalEvent $festival): ?array
    {
        $cta = $workspace->resolveBrandKit()?->default_cta_label ?: 'Get started';
        $festivalHint = $festival
            ? "Festival context: {$festival->name} on {$festival->occurs_on?->toDateString()}"
            : 'No festival — write evergreen tips.';

        $system = 'You write short social marketing posts for Indian local businesses. Return ONLY valid JSON.';
        $user = <<<PROMPT
Brand: {$workspace->name}
Tone: {$settings->tone}
Industry: {$settings->industry}
Location: {$settings->location}
CTA: {$cta}
{$festivalHint}

Return JSON:
{"posts":[{"title":"...","body":"...","platforms":["instagram","facebook"]},{"title":"...","body":"...","platforms":["linkedin"]},{"title":"...","body":"...","platforms":["instagram","facebook","x"]}]}
Exactly 3 posts. Include hashtags in body. Keep each body under 500 chars.
PROMPT;

        $completion = $this->router->complete($system, $user, 900);
        if (! $completion->ok) {
            return null;
        }

        $json = $this->extractJson($completion->text);
        $posts = data_get($json, 'posts');
        if (! is_array($posts) || count($posts) < 1) {
            return null;
        }

        $out = [];
        foreach (array_slice($posts, 0, 3) as $row) {
            $title = trim((string) ($row['title'] ?? ''));
            $body = trim((string) ($row['body'] ?? ''));
            $platforms = $row['platforms'] ?? ['instagram'];
            if ($title === '' || $body === '') {
                continue;
            }
            $out[] = [
                'title' => Str::limit($title, 120, ''),
                'body' => $body,
                'platforms' => array_values(array_intersect(
                    is_array($platforms) ? $platforms : ['instagram'],
                    ['facebook', 'instagram', 'linkedin', 'x']
                )) ?: ['instagram'],
            ];
        }

        if (! count($out)) {
            return null;
        }

        return ['variants' => $out, 'provider' => $completion->provider];
    }

    /**
     * @return array{outline:array<string, mixed>, provider:string}|null
     */
    private function liveBlogOutline(Workspace $workspace, WorkspaceAiSetting $settings, string $topic): ?array
    {
        $cta = $workspace->resolveBrandKit()?->default_cta_label ?: 'Get started';
        $system = 'You write SEO blog outlines. Return ONLY valid JSON.';
        $user = <<<PROMPT
Topic: {$topic}
Brand: {$workspace->name}
Industry: {$settings->industry}
Location: {$settings->location}
CTA: {$cta}

Return JSON:
{"topic":"...","h1":"...","sections":["...","..."],"meta_description":"..."}
PROMPT;

        $completion = $this->router->complete($system, $user, 700);
        if (! $completion->ok) {
            return null;
        }

        $json = $this->extractJson($completion->text);
        if (! is_array($json) || blank($json['h1'] ?? null)) {
            return null;
        }

        return [
            'outline' => [
                'topic' => (string) ($json['topic'] ?? $topic),
                'h1' => (string) $json['h1'],
                'sections' => array_values(array_filter((array) ($json['sections'] ?? []))),
                'meta_description' => Str::limit((string) ($json['meta_description'] ?? $topic), 160, ''),
            ],
            'provider' => $completion->provider,
        ];
    }

    /**
     * @return array{metas:list<string>, provider:string}|null
     */
    private function liveSeoMetas(Workspace $workspace, WorkspaceAiSetting $settings, string $pageTitle): ?array
    {
        $system = 'You write SEO meta description variants. Return ONLY valid JSON.';
        $user = <<<PROMPT
Page title: {$pageTitle}
Brand: {$workspace->name}
Industry: {$settings->industry}
Location: {$settings->location}

Return JSON: {"metas":["...","...","..."]} — exactly 3 strings, each under 155 chars.
PROMPT;

        $completion = $this->router->complete($system, $user, 400);
        if (! $completion->ok) {
            return null;
        }

        $json = $this->extractJson($completion->text);
        $metas = data_get($json, 'metas');
        if (! is_array($metas) || count($metas) < 1) {
            return null;
        }

        $clean = collect($metas)
            ->map(fn ($m) => Str::limit(trim((string) $m), 155, ''))
            ->filter()
            ->take(3)
            ->values()
            ->all();

        if (! count($clean)) {
            return null;
        }

        return ['metas' => $clean, 'provider' => $completion->provider];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractJson(string $text): ?array
    {
        $text = trim($text);
        if (preg_match('/\{.*\}/s', $text, $m)) {
            $text = $m[0];
        }

        $decoded = json_decode($text, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return list<array{title:string, body:string, platforms:list<string>}>
     */
    private function captionVariants(Workspace $workspace, WorkspaceAiSetting $settings, ?FestivalEvent $festival): array
    {
        $cta = $workspace->resolveBrandKit()?->default_cta_label ?: 'Get started';
        $loc = $settings->location ?: 'your city';
        $tags = $this->hashtags($settings);
        $toneLine = match ($settings->tone) {
            'hindi' => 'Aaj se growth shuru karein — simple steps, real results.',
            'english' => 'Small steps today. Measurable growth this month.',
            default => 'Aaj plan clear rakho — today clear, growth compound.',
        };

        $festivalLine = $festival
            ? "{$festival->name} special: ".($festival->suggested_angles[0] ?? 'Share a timely offer with your audience.')
            : 'Tip Tuesday: one useful tip your customers can use today.';

        return [
            [
                'title' => $festival ? $festival->name.' post' : 'Daily tip',
                'body' => "{$festivalLine}\n\n{$toneLine}\n\n{$cta}\n{$tags}",
                'platforms' => ['instagram', 'facebook'],
            ],
            [
                'title' => 'Authority post',
                'body' => "Most {$settings->industry} brands in {$loc} post randomly.\nWe post with a system: brand kit → media → schedule.\n\n{$cta}\n{$tags}",
                'platforms' => ['linkedin'],
            ],
            [
                'title' => 'Engagement ask',
                'body' => "Quick question for {$loc}: what’s your #1 marketing headache this week?\nComment below — we’ll share a fix.\n\n{$tags}",
                'platforms' => ['instagram', 'facebook', 'x'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function templateBlogOutline(Workspace $workspace, WorkspaceAiSetting $settings, string $topic): array
    {
        return [
            'topic' => $topic,
            'h1' => $topic,
            'sections' => [
                'Why this matters for '.($settings->location ?: 'your customers'),
                '3 practical tips',
                'Common mistakes to avoid',
                'How '.$workspace->name.' helps',
                'CTA: '.($workspace->resolveBrandKit()?->default_cta_label ?: 'Get started'),
            ],
            'meta_description' => Str::limit($topic.' — practical guide for '.($settings->industry ?: 'businesses').' in '.($settings->location ?: 'India').'.', 155),
        ];
    }

    /**
     * @return list<string>
     */
    private function templateSeoMetas(Workspace $workspace, WorkspaceAiSetting $settings, string $pageTitle): array
    {
        $brand = $workspace->name;
        $loc = $settings->location ?: 'India';

        return [
            "{$pageTitle} | {$brand} — trusted {$settings->industry} help in {$loc}.",
            "Looking for {$pageTitle}? {$brand} delivers clear results for local businesses.",
            "{$brand}: {$pageTitle}. Book a free consult today.",
        ];
    }

    private function hashtags(WorkspaceAiSetting $settings): string
    {
        $packs = $settings->hashtag_packs ?? [];
        $tags = array_merge($packs['general'] ?? [], $packs['local'] ?? []);

        return implode(' ', array_slice($tags, 0, 6));
    }

    private function logUsage(
        Workspace $workspace,
        ?int $userId,
        string $action,
        float $cost,
        string $provider,
        array $meta
    ): void {
        AiUsageLog::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $userId,
            'action' => $action,
            'provider' => $provider,
            'tokens' => $provider === 'template' ? 0 : 200,
            'cost_usd' => $cost,
            'meta' => $meta,
        ]);

        $this->credits->spend($workspace, $cost);
    }
}
