<?php

namespace App\Services\Crm;

use App\Models\Brochure;
use App\Models\BusinessCard;
use App\Models\CrmLead;
use App\Models\Quotation;
use App\Models\Workspace;

class AnalyticsService
{
    /**
     * @return array<string, mixed>
     */
    public function workspaceSnapshot(Workspace $workspace): array
    {
        $leads = CrmLead::query()->where('workspace_id', $workspace->id);
        $total = (clone $leads)->count();
        $qualified = (clone $leads)->where('stage', 'qualified')->count();
        $won = (clone $leads)->where('stage', 'won')->count();
        $lost = (clone $leads)->where('stage', 'lost')->count();
        $hot = (clone $leads)->where('score_band', 'hot')->count();
        $warm = (clone $leads)->where('score_band', 'warm')->count();
        $cold = (clone $leads)->where('score_band', 'cold')->count();
        $open = (clone $leads)->whereIn('stage', ['new', 'contacted', 'qualified'])->count();
        $pipelineCents = (clone $leads)->whereIn('stage', ['new', 'contacted', 'qualified'])->sum('value_cents');
        $wonCents = (clone $leads)->where('stage', 'won')->sum('value_cents');

        $quotes = Quotation::query()->where('workspace_id', $workspace->id);
        $quoteCount = (clone $quotes)->count();
        $quoteSent = (clone $quotes)->whereIn('status', ['sent', 'accepted'])->count();
        $quoteValue = (float) (clone $quotes)->sum('total');
        $quoteAcceptedValue = (float) (clone $quotes)->where('status', 'accepted')->sum('total');

        $conversionRate = $total > 0 ? round(($won / $total) * 100, 1) : 0.0;
        $avgDeal = $won > 0 ? round($wonCents / $won / 100, 2) : 0.0;

        $followUpsDue = CrmLead::query()
            ->where('workspace_id', $workspace->id)
            ->whereNotNull('follow_up_due_at')
            ->where('follow_up_due_at', '<=', now()->addDay())
            ->whereIn('stage', ['new', 'contacted', 'qualified'])
            ->orderBy('follow_up_due_at')
            ->limit(10)
            ->get()
            ->map(fn (CrmLead $lead) => [
                'id' => $lead->id,
                'name' => $lead->name,
                'score' => $lead->score,
                'score_band' => $lead->score_band,
                'next_action' => $lead->next_action,
                'follow_up_due_at' => $lead->follow_up_due_at?->timezone(config('app.timezone'))->format('d M Y, g:i A'),
            ]);

        $hotLeads = CrmLead::query()
            ->where('workspace_id', $workspace->id)
            ->where('score_band', 'hot')
            ->whereIn('stage', ['new', 'contacted', 'qualified'])
            ->latest('scored_at')
            ->limit(8)
            ->get()
            ->map(fn (CrmLead $lead) => [
                'id' => $lead->id,
                'name' => $lead->name,
                'score' => $lead->score,
                'stage' => $lead->stage,
                'next_action' => $lead->next_action,
            ]);

        $cards = BusinessCard::query()->where('workspace_id', $workspace->id);
        $brochures = Brochure::query()->where('workspace_id', $workspace->id);

        return [
            'leads' => [
                'total' => $total,
                'open' => $open,
                'qualified' => $qualified,
                'hot' => $hot,
                'warm' => $warm,
                'cold' => $cold,
                'won' => $won,
                'lost' => $lost,
                'pipeline_value' => round($pipelineCents / 100, 2),
                'won_value' => round($wonCents / 100, 2),
                'conversion_rate' => $conversionRate,
                'average_deal_value' => $avgDeal,
            ],
            'quotations' => [
                'total' => $quoteCount,
                'sent' => $quoteSent,
                'value' => round($quoteValue, 2),
                'accepted_value' => round($quoteAcceptedValue, 2),
            ],
            'assets' => [
                'card_views' => (int) (clone $cards)->sum('views_count'),
                'brochure_views' => (int) (clone $brochures)->sum('views_count'),
                'brochure_downloads' => (int) (clone $brochures)->sum('downloads_count'),
            ],
            'follow_ups_due' => $followUpsDue,
            'hot_leads' => $hotLeads,
        ];
    }
}
