<?php

namespace App\Services\Billing;

use App\Models\Workspace;
use App\Models\WorkspaceAiSetting;
use App\Models\WorkspaceSubscription;

class CreditWalletService
{
    public function settings(Workspace $workspace): WorkspaceAiSetting
    {
        return WorkspaceAiSetting::query()->firstOrCreate(
            ['workspace_id' => $workspace->id],
            [
                'monthly_budget_usd' => 20,
                'topup_credits' => 0,
                'template_first' => true,
                'tone' => 'mixed',
            ]
        );
    }

    /**
     * @return array{
     *   plan_limit:int,
     *   plan_used:int,
     *   plan_remaining:int,
     *   topup:int,
     *   available:int
     * }
     */
    public function snapshot(Workspace $workspace, ?WorkspaceSubscription $subscription = null): array
    {
        $settings = $this->settings($workspace);
        $subscription ??= app(BillingService::class)->subscription($workspace);

        $planLimitUsd = (float) ($subscription->limits['ai_budget_usd'] ?? $settings->monthly_budget_usd);
        $planLimit = (int) round(max(0, $planLimitUsd) * 100);
        $planUsed = (int) round(max(0, (float) $settings->spent_usd) * 100);
        $planRemaining = max(0, $planLimit - $planUsed);
        $topup = (int) ($settings->topup_credits ?? 0);

        return [
            'plan_limit' => $planLimit,
            'plan_used' => min($planUsed, $planLimit),
            'plan_remaining' => $planRemaining,
            'topup' => $topup,
            'available' => $planRemaining + $topup,
        ];
    }

    public function canSpend(Workspace $workspace, float $costUsd): bool
    {
        $need = CreditPackCatalog::costToCredits($costUsd);
        $snap = $this->snapshot($workspace);

        return $snap['available'] >= $need;
    }

    /**
     * Deduct plan credits first, then top-up credits.
     */
    public function spend(Workspace $workspace, float $costUsd): bool
    {
        $settings = $this->settings($workspace);
        $need = CreditPackCatalog::costToCredits($costUsd);
        $snap = $this->snapshot($workspace);

        if ($snap['available'] < $need) {
            return false;
        }

        $fromPlan = min($snap['plan_remaining'], $need);
        $fromTopup = $need - $fromPlan;

        if ($fromPlan > 0) {
            $settings->increment('spent_usd', CreditPackCatalog::creditsToUsd($fromPlan));
        }

        if ($fromTopup > 0) {
            $settings->refresh();
            $settings->update([
                'topup_credits' => max(0, (int) $settings->topup_credits - $fromTopup),
            ]);
        }

        return true;
    }

    public function addTopup(Workspace $workspace, int $credits): WorkspaceAiSetting
    {
        $settings = $this->settings($workspace);
        $settings->increment('topup_credits', max(0, $credits));

        return $settings->fresh();
    }
}
