<?php

namespace App\Services\Workspaces;

use App\Enums\WorkspaceRole;
use App\Models\ActivityLog;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\BillingService;
use App\Services\Billing\PlanAccess;
use App\Services\Billing\PlanCatalog;
use Illuminate\Support\Collection;

class ProvisionClientWorkspace
{
    public function __construct(private BillingService $billing) {}

    /**
     * Pick the best workspace to open after login (paid/team workspaces first).
     */
    public function resolveActive(User $user): Workspace
    {
        /** @var Collection<int, Workspace> $workspaces */
        $workspaces = $user->workspaces()->get();

        if ($workspaces->isEmpty()) {
            return $this->for($user);
        }

        $plans = app(PlanAccess::class);

        return $workspaces
            ->sort(function (Workspace $a, Workspace $b) use ($plans) {
                $aUnlocked = $plans->hasUnlockedAccess($a) ? 1 : 0;
                $bUnlocked = $plans->hasUnlockedAccess($b) ? 1 : 0;
                if ($aUnlocked !== $bUnlocked) {
                    return $bUnlocked <=> $aUnlocked;
                }

                $aTeam = ($a->pivot->role ?? '') !== WorkspaceRole::Owner->value ? 1 : 0;
                $bTeam = ($b->pivot->role ?? '') !== WorkspaceRole::Owner->value ? 1 : 0;
                if ($aTeam !== $bTeam) {
                    return $bTeam <=> $aTeam;
                }

                return strcasecmp($a->name, $b->name);
            })
            ->first();
    }

    /**
     * Ensure the client has at least one workspace (owner) on a free plan.
     */
    public function for(User $user, ?string $workspaceName = null): Workspace
    {
        $existing = $user->workspaces()->orderBy('name')->first();
        if ($existing) {
            return $existing;
        }

        $name = trim((string) $workspaceName);
        if ($name === '') {
            $name = trim($user->name) !== ''
                ? $user->name."'s workspace"
                : 'My workspace';
        }

        $workspace = Workspace::query()->create([
            'name' => $name,
        ]);

        $workspace->users()->attach($user->id, [
            'role' => WorkspaceRole::Owner->value,
        ]);

        $this->billing->changePlan(
            $workspace,
            'free',
            'active',
            PlanCatalog::MARKET_IN,
            'manual',
            PlanCatalog::INTERVAL_MONTH
        );

        ActivityLog::record($workspace, $user, 'workspace.provisioned', [
            'name' => $workspace->name,
        ]);

        return $workspace;
    }
}
