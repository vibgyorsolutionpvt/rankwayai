<?php

namespace App\Services\Crm;

use App\Models\AiUsageLog;
use App\Models\CrmLead;
use App\Models\Quotation;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Ai\AiProviderRouter;
use App\Services\Billing\CreditWalletService;
use Illuminate\Support\Str;

class LeadScoringService
{
    public function __construct(
        private AiProviderRouter $ai,
        private CreditWalletService $credits,
    ) {}

    /**
     * @return array{ok: bool, message: string, lead?: CrmLead}
     */
    public function score(CrmLead $lead, ?int $userId = null, bool $useAi = true): array
    {
        $workspace = $lead->workspace;
        if (! $workspace) {
            return ['ok' => false, 'message' => 'Workspace missing.'];
        }

        $heuristic = $this->heuristicScore($lead);
        $result = $heuristic;
        $source = 'heuristic';

        $cost = 0.01;
        if ($useAi && $this->ai->anyConfigured() && $this->credits->canSpend($workspace, $cost)) {
            try {
                $aiResult = $this->scoreWithAi($workspace, $lead, $heuristic);
                if ($aiResult) {
                    $result = $aiResult;
                    $source = 'ai';
                    $this->logUsage($workspace, $userId, $cost, [
                        'lead_id' => $lead->id,
                        'score' => $result['score'],
                    ]);
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $lead->forceFill([
            'score' => $result['score'],
            'score_band' => $result['band'],
            'score_reason' => $result['reason'],
            'score_source' => $source,
            'scored_at' => now(),
            'next_action' => $result['next_action'] ?? $lead->next_action,
        ])->save();

        $lead->logActivity(
            'score',
            'Lead scored '.$result['score'].' ('.ucfirst($result['band']).')',
            $userId ? User::query()->find($userId) : null,
            [
                'score' => $result['score'],
                'band' => $result['band'],
                'source' => $source,
            ]
        );

        return [
            'ok' => true,
            'message' => 'Scored '.$result['score'].' — '.ucfirst($result['band']).'.',
            'lead' => $lead->fresh(),
        ];
    }

    /**
     * @return array{ok: bool, message: string, lead?: CrmLead}
     */
    public function suggestFollowUp(CrmLead $lead, ?int $userId = null, bool $useAi = true): array
    {
        $workspace = $lead->workspace;
        if (! $workspace) {
            return ['ok' => false, 'message' => 'Workspace missing.'];
        }

        if ($lead->score === null) {
            $scored = $this->score($lead, $userId, $useAi);
            if (! ($scored['ok'] ?? false)) {
                return $scored;
            }
            $lead = $scored['lead'] ?? $lead->fresh();
        }

        $suggestion = $this->templateFollowUp($lead);
        $source = 'template';
        $cost = 0.01;

        if ($useAi && $this->ai->anyConfigured() && $this->credits->canSpend($workspace, $cost)) {
            try {
                $ai = $this->followUpWithAi($workspace, $lead);
                if ($ai) {
                    $suggestion = $ai;
                    $source = 'ai';
                    $this->logUsage($workspace, $userId, $cost, [
                        'lead_id' => $lead->id,
                        'type' => 'follow_up',
                    ]);
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $dueHours = match ($lead->score_band) {
            'hot' => 4,
            'warm' => 24,
            default => 48,
        };

        $lead->forceFill([
            'follow_up_suggestion' => $suggestion['message'],
            'next_action' => $suggestion['next_action'],
            'follow_up_due_at' => now()->addHours($dueHours),
        ])->save();

        $lead->logActivity(
            'follow_up',
            'Follow-up suggested: '.$suggestion['next_action'],
            $userId ? User::query()->find($userId) : null,
            ['source' => $source, 'due_at' => $lead->follow_up_due_at?->toIso8601String()]
        );

        return [
            'ok' => true,
            'message' => 'Follow-up suggestion ready.',
            'lead' => $lead->fresh(),
        ];
    }

    /**
     * @return array{score: int, band: string, reason: string, next_action: string, signals: list<string>}
     */
    public function heuristicScore(CrmLead $lead): array
    {
        $score = 0;
        $signals = [];

        if (filled($lead->phone)) {
            $score += 12;
            $signals[] = 'Phone on file';
        }
        if (filled($lead->email)) {
            $score += 10;
            $signals[] = 'Email on file';
        }
        if (filled($lead->company)) {
            $score += 5;
            $signals[] = 'Company listed';
        }
        if ((int) $lead->value_cents > 0) {
            $score += 15;
            $signals[] = 'Deal value set';
        }

        $notes = strtolower((string) ($lead->notes ?? ''));
        if (strlen(trim($notes)) >= 20) {
            $score += 8;
            $signals[] = 'Detailed notes';
        }

        $noteSignals = [
            'budget' => 'Budget mentioned',
            'traveller' => 'Traveller count mentioned',
            'people' => 'Group size mentioned',
            'december|january|february|march|april|may|june|july|august|september|october|november|date|dates' => 'Travel timing mentioned',
            'hotel|resort|stay' => 'Stay preference mentioned',
            'kashmir|goa|manali|dubai|thailand|bali|singapore|europe|himachal|ladakh' => 'Destination mentioned',
        ];
        foreach ($noteSignals as $pattern => $label) {
            if ($notes !== '' && preg_match('/'.$pattern.'/i', $notes)) {
                $score += 8;
                $signals[] = $label;
            }
        }

        $score += match ($lead->stage) {
            'contacted' => 10,
            'qualified' => 22,
            'won' => 35,
            'lost' => -20,
            default => 0,
        };
        if (in_array($lead->stage, ['contacted', 'qualified', 'won'], true)) {
            $signals[] = 'Stage: '.ucfirst($lead->stage);
        }

        $hasWhatsapp = $lead->whatsappConversations()->exists();
        if ($hasWhatsapp) {
            $score += 12;
            $signals[] = 'WhatsApp conversation linked';
        }

        $studioQuotes = Quotation::query()
            ->where('workspace_id', $lead->workspace_id)
            ->where('crm_lead_id', $lead->id)
            ->count();
        $crmQuotes = $lead->quotations()->count();
        if ($studioQuotes + $crmQuotes > 0) {
            $score += 18;
            $signals[] = 'Quotation linked';
        }

        if ($lead->last_contacted_at && $lead->last_contacted_at->gt(now()->subDays(7))) {
            $score += 6;
            $signals[] = 'Contacted in last 7 days';
        } elseif ($lead->created_at && $lead->created_at->lt(now()->subDays(14)) && blank($lead->last_contacted_at)) {
            $score -= 8;
            $signals[] = 'No contact in 14+ days';
        }

        $score = max(0, min(100, $score));
        $band = $this->bandFor($score);
        $reason = $signals === []
            ? 'Limited lead details available — score based on stage only.'
            : implode('; ', array_slice($signals, 0, 8));

        $next = match ($band) {
            'hot' => 'Call or WhatsApp today with a quotation',
            'warm' => 'Ask for travel dates, travellers, and budget',
            default => 'Collect phone/email and basic trip requirements',
        };

        return [
            'score' => $score,
            'band' => $band,
            'reason' => $reason,
            'next_action' => $next,
            'signals' => $signals,
        ];
    }

    public function bandFor(int $score): string
    {
        if ($score >= 71) {
            return 'hot';
        }
        if ($score >= 31) {
            return 'warm';
        }

        return 'cold';
    }

    /**
     * @param  array{score: int, band: string, reason: string, next_action: string, signals: list<string>}  $heuristic
     * @return array{score: int, band: string, reason: string, next_action: string}|null
     */
    private function scoreWithAi(Workspace $workspace, CrmLead $lead, array $heuristic): ?array
    {
        $system = 'You score sales leads for an Indian travel/business agency. Return ONLY valid JSON. Do not invent facts not present in the input. Score 0-100. Band: cold (0-30), warm (31-70), hot (71-100).';
        $user = 'Lead data:
Name: '.$lead->name.'
Email: '.($lead->email ?: 'none').'
Phone: '.($lead->phone ?: 'none').'
Company: '.($lead->company ?: 'none').'
Stage: '.$lead->stage.'
Source: '.($lead->source ?: 'unknown').'
Deal value cents: '.(int) $lead->value_cents.'
Notes: '.($lead->notes ?: 'none').'
Heuristic score: '.$heuristic['score'].'
Heuristic signals: '.implode('; ', $heuristic['signals']).'

Return JSON: {"score":87,"band":"hot","reason":"short factual reason","next_action":"one clear next step"}';

        $completion = $this->ai->complete($system, $user, 300);
        if (! $completion->ok) {
            return null;
        }

        $parsed = $this->parseJson($completion->text ?? '');
        if (! $parsed || ! isset($parsed['score'])) {
            return null;
        }

        $score = max(0, min(100, (int) $parsed['score']));

        return [
            'score' => $score,
            'band' => $this->bandFor($score),
            'reason' => Str::limit(trim((string) ($parsed['reason'] ?? $heuristic['reason'])), 500, ''),
            'next_action' => Str::limit(trim((string) ($parsed['next_action'] ?? $heuristic['next_action'])), 160, ''),
        ];
    }

    /**
     * @return array{message: string, next_action: string}
     */
    private function templateFollowUp(CrmLead $lead): array
    {
        $name = $lead->name ?: 'there';
        $band = $lead->score_band ?: 'cold';

        $missing = [];
        if (blank($lead->phone) && blank($lead->email)) {
            $missing[] = 'best contact number';
        }
        $notes = strtolower((string) ($lead->notes ?? ''));
        if (! preg_match('/december|january|february|march|april|may|june|july|august|september|october|november|\d{1,2}[\/\-]/i', $notes)) {
            $missing[] = 'preferred travel dates';
        }
        if (! preg_match('/\b\d+\s*(people|pax|traveller|adult)/i', $notes) && ! preg_match('/traveller/i', $notes)) {
            $missing[] = 'number of travellers';
        }
        if (! preg_match('/budget/i', $notes) && (int) $lead->value_cents <= 0) {
            $missing[] = 'approximate budget';
        }

        if ($band === 'hot') {
            $message = "Hi {$name}, thanks for the details. I can share a tailored quotation today — shall I send it on WhatsApp?";
            $action = 'Send quotation via WhatsApp';
        } elseif ($missing !== []) {
            $ask = implode(', ', array_slice($missing, 0, 3));
            $message = "Hi {$name}, thanks for reaching out. To prepare the right package, could you share your {$ask}?";
            $action = 'Ask for: '.$ask;
        } else {
            $message = "Hi {$name}, checking in — would you like me to prepare a quotation based on your requirements?";
            $action = 'Offer quotation';
        }

        return ['message' => $message, 'next_action' => $action];
    }

    /**
     * @return array{message: string, next_action: string}|null
     */
    private function followUpWithAi(Workspace $workspace, CrmLead $lead): ?array
    {
        $system = 'You write short sales follow-ups for Indian SMBs. Return ONLY JSON. Do not invent trip details. Keep message under 280 characters. Friendly, professional, WhatsApp-ready.';
        $user = 'Lead: '.$lead->name.'
Score: '.($lead->score ?? 'n/a').' ('.($lead->score_band ?? 'n/a').')
Reason: '.($lead->score_reason ?? '').'
Notes: '.($lead->notes ?: 'none').'
Phone: '.($lead->phone ?: 'none').'
Business: '.$workspace->name.'

Return JSON: {"message":"...","next_action":"..."}';

        $completion = $this->ai->complete($system, $user, 280);
        if (! $completion->ok) {
            return null;
        }

        $parsed = $this->parseJson($completion->text ?? '');
        if (! $parsed || blank($parsed['message'] ?? null)) {
            return null;
        }

        return [
            'message' => Str::limit(trim((string) $parsed['message']), 500, ''),
            'next_action' => Str::limit(trim((string) ($parsed['next_action'] ?? 'Send follow-up')), 160, ''),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseJson(string $text): ?array
    {
        $text = trim($text);
        if (preg_match('/\{.*\}/s', $text, $m)) {
            $text = $m[0];
        }
        $decoded = json_decode($text, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function logUsage(Workspace $workspace, ?int $userId, float $cost, array $meta): void
    {
        AiUsageLog::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $userId,
            'action' => 'crm_lead_ai',
            'provider' => $this->ai->activeName(),
            'tokens' => 0,
            'cost_usd' => $cost,
            'meta' => $meta,
        ]);

        $this->credits->spend($workspace, $cost);
    }
}
