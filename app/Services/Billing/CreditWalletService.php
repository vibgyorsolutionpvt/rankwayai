<?php

namespace App\Services\Billing;

use App\Enums\WorkspaceRole;
use App\Models\CreditRecharge;
use App\Models\Workspace;
use App\Models\WorkspaceAiSetting;
use App\Models\WorkspaceSubscription;
use Illuminate\Support\Collection;

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
     *   available:int,
     *   source:string,
     *   pool_workspace_ids:list<int>
     * }
     */
    public function snapshot(Workspace $workspace, ?WorkspaceSubscription $subscription = null): array
    {
        $billing = app(BillingService::class);
        $subscription ??= $billing->subscription($workspace);
        $pool = $this->resolveCreditPool($workspace, $subscription, $billing);

        $planLimit = (int) round(max(0, $pool['plan_limit_usd']) * 100);
        $planUsed = (int) round(max(0, $pool['plan_used_usd']) * 100);
        $planRemaining = max(0, $planLimit - $planUsed);
        $topup = (int) $pool['topup_credits'];

        return [
            'plan_limit' => $planLimit,
            'plan_used' => min($planUsed, $planLimit),
            'plan_remaining' => $planRemaining,
            'topup' => $topup,
            'available' => $planRemaining + $topup,
            'source' => $pool['source'],
            'pool_workspace_ids' => $pool['workspace_ids'],
        ];
    }

    /**
     * Workspace IDs that share this account credit pool (plan + top-up).
     *
     * @return list<int>
     */
    public function poolWorkspaceIds(Workspace $workspace): array
    {
        return $this->snapshot($workspace)['pool_workspace_ids'];
    }

    public function canSpend(Workspace $workspace, float $costUsd): bool
    {
        $need = CreditPackCatalog::costToCredits($costUsd);
        $snap = $this->snapshot($workspace);

        return $snap['available'] >= $need;
    }

    /**
     * Deduct shared plan credits first, then shared top-up credits.
     */
    public function spend(Workspace $workspace, float $costUsd): bool
    {
        $need = CreditPackCatalog::costToCredits($costUsd);
        $snap = $this->snapshot($workspace);

        if ($snap['available'] < $need) {
            return false;
        }

        $fromPlan = min($snap['plan_remaining'], $need);
        $fromTopup = $need - $fromPlan;

        // Plan spend is attributed to the workspace that triggered the action.
        if ($fromPlan > 0) {
            $this->settings($workspace)->increment(
                'spent_usd',
                CreditPackCatalog::creditsToUsd($fromPlan)
            );
        }

        if ($fromTopup > 0) {
            $this->deductTopupFromPool($snap['pool_workspace_ids'], $fromTopup);
        }

        return true;
    }

    /**
     * Top-ups land on the account billing workspace so every covered workspace can spend them.
     */
    public function addTopup(Workspace $workspace, int $credits): WorkspaceAiSetting
    {
        $billing = app(BillingService::class);
        $subscription = $billing->subscription($workspace);
        $pool = $this->resolveCreditPool($workspace, $subscription, $billing);
        $homeId = $pool['wallet_home_id'] ?: (int) $workspace->id;
        $home = Workspace::query()->find($homeId) ?? $workspace;

        $settings = $this->settings($home);
        $settings->increment('topup_credits', max(0, $credits));

        return $settings->fresh();
    }

    /**
     * @return Collection<int, CreditRecharge>
     */
    public function rechargeHistory(Workspace $workspace, int $limit = 20): Collection
    {
        $ids = $this->poolWorkspaceIds($workspace);

        return CreditRecharge::query()
            ->whereIn('workspace_id', $ids)
            ->latest()
            ->limit($limit)
            ->get();
    }

    /**
     * @param  list<int>  $workspaceIds
     */
    private function deductTopupFromPool(array $workspaceIds, int $need): void
    {
        if ($need <= 0 || $workspaceIds === []) {
            return;
        }

        $rows = WorkspaceAiSetting::query()
            ->whereIn('workspace_id', $workspaceIds)
            ->where('topup_credits', '>', 0)
            ->orderByDesc('topup_credits')
            ->get();

        $left = $need;
        foreach ($rows as $row) {
            if ($left <= 0) {
                break;
            }
            $take = min((int) $row->topup_credits, $left);
            $row->update(['topup_credits' => max(0, (int) $row->topup_credits - $take)]);
            $left -= $take;
        }
    }

    /**
     * @return array{
     *   plan_limit_usd:float,
     *   plan_used_usd:float,
     *   topup_credits:int,
     *   source:string,
     *   workspace_ids:list<int>,
     *   wallet_home_id:int|null
     * }
     */
    private function resolveCreditPool(
        Workspace $workspace,
        WorkspaceSubscription $subscription,
        BillingService $billing
    ): array {
        $account = $this->bestAccountPlanForWorkspace($workspace, $billing);
        $covered = $account['covered_ids'];
        $inAccountPool = $account['subscription']
            && in_array((int) $workspace->id, $covered, true);

        if ($inAccountPool) {
            $limit = (float) ($account['subscription']->limits['ai_budget_usd'] ?? 0);
            $used = (float) WorkspaceAiSetting::query()
                ->whereIn('workspace_id', $covered)
                ->sum('spent_usd');
            $topup = (int) WorkspaceAiSetting::query()
                ->whereIn('workspace_id', $covered)
                ->sum('topup_credits');

            return [
                'plan_limit_usd' => $limit,
                'plan_used_usd' => $used,
                'topup_credits' => $topup,
                'source' => 'account',
                'workspace_ids' => $covered,
                'wallet_home_id' => (int) $account['subscription']->workspace_id,
            ];
        }

        // Standalone workspace (not covered by an account plan seat).
        $settings = $this->settings($workspace);

        return [
            'plan_limit_usd' => (float) ($subscription->limits['ai_budget_usd'] ?? $settings->monthly_budget_usd ?? 0),
            'plan_used_usd' => (float) $settings->spent_usd,
            'topup_credits' => (int) ($settings->topup_credits ?? 0),
            'source' => 'workspace',
            'workspace_ids' => [(int) $workspace->id],
            'wallet_home_id' => (int) $workspace->id,
        ];
    }

    /**
     * @return array{subscription:?WorkspaceSubscription,covered_ids:list<int>}
     */
    private function bestAccountPlanForWorkspace(Workspace $workspace, BillingService $billing): array
    {
        $owners = $workspace->users()
            ->wherePivot('role', WorkspaceRole::Owner->value)
            ->get();

        $best = null;
        $bestRank = 0;
        $coveredIds = [];

        foreach ($owners as $owner) {
            $ownedIds = $owner->workspaces()
                ->wherePivot('role', WorkspaceRole::Owner->value)
                ->orderBy('workspaces.id')
                ->pluck('workspaces.id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $bestSub = null;
            $rank = 0;
            foreach ($ownedIds as $ownedId) {
                $ownedWs = Workspace::query()->find($ownedId);
                if (! $ownedWs) {
                    continue;
                }
                $sub = $billing->subscription($ownedWs);
                if (! $this->isPaid($sub)) {
                    continue;
                }
                $r = PlanCatalog::planRank($sub->plan);
                if ($r > $rank) {
                    $rank = $r;
                    $bestSub = $sub;
                }
            }

            if ($rank > $bestRank && $bestSub) {
                $bestRank = $rank;
                $best = $bestSub;
                $coveredIds = array_slice($ownedIds, 0, PlanCatalog::workspaceLimit($bestSub->plan));
            }
        }

        return [
            'subscription' => $best,
            'covered_ids' => $coveredIds,
        ];
    }

    private function isPaid(?WorkspaceSubscription $subscription): bool
    {
        if (! $subscription || $subscription->plan === 'free') {
            return false;
        }

        return in_array($subscription->status, ['active', 'trialing'], true);
    }
}
