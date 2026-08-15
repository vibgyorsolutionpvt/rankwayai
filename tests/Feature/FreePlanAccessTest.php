<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Access\ModuleAccess;
use App\Services\Billing\BillingService;
use App\Services\Billing\CreditWalletService;
use App\Services\Billing\PlanAccess;
use App\Support\NavModules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FreePlanAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_free_plan_only_allows_seo_audit_modules(): void
    {
        $user = User::factory()->create(['is_superadmin' => false]);
        $workspace = Workspace::factory()->create();
        $workspace->users()->attach($user->id, ['role' => WorkspaceRole::Owner->value]);
        app(BillingService::class)->changePlan($workspace, 'free', 'active');

        $plans = app(PlanAccess::class);
        $this->assertFalse($plans->isPaid($workspace->subscription));
        $this->assertSame(
            ['seo', 'billing', 'settings'],
            $plans->modulesFor($workspace)
        );
        $this->assertTrue($plans->allows($workspace, 'seo_audit'));
        $this->assertFalse($plans->allows($workspace, 'seo_apis'));
        $this->assertFalse($plans->allows($workspace, 'seo_metrics'));
        $this->assertFalse($plans->allows($workspace, 'channel_send'));
        $this->assertFalse($plans->allows($workspace, 'social_publish'));
        $this->assertFalse($plans->allows($workspace, 'ai'));
        $this->assertFalse($plans->allows($workspace, 'seo_backlinks'));

        $navKeys = collect(app(ModuleAccess::class)->navItemsFor($user, $workspace))
            ->pluck('key')
            ->all();

        // All menus visible; plan only locks access.
        $this->assertContains('seo', $navKeys);
        $this->assertContains('crm', $navKeys);
        $this->assertContains('ai', $navKeys);
        $this->assertContains('billing', $navKeys);

        $access = app(ModuleAccess::class);
        $this->assertTrue($access->canAccess($user, $workspace, 'seo'));
        $this->assertFalse($access->canAccess($user, $workspace, 'crm'));

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->get(route('seo.index'))
            ->assertOk();

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->get(route('crm.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Billing/PlanGate')
                ->where('module', 'crm'));
    }

    public function test_paid_plan_unlocks_api_modules(): void
    {
        $user = User::factory()->create(['is_superadmin' => false]);
        $workspace = Workspace::factory()->create();
        $workspace->users()->attach($user->id, ['role' => WorkspaceRole::Owner->value]);
        app(BillingService::class)->changePlan($workspace, 'starter', 'active');

        $plans = app(PlanAccess::class);
        $this->assertTrue($plans->isPaid($workspace->fresh()->subscription));
        $this->assertTrue($plans->allows($workspace, 'seo_apis'));
        $this->assertTrue($plans->allows($workspace, 'channel_send'));

        $navKeys = collect(app(ModuleAccess::class)->navItemsFor($user, $workspace))
            ->pluck('key')
            ->all();

        $this->assertContains('seo', $navKeys);
        $this->assertContains('crm', $navKeys);
        $this->assertContains('ai', $navKeys);
    }

    public function test_free_plan_with_topup_credits_unlocks_all_paid_features(): void
    {
        $user = User::factory()->create(['is_superadmin' => false]);
        $workspace = Workspace::factory()->create();
        $workspace->users()->attach($user->id, ['role' => WorkspaceRole::Owner->value]);
        app(BillingService::class)->changePlan($workspace, 'free', 'active');
        app(CreditWalletService::class)->addTopup($workspace, 500);

        $plans = app(PlanAccess::class);
        $this->assertFalse($plans->isPaid($workspace->subscription));
        $this->assertTrue($plans->hasUnlockedAccess($workspace));
        $this->assertTrue($plans->allows($workspace, 'ai'));
        $this->assertTrue($plans->allows($workspace, 'channel_send'));
        $this->assertTrue($plans->allows($workspace, 'social_publish'));
        $this->assertTrue($plans->allows($workspace, 'seo_backlinks'));
        $this->assertTrue($plans->allows($workspace, 'api'));
        $this->assertSame(NavModules::keys(), $plans->modulesFor($workspace));

        $access = app(ModuleAccess::class);
        $this->assertTrue($access->canAccess($user, $workspace, 'crm'));
        $this->assertTrue($access->canAccess($user, $workspace, 'ai'));

        $summary = $plans->summary($workspace);
        $this->assertFalse($summary['paid']);
        $this->assertTrue($summary['unlocked']);
        $this->assertSame(500, $summary['topup']);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->get(route('crm.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Crm/Index'));
    }

    public function test_starter_plan_covers_second_owned_workspace(): void
    {
        $user = User::factory()->create(['is_superadmin' => false]);
        $primary = Workspace::factory()->create(['name' => 'Primary']);
        $primary->users()->attach($user->id, ['role' => WorkspaceRole::Owner->value]);
        app(BillingService::class)->changePlan($primary, 'starter', 'active');

        $second = Workspace::factory()->create(['name' => 'Second Brand']);
        $second->users()->attach($user->id, ['role' => WorkspaceRole::Owner->value]);
        app(BillingService::class)->changePlan($second, 'free', 'active');

        $plans = app(PlanAccess::class);
        $this->assertTrue($plans->hasUnlockedAccess($primary));
        $this->assertTrue($plans->hasUnlockedAccess($second));
        $this->assertTrue($plans->allows($second, 'seo_apis'));
        $this->assertTrue($plans->allows($second, 'social_publish'));

        $third = Workspace::factory()->create(['name' => 'Third Brand']);
        $third->users()->attach($user->id, ['role' => WorkspaceRole::Owner->value]);
        app(BillingService::class)->changePlan($third, 'free', 'active');

        $this->assertFalse($plans->hasUnlockedAccess($third));
        $this->assertFalse($plans->allows($third, 'seo_apis'));
        $this->assertFalse($plans->canCreateWorkspace($user));
    }
}
