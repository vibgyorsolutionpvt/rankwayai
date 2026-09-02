<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgencyTeamTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_invite_member_to_multiple_workspaces_at_once(): void
    {
        $owner = User::factory()->create(['is_superadmin' => false]);
        $wsA = Workspace::factory()->create(['name' => 'Brand A']);
        $wsB = Workspace::factory()->create(['name' => 'Brand B']);
        $wsA->users()->attach($owner->id, ['role' => WorkspaceRole::Owner->value]);
        $wsB->users()->attach($owner->id, ['role' => WorkspaceRole::Owner->value]);

        $this->actingAs($owner)
            ->withSession(['active_workspace_id' => $wsA->id])
            ->post(route('account.team.invite'), [
                'name' => 'Priya',
                'email' => 'priya@agency.test',
                'role' => 'editor',
                'workspace_ids' => [$wsA->id, $wsB->id],
            ])
            ->assertRedirect();

        $member = User::query()->where('email', 'priya@agency.test')->first();
        $this->assertNotNull($member);
        $this->assertTrue($wsA->fresh()->hasMember($member));
        $this->assertTrue($wsB->fresh()->hasMember($member));
    }

    public function test_owner_can_remove_member_from_one_workspace_only(): void
    {
        $owner = User::factory()->create(['is_superadmin' => false]);
        $member = User::factory()->create(['is_superadmin' => false]);
        $wsA = Workspace::factory()->create(['name' => 'Brand A']);
        $wsB = Workspace::factory()->create(['name' => 'Brand B']);
        $wsA->users()->attach($owner->id, ['role' => WorkspaceRole::Owner->value]);
        $wsB->users()->attach($owner->id, ['role' => WorkspaceRole::Owner->value]);
        $wsA->users()->attach($member->id, ['role' => WorkspaceRole::Editor->value]);
        $wsB->users()->attach($member->id, ['role' => WorkspaceRole::Editor->value]);

        $this->actingAs($owner)
            ->withSession(['active_workspace_id' => $wsA->id])
            ->put(route('account.team.workspaces', $member), [
                'workspace_ids' => [$wsB->id],
            ])
            ->assertRedirect();

        $this->assertFalse($wsA->fresh()->hasMember($member));
        $this->assertTrue($wsB->fresh()->hasMember($member));
    }

    public function test_settings_shows_agency_team_roster(): void
    {
        $owner = User::factory()->create(['is_superadmin' => false]);
        $member = User::factory()->create(['email' => 'editor@agency.test']);
        $wsA = Workspace::factory()->create(['name' => 'Brand A']);
        $wsB = Workspace::factory()->create(['name' => 'Brand B']);
        $wsA->users()->attach($owner->id, ['role' => WorkspaceRole::Owner->value]);
        $wsB->users()->attach($owner->id, ['role' => WorkspaceRole::Owner->value]);
        $wsA->users()->attach($member->id, ['role' => WorkspaceRole::Editor->value]);
        app(BillingService::class)->changePlan($wsA, 'starter', 'active');

        $this->actingAs($owner)
            ->withSession(['active_workspace_id' => $wsA->id])
            ->get(route('settings.index', ['tab' => 'workspace']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Settings/Index')
                ->has('agencyTeam.owned_workspaces', 2)
                ->has('agencyTeam.members', 1));
    }
}
