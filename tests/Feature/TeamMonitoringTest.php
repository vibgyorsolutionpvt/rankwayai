<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\ActivityLog;
use App\Models\User;
use App\Models\UserLoginLog;
use App\Models\Workspace;
use App\Services\Billing\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamMonitoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_creates_login_history(): void
    {
        $user = User::factory()->create([
            'email' => 'client@example.com',
            'password' => bcrypt('Password1!'),
            'is_superadmin' => false,
        ]);
        $workspace = Workspace::factory()->create();
        $workspace->users()->attach($user->id, ['role' => 'owner']);

        $this->post('/login', [
            'email' => 'client@example.com',
            'password' => 'Password1!',
        ])->assertRedirect(route('home'));

        $this->assertDatabaseHas('user_login_logs', [
            'user_id' => $user->id,
            'channel' => 'web',
            'simulated' => false,
        ]);
    }

    public function test_superadmin_can_simulate_client_user(): void
    {
        $admin = User::factory()->create(['is_superadmin' => true]);
        $client = User::factory()->create(['is_superadmin' => false]);
        $workspace = Workspace::factory()->create(['name' => 'Client Co']);
        $workspace->users()->attach($client->id, ['role' => 'owner']);
        app(BillingService::class)->changePlan($workspace, 'starter', 'active');

        $this->actingAs($admin)
            ->post(route('admin.users.simulate', $client))
            ->assertRedirect(route('today'));

        $this->assertAuthenticatedAs($client);
        $this->assertSame($admin->id, session('impersonator_id'));

        $this->assertDatabaseHas('user_login_logs', [
            'user_id' => $client->id,
            'simulated' => true,
            'simulated_by' => $admin->id,
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $admin->id,
            'action' => 'admin.simulate_user',
        ]);
    }

    public function test_superadmin_can_leave_user_simulation(): void
    {
        $admin = User::factory()->create(['is_superadmin' => true]);
        $client = User::factory()->create(['is_superadmin' => false]);
        $workspace = Workspace::factory()->create();
        $workspace->users()->attach($client->id, ['role' => 'owner']);

        $this->actingAs($admin)
            ->withSession(['impersonator_id' => $admin->id])
            ->post(route('admin.users.simulate', $client));

        $this->actingAs($client)
            ->withSession(['impersonator_id' => $admin->id])
            ->post(route('admin.leave-simulation'))
            ->assertRedirect(route('admin.users'));

        $this->assertAuthenticatedAs($admin);
        $this->assertNull(session('impersonator_id'));
    }

    public function test_team_member_post_is_logged(): void
    {
        $user = User::factory()->create(['is_superadmin' => false]);
        $workspace = Workspace::factory()->create();
        $workspace->users()->attach($user->id, ['role' => 'owner']);
        app(BillingService::class)->changePlan($workspace, 'starter', 'active');

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('workspaces.switch', $workspace), [
                'redirect' => 'back',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $user->id,
            'action' => 'user.workspaces.switch',
        ]);
    }

    public function test_admin_activity_page_includes_login_history(): void
    {
        $admin = User::factory()->create(['is_superadmin' => true]);
        $client = User::factory()->create(['is_superadmin' => false]);

        UserLoginLog::query()->create([
            'user_id' => $client->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Test',
            'channel' => 'web',
            'logged_in_at' => now(),
        ]);

        ActivityLog::record(null, $client, 'user.social.posts.store', ['path' => '/social/posts']);

        $this->actingAs($admin)
            ->get(route('admin.activity', ['tab' => 'logins']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Activity')
                ->has('loginLogs.data', 1));

        $this->actingAs($admin)
            ->get(route('admin.activity', ['tab' => 'actions', 'user_id' => $client->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Activity')
                ->where('filters.user_id', $client->id));
    }

    public function test_workspace_admin_sees_team_history_in_settings(): void
    {
        $owner = User::factory()->create(['is_superadmin' => false]);
        $editor = User::factory()->create(['is_superadmin' => false, 'email' => 'editor@example.com']);
        $workspace = Workspace::factory()->create(['name' => 'Agency Co']);
        $workspace->users()->attach($owner->id, ['role' => 'owner']);
        $workspace->users()->attach($editor->id, ['role' => 'editor']);

        UserLoginLog::query()->create([
            'user_id' => $editor->id,
            'ip_address' => '10.0.0.5',
            'channel' => 'web',
            'logged_in_at' => now(),
        ]);

        ActivityLog::record($workspace, $editor, 'user.social.posts.store', [
            'path' => '/social/posts',
        ]);

        $this->actingAs($owner)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->get(route('settings.index', ['tab' => 'workspace', 'history_tab' => 'logins']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Settings/Index')
                ->where('canViewTeamHistory', true)
                ->has('teamHistory.login_logs', 1)
                ->has('teamHistory.action_logs', 1));

        $viewer = User::factory()->create(['is_superadmin' => false]);
        $workspace->users()->attach($viewer->id, ['role' => 'viewer']);

        $this->actingAs($viewer)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->get(route('settings.index', ['tab' => 'workspace']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('canViewTeamHistory', false)
                ->where('teamHistory', null));
    }

    public function test_team_member_login_opens_paid_workspace(): void
    {
        $owner = User::factory()->create(['is_superadmin' => false]);
        $member = User::factory()->create([
            'is_superadmin' => false,
            'email' => 'member@example.com',
            'password' => bcrypt('Password1!'),
        ]);

        $personal = Workspace::factory()->create(['name' => 'AAA Personal']);
        $personal->users()->attach($member->id, ['role' => WorkspaceRole::Owner->value]);
        app(BillingService::class)->changePlan($personal, 'free', 'active');

        $team = Workspace::factory()->create(['name' => 'Vibgyor Holidays']);
        $team->users()->attach($owner->id, ['role' => WorkspaceRole::Owner->value]);
        $team->users()->attach($member->id, ['role' => WorkspaceRole::Editor->value]);
        app(BillingService::class)->changePlan($team, 'starter', 'active');

        $this->post('/login', [
            'email' => 'member@example.com',
            'password' => 'Password1!',
        ])->assertRedirect(route('home'));

        $this->assertSame($team->id, session('active_workspace_id'));
    }

    public function test_cannot_simulate_superadmin(): void
    {
        $admin = User::factory()->create(['is_superadmin' => true]);
        $other = User::factory()->create(['is_superadmin' => true]);

        $this->actingAs($admin)
            ->post(route('admin.users.simulate', $other))
            ->assertStatus(422);
    }
}
