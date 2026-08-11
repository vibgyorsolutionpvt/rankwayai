<?php

namespace App\Services\Seo;

use App\Models\AiGeneration;
use App\Models\AiUsageLog;
use App\Models\SeoSite;
use App\Models\Workspace;
use App\Services\Ai\AiProviderRouter;
use App\Services\Billing\CreditWalletService;
use App\Services\Billing\PlanAccess;
use Illuminate\Support\Str;

class SeoKeywordResearchService
{
    public function __construct(
        private AiProviderRouter $router,
        private CreditWalletService $credits,
        private PlanAccess $plans,
    ) {}

    /**
     * Keyword ideas from GSC (real) + AI expansion (ideas only — no fake volume/KD).
     *
     * @return array{
     *   ok:bool,
     *   message:string,
     *   ideas:list<array<string,mixed>>,
     *   source:string,
     *   cost:float
     * }
     */
    public function research(
        Workspace $workspace,
        SeoSite $site,
        ?string $seed = null,
        ?int $userId = null,
    ): array {
        $seed = trim((string) $seed);
        $gscRows = collect($site->gsc_queries ?? [])
            ->filter(fn ($row) => is_array($row) && filled($row['query'] ?? null))
            ->take(40)
            ->values();

        $ideas = [];
        foreach ($gscRows as $row) {
            $ideas[] = [
                'keyword' => (string) $row['query'],
                'source' => 'gsc',
                'reason' => 'Already getting Search Console impressions/clicks',
                'intent' => 'performance',
                'clicks' => (int) ($row['clicks'] ?? 0),
                'impressions' => (int) ($row['impressions'] ?? 0),
                'ctr' => isset($row['ctr']) ? (float) $row['ctr'] : null,
                'position' => isset($row['position']) ? (float) $row['position'] : null,
            ];
        }

        $useAi = $this->plans->allows($workspace, 'ai') && $this->router->anyConfigured();
        $providerName = 'template';
        $cost = 0.0;
        $aiAdded = 0;

        if ($useAi) {
            $cost = $this->router->costFor($this->router->activeName());
            if (! $this->credits->canSpend($workspace, $cost)) {
                return [
                    'ok' => false,
                    'message' => 'AI credits exhausted. Recharge from Billing, or run research with GSC-only (clear seed and try again after sync).',
                    'ideas' => $ideas,
                    'source' => 'gsc',
                    'cost' => 0,
                ];
            }

            $live = $this->expandWithAi($workspace, $site, $seed, $gscRows->pluck('query')->all());
            if ($live) {
                $providerName = $live['provider'];
                foreach ($live['keywords'] as $item) {
                    $kw = Str::lower(trim((string) ($item['keyword'] ?? '')));
                    if ($kw === '' || $this->alreadyHas($ideas, $kw)) {
                        continue;
                    }
                    $ideas[] = [
                        'keyword' => $kw,
                        'source' => 'ai',
                        'reason' => (string) ($item['reason'] ?? 'AI suggestion from GSC + seed'),
                        'intent' => (string) ($item['intent'] ?? 'informational'),
                        'clicks' => null,
                        'impressions' => null,
                        'ctr' => null,
                        'position' => null,
                    ];
                    $aiAdded++;
                }

                AiGeneration::query()->create([
                    'workspace_id' => $workspace->id,
                    'type' => 'keyword_research',
                    'title' => $seed !== '' ? $seed : ('GSC research · '.$site->domain),
                    'payload' => [
                        'provider' => $providerName,
                        'seed' => $seed,
                        'domain' => $site->domain,
                        'ideas' => array_slice($ideas, 0, 60),
                    ],
                    'status' => 'ready',
                ]);

                AiUsageLog::query()->create([
                    'workspace_id' => $workspace->id,
                    'user_id' => $userId,
                    'action' => 'keyword_research',
                    'provider' => $providerName,
                    'tokens' => 400,
                    'cost_usd' => $cost,
                    'meta' => ['seed' => $seed, 'ai_added' => $aiAdded, 'gsc' => $gscRows->count()],
                ]);
                $this->credits->spend($workspace, $cost);
            }
        }

        if ($aiAdded === 0 && $seed !== '') {
            foreach ($this->templateExpand($seed, $workspace) as $kw) {
                if ($this->alreadyHas($ideas, $kw)) {
                    continue;
                }
                $ideas[] = [
                    'keyword' => $kw,
                    'source' => 'template',
                    'reason' => 'Simple expansion from your seed (no AI credits used)',
                    'intent' => 'informational',
                    'clicks' => null,
                    'impressions' => null,
                    'ctr' => null,
                    'position' => null,
                ];
            }
        }

        if ($ideas === []) {
            return [
                'ok' => false,
                'message' => 'No ideas yet. Sync GSC first and/or enter a seed keyword. AI expansion needs credits + an AI provider.',
                'ideas' => [],
                'source' => 'none',
                'cost' => 0,
            ];
        }

        // Prefer GSC rows first, then AI/template; cap list.
        usort($ideas, function (array $a, array $b): int {
            $order = ['gsc' => 0, 'ai' => 1, 'template' => 2];
            $cmp = ($order[$a['source']] ?? 9) <=> ($order[$b['source']] ?? 9);
            if ($cmp !== 0) {
                return $cmp;
            }

            return ((int) ($b['impressions'] ?? 0)) <=> ((int) ($a['impressions'] ?? 0));
        });

        $ideas = array_slice($ideas, 0, 40);
        $source = $aiAdded > 0 ? 'gsc+ai' : ($gscRows->isNotEmpty() ? 'gsc' : 'template');

        return [
            'ok' => true,
            'message' => $aiAdded > 0
                ? count($ideas).' ideas (GSC + AI via '.$providerName.') — no fake volumes; GSC rows show real clicks/impr.'
                : count($ideas).' ideas from GSC/seed — AI credits unlock smarter expansion.',
            'ideas' => $ideas,
            'source' => $source,
            'cost' => $aiAdded > 0 ? $cost : 0,
        ];
    }

    /**
     * @param  list<string>  $gscQueries
     * @return array{provider:string, keywords:list<array{keyword:string, reason?:string, intent?:string}>}|null
     */
    private function expandWithAi(Workspace $workspace, SeoSite $site, string $seed, array $gscQueries): ?array
    {
        $aiSettings = $this->aiSettings($workspace);
        $settingsIndustry = $aiSettings->industry ?: 'local business';
        $location = $aiSettings->location ?: 'India';

        $system = 'You are an SEO keyword researcher for Indian/local businesses. '
            .'Return ONLY valid JSON: {"keywords":[{"keyword":"...","reason":"...","intent":"informational|commercial|local|transactional"}]}. '
            .'Suggest 15-25 useful search phrases. Do NOT invent search volumes or difficulty scores. '
            .'Prefer long-tail and local variants. No duplicates.';

        $user = "Domain: {$site->domain}\nIndustry: {$settingsIndustry}\nLocation: {$location}\n"
            .'Seed: '.($seed !== '' ? $seed : '(none — expand from GSC)')."\n"
            .'GSC queries (real performance data): '.json_encode(array_slice($gscQueries, 0, 25));

        $completion = $this->router->complete($system, $user, 900);
        if (! $completion->ok) {
            return null;
        }

        $json = $this->extractJson($completion->text);
        $list = $json['keywords'] ?? null;
        if (! is_array($list) || $list === []) {
            return null;
        }

        return [
            'provider' => $completion->provider,
            'keywords' => $list,
        ];
    }

    /**
     * @return list<string>
     */
    private function templateExpand(string $seed, Workspace $workspace): array
    {
        $seed = Str::lower(trim($seed));
        $location = Str::lower((string) ($this->aiSettings($workspace)->location ?: 'near me'));
        $variants = [
            $seed,
            'best '.$seed,
            $seed.' services',
            $seed.' '.$location,
            $seed.' near me',
            'affordable '.$seed,
            $seed.' company',
            $seed.' agency',
        ];

        return array_values(array_unique(array_filter($variants)));
    }

    /**
     * @param  list<array<string,mixed>>  $ideas
     */
    private function alreadyHas(array $ideas, string $keyword): bool
    {
        $keyword = Str::lower($keyword);
        foreach ($ideas as $idea) {
            if (Str::lower((string) ($idea['keyword'] ?? '')) === $keyword) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractJson(string $text): ?array
    {
        if (preg_match('/\{.*\}/s', $text, $m)) {
            $decoded = json_decode($m[0], true);

            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }

    private function aiSettings(Workspace $workspace): \App\Models\WorkspaceAiSetting
    {
        return \App\Models\WorkspaceAiSetting::query()->firstOrCreate(
            ['workspace_id' => $workspace->id],
            [
                'monthly_budget_usd' => 20,
                'template_first' => true,
                'tone' => 'mixed',
                'industry' => 'local business',
                'location' => 'India',
            ]
        );
    }
}
