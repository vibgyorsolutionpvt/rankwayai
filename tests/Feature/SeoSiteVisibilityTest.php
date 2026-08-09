<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\SeoSite;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeoSiteVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_connected_sites_are_listed_and_selectable(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        $workspace->users()->attach($user->id, ['role' => WorkspaceRole::Owner->value]);

        SeoSite::query()->create([
            'workspace_id' => $workspace->id,
            'domain' => 'example-site.test',
            'status' => 'connected',
        ]);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('seo.sites.store'), [
                'domain' => 'https://www.vibgyorsolution.com/path',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('seo_sites', [
            'workspace_id' => $workspace->id,
            'domain' => 'vibgyorsolution.com',
        ]);

        $vibgyor = SeoSite::query()->where('domain', 'vibgyorsolution.com')->first();

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->get(route('seo.index', ['site' => $vibgyor->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Seo/Index')
                ->has('sites', 2)
                ->where('site.domain', 'vibgyorsolution.com'));
    }
}
