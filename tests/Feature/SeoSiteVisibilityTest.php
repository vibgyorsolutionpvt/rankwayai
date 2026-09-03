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

    public function test_workspace_keeps_one_site_and_rejects_second_domain(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        $workspace->users()->attach($user->id, ['role' => WorkspaceRole::Owner->value]);

        SeoSite::query()->create([
            'workspace_id' => $workspace->id,
            'domain' => 'vibgyorsolution.com',
            'status' => 'connected',
        ]);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('seo.sites.store'), [
                'domain' => 'https://www.sddsds.com/path',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(1, SeoSite::query()->where('workspace_id', $workspace->id)->count());
        $this->assertDatabaseHas('seo_sites', [
            'workspace_id' => $workspace->id,
            'domain' => 'vibgyorsolution.com',
        ]);
        $this->assertDatabaseMissing('seo_sites', [
            'workspace_id' => $workspace->id,
            'domain' => 'sddsds.com',
        ]);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->get(route('seo.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Seo/Index')
                ->has('sites', 1)
                ->where('sites.0.domain', 'vibgyorsolution.com'));
    }
}
