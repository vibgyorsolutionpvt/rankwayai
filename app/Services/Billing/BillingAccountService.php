<?php

namespace App\Services\Billing;

use App\Enums\WorkspaceRole;
use App\Models\BillingAccount;
use App\Models\BillingTransaction;
use App\Models\CreditRecharge;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceAiSetting;
use App\Models\WorkspaceSubscription;
use Illuminate\Support\Collection;

class BillingAccountService
{
    public function account(User $user): BillingAccount
    {
        $account = BillingAccount::query()->firstOrCreate(
            ['user_id' => $user->id],
            array_merge(
                [
                    'plan' => 'free',
                    'status' => 'active',
                    'billing_provider' => 'manual',
                    'billing_interval' => PlanCatalog::INTERVAL_MONTH,
                ],
                WorkspaceSubscription::defaultsForPlan('free', PlanCatalog::MARKET_IN, PlanCatalog::INTERVAL_MONTH),
                ['spent_usd' => 0, 'topup_credits' => 0],
            ),
        );

        if ($account->wasRecentlyCreated) {
            $this->importLegacyAccountState($user, $account);
        }

        return $account->fresh();
    }

    public function accountForWorkspace(Workspace $workspace): ?BillingAccount
    {
        $owner = $this->primaryOwner($workspace);

        return $owner ? $this->account($owner) : null;
    }

    public function primaryOwner(Workspace $workspace): ?User
    {
        return $workspace->users()
            ->wherePivot('role', WorkspaceRole::Owner->value)
            ->orderBy('workspace_user.created_at')
            ->first();
    }

    /**
     * @return list<int>
     */
    public function ownedWorkspaceIds(User $user): array
    {
        return $user->workspaces()
            ->wherePivot('role', WorkspaceRole::Owner->value)
            ->orderBy('workspaces.id')
            ->pluck('workspaces.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * All owned workspaces share the account plan (central billing).
     *
     * @return list<int>
     */
    public function coveredWorkspaceIds(BillingAccount $account): array
    {
        return $this->ownedWorkspaceIds($account->user);
    }

    public function workspaceIsCovered(Workspace $workspace, BillingAccount $account): bool
    {
        if (! in_array((int) $workspace->id, $this->ownedWorkspaceIds($account->user), true)) {
            return false;
        }

        if ($account->plan !== 'free' && ! in_array($account->status, ['cancelled', 'canceled', 'expired'], true)) {
            return true;
        }

        return (int) $account->topup_credits > 0;
    }

    public function syncWorkspaceSubscriptions(BillingAccount $account): void
    {
        $owned = $this->ownedWorkspaceIds($account->user);
        $covered = $this->coveredWorkspaceIds($account);
        $defaults = WorkspaceSubscription::defaultsForPlan(
            $account->plan,
            $account->billing_market ?: PlanCatalog::MARKET_IN,
            $account->billing_interval ?: PlanCatalog::INTERVAL_MONTH,
        );

        foreach ($owned as $workspaceId) {
            $workspace = Workspace::query()->find($workspaceId);
            if (! $workspace) {
                continue;
            }

            if (in_array($workspaceId, $covered, true) && $account->plan !== 'free') {
                $sub = WorkspaceSubscription::query()->firstOrCreate(
                    ['workspace_id' => $workspaceId],
                    array_merge(
                        [
                            'plan' => 'free',
                            'status' => 'active',
                            'billing_provider' => 'manual',
                            'billing_interval' => PlanCatalog::INTERVAL_MONTH,
                        ],
                        WorkspaceSubscription::defaultsForPlan('free', PlanCatalog::MARKET_IN, PlanCatalog::INTERVAL_MONTH),
                    ),
                );
                $sub->update([
                    'plan' => $account->plan,
                    'status' => $account->status,
                    'billing_provider' => $account->billing_provider,
                    'billing_market' => $account->billing_market,
                    'billing_currency' => $account->billing_currency,
                    'billing_interval' => $account->billing_interval,
                    'seats' => $defaults['seats'],
                    'mrr_usd' => $account->mrr_usd,
                    'mrr_amount' => $account->mrr_amount,
                    'limits' => $account->limits ?? $defaults['limits'],
                    'trial_ends_at' => $account->trial_ends_at,
                    'current_period_ends_at' => $account->current_period_ends_at,
                    'razorpay_customer_id' => $account->razorpay_customer_id,
                    'razorpay_subscription_id' => $account->razorpay_subscription_id,
                    'razorpay_payment_link_id' => $account->razorpay_payment_link_id,
                ]);

                $aiBudget = (float) ($account->limits['ai_budget_usd'] ?? 0);
                WorkspaceAiSetting::query()->updateOrCreate(
                    ['workspace_id' => $workspaceId],
                    ['monthly_budget_usd' => max($aiBudget, 0), 'template_first' => true, 'tone' => 'mixed'],
                );
                WorkspaceAiSetting::query()->where('workspace_id', $workspaceId)
                    ->update(['monthly_budget_usd' => max($aiBudget, 0)]);
            } else {
                $sub = WorkspaceSubscription::query()->firstOrCreate(
                    ['workspace_id' => $workspaceId],
                    array_merge(
                        [
                            'plan' => 'free',
                            'status' => 'active',
                            'billing_provider' => 'manual',
                            'billing_interval' => PlanCatalog::INTERVAL_MONTH,
                        ],
                        WorkspaceSubscription::defaultsForPlan('free', PlanCatalog::MARKET_IN, PlanCatalog::INTERVAL_MONTH),
                    ),
                );
                $freeDefaults = WorkspaceSubscription::defaultsForPlan(
                    'free',
                    $account->billing_market ?: PlanCatalog::MARKET_IN,
                    PlanCatalog::INTERVAL_MONTH,
                );
                $sub->update([
                    'plan' => 'free',
                    'status' => 'active',
                    'billing_provider' => 'manual',
                    'billing_market' => $freeDefaults['billing_market'],
                    'billing_currency' => $freeDefaults['billing_currency'],
                    'billing_interval' => PlanCatalog::INTERVAL_MONTH,
                    'seats' => $freeDefaults['seats'],
                    'mrr_usd' => $freeDefaults['mrr_usd'],
                    'mrr_amount' => $freeDefaults['mrr_amount'],
                    'limits' => $freeDefaults['limits'],
                    'trial_ends_at' => null,
                    'current_period_ends_at' => null,
                ]);

                $aiSettings = WorkspaceAiSetting::query()->firstOrCreate(
                    ['workspace_id' => $workspaceId],
                    ['monthly_budget_usd' => 0, 'template_first' => true, 'tone' => 'mixed'],
                );
                $aiSettings->update(['monthly_budget_usd' => 0]);
            }
        }
    }

    public function recordTransaction(
        BillingAccount $account,
        string $type,
        float $amount,
        string $currency,
        string $status = 'paid',
        ?string $provider = null,
        ?string $providerRef = null,
        ?User $user = null,
        ?Workspace $workspace = null,
        ?string $plan = null,
        ?string $packId = null,
        ?int $credits = null,
        ?array $meta = null,
    ): BillingTransaction {
        return BillingTransaction::query()->create([
            'billing_account_id' => $account->id,
            'user_id' => $user?->id ?? $account->user_id,
            'workspace_id' => $workspace?->id,
            'type' => $type,
            'plan' => $plan,
            'pack_id' => $packId,
            'credits' => $credits,
            'amount' => $amount,
            'currency' => $currency,
            'status' => $status,
            'provider' => $provider,
            'provider_ref' => $providerRef,
            'meta' => $meta,
        ]);
    }

    /**
     * @return Collection<int, BillingTransaction>
     */
    public function paymentHistory(BillingAccount $account, int $limit = 30): Collection
    {
        return BillingTransaction::query()
            ->where('billing_account_id', $account->id)
            ->with('workspace:id,name')
            ->latest()
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, CreditRecharge>
     */
    public function creditRechargeHistory(BillingAccount $account, int $limit = 30): Collection
    {
        return CreditRecharge::query()
            ->where('billing_account_id', $account->id)
            ->with('workspace:id,name')
            ->latest()
            ->limit($limit)
            ->get();
    }

    private function importLegacyAccountState(User $user, BillingAccount $account): void
    {
        $ownedIds = $this->ownedWorkspaceIds($user);
        if ($ownedIds === []) {
            return;
        }

        $best = null;
        $bestRank = 0;
        $topup = 0;
        $spent = 0.0;
        $billingWorkspaceId = $ownedIds[0];

        foreach ($ownedIds as $workspaceId) {
            $workspace = Workspace::query()->find($workspaceId);
            if (! $workspace) {
                continue;
            }
            $sub = WorkspaceSubscription::query()->firstOrCreate(
                ['workspace_id' => $workspaceId],
                array_merge(
                    [
                        'plan' => 'free',
                        'status' => 'active',
                        'billing_provider' => 'manual',
                        'billing_interval' => PlanCatalog::INTERVAL_MONTH,
                    ],
                    WorkspaceSubscription::defaultsForPlan('free', PlanCatalog::MARKET_IN, PlanCatalog::INTERVAL_MONTH),
                ),
            );
            $rank = PlanCatalog::planRank($sub->plan);
            if ($rank > $bestRank && $sub->plan !== 'free') {
                $bestRank = $rank;
                $best = $sub;
                $billingWorkspaceId = $workspaceId;
            }
            $settings = WorkspaceAiSetting::query()->where('workspace_id', $workspaceId)->first();
            if ($settings) {
                $topup += (int) $settings->topup_credits;
                $spent += (float) $settings->spent_usd;
            }
        }

        if ($best && $best->plan !== 'free') {
            $account->update([
                'plan' => $best->plan,
                'status' => $best->status,
                'billing_provider' => $best->billing_provider,
                'billing_market' => $best->billing_market,
                'billing_currency' => $best->billing_currency,
                'billing_interval' => $best->billing_interval,
                'seats' => $best->seats,
                'mrr_usd' => $best->mrr_usd,
                'mrr_amount' => $best->mrr_amount,
                'limits' => $best->limits,
                'trial_ends_at' => $best->trial_ends_at,
                'current_period_ends_at' => $best->current_period_ends_at,
                'razorpay_customer_id' => $best->razorpay_customer_id,
                'razorpay_subscription_id' => $best->razorpay_subscription_id,
                'razorpay_payment_link_id' => $best->razorpay_payment_link_id,
                'billing_workspace_id' => $billingWorkspaceId,
                'topup_credits' => $topup,
                'spent_usd' => $spent,
            ]);
            $this->syncWorkspaceSubscriptions($account->fresh());
        } elseif ($topup > 0 || $spent > 0) {
            $account->update([
                'topup_credits' => $topup,
                'spent_usd' => $spent,
                'billing_workspace_id' => $billingWorkspaceId,
            ]);
        }

        CreditRecharge::query()
            ->whereIn('workspace_id', $ownedIds)
            ->whereNull('billing_account_id')
            ->update(['billing_account_id' => $account->id]);
    }
}
