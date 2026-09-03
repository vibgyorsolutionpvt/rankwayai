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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
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
     * Fill SMM compose fields from a user prompt + workspace/brand settings.
     * Does not create a SocialPost — caller fills the form.
     *
     * @param  array{prompt:string,offer?:string,platforms?:list<string>}  $input
     * @return array{ok:bool,message:string,draft?:array{title:string,body:string,platforms:list<string>},cost:float}
     */
    public function composeSocialFromPrompt(Workspace $workspace, ?int $userId = null, array $input = []): array
    {
        $settings = $this->syncSettingsFromWorkspace($workspace);
        $prompt = trim((string) ($input['prompt'] ?? ''));
        $offer = trim((string) ($input['offer'] ?? ''));

        if (mb_strlen($prompt) < 12) {
            return [
                'ok' => false,
                'message' => 'Write a clear prompt (what to post about) — at least a short sentence.',
                'cost' => 0,
            ];
        }

        $allowedPlatforms = $this->publishPlatforms($workspace);
        $requested = is_array($input['platforms'] ?? null) ? $input['platforms'] : [];
        $platforms = $requested !== []
            ? $this->filterPlatforms($requested, $allowedPlatforms !== [] ? $allowedPlatforms : SocialPlatforms::keys())
            : ($allowedPlatforms !== [] ? $allowedPlatforms : ['instagram', 'facebook']);

        if ($platforms === []) {
            $platforms = ['instagram'];
        }

        // Compose needs room for a professional hook + value + CTA (min ~70 words of main copy).
        $wordLimit = max(70, $this->resolveWordLimit($input, $settings));
        $industry = trim((string) ($settings->industry ?? $workspace->resolvedIndustry() ?? ''));
        $location = trim((string) ($settings->location ?? $workspace->resolvedCity() ?? ''));
        $cta = $offer !== ''
            ? $offer
            : trim((string) ($workspace->resolveBrandKit()?->default_cta_label ?? 'Get in touch'));
        $contact = $this->contactFooter($workspace);
        $platformList = implode(', ', $platforms);
        $platformJsonHint = json_encode(array_values($platforms));

        $providerName = 'template';
        $draft = null;
        $liveError = null;
        $apiLog = [
            'provider' => 'template',
            'api_url' => null,
            'model' => null,
            'http_status' => null,
            'tokens' => 0,
            'ok' => false,
            'error' => null,
            'request' => null,
            'response' => null,
            'response_text' => null,
            'attempts' => [],
        ];

        // Compose is interactive — prefer a real LLM whenever configured (ignore template_first).
        if ($this->router->anyConfigured()) {
            $toneGuide = match ($settings->tone) {
                'hindi' => 'Body in clear Hindi (Devanagari). Professional, warm, not slangy.',
                'english' => 'Body in polished English only. Confident B2B/local-business tone.',
                default => 'Mostly polished English; light Hinglish only if it sounds natural for LinkedIn/Instagram India — never slangy.',
            };
            $topicHint = $this->extractComposeTopic($prompt);
            $website = $workspace->resolvedWebsite() ?: 'not set';
            $industrySpoken = $this->industrySpokenLabel($industry);
            $offerings = $this->resolveBrandOfferings($workspace);
            $offeringsBlock = $offerings['summary'] !== ''
                ? $offerings['summary']
                : 'No website summary available — only use industry "'.$industrySpoken.'" and location "'.$location.'". Do NOT invent SLAs, prices, or fake products.';
            $servicesList = $offerings['services'] !== []
                ? implode(', ', array_slice($offerings['services'], 0, 12))
                : $industrySpoken;
            $isServicesOverview = $topicHint === '' || $this->promptLooksLikeServicesOverview($prompt);
            if ($isServicesOverview) {
                $wordLimit = max(180, $wordLimit);
            }
            $vagueNote = $isServicesOverview
                ? 'User wants a SERVICES showcase post (like a strong ChatGPT social caption). List MANY real offerings (6–10) from Brand offerings — NOT a short cloud-only paragraph.'
                : 'Focus the post on this angle IF it matches Brand offerings: '.$topicHint.' — if it does not match, stay inside Brand offerings.';

            $system = <<<'SYS'
You are an elite Instagram/Facebook copywriter for Indian IT & digital agencies.
Return ONLY valid JSON with keys title, body, platforms.
When the user asks to mention services, write a scroll-stopping showcase post with a clear bullet list — not a vague essay.
GROUNDING: Prefer Brand offerings. For tech/IT digital agencies you may use the listed service names. Never invent SLAs, prices, or fake metrics.
SYS;
            $overviewRules = $isServicesOverview
                ? <<<OVR
OVERVIEW / SERVICES SHOWCASE MODE (match this quality bar):
Title: punchy, benefit-led (max ~70 chars). Example vibe: "Smart Digital Solutions for Growing Businesses"
Body MUST use this structure (plain text with newlines, emoji bullets OK):
1) Hook line with 1 emoji (energy, not cringe)
2) One short intro line naming {$workspace->name}
3) A blank line, then 6–10 service bullets like:
✅ Website & Web Application Development
✅ Mobile App Development
✅ … (only from Known service names / Brand offerings)
4) One short closing line for startups/SMEs
5) Soft CTA: {$cta}
FORBIDDEN: a single dense paragraph that only mentions cloud/security/marketing without a bullet list
FORBIDDEN: inventing "48-hour SLA", "dedicated engineer", fake stats
OVR
                : <<<'OVR'
SINGLE-ANGLE MODE:
- Title + body may deep-dive ONE real offering the user asked for
- Still stay inside Brand offerings
OVR;

            $qualityBar = $this->composeQualityStandards($industry, $location, $workspace->name, $cta, $wordLimit);

            $fewShot = $isServicesOverview
                ? <<<SHOT
Few-shot style to imitate (replace with THIS brand's real services):
Title: "Powering Businesses with Smart Digital Solutions"
Body:
"🚀 Powering businesses with smart digital solutions!

At {$workspace->name}, we help teams grow and build a stronger digital presence with reliable technology.

✅ Service One
✅ Service Two
✅ Service Three
✅ Service Four
✅ Service Five
✅ Service Six

Whether you're a startup or a growing enterprise, we build tech that moves you forward.

{$cta}"
SHOT
                : '';

            $user = <<<PROMPT
Brand name: {$workspace->name}
Website / domain: {$website}
Industry category: {$industry}
Speak about the industry as: {$industrySpoken}
Location (workspace): {$location}
Language: {$toneGuide}
Audience: owners / managers in {$location}

BRAND OFFERINGS (source of truth):
{$offeringsBlock}
Known service names (use these in bullets): {$servicesList}

User brief (intent only — do NOT copy or quote): {$prompt}
{$vagueNote}
{$overviewRules}
{$fewShot}
Soft CTA: {$cta}
Platforms: {$platformList}

{$qualityBar}

Return JSON only:
{"title":"...","body":"...","platforms":{$platformJsonHint}}
PROMPT;

            $completion = $this->router->complete($system, $user, 1400);
            $apiLog = $completion->toLog();
            if ($completion->ok) {
                $json = $this->extractJson($completion->text);
                $title = trim((string) ($json['title'] ?? ''));
                $body = trim((string) ($json['body'] ?? ''));
                // Models often put literal newlines inside JSON strings — recover fields if decode failed.
                if (($title === '' || $body === '') && is_string($completion->text)) {
                    $recovered = $this->extractJsonFieldsLoose($completion->text);
                    if ($title === '' && ($recovered['title'] ?? '') !== '') {
                        $title = $recovered['title'];
                    }
                    if ($body === '' && ($recovered['body'] ?? '') !== '') {
                        $body = $recovered['body'];
                    }
                }
                if ($title !== '' && $body !== '') {
                    $plats = $this->filterPlatforms(
                        is_array($json['platforms'] ?? null) ? $json['platforms'] : $platforms,
                        $platforms,
                    );
                    $draft = $this->sanitizeComposeDraft([
                        'title' => $title,
                        'body' => $body,
                        'platforms' => $plats !== [] ? $plats : $platforms,
                    ], $prompt, $workspace, $settings, $offer, $wordLimit);
                    $providerName = $completion->provider;
                    $apiLog['ok'] = true;
                } else {
                    $liveError = 'LLM returned empty title/body JSON';
                    $apiLog['ok'] = false;
                    $apiLog['error'] = $liveError;
                }
            } else {
                $liveError = $completion->error ?: 'LLM request failed';
            }
        }

        if ($draft === null) {
            $draft = $this->templateComposeDraft($workspace, $settings, $prompt, $offer, $platforms, $wordLimit);
            $providerName = 'template';
            $apiLog['provider'] = 'template';
            if (($apiLog['error'] ?? null) === null && $liveError) {
                $apiLog['error'] = $liveError;
            }
        } else {
            $apiLog['provider'] = $providerName;
        }

        $cost = $this->router->costFor($providerName);
        if (! $this->credits->canSpend($workspace, $cost)) {
            return [
                'ok' => false,
                'message' => 'AI credits exhausted. Recharge credits from Billing to continue.',
                'cost' => 0,
            ];
        }

        // Append contact + hashtags if not already present (templates may already include).
        $body = trim((string) $draft['body']);
        if ($contact !== '' && ! str_contains($body, $workspace->resolvedPhone() ?? '___')) {
            // enforceVariantWordLimit usually appends; keep body as returned.
        }

        AiGeneration::query()->create([
            'workspace_id' => $workspace->id,
            'type' => 'social_compose',
            'title' => $draft['title'],
            'payload' => [
                'prompt' => $prompt,
                'offer' => $offer !== '' ? $offer : null,
                'provider' => $providerName,
                'industry' => $industry,
                'location' => $location,
                'live_error' => $liveError,
                'draft' => $draft,
                'api' => [
                    'api_url' => $apiLog['api_url'] ?? null,
                    'model' => $apiLog['model'] ?? null,
                    'http_status' => $apiLog['http_status'] ?? null,
                    'tokens' => $apiLog['tokens'] ?? 0,
                    'attempts' => $apiLog['attempts'] ?? [],
                ],
            ],
            'status' => 'ready',
        ]);

        $this->logUsage($workspace, $userId, 'social_compose', $cost, $providerName, [
            'prompt' => Str::limit($prompt, 120, ''),
            'api_url' => $apiLog['api_url'] ?? null,
        ]);

        return [
            'ok' => true,
            'message' => $providerName === 'template'
                ? 'Draft ready (template fallback'.($liveError ? ': '.$liveError : '').'). Check AI keys if this keeps happening.'
                : 'Caption ready via '.$providerName.' — review tone, then save.',
            'draft' => [
                'title' => $draft['title'],
                'body' => $draft['body'],
                'platforms' => $draft['platforms'],
            ],
            'cost' => $cost,
            'provider' => $providerName,
            'api' => array_merge($apiLog, ['draft' => $draft]),
            'context' => [
                'industry' => $industry,
                'location' => $location,
                'cta' => $cta,
                'has_contact' => $contact !== '',
            ],
        ];
    }

    /**
     * @param  list<string>  $platforms
     * @return array{title:string,body:string,platforms:list<string>}
     */
    private function templateComposeDraft(
        Workspace $workspace,
        WorkspaceAiSetting $settings,
        string $prompt,
        string $offer,
        array $platforms,
        int $wordLimit,
    ): array {
        $title = $this->composePostTitle($prompt, $workspace, $settings, $offer);
        $main = $this->buildComposeMain($prompt, $workspace, $settings, $offer, $wordLimit);

        return $this->enforceVariantWordLimit([
            'title' => $title,
            'body' => $main,
            'platforms' => $platforms,
        ], $wordLimit, $workspace, $settings, $offer, null);
    }

    /**
     * Quality bar shared by live LLM + validation.
     */
    private function composeQualityStandards(
        string $industry,
        string $location,
        string $brand,
        string $cta,
        int $wordLimit = 70,
    ): string {
        $industry = $industry !== '' ? $industry : 'business';
        $location = $location !== '' ? $location : 'India';

        return <<<STD
PROFESSIONAL COPY STANDARDS (non-negotiable):
VOICE
- Sound like a trusted consultant, not a brochure or casual meme page
- Confident, specific, calm — zero hype, zero fake urgency
- Max 1 emoji in the whole caption (optional). Prefer none in the title.

TITLE
- 5–9 words; benefit or tension headline (gain / pain removed)
- FORBIDDEN: user prompt paste; "likho/write a post"; raw CTA ("{$cta}"); weak "{$industry} with {$brand}"; "{$brand} in {$location} — {$industry}"
- Good: "Stop Paying for Cloud Chaos", "IT Support That Answers in Hours", "{$industry} Clarity for {$location} Owners"
- Bad: "{$cta} — {$brand}", "Growing your business with us"

BODY (~{$wordLimit} words main caption; contact/hashtags appended later)
Problem → Insight → Proof/Process → Soft CTA:
1) Hook: real friction in {$industry} / {$location}
2) Insight: what most teams get wrong
3) How {$brand} helps: 2 concrete mechanisms (scope, owners, timeline, risk) — not "we help you grow"
4) Close with soft CTA using "{$cta}"

HARD BANS:
- "we help businesses grow", "one-stop solution", "best in class", "synergy", "leverage", "game-changer"
- "looking for reliable…?", "your success is our priority", "feel free to reach out"
- Invented SLAs / headcount / prices (e.g. "48-hour SLA", "dedicated engineer") unless in Brand offerings
- Quoting the user brief; phone/email/website/hashtags in body

Stay 100% inside "{$industry}" AND Brand offerings.
STD;
    }

    /**
     * Pull real offerings from the workspace website (meta + keywords). Cached.
     *
     * @return array{summary:string,services:list<string>,source:?string}
     */
    public function resolveBrandOfferings(Workspace $workspace): array
    {
        $website = $workspace->resolvedWebsite();
        $industry = $this->industrySpokenLabel((string) ($workspace->resolvedIndustry() ?? ''));
        $location = (string) ($workspace->resolvedCity() ?? '');
        $fallbackServices = $this->defaultServicesForIndustry($industry);

        if (! $website) {
            $summary = $fallbackServices === []
                ? ''
                : 'Known services (from industry settings only): '.implode(', ', $fallbackServices).'.';

            return ['summary' => $summary, 'services' => $fallbackServices, 'source' => null];
        }

        $cacheKey = 'brand_offerings:v3:'.md5(mb_strtolower($website));

        return Cache::remember($cacheKey, now()->addHours(24), function () use ($website, $fallbackServices, $workspace, $industry, $location) {
            $scraped = $this->scrapeWebsiteOfferings($website);
            // Curated defaults first (showcase quality), then site-specific names.
            $services = $this->normalizeServiceLabels(array_merge($fallbackServices, $scraped['services']));
            $desc = $scraped['description'];
            $title = $scraped['title'];

            $lines = [
                "Brand: {$workspace->name}",
                "Website: {$website}",
            ];
            if ($title !== '') {
                $lines[] = "Site title: {$title}";
            }
            if ($desc !== '') {
                $lines[] = "Site description: {$desc}";
            }
            if ($services !== []) {
                $lines[] = 'Services / offerings: '.implode(', ', $services);
            }
            if ($location !== '') {
                $lines[] = "Primary market: {$location}";
            }
            if ($industry !== '') {
                $lines[] = "Industry: {$industry}";
            }
            $lines[] = 'Do not invent SLAs, prices, or metrics. Prefer this service list for showcase posts.';

            return [
                'summary' => implode("\n", $lines),
                'services' => array_slice($services, 0, 12),
                'source' => $website,
            ];
        });
    }

    /**
     * @return array{title:string,description:string,services:list<string>}
     */
    private function scrapeWebsiteOfferings(string $website): array
    {
        $empty = ['title' => '', 'description' => '', 'services' => []];

        try {
            $response = Http::timeout(8)
                ->withHeaders(['User-Agent' => 'RankwayAIBrandBot/1.0'])
                ->get($website);
        } catch (\Throwable) {
            return $empty;
        }

        if (! $response->successful()) {
            return $empty;
        }

        $html = (string) $response->body();
        if ($html === '') {
            return $empty;
        }

        $title = '';
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
            $title = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        $description = '';
        if (preg_match('/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)
            || preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+name=["\']description["\']/i', $html, $m)) {
            $description = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        $keywords = '';
        if (preg_match('/<meta[^>]+name=["\']keywords["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)
            || preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+name=["\']keywords["\']/i', $html, $m)) {
            $keywords = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        $blob = mb_strtolower(strip_tags($title.' '.$description.' '.$keywords.' '.$html));
        // Proper showcase labels only — short tokens map via normalizeServiceLabels().
        $catalog = [
            'Website & Web Application Development',
            'Mobile App Development',
            'AI & Automation Solutions',
            'SEO & Digital Marketing',
            'Social Media Management',
            'WhatsApp Business API',
            'Cloud & Hosting Solutions',
            'Cybersecurity Solutions',
            'E-Commerce Solutions',
            'API & Third-Party Integrations',
            'Custom Business Software & CRM',
            'Billing Software',
            'School Management Software',
            'Local Business Listing',
        ];
        $found = [];
        foreach ($catalog as $service) {
            $needle = mb_strtolower($service);
            $parts = preg_split('/\s*&\s*|\s+/u', $needle) ?: [];
            $hit = str_contains($blob, $needle);
            if (! $hit && count($parts) >= 2) {
                $hit = str_contains($blob, $parts[0]) && str_contains($blob, $parts[min(1, count($parts) - 1)]);
            }
            // Extra keyword hits for short page copy
            if (! $hit) {
                $hit = match (true) {
                    str_contains($service, 'Website') && (str_contains($blob, 'web develop') || str_contains($blob, 'website')) => true,
                    str_contains($service, 'Mobile') && (str_contains($blob, 'mobile app') || str_contains($blob, 'android') || str_contains($blob, 'ios')) => true,
                    str_contains($service, 'WhatsApp') && str_contains($blob, 'whatsapp') => true,
                    str_contains($service, 'SEO') && (str_contains($blob, 'seo') || str_contains($blob, 'digital marketing')) => true,
                    str_contains($service, 'Cloud') && (str_contains($blob, 'cloud') || str_contains($blob, 'hosting')) => true,
                    str_contains($service, 'Cyber') && str_contains($blob, 'cyber') => true,
                    str_contains($service, 'E-Commerce') && (str_contains($blob, 'e-commerce') || str_contains($blob, 'ecommerce')) => true,
                    str_contains($service, 'CRM') && (str_contains($blob, 'crm') || str_contains($blob, 'custom software')) => true,
                    str_contains($service, 'AI') && (str_contains($blob, 'automation') || str_contains($blob, 'artificial intelligence')) => true,
                    default => false,
                };
            }
            if ($hit) {
                $found[] = $service;
            }
        }

        $found = $this->normalizeServiceLabels($found);

        return [
            'title' => Str::limit($title, 160, ''),
            'description' => Str::limit($description, 320, ''),
            'services' => array_slice($found, 0, 12),
        ];
    }

    /**
     * Map short/scraped tokens → clean showcase labels; drop junk; de-dupe.
     *
     * @param  list<string>  $services
     * @return list<string>
     */
    private function normalizeServiceLabels(array $services): array
    {
        $aliases = [
            'it solutions' => 'IT Consulting & Solutions',
            'cloud' => 'Cloud & Hosting Solutions',
            'hosting' => 'Cloud & Hosting Solutions',
            'cybersecurity' => 'Cybersecurity Solutions',
            'cyber security' => 'Cybersecurity Solutions',
            'digital marketing' => 'SEO & Digital Marketing',
            'seo' => 'SEO & Digital Marketing',
            'web development' => 'Website & Web Application Development',
            'website development' => 'Website & Web Application Development',
            'software development' => 'Custom Business Software & CRM',
            'mobile app development' => 'Mobile App Development',
            'crm' => 'Custom Business Software & CRM',
            'e-commerce' => 'E-Commerce Solutions',
            'ecommerce' => 'E-Commerce Solutions',
            'automation' => 'AI & Automation Solutions',
            'billing software' => 'Billing Software',
            'school management' => 'School Management Software',
            'local business listing' => 'Local Business Listing',
            'managed it' => 'IT Consulting & Solutions',
            'whatsapp' => 'WhatsApp Business API',
        ];

        $out = [];
        $seen = [];
        foreach ($services as $raw) {
            $label = trim((string) $raw);
            if ($label === '' || mb_strlen($label) < 3) {
                continue;
            }
            $key = mb_strtolower($label);
            if (isset($aliases[$key])) {
                $label = $aliases[$key];
                $key = mb_strtolower($label);
            }
            // Drop leftover single-token junk
            if (! str_contains($label, ' ') && mb_strlen($label) < 12 && ! in_array($label, ['SEO', 'CRM'], true)) {
                continue;
            }
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $label;
        }

        return $out;
    }

    /** @return list<string> */
    private function defaultServicesForIndustry(string $industrySpoken): array
    {
        $family = $this->industryFamily($industrySpoken);

        return match ($family) {
            'tech' => [
                'Website & Web Application Development',
                'Mobile App Development',
                'AI & Automation Solutions',
                'SEO & Digital Marketing',
                'Social Media Management',
                'WhatsApp Business API',
                'Cloud & Hosting Solutions',
                'Cybersecurity Solutions',
                'E-Commerce Solutions',
                'Custom Business Software & CRM',
            ],
            'travel' => ['Holiday Packages', 'Corporate Trips', 'Hotel Booking'],
            default => $industrySpoken !== '' ? [$industrySpoken] : [],
        };
    }

    private function extractComposeTopic(string $prompt): string
    {
        $t = mb_strtolower(trim($prompt));
        if ($t === '') {
            return '';
        }

        $t = preg_replace(
            '/\b(please|pls|kindly|write|likho|likhna|likh|banao|banaye|generate|create|make|draft|caption|content|post|posts|social\s*media|ke\s+lie|ke\s+liye|ke\s+liye|for\s+(a\s+|the\s+)?post|about|regarding|on\s+the\s+topic\s+of|topic|offer|announce|promotion)\b/iu',
            ' ',
            $t,
        ) ?? $t;
        $t = preg_replace('/[^\p{L}\p{N}\s\-]/u', ' ', $t) ?? $t;
        $t = preg_replace('/\s+/u', ' ', trim($t)) ?? '';

        // Drop leftover filler words
        $stop = [
            'a', 'an', 'the', 'and', 'or', 'to', 'for', 'with', 'from', 'our', 'my', 'your',
            'ki', 'ke', 'ka', 'ko', 'se', 'me', 'mein', 'par', 'aur', 'ek', 'ye', 'woh',
            'sath', 'saath', 'wala', 'wali', 'wale', 'hai', 'hain', 'kar', 'karke',
        ];
        $words = array_values(array_filter(
            preg_split('/\s+/u', $t) ?: [],
            fn (string $w) => $w !== '' && ! in_array($w, $stop, true) && mb_strlen($w) > 2,
        ));

        if (count($words) < 2) {
            return '';
        }

        $phrase = implode(' ', array_slice($words, 0, 6));
        $phrase = Str::limit($phrase, 48, '');

        return $this->composeTopicIsUseless($phrase) ? '' : $phrase;
    }

    /**
     * Vague prompts like "company service" must not become the post topic.
     */
    private function composeTopicIsUseless(string $topic): bool
    {
        $t = mb_strtolower(trim($topic));
        if ($t === '' || mb_strlen($t) < 4) {
            return true;
        }

        if (preg_match('/^(company|business|our|your|the)?\s*services?$/u', $t)) {
            return true;
        }

        $banned = [
            'company service', 'company services', 'business service', 'business services',
            'our service', 'our services', 'service company', 'services company',
            'social media', 'marketing post', 'growth', 'business growth',
        ];

        return in_array($t, $banned, true);
    }

    /**
     * "IT Company" → "IT services" for natural English sentences.
     */
    private function industrySpokenLabel(string $industry): string
    {
        $industry = trim($industry);
        if ($industry === '') {
            return 'business services';
        }

        if (preg_match('/^(.+?)\s+(company|agency|firm|studio|group|solutions?)$/iu', $industry, $m)) {
            $core = trim($m[1]);

            return $core === '' ? 'business services' : $core.' services';
        }

        return $industry;
    }

    private function titleCasePhrase(string $phrase): string
    {
        $phrase = trim($phrase);
        if ($phrase === '') {
            return '';
        }

        return mb_convert_case(mb_strtolower($phrase), MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * Punchy post title from topic + industry — never a prompt dump.
     */
    private function composePostTitle(
        string $prompt,
        Workspace $workspace,
        WorkspaceAiSetting $settings,
        string $offer = '',
    ): string {
        $brand = $workspace->name;
        $industry = trim((string) ($settings->industry ?: 'business'));
        $industrySpoken = $this->industrySpokenLabel($industry);
        $city = $this->primaryLocation($settings->location);
        $topic = $this->extractComposeTopic($prompt);
        $topicTitle = $this->titleCasePhrase($topic);
        $family = $this->industryFamily($industry);
        $angle = abs(crc32($prompt.'|'.$brand.'|title')) % 4;

        $pool = match ($family) {
            'tech' => array_values(array_filter([
                $topicTitle !== '' ? "{$topicTitle} for {$city} Teams" : null,
                $topicTitle !== '' ? "{$topicTitle}: Clear Next Steps" : null,
                "{$brand}: {$industrySpoken} Without the Chaos",
                "Clear {$industrySpoken} for {$city} Teams",
                "Less Firefighting. Better {$industrySpoken}.",
                "{$industrySpoken} Delivery {$city} Can Trust",
            ])),
            'travel' => array_values(array_filter([
                $topicTitle !== '' ? "{$topicTitle} from {$city}" : null,
                "Weekend Escapes from {$city}",
                "{$brand}: Trips Planned Properly",
                "Your Next Trip, Sorted by {$brand}",
            ])),
            'health' => array_values(array_filter([
                $topicTitle !== '' ? "{$topicTitle} — Patient-First Care" : null,
                "Gentle Care in {$city}",
                "Clear Advice. Calm Visits. {$brand}",
            ])),
            'edu' => array_values(array_filter([
                $topicTitle !== '' ? "{$topicTitle} for Ambitious Learners" : null,
                "Skills That Actually Stick — {$brand}",
                "Learn Better with {$brand}",
            ])),
            'food' => array_values(array_filter([
                $topicTitle !== '' ? "{$topicTitle} in {$city}" : null,
                "Fresh Flavours from {$brand}",
                "{$city}'s Go-To Spot — {$brand}",
            ])),
            'realty' => array_values(array_filter([
                $topicTitle !== '' ? "{$topicTitle} in {$city}" : null,
                "Homes & Spaces That Fit {$city}",
                "Straightforward Guidance from {$brand}",
            ])),
            default => array_values(array_filter([
                $topicTitle !== '' ? "{$topicTitle} — Done Properly" : null,
                $topicTitle !== '' ? "Why {$topicTitle} Matters for {$city}" : null,
                "{$brand}: Clear {$industrySpoken} in {$city}",
                "Reliable {$industrySpoken} for {$city} Teams",
                "Better Outcomes. Less Guesswork. {$brand}",
            ])),
        };

        $title = $pool[$angle % count($pool)] ?? ("{$brand}: Clear {$industrySpoken} in {$city}");

        // Offer may flavour the title once — never become the whole title.
        if ($offer !== '' && ! $this->promptLooksLikeInstruction($offer) && mb_strlen($offer) <= 28) {
            $offerTopic = $this->extractComposeTopic($offer);
            $offerTitle = $offerTopic !== '' ? $this->titleCasePhrase($offerTopic) : '';
            if ($offerTitle !== '' && ! $this->composeTopicIsUseless($offerTitle) && $angle % 2 === 0) {
                $title = Str::limit("{$offerTitle} · {$industrySpoken}", 70, '');
            }
        }

        return $this->polishComposeTitle($title, $prompt, $workspace, $settings, $offer);
    }

    private function industryFamily(string $industry): string
    {
        $i = mb_strtolower($industry);

        return match (true) {
            (bool) preg_match('/\b(it|software|saas|tech|cloud|digital|consult|cyber|devops|app)\b/u', $i) => 'tech',
            (bool) preg_match('/\b(travel|tour|hotel|trip|holiday|tourism)\b/u', $i) => 'travel',
            (bool) preg_match('/\b(clinic|dental|health|hospital|doctor|medico|pharma)\b/u', $i) => 'health',
            (bool) preg_match('/\b(school|coach|edu|training|tuition|academy)\b/u', $i) => 'edu',
            (bool) preg_match('/\b(restaurant|cafe|food|cater|bakery)\b/u', $i) => 'food',
            (bool) preg_match('/\b(real\s*estate|property|builder|housing)\b/u', $i) => 'realty',
            default => 'generic',
        };
    }

    private function promptLooksLikeInstruction(string $prompt): bool
    {
        return (bool) preg_match(
            '/\b(likho|likhna|likh\s|write|banao|banaye|generate|caption|content|post\s+karo|ke\s+lie\s+post|for\s+(a\s+)?post)\b/iu',
            $prompt,
        );
    }

    /** Vague "tell our services" briefs → multi-service overview, not a single niche. */
    private function promptLooksLikeServicesOverview(string $prompt): bool
    {
        $p = mb_strtolower(trim($prompt));
        if ($p === '') {
            return true;
        }

        if ($this->composeTopicIsUseless($this->extractComposeTopic($prompt))) {
            return true;
        }

        return (bool) preg_match(
            '/\b(services?|offerings?|kya\s+karte|what\s+do\s+you\s+do|company\s+profile|about\s+(us|the\s+company)|hamari\s+services?)\b/iu',
            $p,
        );
    }

    /**
     * Reject weak titles and rebuild.
     */
    private function polishComposeTitle(
        string $title,
        string $prompt,
        Workspace $workspace,
        WorkspaceAiSetting $settings,
        string $offer = '',
    ): string {
        $title = trim(preg_replace('/\s+/u', ' ', $title) ?? $title);
        $title = Str::limit($title, 70, '');

        if ($this->composeTitleIsWeak($title, $prompt, $workspace, $settings, $offer)) {
            $industrySpoken = $this->industrySpokenLabel((string) ($settings->industry ?: 'business'));
            $city = $this->primaryLocation($settings->location);
            $brand = $workspace->name;
            $topic = $this->titleCasePhrase($this->extractComposeTopic($prompt));
            $title = $topic !== ''
                ? Str::limit("{$topic} for {$city} Teams", 70, '')
                : Str::limit("{$brand}: Clear {$industrySpoken} in {$city}", 70, '');
        }

        // Final guard — never ship "Company Service…" titles
        if ($this->composeTopicIsUseless($title) || preg_match('/\bcompany\s+services?\b/iu', $title)) {
            $industrySpoken = $this->industrySpokenLabel((string) ($settings->industry ?: 'business'));
            $city = $this->primaryLocation($settings->location);
            $title = Str::limit("{$workspace->name}: Clear {$industrySpoken} in {$city}", 70, '');
        }

        return $title;
    }

    private function composeTitleIsWeak(
        string $title,
        string $prompt,
        Workspace $workspace,
        WorkspaceAiSetting $settings,
        string $offer = '',
    ): bool {
        $title = trim($title);
        if ($title === '' || mb_strlen($title) < 12) {
            return true;
        }

        $lower = mb_strtolower($title);
        $promptLower = mb_strtolower(trim($prompt));
        $brand = mb_strtolower($workspace->name);
        $industry = mb_strtolower(trim((string) ($settings->industry ?: '')));
        $city = mb_strtolower($this->primaryLocation($settings->location));

        if ($promptLower !== '' && ($lower === $promptLower || str_starts_with($lower, $promptLower))) {
            return true;
        }

        if ($this->promptLooksLikeInstruction($title)) {
            return true;
        }

        // Weak templates we used to emit
        if ($industry !== '' && preg_match('/^'.preg_quote($industry, '/').'\s+with\s+/iu', $title)) {
            return true;
        }
        if ($brand !== '' && $city !== '' && preg_match(
            '/^'.preg_quote($brand, '/').'\s+in\s+'.preg_quote($city, '/').'\s*[—\-]/iu',
            $title,
        )) {
            return true;
        }
        if (preg_match('/^(offer highlight|question for your feed|social post)$/iu', $title)) {
            return true;
        }
        if (preg_match('/\b(company\s+services?|our\s+services?|business\s+services?)\b/iu', $title)) {
            return true;
        }

        if ($offer !== '' && str_starts_with($lower, mb_strtolower(trim($offer)).' —')) {
            return true;
        }

        if ($offer !== '' && mb_strtolower(trim($offer)) === $lower) {
            return true;
        }

        // Prefer multi-word headlines
        $words = preg_split('/\s+/u', $title) ?: [];

        return count($words) < 3;
    }

    /**
     * Caption body from topic + industry voice — never pastes the raw prompt.
     */
    private function buildComposeMain(
        string $prompt,
        Workspace $workspace,
        WorkspaceAiSetting $settings,
        string $offer,
        int $wordLimit,
    ): string {
        $brand = $workspace->name;
        $loc = $this->primaryLocation($settings->location);
        $industry = trim((string) ($settings->industry ?: 'business'));
        $industrySpoken = $this->industrySpokenLabel($industry);
        $offerings = $this->resolveBrandOfferings($workspace);
        $services = $offerings['services'] !== []
            ? $offerings['services']
            : $this->defaultServicesForIndustry($industrySpoken);
        $serviceFocus = array_slice($services, 0, 3);
        $serviceList = $serviceFocus !== [] ? implode(', ', $serviceFocus) : $industrySpoken;
        $topic = $this->extractComposeTopic($prompt);
        $angle = abs(crc32($prompt.'|'.$brand.'|body')) % 3;
        $cta = $offer !== ''
            ? $offer
            : trim((string) ($workspace->resolveBrandKit()?->default_cta_label ?? 'Get in touch'));
        $website = $workspace->resolvedWebsite();
        $siteLine = $website ? " Learn more at {$website}." : '';

        // If user named a real service in the prompt, prefer that
        $focus = $serviceList;
        if ($topic !== '') {
            foreach ($services as $service) {
                if (str_contains(mb_strtolower($topic), mb_strtolower($service))) {
                    $focus = $service;
                    break;
                }
            }
        }

        // Vague "services" brief → ChatGPT-style showcase with emoji bullets
        if ($this->promptLooksLikeServicesOverview($prompt) && count($services) >= 2) {
            $named = array_slice($services, 0, 10);
            $bullets = implode("\n", array_map(fn (string $s) => '✅ '.$s, $named));
            $main = "🚀 Powering businesses with smart digital solutions!\n\n"
                ."At {$brand}, we help teams grow, automate, and build a stronger digital presence with reliable technology.\n\n"
                ."{$bullets}\n\n"
                ."Whether you're a startup, small business, or growing enterprise — we build tech that moves you forward.{$siteLine}";

            return $this->trimToWordLimit($main, max(180, $wordLimit));
        }

        $hooks = [
            "{$loc} businesses don’t need generic IT talk — they need clear {$focus}.",
            "When {$focus} is fuzzy, teams in {$loc} waste weeks on the wrong tools.",
            "{$brand} builds around real offerings: {$serviceList}.",
        ];

        $values = [
            "{$brand} delivers {$serviceList} for teams across {$loc}. Scope stays written, delivery stays practical, and the work matches what we publish on our site — not invented promises.{$siteLine}",
            "From {$serviceList}, {$brand} helps {$loc} companies ship cleaner outcomes: clearer ownership, fewer fire drills, and tech that supports growth.{$siteLine}",
            "Pick the service you need — {$serviceList} — and {$brand} maps the next steps for your stack and market in {$loc}.{$siteLine}",
        ];

        $closers = [
            "{$cta} — ask about {$focus}.",
            "If {$focus} is on your plate this month: {$cta}.",
            "Want a no-fluff next step on {$focus}? {$cta}.",
        ];

        $paragraphs = [
            $hooks[$angle],
            $values[$angle],
            $closers[$angle],
        ];

        return $this->trimToWordLimit(implode("\n\n", $paragraphs), $wordLimit);
    }

    /**
     * @param  array{title:string,body:string,platforms:list<string>}  $draft
     * @return array{title:string,body:string,platforms:list<string>}
     */
    private function sanitizeComposeDraft(
        array $draft,
        string $prompt,
        Workspace $workspace,
        WorkspaceAiSetting $settings,
        string $offer,
        int $wordLimit,
    ): array {
        $prompt = trim($prompt);
        $title = trim((string) ($draft['title'] ?? ''));
        $body = trim((string) ($draft['body'] ?? ''));
        $promptLower = mb_strtolower($prompt);

        if ($prompt !== '') {
            if ($this->composeTitleIsWeak($title, $prompt, $workspace, $settings, $offer)) {
                $title = $this->composePostTitle($prompt, $workspace, $settings, $offer);
            } else {
                $title = $this->polishComposeTitle($title, $prompt, $workspace, $settings, $offer);
            }

            if ($body !== '' && str_starts_with(mb_strtolower($body), $promptLower)) {
                $body = trim(mb_substr($body, mb_strlen($prompt)));
                $body = ltrim($body, " \n\r\t:-–—|");
            }

            $parts = preg_split("/\n\s*\n/", $body) ?: [];
            if ($parts !== [] && mb_strtolower(trim($parts[0])) === $promptLower) {
                array_shift($parts);
                $body = trim(implode("\n\n", $parts));
            }
        }

        if ($title === '' || $this->composeTitleIsWeak($title, $prompt, $workspace, $settings, $offer)) {
            $title = $this->composePostTitle($prompt, $workspace, $settings, $offer);
        }

        if ($body === '' || $this->composeBodyIsWeak($body) || str_contains(mb_strtolower($body), 'company service')) {
            $body = $this->buildComposeMain($prompt, $workspace, $settings, $offer, $wordLimit);
        } elseif ($this->wordCount($body) < 25) {
            // Too thin — expand from brand template, don't keep a stub
            $body = $this->buildComposeMain($prompt, $workspace, $settings, $offer, $wordLimit);
        }

        // Services showcase MUST include a visible bullet list — never keep a vague paragraph.
        if ($this->promptLooksLikeServicesOverview($prompt) && ! $this->bodyHasServiceBullets($body)) {
            $body = $this->buildComposeMain($prompt, $workspace, $settings, $offer, max(180, $wordLimit));
        }

        // Never keep an LLM/template title that is basically "Company Service…"
        if (preg_match('/\bcompany\s+services?\b/iu', $title) || $this->composeTopicIsUseless($title)) {
            $title = $this->composePostTitle($prompt, $workspace, $settings, $offer);
        }

        return $this->enforceVariantWordLimit([
            'title' => Str::limit($title, 70, ''),
            'body' => $body,
            'platforms' => $draft['platforms'] ?? [],
        ], $this->promptLooksLikeServicesOverview($prompt) ? max(180, $wordLimit) : $wordLimit, $workspace, $settings, $offer, null);
    }

    private function bodyHasServiceBullets(string $body): bool
    {
        if (str_contains($body, '✅')) {
            return true;
        }

        // At least 3 bullet-ish lines (• - * or emoji + service name)
        $lines = preg_split("/\r\n|\r|\n/", $body) ?: [];
        $hits = 0;
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^(?:✅|☑|•|\-|\*|\d+[\.\)])\s+\S+/u', $line)
                || preg_match('/^(?:🌐|🔍|📱|💼|☁️|🛡️|🤖|💬|🤝)\s+\S+/u', $line)) {
                $hits++;
            }
        }

        return $hits >= 3;
    }

    private function composeBodyIsWeak(string $body): bool
    {
        // Showcase posts with service bullets are intentionally punchy — keep them.
        if ($this->bodyHasServiceBullets($body) || substr_count($body, "\n") >= 6) {
            return false;
        }

        $lower = mb_strtolower($body);
        $bans = [
            'one-stop solution',
            'best in class',
            'your success is our priority',
            'feel free to reach out',
            'looking for reliable',
            'game-changer',
            'synergy',
            '48-hour sla',
            '48 hour sla',
            'dedicated engineer',
            'no surprise bills',
            'part-time freelancer',
        ];

        foreach ($bans as $ban) {
            if (str_contains($lower, $ban)) {
                return true;
            }
        }

        return false;
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
        $main = trim($main);
        // Never chop a services bullet list for a hard word cap.
        if ($this->bodyHasServiceBullets($main)) {
            $wordLimit = max($wordLimit, 220);
        }
        $main = $this->trimToWordLimit($main, $wordLimit);

        $cta = trim($ctaBlock);
        // Avoid duplicating CTA / soft close already present in the main copy.
        if ($cta !== '') {
            $ctaLines = preg_split("/\r\n|\r|\n/", $cta) ?: [];
            $firstCta = trim((string) ($ctaLines[0] ?? ''));
            if ($firstCta !== '' && str_contains(mb_strtolower($main), mb_strtolower($firstCta))) {
                $cta = trim(implode("\n", array_slice($ctaLines, 1)));
            }
        }

        return implode("\n\n", array_filter([$main, $cta, trim($tags)], fn ($p) => $p !== ''));
    }

    /**
     * @return array{main:string,contact:string,hashtags:string}
     */
    private function splitCaptionBody(string $body): array
    {
        $mainLines = [];
        $hashtagParts = [];
        $pendingBlank = false;

        foreach (preg_split("/\r\n|\r|\n/", trim($body)) as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                $pendingBlank = true;

                continue;
            }

            if (preg_match('/(?:^|\s)#\w/u', $trimmed)) {
                preg_match_all('/#\w+/u', $trimmed, $tags);
                foreach ($tags[0] ?? [] as $tag) {
                    $hashtagParts[] = $tag;
                }

                continue;
            }

            if (preg_match('/^(?:📞|✉️|🌐|📱|☎️)|^(?:Phone|Email|Website|Call|DM|WhatsApp)\b/iu', $trimmed)) {
                continue;
            }

            $isBullet = (bool) preg_match('/^(?:✅|☑|•|\-|\*|\d+[\.\)])\s+\S+/u', $trimmed)
                || (bool) preg_match('/^(?:🌐|🔍|📱|💼|☁️|🛡️|🤖|💬|🤝)\s+\S+/u', $trimmed);

            if ($mainLines !== [] && $pendingBlank && ! $isBullet) {
                $mainLines[] = '';
            } elseif ($mainLines !== [] && $pendingBlank && $isBullet) {
                // Keep bullet clusters tight (single newlines).
            } elseif ($mainLines !== [] && $isBullet && preg_match('/^(?:✅|☑|•|\-|\*)/u', (string) end($mainLines))) {
                // consecutive bullets — no extra blank
            } elseif ($mainLines !== [] && $isBullet) {
                $mainLines[] = '';
            }

            $mainLines[] = $trimmed;
            $pendingBlank = false;
        }

        // Rebuild: blank markers as paragraph breaks, bullets stay single-spaced via join
        $chunks = [];
        $bulletRun = [];
        $flushBullets = function () use (&$chunks, &$bulletRun) {
            if ($bulletRun !== []) {
                $chunks[] = implode("\n", $bulletRun);
                $bulletRun = [];
            }
        };

        foreach ($mainLines as $line) {
            if ($line === '') {
                $flushBullets();
                continue;
            }
            $isBullet = (bool) preg_match('/^(?:✅|☑|•|\-|\*|\d+[\.\)])\s+\S+/u', $line)
                || (bool) preg_match('/^(?:🌐|🔍|📱|💼|☁️|🛡️|🤖|💬|🤝)\s+\S+/u', $line);
            if ($isBullet) {
                $bulletRun[] = $line;
            } else {
                $flushBullets();
                $chunks[] = $line;
            }
        }
        $flushBullets();

        return [
            'main' => implode("\n\n", $chunks),
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
     * @param  array{
     *   audience?:string,
     *   intent?:string,
     *   length?:string,
     *   notes?:string,
     *   tone?:string
     * }  $options
     * @return array{ok:bool,message:string,article?:array{title:string,body_html:string,meta_title:string,meta_description:string},cost:float,provider?:string}
     */
    public function writeBlogArticle(Workspace $workspace, string $topic, ?int $userId = null, array $options = []): array
    {
        $settings = $this->settings($workspace);
        $brief = trim($topic);
        $options = $this->normalizeBlogOptions($options);

        if ($brief === '') {
            return ['ok' => false, 'message' => 'Enter a blog topic or keyword.', 'cost' => 0];
        }

        $subject = $this->extractBlogSubject($brief);
        if ($this->blogBriefLooksLikeTravel($brief.' '.$subject) && ($options['intent'] ?? 'guide') === 'guide') {
            $options['intent'] = 'howto';
        }
        if ($options['audience'] === '' && $this->blogBriefLooksLikeTravel($brief.' '.$subject)) {
            $options['audience'] = 'Delhi NCR travellers planning a same-day pilgrimage / leisure trip';
        }

        $liveError = null;
        $article = null;
        $providerName = 'template';

        // Interactive write — prefer a real LLM whenever keys exist (ignore template_first).
        if ($this->router->anyConfigured()) {
            $live = $this->liveBlogArticle($workspace, $settings, $brief, $subject, $options);
            if ($live) {
                $article = $live['article'];
                $providerName = $live['provider'];
            } else {
                $liveError = 'LLM returned unusable blog JSON';
            }
        }

        if ($article === null) {
            $article = $this->templateBlogArticle($workspace, $settings, $subject, $options);
            $providerName = 'template';
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
                'topic' => $brief,
                'subject' => $subject,
                'options' => $options,
                'live_error' => $liveError,
            ]),
            'status' => 'ready',
        ]);

        $this->logUsage($workspace, $userId, 'blog_article', $cost, $providerName, [
            'topic' => Str::limit($subject, 120, ''),
            'intent' => $options['intent'],
            'length' => $options['length'],
        ]);

        $message = $providerName === 'template'
            ? 'Draft ready (template fallback'.($liveError ? ' — AI keys/response failed' : '').'). Review carefully.'
            : 'Blog article ready via '.$providerName.' — review before publish.';

        return [
            'ok' => true,
            'message' => $message,
            'article' => $article,
            'cost' => $cost,
            'provider' => $providerName,
        ];
    }

    /**
     * Strip "write a blog about…" / "ek blog likho jisme…" so the model gets the real subject.
     */
    public function extractBlogSubject(string $brief): string
    {
        $t = trim($brief);
        if ($t === '') {
            return '';
        }

        $patterns = [
            '/^(please\s+|pls\s+|kindly\s+)?(write|create|generate|draft|make)\s+(me\s+|us\s+)?(a\s+|an\s+|one\s+|ek\s+)?(seo\s+)?(blog|article|post)\s+(post\s+)?(on|about|for|regarding|around|covering)\s+/iu',
            '/^(ek\s+)?(blog|article|post)\s+(likho|likhna|likh|banao|banaye|write|create)\s*(jisme|jismen|jis\s*me|jiss\s*me|about|on|par|pe)?\s*/iu',
            '/\b(blog|article)\s+(likho|likhna|write)\s+(jisme|jismen|jis\s*me|about|on)\s+/iu',
            '/^(i\s+want\s+|mujhe\s+|hum\s+)?(a\s+|an\s+|ek\s+)?(blog|article)\s+(on|about|for|par|pe)\s+/iu',
        ];

        foreach ($patterns as $pattern) {
            $next = preg_replace($pattern, '', $t);
            if (is_string($next) && trim($next) !== '' && mb_strlen(trim($next)) >= 8) {
                $t = trim($next);
            }
        }

        // Drop leftover instruction crumbs without destroying the subject.
        $t = preg_replace('/\b(jisme|jismen|jis\s*me)\b/iu', ' ', $t) ?? $t;
        $t = preg_replace('/\b(likho|likhna|banaye|banao)\b/iu', ' ', $t) ?? $t;
        $t = preg_replace('/\s+/u', ' ', trim($t)) ?? '';

        // Trailing / mid-clause Hindi "ho" from "tour ho by bus"
        $t = preg_replace('/\b(tour|trip|yatra)\s+ho\b/iu', '$1', $t) ?? $t;
        $t = preg_replace('/\s+ho\.?$/iu', '', $t) ?? $t;
        $t = trim($t, " \t\n\r\0\x0B-–—:");

        if ($t === '' || mb_strlen($t) < 8) {
            return trim($brief);
        }

        return $t;
    }

    private function blogBriefLooksLikeTravel(string $text): bool
    {
        return (bool) preg_match(
            '/\b(tour|trip|travel|traveller|traveler|yatra|pilgrim|mathura|vrindavan|goa|manali|shimla|jaipur|agra|rishikesh|haridwar|bus|cab|car|itinerary|same[\s-]?day|1[\s-]?day|one[\s-]?day)\b/iu',
            $text,
        );
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{audience:string,intent:string,length:string,notes:string,tone:string}
     */
    private function normalizeBlogOptions(array $options): array
    {
        $intent = strtolower(trim((string) ($options['intent'] ?? 'guide')));
        if (! in_array($intent, ['guide', 'howto', 'listicle', 'comparison', 'local'], true)) {
            $intent = 'guide';
        }

        $length = strtolower(trim((string) ($options['length'] ?? 'standard')));
        if (! in_array($length, ['short', 'standard', 'long'], true)) {
            $length = 'standard';
        }

        $tone = strtolower(trim((string) ($options['tone'] ?? '')));
        if (! in_array($tone, ['', 'hindi', 'english', 'hinglish'], true)) {
            $tone = '';
        }

        return [
            'audience' => Str::limit(trim((string) ($options['audience'] ?? '')), 160, ''),
            'intent' => $intent,
            'length' => $length,
            'notes' => Str::limit(trim((string) ($options['notes'] ?? '')), 1000, ''),
            'tone' => $tone,
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
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
        $text = preg_replace('/\s*```$/', '', $text) ?? $text;
        $text = trim($text);

        if (preg_match('/\{.*\}/s', $text, $m)) {
            $text = $m[0];
        }

        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Repair common LLM mistake: literal newlines / tabs inside JSON string values.
        $repaired = preg_replace_callback(
            '/"(?:\\\\.|[^"\\\\])*"/s',
            static function (array $m): string {
                $s = $m[0];
                $inner = substr($s, 1, -1);
                $inner = str_replace(["\r\n", "\r", "\n", "\t"], ['\\n', '\\n', '\\n', '\\t'], $inner);

                return '"'.$inner.'"';
            },
            $text,
        );
        if (is_string($repaired)) {
            $decoded = json_decode($repaired, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Last-resort field scrape when JSON is badly broken.
     *
     * @return array{title?:string,body?:string}
     */
    private function extractJsonFieldsLoose(string $text): array
    {
        $out = [];
        if (preg_match('/"title"\s*:\s*"((?:\\\\.|[^"\\\\])*)"/s', $text, $m)
            || preg_match('/"title"\s*:\s*"([^"]*)"/s', $text, $m)) {
            $out['title'] = trim(stripcslashes($m[1]));
        }
        if (preg_match('/"body"\s*:\s*"((?:\\\\.|[^"\\\\])*)"/s', $text, $m)) {
            $out['body'] = trim(stripcslashes($m[1]));
        } elseif (preg_match('/"body"\s*:\s*"(.*?)"\s*,\s*"platforms"/s', $text, $m)
            || preg_match('/"body"\s*:\s*"(.*?)"\s*\}/s', $text, $m)) {
            $out['body'] = trim(stripcslashes($m[1]));
        }

        return $out;
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
            foreach (array_slice($this->resolveBrandOfferings($workspace)['services'] ?? [], 0, 4) as $service) {
                $tag = '#'.preg_replace('/[^a-zA-Z0-9]+/', '', $service);
                if (strlen($tag) > 2) {
                    $tags[] = $tag;
                }
            }
        }

        $tags = array_values(array_unique(array_filter($tags)));

        if ($tags === []) {
            $tags = ['#SmallBusiness', '#India', '#Travel'];
        }

        return implode(' ', array_slice($tags, 0, 8));
    }

    /**
     * @param  array{audience:string,intent:string,length:string,notes:string,tone:string}  $options
     */
    private function liveBlogArticle(
        Workspace $workspace,
        WorkspaceAiSetting $settings,
        string $brief,
        string $subject,
        array $options = [],
    ): ?array {
        $options = $this->normalizeBlogOptions($options);
        $cta = $workspace->resolveBrandKit()?->default_cta_label ?: 'Get started';
        $contact = collect([
            $workspace->resolvedPhone(),
            $workspace->resolvedEmail(),
            $workspace->resolvedWebsite(),
        ])->filter()->implode(' · ');

        $toneKey = $options['tone'] !== '' ? $options['tone'] : (string) ($settings->tone ?? 'hinglish');
        $toneGuide = match ($toneKey) {
            'hindi' => 'Write mainly in clear Hindi (Devanagari). Practical, warm, not slangy.',
            'english' => 'Write in polished English only — professional, conversational, SEO-aware.',
            default => 'Write in natural English with light Hinglish only where it helps Indian readers. Prefer clear English for SEO body copy.',
        };

        $wordTarget = match ($options['length']) {
            'short' => '700–900 words',
            'long' => '1,600–2,000 words',
            default => '1,100–1,400 words',
        };
        $sectionCount = match ($options['length']) {
            'short' => '4–5',
            'long' => '7–9',
            default => '5–7',
        };

        $isTravel = $this->blogBriefLooksLikeTravel($brief.' '.$subject);
        $intentGuide = match ($options['intent']) {
            'howto' => $isTravel
                ? 'Same-day trip how-to: departure options (bus / traveller / car), timings, route, stops, costs mindset, and return plan.'
                : 'Format as a step-by-step how-to. Numbered steps where useful. Outcome-first headings.',
            'listicle' => 'Format as a scannable listicle. Each H2 is one list item with depth.',
            'comparison' => 'Compare options fairly (e.g. bus vs traveller vs car). Criteria, pros/cons, who should choose what.',
            'local' => 'Local SEO angle with city/area usefulness and location-specific advice.',
            default => $isTravel
                ? 'Practical travel guide with itinerary, transport choices, and decision help.'
                : 'Authoritative practical guide: teach, clarify, then help the reader take the next step.',
        };

        $audience = $options['audience'] !== ''
            ? $options['audience']
            : ($isTravel
                ? 'Delhi NCR travellers who want a clear same-day plan'
                : 'business owners and decision-makers in '.($settings->location ?: 'India'));

        $offerings = $this->resolveBrandOfferings($workspace);
        $industrySpoken = $this->industrySpokenLabel((string) ($settings->industry ?? ''));
        $isTravelBrand = (bool) preg_match('/travel|tour|holiday|trip|yatra/i', $industrySpoken.' '.$workspace->name);

        if ($isTravel && ! $isTravelBrand) {
            $offeringsBlock = 'This brief is a TRAVEL / TRIP article. Do NOT write about IT, software, SLAs, sprints, vendors, or "'.$industrySpoken.'". Write a real trip guide. Brand "'.$workspace->name.'" may appear once at the end as publisher / trip helper only — never as an IT vendor.';
            $servicesList = 'day trip planning, local travel tips, Delhi NCR departures';
        } else {
            $offeringsBlock = $offerings['summary'] !== ''
                ? $offerings['summary']
                : 'Use industry “'.($settings->industry ?: 'business').'” and location “'.($settings->location ?: 'India').'”. Do not invent products, prices, SLAs, or awards.';
            $servicesList = $offerings['services'] !== []
                ? implode(', ', array_slice($offerings['services'], 0, 12))
                : ($settings->industry ?: 'core services');
        }

        $extraNotes = $options['notes'] !== ''
            ? "Writer notes from the user (follow these):\n{$options['notes']}"
            : 'No extra writer notes.';

        $website = $workspace->resolvedWebsite() ?: 'not set';
        $brandCtaRule = ($isTravel && ! $isTravelBrand)
            ? "Optional soft ending: one short line that {$workspace->name} can help plan the day — no IT/services pitch."
            : "Mention {$workspace->name} once near the end with soft CTA \"{$cta}\".";

        $system = <<<'SYS'
You are an elite SEO blog editor — same bar as a strong ChatGPT long-form answer.
Write ready-to-publish articles. Return ONLY valid JSON. No markdown fences. No preamble.
Voice: specific, useful, human. Never generic AI fluff.
CRITICAL: If the user brief is an instruction ("write a blog…", "ek blog likho…"), treat it as instructions ONLY. The article must be ABOUT the subject, never paste those instruction words into title, headings, or body.
Never invent statistics, awards, prices, or fake reviews.
SYS;

        $user = <<<PROMPT
Write one complete blog article.

USER BRIEF (instructions only — NEVER quote/paste this wording into the article):
{$brief}

ARTICLE SUBJECT (what the post is ABOUT — use this):
{$subject}

BRAND CONTEXT
- Brand: {$workspace->name}
- Website: {$website}
- Industry label on file: {$industrySpoken}
- Workspace cities on file: {$settings->location}
- Audience: {$audience}
- Article type: {$intentGuide}
- Length target: {$wordTarget} ({$sectionCount} H2 sections)
- Language: {$toneGuide}
- Soft CTA label: {$cta}
- Contact (optional, only near end if natural): {$contact}

GROUNDING
{$offeringsBlock}
Useful names/themes: {$servicesList}

{$extraNotes}

QUALITY BAR
1) Hook in first 2 sentences about the SUBJECT trip/topic — concrete plan, not a dictionary definition.
2) Cover real decision questions (for travel: bus vs traveller vs car, start time, route, temples/stops, food, return).
3) Short paragraphs + bullets for timings/checklist.
4) FAQ: 3–5 real traveller questions.
5) {$brandCtaRule}
6) Forbidden in body/title: "ek blog likho", "write a blog", "scope", "sprint", "vendor", "vanity metrics", "IT Company" (unless the subject is truly about IT).

Return JSON only:
{
  "title": "compelling SEO title under 70 chars about the SUBJECT",
  "meta_title": "meta title under 60 chars",
  "meta_description": "benefit-led meta under 155 chars",
  "sections": [
    {
      "heading": "H2 heading",
      "paragraphs": ["plain text paragraph", "plain text paragraph"],
      "bullets": ["optional bullet", "optional bullet"]
    }
  ],
  "faq": [
    {"q": "question readers actually ask", "a": "clear answer"}
  ]
}
PROMPT;

        $maxTokens = match ($options['length']) {
            'short' => 2500,
            'long' => 4500,
            default => 3500,
        };

        $completion = $this->router->complete($system, $user, $maxTokens);
        if (! $completion->ok) {
            return null;
        }

        $json = $this->extractJson($completion->text);
        if (! is_array($json) || blank($json['title'] ?? null) || ! is_array($json['sections'] ?? null)) {
            return null;
        }

        // Reject outputs that still echo the instruction phrase.
        $blob = mb_strtolower(($json['title'] ?? '').' '.json_encode($json['sections']));
        if (str_contains($blob, 'ek blog likho') || str_contains($blob, 'write a blog')) {
            return null;
        }

        $article = $this->normalizeBlogArticlePayload($json, $workspace, $settings, $subject);
        if ($article === null) {
            return null;
        }

        return ['article' => $article, 'provider' => $completion->provider];
    }

    /**
     * @param  array{audience:string,intent:string,length:string,notes:string,tone:string}  $options
     * @return array{title:string,body_html:string,meta_title:string,meta_description:string}
     */
    private function templateBlogArticle(
        Workspace $workspace,
        WorkspaceAiSetting $settings,
        string $subject,
        array $options = [],
    ): array {
        $options = $this->normalizeBlogOptions($options);
        $brand = $workspace->name;
        $subject = trim($subject) !== '' ? trim($subject) : 'your topic';
        $isTravel = $this->blogBriefLooksLikeTravel($subject);
        $cta = $workspace->resolveBrandKit()?->default_cta_label ?: 'Get started';
        $title = Str::limit(
            $isTravel
                ? Str::title(Str::limit($subject, 58, ''))
                : $subject.' — practical guide',
            70,
            '',
        );

        if ($isTravel) {
            $sections = [
                [
                    'heading' => 'Who this 1-day plan is for',
                    'paragraphs' => [
                        'If you are starting from Delhi NCR and want a same-day Mathura visit without overnight stay, you need timings and transport choice more than generic “travel tips”.',
                        'This plan covers bus, traveller (tempo), and car options so you can pick based on group size, budget, and how much walking you want at the temples.',
                    ],
                ],
                [
                    'heading' => 'Bus vs traveller vs car',
                    'paragraphs' => [
                        'Bus is usually cheapest for solo or pair travellers comfortable with fixed schedules. A traveller suits families/groups who want door-to-door comfort. A car gives the most flexible temple-hopping pace.',
                    ],
                    'bullets' => [
                        'Bus: lowest cost, less flexibility on stops',
                        'Traveller: shared group comfort, better for 6–12 people',
                        'Car/cab: fastest decisions on route and return time',
                    ],
                ],
                [
                    'heading' => 'Suggested same-day flow',
                    'paragraphs' => [
                        'Leave early from Noida / Ghaziabad / Delhi so you reach Mathura with enough time for the main temples and a calm return before late night.',
                        'Keep the middle of the day for darshan and a simple meal; save buffer time for traffic on the Yamuna Expressway corridor.',
                    ],
                    'bullets' => [
                        'Early start from Delhi NCR',
                        'Core darshan window late morning to afternoon',
                        'Return buffer for evening traffic',
                    ],
                ],
                [
                    'heading' => 'Packing and common mistakes',
                    'paragraphs' => [
                        'Carry water, comfortable footwear, and a light scarf/cover for temple etiquette. Avoid overpacking the day with too many side stops or you will rush darshan.',
                        'The biggest mistake is starting late and then blaming transport — for a 1-day Mathura trip, departure time decides the whole experience.',
                    ],
                ],
                [
                    'heading' => 'Plan the day with '.$brand,
                    'paragraphs' => [
                        'Want a clearer pickup point and return window from Noida, Ghaziabad, or Delhi? '.$cta.' and share your group size plus preferred transport — bus, traveller, or car.',
                    ],
                ],
            ];
            $faq = [
                [
                    'q' => 'Can I do Mathura as a 1-day trip from Delhi NCR?',
                    'a' => 'Yes — if you start early and keep the plan to core temples plus one meal stop, a same-day return is realistic by bus, traveller, or car.',
                ],
                [
                    'q' => 'Which is better: bus, traveller, or car?',
                    'a' => 'Bus for budget, traveller for groups, car for maximum flexibility on temple timing and return.',
                ],
                [
                    'q' => 'What time should I leave?',
                    'a' => 'Aim for an early morning departure from Noida / Ghaziabad / Delhi so you are not rushing darshan or driving back too late.',
                ],
            ];
        } else {
            $loc = $this->primaryLocation($settings->location);
            $sections = [
                [
                    'heading' => 'Why '.$subject.' matters',
                    'paragraphs' => [
                        'People searching for '.$subject.' usually want a clear plan, costs/effort expectations, and what to do next — not vague advice.',
                        'This guide covers the practical checks, common mistakes, and a simple next step with '.$brand.'.',
                    ],
                ],
                [
                    'heading' => 'What a strong plan includes',
                    'paragraphs' => [
                        'Get specific on outcome, timeline, and who does what. Local context for '.$loc.' readers should show up in examples and next steps.',
                    ],
                    'bullets' => [
                        'Clear offer and inclusions',
                        'Realistic timeline',
                        'Local support people can reach',
                    ],
                ],
                [
                    'heading' => 'Practical next steps',
                    'paragraphs' => [
                        'Write down the goal, constraints, and deadline first. Then choose the smallest useful version of '.$subject.' you can execute this week.',
                    ],
                ],
                [
                    'heading' => 'How '.$brand.' helps',
                    'paragraphs' => [
                        $brand.' can help you turn '.$subject.' into an actionable plan. '.$cta.'.',
                    ],
                ],
            ];
            $faq = [
                [
                    'q' => 'Where should I start with '.$subject.'?',
                    'a' => 'Start with the outcome you want, your constraints, and one next action you can finish this week.',
                ],
                [
                    'q' => 'Do I need local help in '.$loc.'?',
                    'a' => 'Local coordination often speeds decisions and reduces back-and-forth when the work needs on-ground clarity.',
                ],
                [
                    'q' => 'What should I prepare before reaching out?',
                    'a' => 'Goal, budget range, timeline, and any must-have constraints — that alone removes most early confusion.',
                ],
            ];
        }

        return $this->normalizeBlogArticlePayload([
            'title' => $title,
            'meta_title' => Str::limit($title, 60, ''),
            'meta_description' => Str::limit(
                $isTravel
                    ? 'Plan a same-day Mathura trip from Delhi NCR by bus, traveller, or car — timings, tips, and a clear return plan.'
                    : $subject.' — practical guide from '.$brand.'.',
                155,
                ''
            ),
            'sections' => $sections,
            'faq' => $faq,
        ], $workspace, $settings, $subject) ?? [
            'title' => $title,
            'body_html' => '<p>'.e($subject).'</p>',
            'meta_title' => Str::limit($title, 60, ''),
            'meta_description' => Str::limit($subject, 155, ''),
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

        $faq = $payload['faq'] ?? [];
        if (is_array($faq) && $faq !== []) {
            $html .= '<h2>Frequently asked questions</h2>';
            foreach ($faq as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $q = trim((string) ($item['q'] ?? $item['question'] ?? ''));
                $a = trim((string) ($item['a'] ?? $item['answer'] ?? ''));
                if ($q === '' || $a === '') {
                    continue;
                }
                $html .= '<h3>'.e($q).'</h3>';
                $html .= '<p>'.e($a).'</p>';
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
