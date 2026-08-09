<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\CmsConnection;
use App\Models\SeoContentDraft;
use App\Models\SeoLocalTarget;
use App\Models\SeoSite;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\BillingService;
use App\Services\Seo\SeoBacklinkService;
use App\Services\Seo\SeoCmsPublishService;
use App\Services\Seo\SeoCrawlerService;
use App\Services\Seo\SeoLocalPackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SeoV2FeaturesTest extends TestCase
{
    use RefreshDatabase;

    private function memberWithWorkspace(string $plan = 'starter'): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['name' => 'Acme Plumbers']);
        $workspace->users()->attach($user->id, ['role' => WorkspaceRole::Owner->value]);
        app(BillingService::class)->changePlan($workspace, $plan, 'active');

        return [$user, $workspace];
    }

    public function test_backlink_sync_stores_summary_and_links(): void
    {
        config([
            'services.dataforseo.login' => 'test@example.com',
            'services.dataforseo.password' => 'secret',
        ]);

        Http::fake([
            'https://api.dataforseo.com/v3/backlinks/summary/live' => Http::response([
                'status_code' => 20000,
                'cost' => 0.02,
                'tasks' => [[
                    'result' => [[
                        'backlinks' => 120,
                        'referring_domains' => 40,
                        'referring_links_dofollow' => 90,
                    ]],
                ]],
            ], 200),
            'https://api.dataforseo.com/v3/backlinks/backlinks/live' => Http::response([
                'status_code' => 20000,
                'cost' => 0.01,
                'tasks' => [[
                    'result' => [[
                        'items' => [[
                            'url_from' => 'https://news.example/post',
                            'domain_from' => 'news.example',
                            'url_to' => 'https://demo.test/',
                            'anchor' => 'best plumbers',
                            'dofollow' => true,
                            'rank' => 55,
                        ]],
                    ]],
                ]],
            ], 200),
        ]);

        [$user, $workspace] = $this->memberWithWorkspace();
        $site = SeoSite::query()->create([
            'workspace_id' => $workspace->id,
            'domain' => 'demo.test',
            'status' => 'connected',
        ]);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('seo.sites.backlinks', $site))
            ->assertRedirect()
            ->assertSessionHas('success');

        $site->refresh();
        $this->assertSame(120, $site->backlinks);
        $this->assertSame(40, $site->referring_domains);
        $this->assertDatabaseHas('seo_backlinks', [
            'seo_site_id' => $site->id,
            'source_domain' => 'news.example',
        ]);
    }

    public function test_local_pack_track_stores_snapshot(): void
    {
        config([
            'services.dataforseo.login' => 'test@example.com',
            'services.dataforseo.password' => 'secret',
        ]);

        Http::fake([
            'https://api.dataforseo.com/v3/serp/google/local_finder/live/advanced' => Http::response([
                'status_code' => 20000,
                'cost' => 0.002,
                'tasks' => [[
                    'result' => [[
                        'items' => [[
                            'type' => 'local_pack',
                            'items' => [
                                ['title' => 'Acme Plumbers Jaipur', 'domain' => 'demo.test', 'rating' => ['value' => 4.8]],
                                ['title' => 'Other Co', 'domain' => 'other.test'],
                            ],
                        ]],
                    ]],
                ]],
            ], 200),
        ]);

        [$user, $workspace] = $this->memberWithWorkspace();
        $target = SeoLocalTarget::query()->create([
            'workspace_id' => $workspace->id,
            'keyword' => 'plumber',
            'location_name' => 'Jaipur,India',
            'business_name' => 'Acme Plumbers',
        ]);

        $snap = app(SeoLocalPackService::class)->track($workspace, $target);
        $this->assertSame(1, $snap->our_rank);
        $this->assertCount(2, $snap->pack_json);
    }

    public function test_architecture_map_built_from_crawl(): void
    {
        Http::fake([
            'https://example.test/' => Http::response(
                '<html><head><title>Home</title></head><body><h1>Home</h1><a href="/about">About</a></body></html>',
                200
            ),
            'https://example.test/about' => Http::response(
                '<html><head><title>About</title><meta name="description" content="About page"/></head><body><h1>About</h1></body></html>',
                200
            ),
            'http://example.test/*' => Http::response('redirect', 301, ['Location' => 'https://example.test/']),
            'https://www.example.test/*' => Http::response('nf', 404),
        ]);

        [$user, $workspace] = $this->memberWithWorkspace();
        $site = SeoSite::query()->create([
            'workspace_id' => $workspace->id,
            'domain' => 'example.test',
            'status' => 'connected',
            'crawl_mode' => 'static',
        ]);

        $crawler = app(SeoCrawlerService::class);
        $crawler->crawl($site);
        $map = $crawler->architectureMap($site->fresh());

        $this->assertGreaterThanOrEqual(1, count($map['nodes']));
        $this->assertDatabaseHas('seo_links', ['seo_site_id' => $site->id]);
    }

    public function test_wordpress_publish_after_approve(): void
    {
        Http::fake([
            'https://blog.example.com/wp-json/wp/v2/users/me' => Http::response(['name' => 'Editor'], 200),
            'https://blog.example.com/wp-json/wp/v2/posts' => Http::response([
                'id' => 99,
                'link' => 'https://blog.example.com/guide-plumber',
            ], 201),
        ]);

        [$user, $workspace] = $this->memberWithWorkspace();
        // Ensure AI credits path works on starter
        $connection = CmsConnection::query()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'wordpress',
            'label' => 'WP',
            'base_url' => 'https://blog.example.com',
            'credentials' => [
                'base_url' => 'https://blog.example.com',
                'username' => 'editor',
                'app_password' => 'xxxx xxxx',
            ],
            'status' => 'active',
        ]);

        $cms = app(SeoCmsPublishService::class);
        $draft = $cms->createDraftFromKeyword($workspace, 'plumber jaipur');
        $this->assertSame('draft', $draft->status);

        $cms->approve($draft);
        $published = $cms->publish($workspace, $draft->fresh(), $connection);

        $this->assertSame('published', $published->status);
        $this->assertSame('https://blog.example.com/guide-plumber', $published->published_url);
    }

    public function test_backlinks_blocked_on_free_plan(): void
    {
        config([
            'services.dataforseo.login' => 'test@example.com',
            'services.dataforseo.password' => 'secret',
        ]);

        [$user, $workspace] = $this->memberWithWorkspace('free');
        $site = SeoSite::query()->create([
            'workspace_id' => $workspace->id,
            'domain' => 'demo.test',
            'status' => 'connected',
        ]);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('seo.sites.backlinks', $site))
            ->assertRedirect()
            ->assertSessionHas('error');
    }
}
