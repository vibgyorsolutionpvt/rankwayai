<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\SeoKeyword;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\BillingService;
use App\Services\Seo\Providers\DataForSeoClient;
use App\Services\Seo\SeoKeywordMetricsService;
use App\Services\Seo\SeoRankTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DataForSeoSeoIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function memberWithWorkspace(string $plan = 'starter'): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        $workspace->users()->attach($user->id, ['role' => WorkspaceRole::Owner->value]);
        app(BillingService::class)->changePlan($workspace, $plan, 'active');

        return [$user, $workspace];
    }

    public function test_metrics_refresh_uses_dataforseo_and_stores_volume_kd(): void
    {
        config([
            'services.dataforseo.login' => 'test@example.com',
            'services.dataforseo.password' => 'secret',
            'seo.providers.metrics' => 'dataforseo',
        ]);

        Http::fake([
            'https://api.dataforseo.com/v3/dataforseo_labs/google/keyword_overview/live' => Http::response([
                'status_code' => 20000,
                'cost' => 0.01,
                'tasks' => [[
                    'result' => [[
                        'items' => [[
                            'keyword' => 'plumber jaipur',
                            'keyword_info' => [
                                'search_volume' => 2400,
                                'cpc' => 1.25,
                                'competition' => 0.42,
                            ],
                            'keyword_properties' => [
                                'keyword_difficulty' => 28,
                            ],
                        ]],
                    ]],
                ]],
            ], 200),
        ]);

        [$user, $workspace] = $this->memberWithWorkspace('starter');
        SeoKeyword::query()->create([
            'workspace_id' => $workspace->id,
            'keyword' => 'plumber jaipur',
            'group_name' => 'Local',
        ]);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('seo.keywords.metrics'))
            ->assertRedirect()
            ->assertSessionHas('success');

        $kw = SeoKeyword::query()->first();
        $this->assertSame(2400, $kw->search_volume);
        $this->assertSame(28, $kw->keyword_difficulty);
        $this->assertSame('dataforseo', $kw->metrics_provider);
        $this->assertDatabaseHas('seo_api_usage_logs', [
            'workspace_id' => $workspace->id,
            'provider' => 'dataforseo',
            'operation' => 'keyword_metrics',
        ]);
        $this->assertTrue(DataForSeoClient::configured());
    }

    public function test_metrics_blocked_on_free_plan(): void
    {
        config([
            'services.dataforseo.login' => 'test@example.com',
            'services.dataforseo.password' => 'secret',
        ]);

        [$user, $workspace] = $this->memberWithWorkspace('free');
        SeoKeyword::query()->create([
            'workspace_id' => $workspace->id,
            'keyword' => 'test',
        ]);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('seo.keywords.metrics'))
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_live_serp_rank_uses_dataforseo_when_paid(): void
    {
        config([
            'services.dataforseo.login' => 'test@example.com',
            'services.dataforseo.password' => 'secret',
            'seo.providers.ranks' => 'dataforseo',
        ]);

        Http::fake([
            'https://api.dataforseo.com/v3/serp/google/organic/live/advanced' => Http::response([
                'status_code' => 20000,
                'cost' => 0.002,
                'tasks' => [[
                    'result' => [[
                        'items' => [
                            [
                                'type' => 'organic',
                                'rank_group' => 7,
                                'url' => 'https://www.demo.test/services',
                            ],
                        ],
                    ]],
                ]],
            ], 200),
        ]);

        [$user, $workspace] = $this->memberWithWorkspace('starter');
        $workspace->seoSites()->create([
            'domain' => 'demo.test',
            'status' => 'connected',
        ]);
        SeoKeyword::query()->create([
            'workspace_id' => $workspace->id,
            'keyword' => 'demo services',
            'position' => 12,
        ]);

        app(SeoRankTracker::class)->track($workspace);

        $kw = SeoKeyword::query()->first();
        $this->assertSame(7, $kw->position);
        $this->assertSame('dataforseo', $kw->rank_provider);
        $this->assertSame(5, $kw->position_change); // improved 12 → 7
    }

    public function test_metrics_service_requires_credentials(): void
    {
        config([
            'services.dataforseo.login' => null,
            'services.dataforseo.password' => null,
        ]);

        [$user, $workspace] = $this->memberWithWorkspace('starter');

        $this->expectException(\RuntimeException::class);
        app(SeoKeywordMetricsService::class)->refresh($workspace);
    }
}
