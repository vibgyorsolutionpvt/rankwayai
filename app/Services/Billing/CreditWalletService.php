<?php

namespace App\Services\Billing;

use App\Models\BillingAccount;
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
     *   pool_workspace_ids:list<int>,
     *   billing_account_id:?int
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
            'billing_account_id' => $pool['billing_account_id'],
        ];
    }

    /**
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

    public function spend(Workspace $workspace, float $costUsd): bool
    {
        $need = CreditPackCatalog::costToCredits($costUsd);
        $snap = $this->snapshot($workspace);

        if ($snap['available'] < $need) {
            return false;
        }

        $fromPlan = min($snap['plan_remaining'], $need);
        $fromTopup = $need - $fromPlan;

        if ($snap['source'] === 'account' && $snap['billing_account_id']) {
            $account = BillingAccount::query()->find($snap['billing_account_id']);
            if ($account) {
                if ($fromPlan > 0) {
                    $account->increment(
                        'spent_usd',
                        CreditPackCatalog::creditsToUsd($fromPlan)
                    );
                }
                if ($fromTopup > 0) {
                    $account->update([
                        'topup_credits' => max(0, (int) $account->topup_credits - $fromTopup),
                    ]);
                }

                return true;
            }
        }

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

    public function addTopup(Workspace $workspace, int $credits): WorkspaceAiSetting
    {
        $billing = app(BillingService::class);
        $subscription = $billing->subscription($workspace);
        $pool = $this->resolveCreditPool($workspace, $subscription, $billing);

        if ($pool['source'] === 'account' && $pool['billing_account_id']) {
            BillingAccount::query()
                ->whereKey($pool['billing_account_id'])
                ->increment('topup_credits', max(0, $credits));

            $homeId = $pool['wallet_home_id'] ?: (int) $workspace->id;

            return $this->settings(Workspace::query()->find($homeId) ?? $workspace);
        }

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
        $account = app(BillingAccountService::class)->accountForWorkspace($workspace);
        if ($account) {
            return CreditRecharge::query()
                ->where('billing_account_id', $account->id)
                ->with('workspace:id,name')
                ->latest()
                ->limit($limit)
                ->get();
        }

        return CreditRecharge::query()
            ->where('workspace_id', $workspace->id)
            ->with('workspace:id,name')
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
     *   wallet_home_id:int|null,
     *   billing_account_id:?int
     * }
     */
    private function resolveCreditPool(
        Workspace $workspace,
        WorkspaceSubscription $subscription,
        BillingService $billing
    ): array {
        $accounts = app(BillingAccountService::class);
        $account = $accounts->accountForWorkspace($workspace);

        if ($account && ($accounts->workspaceIsCovered($workspace, $account) || $account->topup_credits > 0 || $this->isPaidAccount($account))) {
            $covered = $accounts->coveredWorkspaceIds($account);
            $limit = (float) ($account->limits['ai_budget_usd'] ?? 0);

            return [
                'plan_limit_usd' => $limit,
                'plan_used_usd' => (float) $account->spent_usd,
                'topup_credits' => (int) $account->topup_credits,
                'source' => 'account',
                'workspace_ids' => $covered,
                'wallet_home_id' => (int) ($account->billing_workspace_id ?: ($covered[0] ?? $workspace->id)),
                'billing_account_id' => (int) $account->id,
            ];
        }

        $settings = $this->settings($workspace);

        return [
            'plan_limit_usd' => (float) ($subscription->limits['ai_budget_usd'] ?? $settings->monthly_budget_usd ?? 0),
            'plan_used_usd' => (float) $settings->spent_usd,
            'topup_credits' => (int) ($settings->topup_credits ?? 0),
            'source' => 'workspace',
            'workspace_ids' => [(int) $workspace->id],
            'wallet_home_id' => (int) $workspace->id,
            'billing_account_id' => null,
        ];
    }

    private function isPaidAccount(BillingAccount $account): bool
    {
        if ($account->plan === 'free') {
            return false;
        }

        return ! in_array($account->status, ['cancelled', 'canceled', 'expired'], true);
    }
}
