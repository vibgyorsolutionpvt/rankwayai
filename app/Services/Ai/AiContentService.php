<?php

namespace App\Services\Ai;

use App\Jobs\GeneratePosterVariantsJob;
use App\Models\AiGeneration;
use App\Models\AiUsageLog;
use App\Models\FestivalEvent;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\Workspace;
use App\Models\WorkspaceAiSetting;
use App\Services\Billing\CreditWalletService;
use App\Services\Festivals\FestivalCalendarService;
use App\Support\SocialPlatforms;
use Illuminate\Support\Str;

class AiContentService
{
    public function __construct(
        private AiProviderRouter $router,
        private CreditWalletService $credits,
        private FestivalCalendarService $festivals,
    ) {}

    public function settings(Workspace $workspace): WorkspaceAiSetting
    {
        $industry = $workspace->resolvedIndustry() ?? 'local business';
        $location = $workspace->resolvedCity() ?? 'India';

        return WorkspaceAiSetting::query()->firstOrCreate(
            ['workspace_id' => $workspace->id],
            [
                'monthly_budget_usd' => 20,
                'template_first' => true,
                'tone' => 'mixed',
                'caption_word_limit' => 50,
                'industry' => $industry,
                'location' => $location,
                'hashtag_packs' => [
                    'general' => ['#Marketing', '#Growth', '#SmallBusiness'],
                    'local' => ['#LocalBusiness', '#India', '#SupportLocal'],
                ],
            ]
        );
    }

    public function syncSettingsFromWorkspace(Workspace $workspace): WorkspaceAiSetting
    {
        $settings = $this->settings($workspace);

        if ($workspace->hasBusinessProfile()) {
            $settings->update([
                'industry' => $workspace->resolvedIndustry(),
                'location' => $workspace->resolvedCity(),
            ]);
        }

        return $settings->fresh();
    }

    private function resolveFestival(mixed $festivalId): ?FestivalEvent
    {
        if ($festivalId === null || $festivalId === '' || $festivalId === 0) {
            return null;
        }

        $festival = FestivalEvent::query()->find((int) $festivalId);

        if (! $festival) {
            return null;
        }

        $occurs = $festival->occurs_on?->timezone(config('app.timezone'))->startOfDay();
        $today = now()->timezone(config('app.timezone'))->startOfDay();

        if (! $occurs || $occurs->lt($today)) {
            return null;
        }

        return $festival;
    }

    /**
     * @param  array{brief:string,offer?:string,festival_id?:int|null}  $input
     * @return array{ok:bool, message:string, posts:list<SocialPost>, generation:?AiGeneration, cost:float}
     */
    public function generateTodaysPosts(Workspace $workspace, ?int $userId = null, array $input = []): array
    {
        $settings = $this->settings($workspace);
        $useLive = $this->shouldUseLive($settings);

        $festival = $this->resolveFestival($input['festival_id'] ?? null);
        $brief = trim((string) ($input['brief'] ?? ''));
        $offer = trim((string) ($input['offer'] ?? ''));

        if ($brief === '') {
            if ($festival) {
                $brief = $this->suggestBrief($workspace, $settings, $festival, $offer);
            } else {
                return [
                    'ok' => false,
                    'message' => 'Tell us what topic or offer you want to post about — or pick a festival above.',
                    'posts' => [],
                    'generation' => null,
                    'cost' => 0,
                ];
            }
        } else {
            $brief = $this->normalizeBrief($brief, $workspace, $settings, $festival, $offer);
        }

        $allowedPlatforms = $this->publishPlatforms($workspace);
        if ($allowedPlatforms === []) {
            return [
                'ok' => false,
                'message' => 'Enable and connect at least one SMM platform (Facebook, Instagram, Threads, etc.) in Settings → Workspace or SMM.',
                'posts' => [],
                'generation' => null,
                'cost' => 0,
            ];
        }

        $wordLimit = $this->resolveWordLimit($input, $settings);
        $draftCount = $this->resolveDraftCount($input);

        $providerName = 'template';
        $variants = $this->captionVariants($workspace, $settings, $festival, $brief, $offer, $allowedPlatforms, $wordLimit, $draftCount);

        if ($useLive) {
            $live = $this->liveCaptionVariants($workspace, $settings, $festival, $brief, $offer, $allowedPlatforms, $wordLimit, $draftCount);
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
                'brand_kit_id' => $workspace->resolveBrandKit()?->id,
                'title' => $variant['title'],
                'body' => $variant['body'],
                'platforms' => $variant['platforms'],
                'status' => 'draft',
                'scheduled_at' => $scheduledBase->copy()->addHours($i * 3),
                'requires_approval' => true,
            ]);
            GeneratePosterVariantsJob::dispatchSync($post->id);
            $posts[] = $post->fresh(['media']);
        }

        $generation = AiGeneration::query()->create([
            'workspace_id' => $workspace->id,
            'type' => 'today_pack',
            'title' => 'Today’s post pack — '.now()->toDateString(),
            'payload' => [
                'tone' => $settings->tone,
                'provider' => $providerName,
                'brief' => $brief,
                'offer' => $offer !== '' ? $offer : null,
                'festival_id' => $festival?->id,
                'word_limit' => $wordLimit,
                'draft_count' => $draftCount,
                'platforms' => $allowedPlatforms,
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

        $message = count($posts).' drafts ready — based on your topic. '.$creditsUsed.' credits used.';
        if ($festival) {
            $message = count($posts).' drafts ready — '.$brief.' (+ '.$festival->name.'). '.$creditsUsed.' credits used.';
        }

        return [
            'ok' => true,
            'message' => $message,
            'posts' => $posts,
            'generation' => $generation,
            'cost' => $cost,
        ];
    }

    /**
     * Preview captions without saving drafts or spending credits.
     *
     * @param  array{brief?:string,offer?:string,festival_id?:int|null}  $input
     * @return array{ok:bool,message:string,brief:string,previews:list<array{title:string,body:string,platforms:list<string>}>,provider:string,suggested_brief:bool}
     */
    public function previewTodaysPosts(Workspace $workspace, array $input = []): array
    {
        $settings = $this->settings($workspace);
        $festival = $this->resolveFestival($input['festival_id'] ?? null);
        $offer = trim((string) ($input['offer'] ?? ''));
        $brief = trim((string) ($input['brief'] ?? ''));
        $suggested = false;

        if ($brief === '') {
            $brief = $this->suggestBrief($workspace, $settings, $festival, $offer);
            $suggested = true;
        }

        $allowedPlatforms = $this->publishPlatforms($workspace);
        if ($allowedPlatforms === []) {
            return [
                'ok' => false,
                'message' => 'Enable and connect at least one SMM platform in Settings → Workspace or SMM.',
                'brief' => $brief,
                'suggested_brief' => $suggested,
                'previews' => [],
                'provider' => 'template',
            ];
        }

        $wordLimit = $this->resolveWordLimit($input, $settings);
        $draftCount = $this->resolveDraftCount($input);

        $providerName = 'template';
        $variants = $this->captionVariants($workspace, $settings, $festival, $brief, $offer, $allowedPlatforms, $wordLimit, $draftCount);

        if ($this->shouldUseLive($settings)) {
            $live = $this->liveCaptionVariants($workspace, $settings, $festival, $brief, $offer, $allowedPlatforms, $wordLimit, $draftCount);
            if ($live) {
                $variants = $live['variants'];
                $providerName = $live['provider'];
            }
        }

        return [
            'ok' => true,
            'message' => 'Preview ready — edit the topic if needed, then create drafts.',
            'brief' => $brief,
            'suggested_brief' => $suggested,
            'previews' => $this->previewsWithWordCounts($variants),
            'word_limit' => $wordLimit,
            'provider' => $providerName,
        ];
    }

    /**
     * @param  list<array{title:string,body:string,platforms:list<string>}>  $variants
     * @return list<array{title:string,body:string,platforms:list<string>,word_count:int}>
     */
    private function previewsWithWordCounts(array $variants): array
    {
        return array_map(function (array $variant): array {
            $main = $this->splitCaptionBody((string) ($variant['body'] ?? ''))['main'];
            $variant['word_count'] = $this->wordCount($main);

            return $variant;
        }, $variants);
    }

    private function resolveWordLimit(array $input, WorkspaceAiSetting $settings): int
    {
        $limit = (int) ($input['word_limit'] ?? $settings->caption_word_limit ?? 50);

        return max(25, min(150, $limit));
    }

    private function resolveDraftCount(array $input): int
    {
        return max(1, min(5, (int) ($input['draft_count'] ?? 1)));
    }

    private function wordCount(string $text): int
    {
        preg_match_all('/\S+/u', trim(strip_tags($text)), $matches);

        return count($matches[0] ?? []);
    }

    private function trimToWordLimit(string $text, int $limit): string
    {
        if ($limit < 1) {
            return $text;
        }

        preg_match_all('/\S+/u', $text, $matches, PREG_OFFSET_CAPTURE);
        $words = $matches[0] ?? [];

        if (count($words) <= $limit) {
            return trim($text);
        }

        $last = $words[$limit - 1];
        $end = $last[1] + strlen($last[0]);
        $trimmed = rtrim(substr($text, 0, $end), ".,;:!—- \t\n\r");

        return $trimmed.'…';
    }

    private function assembleCaption(string $main, string $ctaBlock, string $tags, int $wordLimit): string
    {
        $main = $this->trimToWordLimit(trim($main), $wordLimit);

        return implode("\n\n", array_filter([$main, trim($ctaBlock), trim($tags)]));
    }

    /**
     * @return array{main:string,contact:string,hashtags:string}
     */
    private function splitCaptionBody(string $body): array
    {
        $mainLines = [];
        $hashtagParts = [];

        foreach (preg_split("/\r\n|\r|\n/", trim($body)) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (preg_match('/(?:^|\s)#\w/u', $line)) {
                preg_match_all('/#\w+/u', $line, $tags);
                foreach ($tags[0] ?? [] as $tag) {
                    $hashtagParts[] = $tag;
                }

                continue;
            }

            if (preg_match('/^(?:📞|✉️|🌐|📱|☎️)|^(?:Phone|Email|Website|Call|DM|WhatsApp)\b/iu', $line)) {
                continue;
            }

            $mainLines[] = $line;
        }

        return [
            'main' => implode("\n\n", $mainLines),
            'contact' => '',
            'hashtags' => implode(' ', array_unique($hashtagParts)),
        ];
    }

    private function buildTemplateMain(
        string $seed,
        Workspace $workspace,
        WorkspaceAiSetting $settings,
        ?FestivalEvent $festival,
        int $wordLimit,
        int $variantIndex = 0,
    ): string {
        $seed = trim($seed);
        $brand = $workspace->name;
        $loc = $this->primaryLocation($settings->location);
        $industry = $settings->industry ?: 'business';

        $hooks = $festival
            ? [
                "🎉 {$festival->name} special from {$brand}!",
                "✨ How are you celebrating {$festival->name} in {$loc}?",
                "⏰ {$festival->name} limited-period offer at {$brand}",
            ]
            : [
                "✈️ Your next trip from {$loc} starts here!",
                "❓ Planning a break from {$loc}?",
                "🔥 This week's best {$industry} deals",
            ];

        $paragraphs = [
            $hooks[$variantIndex % count($hooks)],
            $seed,
        ];

        $fillers = $festival
            ? [
                "{$brand} curates {$industry} packages for families, couples, and groups — flexible dates and clear pricing.",
                "Celebrate {$festival->name} with a memorable getaway — limited slots for {$loc} travellers.",
                "Custom routes, stays, and transport arranged by our {$loc} team — hassle-free booking.",
            ]
            : [
                "{$brand} curates {$industry} packages for families, couples, and groups — flexible dates and clear pricing.",
                "Weekend escapes, honeymoons, and corporate trips — transparent pricing from {$loc}.",
                "Enquire today — our {$loc} team helps you pick the right package for your budget.",
            ];

        $main = implode("\n\n", $paragraphs);
        $i = 0;
        while ($this->wordCount($main) < $wordLimit && $i < count($fillers)) {
            $paragraphs[] = $fillers[$i];
            $main = implode("\n\n", $paragraphs);
            $i++;
        }

        return $this->trimToWordLimit($main, $wordLimit);
    }

    /**
     * @param  array{title:string,body:string,platforms:list<string>}  $variant
     * @return array{title:string,body:string,platforms:list<string>}
     */
    private function enforceVariantWordLimit(
        array $variant,
        int $wordLimit,
        Workspace $workspace,
        WorkspaceAiSetting $settings,
        string $offer,
        ?FestivalEvent $festival,
    ): array {
        $parsed = $this->splitCaptionBody((string) ($variant['body'] ?? ''));
        $variant['body'] = $this->assembleCaption(
            $parsed['main'],
            $this->ctaBlock($workspace, $offer),
            $this->hashtags($settings, $workspace, $festival),
            $wordLimit,
        );

        return $variant;
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
     * Full SEO blog article (HTML) for CMS drafts.
     *
     * @return array{ok:bool,message:string,article?:array{title:string,body_html:string,meta_title:string,meta_description:string},cost:float,provider?:string}
     */
    public function writeBlogArticle(Workspace $workspace, string $topic, ?int $userId = null): array
    {
        $settings = $this->settings($workspace);
        $useLive = $this->shouldUseLive($settings);
        $topic = trim($topic);

        if ($topic === '') {
            return ['ok' => false, 'message' => 'Enter a blog topic or keyword.', 'cost' => 0];
        }

        $providerName = 'template';
        $article = $this->templateBlogArticle($workspace, $settings, $topic);

        if ($useLive) {
            $live = $this->liveBlogArticle($workspace, $settings, $topic);
            if ($live) {
                $article = $live['article'];
                $providerName = $live['provider'];
            }
        }

        $cost = $this->router->costFor($providerName);

        if (! $this->credits->canSpend($workspace, $cost)) {
            return ['ok' => false, 'message' => 'AI credits exhausted. Recharge from Billing.', 'cost' => 0];
        }

        AiGeneration::query()->create([
            'workspace_id' => $workspace->id,
            'type' => 'blog_article',
            'title' => $article['title'],
            'payload' => array_merge($article, [
                'provider' => $providerName,
                'topic' => $topic,
            ]),
            'status' => 'ready',
        ]);

        $this->logUsage($workspace, $userId, 'blog_article', $cost, $providerName, ['topic' => $topic]);

        return [
            'ok' => true,
            'message' => 'Blog article ready ('.$providerName.')',
            'article' => $article,
            'cost' => $cost,
            'provider' => $providerName,
        ];
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

        return ['ok' => true, 'message' => 'Meta descriptions ready ('.$providerName.')', 'metas' => $metas, 'cost' => $cost];
    }

    private function shouldUseLive(WorkspaceAiSetting $settings): bool
    {
        return ! $settings->template_first && $this->router->anyConfigured();
    }

    /**
     * @return array{variants:list<array{title:string, body:string, platforms:list<string>}>, provider:string}|null
     */
    private function liveCaptionVariants(
        Workspace $workspace,
        WorkspaceAiSetting $settings,
        ?FestivalEvent $festival,
        string $brief,
        string $offer = '',
        array $allowedPlatforms = [],
        int $wordLimit = 50,
        int $draftCount = 1,
    ): ?array {
        $allowedPlatforms = $this->normalizeAllowedPlatforms($allowedPlatforms);
        if ($allowedPlatforms === []) {
            return null;
        }

        $draftCount = max(1, min(5, $draftCount));
        $cta = $this->ctaBlock($workspace, $offer);
        $contactLine = $this->contactPromptLine($workspace);
        $platformList = implode(', ', $allowedPlatforms);
        $tags = $this->hashtags($settings, $workspace, $festival);
        $toneGuide = match ($settings->tone) {
            'hindi' => 'Write in Hindi (Devanagari). Light, conversational.',
            'english' => 'Write in English only. Professional but friendly.',
            default => 'Mix Hindi and English naturally (Hinglish) — common on Indian social media.',
        };

        if ($festival) {
            $angles = implode('; ', array_slice($festival->suggested_angles ?? [], 0, 3));
            $festivalBlock = <<<FEST
PRIMARY CAMPAIGN: {$festival->name} ({$festival->occurs_on?->format('d M Y')})
Every post MUST clearly mention "{$festival->name}" in the opening line.
Tie the client's offer/topic to this festival — wishes, celebration, limited-time deal, or travel mood.
Suggested angles: {$angles}
FEST;
        } else {
            $festivalBlock = 'No festival — write about the client topic only.';
        }

        $system = 'You are an expert social media copywriter for Indian local businesses. Write ready-to-publish captions — never meta-instructions like "highlight your offer". Return ONLY valid JSON.';
        $user = <<<PROMPT
Brand: {$workspace->name}
Industry: {$settings->industry}
Location: {$settings->location}
Language: {$toneGuide}
Client topic / offer: {$brief}
CTA / offer line: {$cta}
{$contactLine}
{$festivalBlock}

Platforms (use these keys exactly): {$platformList}
Example hashtags to include (adapt + add 2 more): {$tags}

Return JSON:
{"posts":[{"title":"short internal label","body":"main caption text only","platforms":["..."]}, ...]}
Exactly {$draftCount} posts. Rules:
- Each body: ONLY the main caption (~{$wordLimit} words — count carefully; do not exceed {$wordLimit})
- Do NOT include phone, email, website, or hashtags in body — we append contact + hashtags automatically after generation
- Structure: hook → 1–2 short paragraphs of engaging copy about the topic
- {$draftCount} different angles (celebration/offer, engagement question, urgency, tip, behind-the-scenes — vary as needed)
- Use 1–2 relevant emojis where natural
- Do NOT repeat the brief verbatim; write polished social copy
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
        foreach (array_slice($posts, 0, $draftCount) as $row) {
            $title = trim((string) ($row['title'] ?? ''));
            $body = trim((string) ($row['body'] ?? ''));
            if ($title === '' || $body === '') {
                continue;
            }
            $platforms = $this->filterPlatforms(
                is_array($row['platforms'] ?? null) ? $row['platforms'] : ['instagram'],
                $allowedPlatforms,
            );
            if ($platforms === []) {
                continue;
            }
            $out[] = $this->enforceVariantWordLimit([
                'title' => Str::limit($title, 120, ''),
                'body' => $body,
                'platforms' => $platforms,
            ], $wordLimit, $workspace, $settings, $offer, $festival);
        }

        if (! count($out)) {
            return null;
        }

        $out = $this->ensureVariantCount($out, $workspace, $settings, $brief, $offer, $festival, $allowedPlatforms, $wordLimit, $draftCount);

        return ['variants' => array_slice($out, 0, $draftCount), 'provider' => $completion->provider];
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
    private function captionVariants(
        Workspace $workspace,
        WorkspaceAiSetting $settings,
        ?FestivalEvent $festival,
        string $brief,
        string $offer = '',
        array $allowedPlatforms = [],
        int $wordLimit = 50,
        int $draftCount = 1,
    ): array {
        $allowedPlatforms = $this->normalizeAllowedPlatforms($allowedPlatforms);
        if ($allowedPlatforms === []) {
            return [];
        }

        $draftCount = max(1, min(5, $draftCount));
        $brief = $this->normalizeBrief($brief, $workspace, $settings, $festival, $offer);
        $cta = $this->ctaBlock($workspace, $offer);
        $tags = $this->hashtags($settings, $workspace, $festival);
        $brand = $workspace->name;

        $visual = $this->filterPlatforms(['instagram', 'facebook', 'threads'], $allowedPlatforms);
        $threadsOnly = $this->filterPlatforms(['threads'], $allowedPlatforms);
        $social = $this->filterPlatforms(['instagram', 'facebook', 'threads', 'x'], $allowedPlatforms);

        $candidates = [];
        $variantIndex = 0;

        if ($festival) {
            $festName = $festival->name;

            if ($visual !== []) {
                $candidates[] = [
                    'title' => "{$festName} — {$brand}",
                    'body' => $this->assembleCaption(
                        $this->buildTemplateMain($brief, $workspace, $settings, $festival, $wordLimit, $variantIndex++),
                        $cta,
                        $tags,
                        $wordLimit,
                    ),
                    'platforms' => $visual,
                ];
            }

            if ($social !== []) {
                $candidates[] = [
                    'title' => "{$festName} — ask your audience",
                    'body' => $this->assembleCaption(
                        $this->buildTemplateMain($brief, $workspace, $settings, $festival, $wordLimit, $variantIndex++),
                        $cta,
                        $tags,
                        $wordLimit,
                    ),
                    'platforms' => $social,
                ];
            }

            $offerPlatforms = $threadsOnly !== [] ? $threadsOnly : $visual;
            if ($offerPlatforms !== []) {
                $candidates[] = [
                    'title' => "{$festName} limited offer",
                    'body' => $this->assembleCaption(
                        $this->buildTemplateMain($brief, $workspace, $settings, $festival, $wordLimit, $variantIndex++),
                        $cta,
                        $tags,
                        $wordLimit,
                    ),
                    'platforms' => $offerPlatforms,
                ];
            }
        } else {
            if ($visual !== []) {
                $candidates[] = [
                    'title' => Str::limit($brief, 55, '…'),
                    'body' => $this->assembleCaption(
                        $this->buildTemplateMain($brief, $workspace, $settings, $festival, $wordLimit, $variantIndex++),
                        $cta,
                        $tags,
                        $wordLimit,
                    ),
                    'platforms' => $visual,
                ];
            }

            if ($social !== []) {
                $candidates[] = [
                    'title' => 'Question for your feed',
                    'body' => $this->assembleCaption(
                        $this->buildTemplateMain($brief, $workspace, $settings, $festival, $wordLimit, $variantIndex++),
                        $cta,
                        $tags,
                        $wordLimit,
                    ),
                    'platforms' => $social,
                ];
            }

            $offerPlatforms = $threadsOnly !== [] ? $threadsOnly : $visual;
            if ($offerPlatforms !== []) {
                $candidates[] = [
                    'title' => 'Offer highlight',
                    'body' => $this->assembleCaption(
                        $this->buildTemplateMain($brief, $workspace, $settings, $festival, $wordLimit, $variantIndex++),
                        $cta,
                        $tags,
                        $wordLimit,
                    ),
                    'platforms' => $offerPlatforms,
                ];
            }
        }

        return $this->ensureVariantCount($candidates, $workspace, $settings, $brief, $offer, $festival, $allowedPlatforms, $wordLimit, $draftCount);
    }

    /**
     * Enabled workspace platforms, limited to connected accounts when any exist.
     *
     * @return list<string>
     */
    public function publishPlatforms(Workspace $workspace): array
    {
        $enabled = SocialPlatforms::enabled($workspace->enabled_social_platforms);

        $connected = SocialAccount::query()
            ->where('workspace_id', $workspace->id)
            ->where('status', 'connected')
            ->pluck('platform')
            ->unique()
            ->values()
            ->all();

        $connected = array_values(array_intersect($connected, SocialPlatforms::keys()));

        if ($connected !== []) {
            return array_values(array_intersect($enabled, $connected));
        }

        return $enabled;
    }

    /**
     * @param  list<string>  $platforms
     * @param  list<string>  $allowed
     * @return list<string>
     */
    private function filterPlatforms(array $platforms, array $allowed): array
    {
        $allowed = $this->normalizeAllowedPlatforms($allowed);

        return array_values(array_intersect($platforms, $allowed));
    }

    /**
     * @param  list<string>  $platforms
     * @return list<string>
     */
    private function normalizeAllowedPlatforms(array $platforms): array
    {
        return array_values(array_intersect(
            SocialPlatforms::normalize($platforms) ?? [],
            SocialPlatforms::keys(),
        ));
    }

    /**
     * @param  list<array{title:string,body:string,platforms:list<string>}>  $variants
     * @param  list<string>  $allowedPlatforms
     * @return list<array{title:string,body:string,platforms:list<string>}>
     */
    private function ensureVariantCount(
        array $variants,
        Workspace $workspace,
        WorkspaceAiSetting $settings,
        string $brief,
        string $offer,
        ?FestivalEvent $festival,
        array $allowedPlatforms,
        int $wordLimit = 50,
        int $draftCount = 1,
    ): array {
        $draftCount = max(1, min(5, $draftCount));

        $variants = array_values(array_filter(
            $variants,
            fn (array $v) => ($v['platforms'] ?? []) !== [] && trim((string) ($v['body'] ?? '')) !== '',
        ));

        $cta = $this->ctaBlock($workspace, $offer);
        $tags = $this->hashtags($settings, $workspace, $festival);
        $allowedPlatforms = $this->normalizeAllowedPlatforms($allowedPlatforms);
        $brief = $this->normalizeBrief($brief, $workspace, $settings, $festival, $offer);

        while (count($variants) < $draftCount && $allowedPlatforms !== []) {
            $index = count($variants);
            $platform = $allowedPlatforms[$index % count($allowedPlatforms)];
            $group = $this->filterPlatforms(
                $index === 0 ? ['instagram', 'facebook'] : ($index === 1 ? ['threads'] : [$platform]),
                $allowedPlatforms,
            ) ?: [$platform];

            $festLead = $festival ? "🎉 {$festival->name} — " : '';
            $titles = [
                Str::limit($festLead.$brief, 60, '…'),
                $festival ? "{$festival->name} — engagement" : 'Tip of the day',
                'More from '.$workspace->name,
                $festival ? "{$festival->name} — quick tip" : 'Behind the brand',
                $festival ? "{$festival->name} — last call" : 'Limited offer',
            ];

            $variants[] = [
                'title' => $titles[$index] ?? ($titles[$index % count($titles)]),
                'body' => $this->assembleCaption(
                    $this->buildTemplateMain($brief, $workspace, $settings, $festival, $wordLimit, $index),
                    $cta,
                    $tags,
                    $wordLimit,
                ),
                'platforms' => $group,
            ];
        }

        return array_map(
            fn (array $variant) => $this->enforceVariantWordLimit($variant, $wordLimit, $workspace, $settings, $offer, $festival),
            array_slice($variants, 0, $draftCount),
        );
    }

    private function suggestBrief(
        Workspace $workspace,
        WorkspaceAiSetting $settings,
        ?FestivalEvent $festival,
        string $offer = '',
    ): string {
        $loc = $this->primaryLocation($settings->location);
        $industry = $settings->industry ?: 'business';

        if ($festival) {
            $angle = $festival->suggested_angles[0] ?? 'limited-time deal';

            if ($offer !== '') {
                return "{$festival->name} special — {$offer} on {$industry} packages from {$loc}";
            }

            return "{$festival->name} travel packages from {$loc} — {$angle}";
        }

        if ($offer !== '') {
            return "{$offer} — {$industry} services in {$loc}";
        }

        return "Weekend getaways & {$industry} packages from {$loc} — best deals this week";
    }

    /**
     * Turn meta-instruction briefs into post-ready copy.
     */
    private function normalizeBrief(
        string $brief,
        Workspace $workspace,
        WorkspaceAiSetting $settings,
        ?FestivalEvent $festival,
        string $offer = '',
    ): string {
        $brief = trim($brief);
        if ($brief === '') {
            return $this->suggestBrief($workspace, $settings, $festival, $offer);
        }

        if (preg_match('/highlight your best|this week at|enter your topic/i', $brief)) {
            return $this->suggestBrief($workspace, $settings, $festival, $offer);
        }

        return $brief;
    }

    private function primaryLocation(?string $location): string
    {
        $location = trim((string) $location);
        if ($location === '') {
            return 'your city';
        }

        $parts = preg_split('/[,·|]+/', $location);

        return trim($parts[0] ?? $location);
    }

    private function contactFooter(Workspace $workspace): string
    {
        $lines = [];

        if ($phone = $workspace->resolvedPhone()) {
            $lines[] = "📞 {$phone}";
        }

        if ($email = $workspace->resolvedEmail()) {
            $lines[] = "✉️ {$email}";
        }

        if ($website = $workspace->resolvedWebsite()) {
            $lines[] = "🌐 {$website}";
        }

        return implode("\n", $lines);
    }

    private function ctaBlock(Workspace $workspace, string $offer = ''): string
    {
        $lines = [];

        if ($offer !== '') {
            $lines[] = $offer;
        } else {
            $label = trim((string) ($workspace->resolveBrandKit()?->default_cta_label ?? ''));
            if ($label !== '') {
                $lines[] = $label;
            }
        }

        $contact = $this->contactFooter($workspace);
        if ($contact !== '') {
            $lines[] = $contact;
        } elseif ($lines === []) {
            $lines[] = 'DM us to book';
        }

        return implode("\n", $lines);
    }

    private function contactPromptLine(Workspace $workspace): string
    {
        $contact = $workspace->contactDetails();
        $parts = array_values(array_filter([
            $contact['phone'] ? "Phone: {$contact['phone']}" : null,
            $contact['email'] ? "Email: {$contact['email']}" : null,
            $contact['website'] ? "Website: {$contact['website']}" : null,
        ]));

        if ($parts === []) {
            return 'Contact: not provided — use a generic CTA only.';
        }

        return 'Contact (MUST appear in every post footer): '.implode(' · ', $parts);
    }

    private function hashtags(WorkspaceAiSetting $settings, ?Workspace $workspace = null, ?FestivalEvent $festival = null): string
    {
        $packs = $settings->hashtag_packs ?? [];
        $tags = array_merge($packs['general'] ?? [], $packs['local'] ?? []);

        if ($festival) {
            $festTag = '#'.preg_replace('/[^a-zA-Z0-9]+/', '', $festival->name);
            if ($festTag !== '#') {
                $tags[] = $festTag;
            }
        }

        $industry = preg_replace('/[^a-zA-Z0-9]+/', '', $settings->industry ?? '');
        if ($industry !== '') {
            $tags[] = '#'.ucfirst(strtolower($industry));
        }

        foreach (preg_split('/[,·|]+/', $settings->location ?? '') as $city) {
            $city = trim($city);
            if ($city !== '') {
                $tags[] = '#'.preg_replace('/[^a-zA-Z0-9]+/', '', $city);
            }
        }

        if ($workspace) {
            $brandTag = '#'.preg_replace('/[^a-zA-Z0-9]+/', '', $workspace->name);
            if (strlen($brandTag) > 2) {
                $tags[] = $brandTag;
            }
        }

        $tags = array_values(array_unique(array_filter($tags)));

        if ($tags === []) {
            $tags = ['#SmallBusiness', '#India', '#Travel'];
        }

        return implode(' ', array_slice($tags, 0, 8));
    }

    private function liveBlogArticle(Workspace $workspace, WorkspaceAiSetting $settings, string $topic): ?array
    {
        $cta = $workspace->resolveBrandKit()?->default_cta_label ?: 'Get started';
        $contact = collect([
            $workspace->phone,
            $workspace->email,
            $workspace->website,
        ])->filter()->implode(' · ');

        $toneGuide = match ($settings->tone) {
            'hindi' => 'Write mainly in Hindi (Devanagari), clear and practical.',
            'english' => 'Write in English only — professional, friendly, SEO-aware.',
            default => 'Write in natural Hinglish / English mix suited for Indian readers.',
        };

        $system = 'You are an SEO content writer for Indian local businesses. Return ONLY valid JSON with a ready-to-publish blog article.';
        $user = <<<PROMPT
Topic / keyword: {$topic}
Brand: {$workspace->name}
Industry: {$settings->industry}
Location: {$settings->location}
Language: {$toneGuide}
CTA: {$cta}
Contact (optional mention near end): {$contact}

Return JSON:
{
  "title": "SEO title under 70 chars",
  "meta_title": "meta title under 60 chars",
  "meta_description": "meta description under 155 chars",
  "sections": [
    {"heading": "H2 heading", "paragraphs": ["paragraph 1", "paragraph 2"]},
    {"heading": "...", "paragraphs": ["..."]}
  ]
}

Rules:
- 4–6 sections, intro first (can use heading "Introduction" or a stronger hook)
- Each section: 2–3 short paragraphs, practical and specific to {$settings->location} / {$settings->industry}
- Mention the brand naturally once near the end with a soft CTA
- No markdown, no HTML tags inside strings — plain text only
- Do not invent fake statistics or awards
PROMPT;

        $completion = $this->router->complete($system, $user, 2200);
        if (! $completion->ok) {
            return null;
        }

        $json = $this->extractJson($completion->text);
        if (! is_array($json) || blank($json['title'] ?? null) || ! is_array($json['sections'] ?? null)) {
            return null;
        }

        $article = $this->normalizeBlogArticlePayload($json, $workspace, $settings, $topic);
        if ($article === null) {
            return null;
        }

        return ['article' => $article, 'provider' => $completion->provider];
    }

    /**
     * @return array{title:string,body_html:string,meta_title:string,meta_description:string}
     */
    private function templateBlogArticle(Workspace $workspace, WorkspaceAiSetting $settings, string $topic): array
    {
        $brand = $workspace->name;
        $loc = $settings->location ?: 'India';
        $industry = $settings->industry ?: 'business';
        $cta = $workspace->resolveBrandKit()?->default_cta_label ?: 'Get started';
        $title = Str::limit($topic.' — practical guide for '.$loc, 70, '');

        $sections = [
            [
                'heading' => 'Why '.$topic.' matters',
                'paragraphs' => [
                    $topic.' is a common search for people looking for reliable '.$industry.' help in '.$loc.'. Clear answers build trust before someone calls you.',
                    'This guide covers what customers ask most, mistakes to avoid, and how '.$brand.' approaches the work.',
                ],
            ],
            [
                'heading' => 'What customers usually want',
                'paragraphs' => [
                    'People searching for '.$topic.' want practical steps, honest pricing cues, and a local team they can reach quickly.',
                    'Focus on outcomes: timelines, what is included, and how you support them after the first conversation.',
                ],
            ],
            [
                'heading' => '3 practical tips',
                'paragraphs' => [
                    'Start with a clear offer tied to '.$topic.' — one page, one promise, one next step.',
                    'Use local language and city cues so readers in '.$loc.' know you serve them.',
                    'End every article with a simple CTA so interested readers can contact '.$brand.' without hunting for details.',
                ],
            ],
            [
                'heading' => 'How '.$brand.' helps',
                'paragraphs' => [
                    $brand.' works with '.$industry.' clients who need dependable support around '.$topic.'.',
                    'Ready to talk? '.$cta.'. Reach out and we will map the next steps for your goals in '.$loc.'.',
                ],
            ],
        ];

        return $this->normalizeBlogArticlePayload([
            'title' => $title,
            'meta_title' => Str::limit($title, 60, ''),
            'meta_description' => Str::limit(
                $topic.' — practical guide for '.$industry.' in '.$loc.' from '.$brand.'.',
                155,
                ''
            ),
            'sections' => $sections,
        ], $workspace, $settings, $topic) ?? [
            'title' => $title,
            'body_html' => '<p>'.e($topic).'</p>',
            'meta_title' => Str::limit($title, 60, ''),
            'meta_description' => Str::limit($topic, 155, ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{title:string,body_html:string,meta_title:string,meta_description:string}|null
     */
    private function normalizeBlogArticlePayload(
        array $payload,
        Workspace $workspace,
        WorkspaceAiSetting $settings,
        string $topic,
    ): ?array {
        $title = trim((string) ($payload['title'] ?? $payload['h1'] ?? $topic));
        if ($title === '') {
            return null;
        }

        $sections = $payload['sections'] ?? [];
        if (! is_array($sections) || $sections === []) {
            return null;
        }

        $html = '';
        foreach ($sections as $section) {
            if (is_string($section)) {
                $html .= '<h2>'.e($section).'</h2><p></p>';
                continue;
            }
            if (! is_array($section)) {
                continue;
            }

            $heading = trim((string) ($section['heading'] ?? $section['title'] ?? ''));
            if ($heading !== '') {
                $html .= '<h2>'.e($heading).'</h2>';
            }

            $paragraphs = $section['paragraphs'] ?? $section['body'] ?? [];
            if (is_string($paragraphs)) {
                $paragraphs = [$paragraphs];
            }
            if (! is_array($paragraphs)) {
                $paragraphs = [];
            }

            foreach ($paragraphs as $paragraph) {
                $text = trim((string) $paragraph);
                if ($text === '') {
                    continue;
                }
                $html .= '<p>'.e($text).'</p>';
            }

            if (! empty($section['bullets']) && is_array($section['bullets'])) {
                $html .= '<ul>';
                foreach ($section['bullets'] as $bullet) {
                    $html .= '<li>'.e((string) $bullet).'</li>';
                }
                $html .= '</ul>';
            }
        }

        if (trim(strip_tags($html)) === '') {
            return null;
        }

        $metaTitle = trim((string) ($payload['meta_title'] ?? $title));
        $metaDescription = trim((string) ($payload['meta_description'] ?? ''));
        if ($metaDescription === '') {
            $metaDescription = $topic.' — guide for '.($settings->industry ?: 'businesses').' in '.($settings->location ?: 'India').'.';
        }

        return [
            'title' => Str::limit($title, 120, ''),
            'body_html' => $html,
            'meta_title' => Str::limit($metaTitle, 60, ''),
            'meta_description' => Str::limit($metaDescription, 155, ''),
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
        $industry = $settings->industry ?: 'business';

        return [
            "{$pageTitle} | {$brand} — trusted {$industry} help in {$loc}.",
            "Looking for {$pageTitle}? {$brand} delivers clear results for local businesses in {$loc}.",
            "{$brand}: {$pageTitle}. Book a free consult today.",
        ];
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
