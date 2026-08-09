<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_workspace_assigns_owner_role(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/workspaces', ['name' => 'Acme Co']);

        $response
            ->assertCreated()
            ->assertJsonPath('data.name', 'Acme Co')
            ->assertJsonPath('data.role', WorkspaceRole::Owner->value);

        $this->assertDatabaseHas('workspace_user', [
            'user_id' => $user->id,
            'workspace_id' => $response->json('data.id'),
            'role' => WorkspaceRole::Owner->value,
        ]);
    }

    public function test_user_cannot_read_another_users_workspace(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();

        $workspace = Workspace::factory()->create(['name' => 'Private']);
        $workspace->users()->attach($owner->id, ['role' => WorkspaceRole::Owner->value]);

        $this->actingAs($stranger, 'sanctum')
            ->getJson('/api/workspaces/'.$workspace->id)
            ->assertForbidden();

        $this->actingAs($stranger, 'sanctum')
            ->getJson('/api/workspaces')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_viewer_cannot_mutate_members(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $invitee = User::factory()->create(['email' => 'new@example.com']);

        $workspace = Workspace::factory()->create();
        $workspace->users()->attach($owner->id, ['role' => WorkspaceRole::Owner->value]);
        $workspace->users()->attach($viewer->id, ['role' => WorkspaceRole::Viewer->value]);

        $this->actingAs($viewer, 'sanctum')
            ->postJson('/api/workspaces/'.$workspace->id.'/members', [
                'email' => $invitee->email,
                'role' => WorkspaceRole::Editor->value,
            ])
            ->assertForbidden();

        $this->actingAs($viewer, 'sanctum')
            ->patchJson('/api/workspaces/'.$workspace->id.'/members/'.$owner->id, [
                'role' => WorkspaceRole::Editor->value,
            ])
            ->assertForbidden();
    }

    public function test_owner_can_add_and_update_members(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create(['email' => 'editor@example.com']);

        $workspace = Workspace::factory()->create();
        $workspace->users()->attach($owner->id, ['role' => WorkspaceRole::Owner->value]);

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/workspaces/'.$workspace->id.'/members', [
                'email' => $member->email,
                'role' => WorkspaceRole::Editor->value,
            ])
            ->assertCreated()
            ->assertJsonPath('data.email', 'editor@example.com')
            ->assertJsonPath('data.role', WorkspaceRole::Editor->value);

        $this->actingAs($owner, 'sanctum')
            ->patchJson('/api/workspaces/'.$workspace->id.'/members/'.$member->id, [
                'role' => WorkspaceRole::Admin->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.role', WorkspaceRole::Admin->value);
    }

    public function test_x_workspace_id_header_rejects_non_members(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();

        $workspace = Workspace::factory()->create();
        $workspace->users()->attach($owner->id, ['role' => WorkspaceRole::Owner->value]);

        $this->actingAs($stranger, 'sanctum')
            ->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->getJson('/api/user')
            ->assertForbidden();
    }
}
