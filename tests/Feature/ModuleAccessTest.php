<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\PlatformMenu;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Access\ModuleAccess;
use App\Services\Billing\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModuleAccessTest extends TestCase
{
    use RefreshDatabase;

    private function clientWithWorkspace(): array
    {
        $user = User::factory()->create(['is_superadmin' => false]);
        $workspace = Workspace::factory()->create();
        $workspace->users()->attach($user->id, ['role' => WorkspaceRole::Owner->value]);
        app(BillingService::class)->changePlan($workspace, 'starter', 'active');

        return [$user, $workspace];
    }

    public function test_superadmin_can_disable_menu_globally(): void
    {
        $admin = User::factory()->create(['is_superadmin' => true]);
        [$client, $workspace] = $this->clientWithWorkspace();

        $this->actingAs($admin)
            ->patch(route('admin.menus.update', 'funnels'), ['enabled' => false])
            ->assertRedirect();

        $this->assertFalse(PlatformMenu::query()->where('key', 'funnels')->value('enabled'));

        $this->actingAs($client)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->get(route('funnels.index'))
            ->assertRedirect();

        $nav = app(ModuleAccess::class)->navItemsFor($client, $workspace);
        $this->assertFalse(collect($nav)->contains(fn ($item) => $item['key'] === 'funnels'));

        foreach (['channels', 'whatsapp', 'crm', 'billing'] as $key) {
            $this->actingAs($admin)
                ->patch(route('admin.menus.update', $key), ['enabled' => false])
                ->assertRedirect();
        }

        $this->actingAs($client)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->get(route('settings.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('navigation', fn ($items) => collect($items)->pluck('key')->intersect([
                    'channels', 'whatsapp', 'crm', 'funnels', 'billing',
                ])->isEmpty()));
    }

    public function test_workspace_admin_can_limit_modules_and_members(): void
    {
        app(ModuleAccess::class)->setPlatformMenu('leads', true);
        app(ModuleAccess::class)->setPlatformMenu('email', true);
        [$owner, $workspace] = $this->clientWithWorkspace();
        $editor = User::factory()->create(['is_superadmin' => false]);
        $workspace->users()->attach($editor->id, ['role' => WorkspaceRole::Editor->value]);

        $this->actingAs($owner)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->put(route('workspaces.modules.update', $workspace), [
                'modules' => ['today', 'crm', 'email', 'settings'],
                'inherit_all' => false,
            ])
            ->assertRedirect();

        $access = app(ModuleAccess::class);
        $this->assertSame(
            ['today', 'crm', 'email', 'settings'],
            array_values(array_intersect(['today', 'crm', 'email', 'settings'], $access->workspaceEnabledKeys($workspace->fresh())))
        );

        $this->actingAs($owner)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->put(route('workspaces.members.modules.update', [$workspace, $editor->id]), [
                'modules' => ['crm', 'email'],
                'inherit_all' => false,
            ])
            ->assertRedirect();

        $editorKeys = $access->userEnabledKeys($editor, $workspace->fresh());
        $this->assertSame(['email', 'crm'], $editorKeys);

        $this->actingAs($editor)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->get(route('crm.index'))
            ->assertOk();

        $this->actingAs($editor)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->get(route('dashboard'))
            ->assertRedirect();

        $this->actingAs($editor)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->get(route('email.index'))
            ->assertOk();
    }

    public function test_admin_dashboard_lists_menus(): void
    {
        $admin = User::factory()->create(['is_superadmin' => true]);
        $businessGroup = collect(app(ModuleAccess::class)->platformMenuGroups())
            ->firstWhere('key', 'business_section');
        $marketingGroup = collect(app(ModuleAccess::class)->platformMenuGroups())
            ->firstWhere('key', 'marketing');

        $this->assertNotContains('media', collect($businessGroup['children'])->pluck('key')->all());
        $this->assertNotContains('media', collect($marketingGroup['children'])->pluck('key')->all());
        $this->assertContains('blog', collect($marketingGroup['children'])->pluck('key')->all());
        $this->assertContains('channels', collect($marketingGroup['children'])->pluck('key')->all());
        $this->assertContains('media', app(ModuleAccess::class)->globallyEnabledKeys());

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Dashboard')
                ->has('menus')
                ->has('menuGroups', 5)
                ->where('menuGroups.0.key', 'business_section')
                ->where('menuGroups.0.enabled', true)
                ->where('menuGroups.1.key', 'marketing')
                ->where('menuGroups.1.enabled', true)
                ->where('menuGroups.2.key', 'leads')
                ->where('menuGroups.2.enabled', false)
                ->where('menuGroups.3.key', 'sales')
                ->where('menuGroups.3.enabled', false)
                ->where('menuGroups.4.key', 'business_studio')
                ->where('menuGroups.4.enabled', true));
    }

    public function test_legacy_studio_permissions_are_normalized_for_workspace_settings(): void
    {
        [$owner, $workspace] = $this->clientWithWorkspace();
        $workspace->forceFill(['enabled_modules' => ['studio']])->save();
        $workspace->users()->updateExistingPivot($owner->id, [
            'enabled_modules' => json_encode(['studio']),
        ]);

        $this->actingAs($owner)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->get(route('settings.index', ['tab' => 'workspace']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('moduleCatalog.workspace_modules', ['business_card', 'brochure'])
                ->where('members.0.enabled_modules', ['business_card', 'brochure']));
    }

    public function test_superadmin_can_toggle_leads_sales_and_email_menus(): void
    {
        $admin = User::factory()->create(['is_superadmin' => true]);
        [$client, $workspace] = $this->clientWithWorkspace();
        $access = app(ModuleAccess::class);

        $menuStates = collect($access->platformMenuStates())->keyBy('key');
        foreach (['leads', 'sales', 'email'] as $key) {
            $this->assertFalse($menuStates[$key]['enabled']);
        }

        $this->assertFalse(collect($access->navItemsFor($client, $workspace))
            ->pluck('key')
            ->intersect(['funnels', 'crm'])
            ->isNotEmpty());
        $this->assertFalse(in_array('email', $access->globallyEnabledKeys(), true));

        $this->actingAs($client)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->get(route('funnels.index'))
            ->assertRedirect();

        $this->actingAs($admin)
            ->patch(route('admin.menus.update', 'leads'), ['enabled' => true])
            ->assertRedirect();

        $this->assertTrue(collect($access->navItemsFor($client, $workspace))
            ->pluck('key')
            ->intersect(['funnels', 'crm'])
            ->isNotEmpty());

        $this->actingAs($admin)
            ->patch(route('admin.menus.update', 'sales'), ['enabled' => true])
            ->assertRedirect();

        $this->actingAs($admin)
            ->patch(route('admin.menus.update', 'email'), ['enabled' => true])
            ->assertRedirect();

        $this->actingAs($client)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->get(route('email.index'))
            ->assertOk();

        $this->actingAs($admin)
            ->patch(route('admin.menus.update', 'quotations'), ['enabled' => false])
            ->assertRedirect();

        $this->actingAs($client)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->get(route('studio.quotations.index'))
            ->assertRedirect();

        $this->actingAs($admin)
            ->patch(route('admin.menus.update', 'quotations'), ['enabled' => true])
            ->assertRedirect();

        $this->actingAs($admin)
            ->patch(route('admin.menus.update', 'sales'), ['enabled' => false])
            ->assertRedirect();

        $this->actingAs($client)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->get(route('studio.quotations.index'))
            ->assertRedirect();

        $this->actingAs($admin)
            ->patch(route('admin.menus.update', 'email'), ['enabled' => false])
            ->assertRedirect();

        $this->actingAs($client)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->get(route('email.index'))
            ->assertRedirect();

        $this->actingAs($client)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->put(route('workspaces.modules.update', $workspace), [
                'modules' => ['email'],
                'inherit_all' => false,
            ])
            ->assertRedirect();

        $this->assertNotContains('email', app(ModuleAccess::class)->workspaceEnabledKeys($workspace->fresh()));
    }
}
