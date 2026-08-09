<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspacePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_create_workspace_via_web(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('workspaces.store'), ['name' => 'Acme Co'])
            ->assertRedirect(route('settings.index', ['tab' => 'workspace']));

        $this->assertDatabaseHas('workspace_user', [
            'user_id' => $user->id,
            'role' => WorkspaceRole::Owner->value,
        ]);

        $this->actingAs($user)
            ->get(route('settings.index', ['tab' => 'workspace']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Settings/Index')
                ->has('workspaces', 1)
                ->where('workspaces.0.name', 'Acme Co')
                ->where('workspaces.0.role', 'owner'));
    }

    public function test_non_member_cannot_switch_to_foreign_workspace(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();

        $workspace = Workspace::factory()->create();
        $workspace->users()->attach($owner->id, ['role' => WorkspaceRole::Owner->value]);

        $this->actingAs($stranger)
            ->post(route('workspaces.switch', $workspace))
            ->assertForbidden();
    }
}
