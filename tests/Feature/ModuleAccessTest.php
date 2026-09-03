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
        [$owner, $workspace] = $this->clientWithWorkspace();
        $editor = User::factory()->create(['is_superadmin' => false]);
        $workspace->users()->attach($editor->id, ['role' => WorkspaceRole::Editor->value]);

        $this->actingAs($owner)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->put(route('workspaces.modules.update', $workspace), [
                'modules' => ['today', 'crm', 'settings'],
                'inherit_all' => false,
            ])
            ->assertRedirect();

        $access = app(ModuleAccess::class);
        $this->assertSame(
            ['today', 'crm', 'settings'],
            array_values(array_intersect(['today', 'crm', 'settings'], $access->workspaceEnabledKeys($workspace->fresh())))
        );

        $this->actingAs($owner)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->put(route('workspaces.members.modules.update', [$workspace, $editor->id]), [
                'modules' => ['crm'],
                'inherit_all' => false,
            ])
            ->assertRedirect();

        $editorKeys = $access->userEnabledKeys($editor, $workspace->fresh());
        $this->assertSame(['crm'], $editorKeys);

        $this->actingAs($editor)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->get(route('crm.index'))
            ->assertOk();

        $this->actingAs($editor)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->get(route('today'))
            ->assertRedirect();
    }

    public function test_admin_dashboard_lists_menus(): void
    {
        $admin = User::factory()->create(['is_superadmin' => true]);

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Dashboard')
                ->has('menus'));
    }
}
