<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_lands_on_admin_dashboard(): void
    {
        $admin = User::factory()->create([
            'is_superadmin' => true,
        ]);

        $this->actingAs($admin)
            ->get(route('home'))
            ->assertRedirect(route('admin.dashboard'));

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Dashboard'));
    }

    public function test_client_cannot_open_admin_dashboard(): void
    {
        $client = User::factory()->create([
            'is_superadmin' => false,
        ]);

        $this->actingAs($client)
            ->get(route('home'))
            ->assertRedirect();

        $this->actingAs($client)
            ->get(route('admin.dashboard'))
            ->assertForbidden();
    }

    public function test_superadmin_can_manage_users_and_plans(): void
    {
        $admin = User::factory()->create(['is_superadmin' => true]);
        $owner = User::factory()->create(['is_superadmin' => false]);
        $workspace = Workspace::factory()->create(['name' => 'Acme Co']);
        $workspace->users()->attach($owner->id, ['role' => WorkspaceRole::Owner->value]);

        $this->actingAs($admin)
            ->get(route('admin.users'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Users'));

        $this->actingAs($admin)
            ->post(route('admin.users.store'), [
                'name' => 'New Client',
                'email' => 'new@example.com',
                'password' => 'Password1!',
                'is_superadmin' => true, // ignored — always client
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('users', [
            'email' => 'new@example.com',
            'is_superadmin' => false,
        ]);

        $created = User::query()->where('email', 'new@example.com')->first();
        $this->assertNotNull($created);
        $this->assertFalse($created->workspaces()->exists());

        $this->actingAs($admin)
            ->post(route('admin.users.store'), [
                'name' => 'Client With Brand',
                'email' => 'brand@example.com',
                'password' => 'Password1!',
                'workspace_name' => 'Brand Co',
            ])
            ->assertRedirect();

        $withWorkspace = User::query()->where('email', 'brand@example.com')->first();
        $this->assertNotNull($withWorkspace);
        $this->assertTrue($withWorkspace->workspaces()->exists());


        $this->actingAs($admin)
            ->get(route('admin.workspaces'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Workspaces'));

        $this->actingAs($admin)
            ->patch(route('admin.workspaces.update', $workspace), [
                'plan' => 'growth',
                'status' => 'active',
                'billing_market' => 'in',
                'billing_interval' => 'month',
            ])
            ->assertRedirect();

        $sub = app(BillingService::class)->subscription($workspace->fresh());
        $this->assertSame('growth', $sub->plan);
        $this->assertSame('manual', $sub->billing_provider);
    }

    public function test_superadmin_flag_cannot_be_changed_from_panel(): void
    {
        $admin = User::factory()->create(['is_superadmin' => true]);
        $client = User::factory()->create(['is_superadmin' => false]);

        $this->actingAs($admin)
            ->patch(route('admin.users.update', $client), [
                'is_superadmin' => true,
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertFalse($client->fresh()->is_superadmin);

        $this->actingAs($admin)
            ->patch(route('admin.users.update', $admin), [
                'is_superadmin' => false,
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertTrue($admin->fresh()->is_superadmin);
    }

    public function test_superadmin_can_disable_client_and_open_workspace(): void
    {
        $admin = User::factory()->create(['is_superadmin' => true]);
        $client = User::factory()->create(['is_superadmin' => false, 'is_active' => true]);
        $workspace = Workspace::factory()->create();

        $this->actingAs($admin)
            ->patch(route('admin.users.update', $client), ['is_active' => false])
            ->assertRedirect();

        $this->assertFalse($client->fresh()->is_active);

        $this->actingAs($admin)
            ->post(route('admin.workspaces.enter', $workspace))
            ->assertRedirect(route('today'));

        $this->actingAs($admin)
            ->get(route('admin.billing'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Billing'));

        $this->actingAs($admin)
            ->get(route('admin.system'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/System'));

        $this->actingAs($admin)
            ->patch(route('admin.system.update'), [
                'contact_email' => 'ops@rankwayai.com',
                'contact_phone' => '+91 9889995999',
            ])
            ->assertRedirect();
    }
}
