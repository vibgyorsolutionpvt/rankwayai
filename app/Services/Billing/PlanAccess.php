<?php

namespace App\Services\Billing;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceSubscription;
use App\Support\NavModules;

class PlanAccess
{
    /** Modules available without a paid plan or credit top-up. */
    public const FREE_MODULES = ['seo', 'billing', 'settings'];

    public function __construct(
        private BillingService $billing,
        private CreditWalletService $wallet,
    ) {}

    /**
     * @return array{
     *   plan:string,
     *   status:string,
     *   paid:bool,
     *   unlocked:bool,
     *   topup:int,
     *   features:array<string,bool>,
     *   modules:list<string>,
     *   workspace_limit:int,
     *   workspaces_used:int,
     *   account_plan:string
     * }
     */
    public function summary(Workspace $workspace): array
    {
        $local = $this->billing->subscription($workspace);
        $topupCredits = $this->wallet->snapshot($workspace, $local)['topup'];
        $unlocked = $this->hasUnlockedAccess($workspace);
        $account = $this->accountEntitlementForWorkspace($workspace);
        $effectivePlan = $this->isPaid($local)
            ? $local->plan
            : ($account['plan'] ?? 'free');

        return [
            'plan' => $effectivePlan,
            'status' => $this->isPaid($local) ? $local->status : ($account['status'] ?? $local->status),
            'paid' => $unlocked && ($this->isPaid($local) || $this->isPaid($account['subscription'] ?? null)),
            'unlocked' => $unlocked,
            'topup' => $topupCredits,
            'features' => $this->featuresFor($unlocked),
            'modules' => $this->modulesFor($workspace),
            'workspace_limit' => $account['limit'] ?? PlanCatalog::workspaceLimit('free'),
            'workspaces_used' => $account['used'] ?? 0,
            'account_plan' => $account['plan'] ?? 'free',
        ];
    }

    /**
     * @return array<string, bool>
     */
    public function features(Workspace $workspace): array
    {
        return $this->summary($workspace)['features'];
    }

    /**
     * @return list<string>
     */
    public function modulesFor(Workspace $workspace): array
    {
        if ($this->hasUnlockedAccess($workspace)) {
            return NavModules::keys();
        }

        return self::FREE_MODULES;
    }

    public function allows(Workspace $workspace, string $feature): bool
    {
        $features = $this->features($workspace);

        return (bool) ($features[$feature] ?? false);
    }

    /**
     * Paid local subscription, top-up credits, or covered by the owner's account plan seats.
     */
    public function hasUnlockedAccess(Workspace $workspace): bool
    {
        $sub = $this->billing->subscription($workspace);

        if ($this->isPaid($sub)) {
            return true;
        }

        if ($this->wallet->snapshot($workspace, $sub)['topup'] > 0) {
            return true;
        }

        return $this->coveredByAccountPlan($workspace);
    }

    public function workspaceLimitForUser(User $user): int
    {
        return $this->accountEntitlementForUser($user)['limit'];
    }

    public function ownedWorkspaceCount(User $user): int
    {
        return $user->workspaces()
            ->wherePivot('role', WorkspaceRole::Owner->value)
            ->count();
    }

    public function canCreateWorkspace(User $user): bool
    {
        return $this->ownedWorkspaceCount($user) < $this->workspaceLimitForUser($user);
    }

    public function denyCreateWorkspaceMessage(User $user): string
    {
        $ent = $this->accountEntitlementForUser($user);
        $limit = $ent['limit'];
        $plan = $ent['plan'];

        return "Your {$plan} plan includes {$limit} workspace(s). Upgrade to add more brands.";
    }

    public function denyMessage(string $feature): string
    {
        return match ($feature) {
            'ai' => 'AI needs a paid plan or credit top-up. Buy credits or upgrade to continue.',
            'channel_send' => 'Sending WhatsApp / Email / RCS needs a paid plan or credit top-up.',
            'seo_apis' => 'Google Search Console, PageSpeed, and SEO APIs need a paid plan or credit top-up.',
            'seo_metrics' => 'Keyword volume, difficulty, and live SERP ranks need a paid plan or credit top-up.',
            'seo_local' => 'Local pack / Maps rank tracking needs a paid plan or credit top-up.',
            'seo_backlinks' => 'Backlink data needs a paid plan or credit top-up.',
            'seo_cms' => 'CMS autopublish needs a paid plan or credit top-up.',
            'seo_js_crawl' => 'JavaScript / advanced crawl needs a paid plan or credit top-up.',
            'social_oauth' => 'Live social connect & publish needs a paid plan or credit top-up.',
            'social_publish' => 'Publishing to social networks needs a paid plan or credit top-up.',
            'api' => 'This action needs a paid plan or credit top-up. Buy credits or upgrade to continue.',
            default => 'Free includes SEO crawl and settings only. Buy credits or upgrade for APIs and other modules.',
        };
    }

    public function isPaid(?WorkspaceSubscription $sub): bool
    {
        if (! $sub) {
            return false;
        }

        if ($sub->plan === 'free') {
            return false;
        }

        return ! in_array($sub->status, ['cancelled', 'canceled', 'expired'], true);
    }

    /**
     * Best paid plan among workspaces this user owns.
     *
     * @return array{plan:string,status:string,limit:int,used:int,subscription:?WorkspaceSubscription,covered_ids:list<int>}
     */
    public function accountEntitlementForUser(User $user): array
    {
        $owned = $user->workspaces()
            ->wherePivot('role', WorkspaceRole::Owner->value)
            ->orderBy('workspaces.id')
            ->get(['workspaces.id']);

        $ownedIds = $owned->pluck('id')->map(fn ($id) => (int) $id)->all();
        $used = count($ownedIds);

        $best = null;
        $bestRank = 0;
        foreach ($ownedIds as $workspaceId) {
            $workspace = Workspace::query()->find($workspaceId);
            if (! $workspace) {
                continue;
            }
            $sub = $this->billing->subscription($workspace);
            if (! $this->isPaid($sub)) {
                continue;
            }
            $rank = PlanCatalog::planRank($sub->plan);
            if ($rank > $bestRank) {
                $bestRank = $rank;
                $best = $sub;
            }
        }

        $plan = $best?->plan ?? 'free';
        $limit = PlanCatalog::workspaceLimit($plan);
        $coveredIds = array_slice($ownedIds, 0, $limit);

        return [
            'plan' => $plan,
            'status' => $best?->status ?? 'active',
            'limit' => $limit,
            'used' => $used,
            'subscription' => $best,
            'covered_ids' => $coveredIds,
        ];
    }

    /**
     * @return array{plan:string,status:string,limit:int,used:int,subscription:?WorkspaceSubscription,covered_ids:list<int>}
     */
    public function accountEntitlementForWorkspace(Workspace $workspace): array
    {
        $owners = $workspace->users()
            ->wherePivot('role', WorkspaceRole::Owner->value)
            ->get();

        $best = [
            'plan' => 'free',
            'status' => 'active',
            'limit' => PlanCatalog::workspaceLimit('free'),
            'used' => 0,
            'subscription' => null,
            'covered_ids' => [],
        ];
        $bestRank = 0;

        foreach ($owners as $owner) {
            $ent = $this->accountEntitlementForUser($owner);
            $rank = PlanCatalog::planRank($ent['plan']);
            if ($rank > $bestRank) {
                $bestRank = $rank;
                $best = $ent;
            }
        }

        return $best;
    }

    private function coveredByAccountPlan(Workspace $workspace): bool
    {
        $owners = $workspace->users()
            ->wherePivot('role', WorkspaceRole::Owner->value)
            ->get();

        foreach ($owners as $owner) {
            $ent = $this->accountEntitlementForUser($owner);
            if (! $this->isPaid($ent['subscription'] ?? null)) {
                continue;
            }
            if (in_array((int) $workspace->id, $ent['covered_ids'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Free = crawl + settings only (no external APIs). Paid / top-up unlocks APIs + modules.
     *
     * @return array<string, bool>
     */
    private function featuresFor(bool $unlocked): array
    {
        return [
            'ai' => $unlocked,
            'api' => $unlocked,
            'channel_send' => $unlocked,
            'seo_apis' => $unlocked,
            'seo_metrics' => $unlocked,
            'seo_local' => $unlocked,
            'seo_backlinks' => $unlocked,
            'seo_cms' => $unlocked,
            'seo_js_crawl' => $unlocked,
            'social_oauth' => $unlocked,
            'social_publish' => $unlocked,
            'seo_audit' => true,
        ];
    }
}
