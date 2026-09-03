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
            ->post(route('workspaces.store'), ['domain' => 'https://www.acme-co.test'])
            ->assertRedirect();

        $this->assertDatabaseHas('workspace_user', [
            'user_id' => $user->id,
            'role' => WorkspaceRole::Owner->value,
        ]);

        $this->assertDatabaseHas('workspaces', [
            'name' => 'acme-co.test',
            'website' => 'https://acme-co.test',
        ]);

        $this->assertDatabaseHas('seo_sites', [
            'domain' => 'acme-co.test',
        ]);

        $this->actingAs($user)
            ->get(route('settings.index', ['tab' => 'workspace']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Settings/Index')
                ->has('workspaces', 1)
                ->where('workspaces.0.name', 'acme-co.test')
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

    public function test_workspace_switch_clears_compose_ai_flash(): void
    {
        $user = User::factory()->create();
        $a = Workspace::factory()->create(['name' => 'Alpha']);
        $b = Workspace::factory()->create(['name' => 'Beta']);
        $a->users()->attach($user->id, ['role' => WorkspaceRole::Owner->value]);
        $b->users()->attach($user->id, ['role' => WorkspaceRole::Owner->value]);

        $this->actingAs($user)
            ->withSession([
                'active_workspace_id' => $a->id,
                'ai_compose' => [
                    'title' => 'Old brand title',
                    'body' => 'Old brand caption',
                    'platforms' => ['instagram'],
                ],
                'ai_prompt' => 'old prompt about Alpha services',
                'ai_offer' => 'Book Alpha',
            ])
            ->from(route('social.index', ['view' => 'compose']))
            ->post(route('workspaces.switch', $b), ['redirect' => 'back'])
            ->assertRedirect(route('social.index', ['view' => 'compose']));

        $this->assertSame($b->id, (int) session('active_workspace_id'));
        $this->assertNull(session('ai_compose'));
        $this->assertNull(session('ai_prompt'));
        $this->assertNull(session('ai_offer'));
    }
}
