<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\SeoSite;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\CreditWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeoKeywordResearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_gsc_seed_research_returns_ideas_without_ai(): void
    {
        $user = User::factory()->create(['is_superadmin' => false]);
        $workspace = Workspace::factory()->create();
        $workspace->users()->attach($user->id, ['role' => WorkspaceRole::Owner->value]);

        $site = SeoSite::query()->create([
            'workspace_id' => $workspace->id,
            'domain' => 'research.test',
            'status' => 'connected',
            'gsc_connected' => true,
            'gsc_queries' => [
                [
                    'query' => 'seo agency near me',
                    'clicks' => 12,
                    'impressions' => 400,
                    'ctr' => 3.0,
                    'position' => 8.2,
                ],
            ],
        ]);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('seo.keywords.research'), [
                'site_id' => $site->id,
                'seed' => 'local seo',
            ])
            ->assertRedirect()
            ->assertSessionHas('success')
            ->assertSessionHas('keyword_research');

        $ideas = session('keyword_research');
        $this->assertIsArray($ideas);
        $this->assertTrue(
            collect($ideas)->contains(fn ($i) => ($i['keyword'] ?? '') === 'seo agency near me')
        );
        $this->assertTrue(
            collect($ideas)->contains(fn ($i) => str_contains((string) ($i['keyword'] ?? ''), 'local seo'))
        );
    }

    public function test_research_can_track_keyword_from_store(): void
    {
        $user = User::factory()->create(['is_superadmin' => false]);
        $workspace = Workspace::factory()->create();
        $workspace->users()->attach($user->id, ['role' => WorkspaceRole::Owner->value]);
        app(CreditWalletService::class)->addTopup($workspace, 100);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('seo.keywords.store'), [
                'keyword' => 'seo agency near me',
                'group_name' => 'GSC',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('seo_keywords', [
            'workspace_id' => $workspace->id,
            'keyword' => 'seo agency near me',
            'group_name' => 'GSC',
        ]);
    }
}
