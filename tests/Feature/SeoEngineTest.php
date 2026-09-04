<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Jobs\CrawlAndAuditSeoSiteJob;
use App\Models\SeoIssue;
use App\Models\SeoKeyword;
use App\Models\SeoReport;
use App\Models\SeoSite;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Seo\SeoAuditEngine;
use App\Services\Seo\SeoCrawlerService;
use App\Services\Seo\SeoRankTracker;
use App\Services\Seo\SeoTaskGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SeoEngineTest extends TestCase
{
    use RefreshDatabase;

    private function memberWithWorkspace(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        $workspace->users()->attach($user->id, ['role' => WorkspaceRole::Owner->value]);

        return [$user, $workspace];
    }

    public function test_team_member_sees_owner_connected_seo_site(): void
    {
        $owner = User::factory()->create(['is_superadmin' => false]);
        $member = User::factory()->create(['is_superadmin' => false]);
        $workspace = Workspace::factory()->create([
            'name' => 'Vibgyor Solution',
            'website' => 'https://vibgyorsolution.com',
        ]);
        $workspace->users()->attach($owner->id, ['role' => WorkspaceRole::Owner->value]);
        $workspace->users()->attach($member->id, ['role' => WorkspaceRole::Editor->value]);

        SeoSite::query()->create([
            'workspace_id' => $workspace->id,
            'domain' => 'vibgyorsolution.com',
            'status' => 'connected',
            'gsc_connected' => true,
            'gsc_queries' => [
                [
                    'query' => 'vibgyor solution',
                    'page' => 'https://vibgyorsolution.com/',
                    'clicks' => 5,
                    'impressions' => 40,
                    'ctr' => 12.5,
                    'position' => 8.2,
                    'google_page' => 1,
                ],
            ],
            'gsc_summary' => ['clicks' => 5, 'impressions' => 40, 'keywords_count' => 1],
        ]);

        $this->actingAs($member)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->get(route('seo.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Seo/Index')
                ->has('sites', 1)
                ->where('sites.0.domain', 'vibgyorsolution.com')
                ->where('site.domain', 'vibgyorsolution.com')
                ->where('workspace.role', 'editor')
                ->where('site.gsc_connected', true));
    }

    public function test_live_crawl_stores_real_pages_and_site_specific_issues(): void
    {
        Http::fake([
            'https://example.test/' => Http::response(
                '<html><head><title>Example Home</title></head><body><h1>Home</h1><a href="/about">About</a><img src="/a.jpg"></body></html>',
                200
            ),
            'https://example.test/about' => Http::response(
                '<html><head><title>About Example</title><meta name="description" content="About us page description for testing"/></head><body><h1>About</h1></body></html>',
                200
            ),
            'http://example.test/*' => Http::response('redirect', 301, ['Location' => 'https://example.test/']),
            'https://www.example.test/*' => Http::response('not found', 404),
        ]);

        [$user, $workspace] = $this->memberWithWorkspace();
        $site = SeoSite::query()->create([
            'workspace_id' => $workspace->id,
            'domain' => 'example.test',
            'status' => 'connected',
        ]);

        (new CrawlAndAuditSeoSiteJob($site->id))->handle(
            app(SeoCrawlerService::class),
            app(SeoAuditEngine::class),
            app(SeoTaskGenerator::class)
        );

        $site->refresh();
        $this->assertSame('idle', $site->crawl_status);
        $this->assertTrue($site->pages()->count() >= 1);
        $this->assertDatabaseHas('seo_issues', [
            'seo_site_id' => $site->id,
            'code' => 'missing_meta_description',
        ]);
        // Message must reference this site's page path — not a generic copy for all tenants
        $issue = SeoIssue::query()->where('seo_site_id', $site->id)->where('code', 'missing_meta_description')->first();
        $this->assertStringContainsString('/', (string) $issue->message);

        $home = $site->pages()->where('url', 'https://example.test/')->first();
        $this->assertNotNull($home);
        $this->assertSame(1, (int) $home->images_missing_alt);
        $this->assertContains(
            'https://example.test/a.jpg',
            $home->audit_meta['images_missing_alt_srcs'] ?? []
        );
        $this->assertDatabaseHas('seo_issues', [
            'seo_site_id' => $site->id,
            'code' => 'images_missing_alt',
        ]);
    }

    public function test_empty_alt_attribute_is_not_flagged_as_missing(): void
    {
        Http::fake([
            'https://decor.test/' => Http::response(
                '<html><head><title>Decor Home</title><meta name="description" content="Decorative image page with enough text"/></head>'
                .'<body><h1>Home</h1>'
                .'<img src="/banner.png" alt=""/>'
                .'<img src="/hero.jpg" alt="Hero photo">'
                .'<img src="/no-alt.png">'
                .'</body></html>',
                200
            ),
            'http://decor.test/*' => Http::response('redirect', 301, ['Location' => 'https://decor.test/']),
            'https://www.decor.test/*' => Http::response('not found', 404),
        ]);

        [, $workspace] = $this->memberWithWorkspace();
        $site = SeoSite::query()->create([
            'workspace_id' => $workspace->id,
            'domain' => 'decor.test',
            'status' => 'connected',
        ]);

        (new CrawlAndAuditSeoSiteJob($site->id))->handle(
            app(SeoCrawlerService::class),
            app(SeoAuditEngine::class),
            app(SeoTaskGenerator::class)
        );

        $home = $site->pages()->where('url', 'https://decor.test/')->first();
        $this->assertNotNull($home);
        $this->assertSame(1, (int) $home->images_missing_alt);
        $this->assertSame(
            ['https://decor.test/no-alt.png'],
            $home->audit_meta['images_missing_alt_srcs'] ?? []
        );
    }

    public function test_crawl_skips_private_and_auth_urls(): void
    {
        Http::fake([
            'https://shop.test/' => Http::response(
                '<html><head><title>Shop Home</title><meta name="description" content="Public shop homepage with enough text"/></head><body><h1>Shop</h1>'
                .'<a href="/services">Services</a><a href="/cart">Cart</a><a href="/orders">Orders</a>'
                .'<a href="/profile">Profile</a><a href="/auth/login">Login</a><a href="/register">Register</a>'
                .'</body></html>',
                200
            ),
            'https://shop.test/services' => Http::response(
                '<html><head><title>Services</title><meta name="description" content="Our services page description text"/></head><body><h1>Services</h1></body></html>',
                200
            ),
            'https://shop.test/cart' => Http::response('<html><head><meta name="robots" content="noindex"/></head><body>Cart</body></html>', 200),
            'https://shop.test/orders' => Http::response('<html><head><meta name="robots" content="noindex"/></head><body>Orders</body></html>', 200),
            'https://shop.test/profile' => Http::response('<html><head><meta name="robots" content="noindex"/></head><body>Profile</body></html>', 200),
            'https://shop.test/auth/login' => Http::response('<html><head><meta name="robots" content="noindex"/></head><body>Login</body></html>', 200),
            'https://shop.test/register' => Http::response('<html><head><meta name="robots" content="noindex"/></head><body>Register</body></html>', 200),
            'http://shop.test/*' => Http::response('redirect', 301, ['Location' => 'https://shop.test/']),
            'https://www.shop.test/*' => Http::response('not found', 404),
        ]);

        [$user, $workspace] = $this->memberWithWorkspace();
        $site = SeoSite::query()->create([
            'workspace_id' => $workspace->id,
            'domain' => 'shop.test',
            'status' => 'connected',
        ]);

        (new CrawlAndAuditSeoSiteJob($site->id))->handle(
            app(SeoCrawlerService::class),
            app(SeoAuditEngine::class),
            app(SeoTaskGenerator::class)
        );

        $urls = $site->pages()->pluck('url')->all();
        $this->assertTrue(collect($urls)->contains(fn ($u) => str_contains($u, '/services')));
        foreach (['/cart', '/orders', '/profile', '/auth/login', '/register'] as $blocked) {
            $this->assertFalse(
                collect($urls)->contains(fn ($u) => str_contains($u, $blocked)),
                "Should not crawl {$blocked}"
            );
        }

        $this->assertDatabaseMissing('seo_issues', [
            'seo_site_id' => $site->id,
            'code' => 'noindex',
        ]);
    }

    public function test_unreachable_site_does_not_invent_fake_audit_pages(): void
    {
        Http::fake([
            '*' => function () {
                throw new \Illuminate\Http\Client\ConnectionException('Could not resolve host');
            },
        ]);

        [$user, $workspace] = $this->memberWithWorkspace();
        $site = SeoSite::query()->create([
            'workspace_id' => $workspace->id,
            'domain' => 'nope-does-not-exist.test',
            'status' => 'connected',
        ]);

        (new CrawlAndAuditSeoSiteJob($site->id))->handle(
            app(SeoCrawlerService::class),
            app(SeoAuditEngine::class),
            app(SeoTaskGenerator::class)
        );

        $site->refresh();
        $this->assertSame('failed', $site->crawl_status);
        $this->assertSame(0, $site->pages()->count());
        $this->assertDatabaseHas('seo_issues', [
            'seo_site_id' => $site->id,
            'code' => 'site_unreachable',
        ]);
        $this->assertSame(1, $site->issues()->where('status', 'open')->count());
    }

    public function test_gsc_button_is_honest_not_fake_connected(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();
        $site = SeoSite::query()->create([
            'workspace_id' => $workspace->id,
            'domain' => 'demo.test',
            'status' => 'connected',
        ]);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->get(route('seo.sites.gsc', $site))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertFalse((bool) $site->fresh()->gsc_connected);
    }

    public function test_gsc_sync_stores_query_rows_on_site(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();
        $site = SeoSite::query()->create([
            'workspace_id' => $workspace->id,
            'domain' => 'vibgyorsolution.com',
            'status' => 'connected',
            'gsc_connected' => true,
            'gsc_property' => 'sc-domain:vibgyorsolution.com',
            'gsc_token' => json_encode([
                'access_token' => 'test-access',
                'expires_at' => now()->addHour()->timestamp,
            ]),
        ]);

        \App\Models\WorkspaceSubscription::query()->updateOrCreate(
            ['workspace_id' => $workspace->id],
            ['plan' => 'growth', 'status' => 'active'],
        );

        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            if (str_ends_with($request->url(), '/webmasters/v3/sites')) {
                return Http::response([
                    'siteEntry' => [
                        ['siteUrl' => 'sc-domain:vibgyorsolution.com'],
                    ],
                ]);
            }

            if (str_contains($request->url(), '/searchAnalytics/query')) {
                $dimensions = $request['dimensions'] ?? [];
                if ($dimensions === ['page'] || (is_array($dimensions) && $dimensions === ['page'])) {
                    return Http::response([
                        'rows' => [
                            [
                                'keys' => ['https://vibgyorsolution.com/'],
                                'clicks' => 20,
                                'impressions' => 400,
                                'ctr' => 0.05,
                                'position' => 7.2,
                            ],
                        ],
                    ]);
                }

                return Http::response([
                    'rows' => [
                        [
                            'keys' => ['vibgyor solution', 'https://vibgyorsolution.com/'],
                            'clicks' => 12,
                            'impressions' => 200,
                            'ctr' => 0.06,
                            'position' => 8.4,
                        ],
                        [
                            'keys' => ['seo company noida', 'https://vibgyorsolution.com/seo'],
                            'clicks' => 3,
                            'impressions' => 90,
                            'ctr' => 0.033,
                            'position' => 14.1,
                        ],
                        [
                            'keys' => ['bibgyor', 'https://vibgyorsolution.com/'],
                            'clicks' => 0,
                            'impressions' => 2,
                            'ctr' => 0,
                            'position' => 6.5,
                        ],
                    ],
                ]);
            }

            return Http::response(['error' => 'unexpected'], 500);
        });

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('seo.sites.gsc.sync', $site))
            ->assertRedirect(route('seo.index', ['site' => $site->id, 'tab' => 'keywords']))
            ->assertSessionHas('success');

        $site->refresh();
        $this->assertNotNull($site->gsc_synced_at);
        $this->assertSame(15, $site->gsc_summary['clicks']);
        $this->assertSame('vibgyor solution', $site->gsc_queries[0]['query']);
        $this->assertSame('https://vibgyorsolution.com/', $site->gsc_queries[0]['page']);
        $this->assertSame(1, $site->gsc_queries[0]['google_page']);
        $this->assertSame(2, $site->gsc_queries[1]['google_page']);
        $this->assertSame(1, $site->gsc_queries[2]['google_page']);
        $this->assertSame(2, $site->gsc_queries[2]['impressions']);
        $this->assertSame(1, $site->gsc_summary['page1_keywords']);
        $this->assertCount(1, $site->gsc_summary['landing_pages']);
        $this->assertSame(1, $site->gsc_summary['landing_pages'][0]['google_page']);
    }

    public function test_gsc_sync_respects_cooldown_to_save_quota(): void
    {
        config(['seo.google.gsc_sync_cooldown_minutes' => 60]);

        [$user, $workspace] = $this->memberWithWorkspace();
        $site = SeoSite::query()->create([
            'workspace_id' => $workspace->id,
            'domain' => 'quota.test',
            'status' => 'connected',
            'gsc_connected' => true,
            'gsc_property' => 'sc-domain:quota.test',
            'gsc_token' => json_encode([
                'access_token' => 'test-access',
                'expires_at' => now()->addHour()->timestamp,
            ]),
            'gsc_synced_at' => now()->subMinutes(10),
            'gsc_queries' => [['query' => 'old', 'clicks' => 1, 'impressions' => 2, 'ctr' => 50, 'position' => 1]],
        ]);

        \App\Models\WorkspaceSubscription::query()->updateOrCreate(
            ['workspace_id' => $workspace->id],
            ['plan' => 'growth', 'status' => 'active'],
        );

        Http::fake();

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('seo.sites.gsc.sync', $site))
            ->assertRedirect()
            ->assertSessionHas('error');

        Http::assertNothingSent();
        $this->assertSame('old', $site->fresh()->gsc_queries[0]['query']);
    }

    public function test_pagespeed_stores_psi_style_categories_for_strategy(): void
    {
        config([
            'seo.google.pagespeed_cooldown_minutes' => 0,
        ]);

        [$user, $workspace] = $this->memberWithWorkspace();
        \App\Models\WorkspaceSubscription::query()->updateOrCreate(
            ['workspace_id' => $workspace->id],
            ['plan' => 'growth', 'status' => 'active'],
        );

        app(\App\Services\Integrations\WorkspaceIntegrationService::class)->upsert(
            $workspace,
            'google_pagespeed',
            ['api_key' => 'psi-test-key'],
            true,
        );

        $site = SeoSite::query()->create([
            'workspace_id' => $workspace->id,
            'domain' => 'psi-view.test',
            'status' => 'connected',
        ]);

        Http::fake([
            'https://www.googleapis.com/pagespeedonline/v5/*' => Http::response([
                'lighthouseResult' => [
                    'categories' => [
                        'performance' => [
                            'score' => 0.75,
                            'auditRefs' => [
                                ['id' => 'unused-javascript', 'group' => 'opportunities'],
                            ],
                        ],
                        'accessibility' => ['score' => 0.87],
                        'best-practices' => ['score' => 0.96],
                        'seo' => ['score' => 0.92],
                    ],
                    'audits' => [
                        'first-contentful-paint' => ['numericValue' => 1100, 'score' => 0.9],
                        'largest-contentful-paint' => ['numericValue' => 1800, 'score' => 0.85],
                        'total-blocking-time' => ['numericValue' => 120, 'score' => 0.9],
                        'cumulative-layout-shift' => ['numericValue' => 0.099, 'score' => 0.9],
                        'speed-index' => ['numericValue' => 2200, 'score' => 0.9],
                        'unused-javascript' => [
                            'title' => 'Reduce unused JavaScript',
                            'description' => 'Save bytes.',
                            'score' => 0.4,
                            'displayValue' => 'Est savings of 200 KiB',
                            'details' => ['overallSavingsMs' => 300],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('seo.sites.pagespeed', $site), ['strategy' => 'desktop'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $site->refresh();
        $this->assertSame('desktop', $site->pagespeed_strategy);
        $this->assertSame(75, (int) $site->pagespeed_score);
        $this->assertSame(1.8, (float) $site->cwv_lcp);
        $this->assertSame(75, $site->pagespeed_report['desktop']['categories']['performance']);
        $this->assertSame(87, $site->pagespeed_report['desktop']['categories']['accessibility']);
        $this->assertSame(96, $site->pagespeed_report['desktop']['categories']['best-practices']);
        $this->assertSame(92, $site->pagespeed_report['desktop']['categories']['seo']);
        $this->assertSame(1.1, $site->pagespeed_report['desktop']['metrics']['fcp']);
    }

    public function test_pagespeed_stops_when_google_daily_quota_exhausted(): void
    {
        config([
            'seo.google.pagespeed_queries_per_day' => 25000,
            'seo.google.pagespeed_queries_per_minute' => 240,
            'seo.google.pagespeed_quota_safety_percent' => 90,
            'seo.google.pagespeed_cooldown_minutes' => 0,
        ]);

        [$user, $workspace] = $this->memberWithWorkspace();
        \App\Models\WorkspaceSubscription::query()->updateOrCreate(
            ['workspace_id' => $workspace->id],
            ['plan' => 'growth', 'status' => 'active'],
        );

        app(\App\Services\Integrations\WorkspaceIntegrationService::class)->upsert($workspace, 'google_pagespeed', [
            'enabled' => true,
            'credentials' => ['api_key' => 'psi-test-key'],
        ]);

        $site = SeoSite::query()->create([
            'workspace_id' => $workspace->id,
            'domain' => 'speed.test',
            'status' => 'connected',
        ]);

        $quota = app(\App\Services\Seo\GooglePagespeedQuota::class);
        $limit = $quota->effectiveDailyLimit();
        for ($i = 0; $i < $limit; $i++) {
            $quota->record('psi-test-key');
        }

        Http::fake();

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('seo.sites.pagespeed', $site))
            ->assertRedirect()
            ->assertSessionHas('error');

        Http::assertNothingSent();
    }

    public function test_build_todos_syncs_from_open_issues(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();
        $site = SeoSite::query()->create([
            'workspace_id' => $workspace->id,
            'domain' => 'todos.test',
            'status' => 'connected',
        ]);

        $issue = SeoIssue::query()->create([
            'workspace_id' => $workspace->id,
            'seo_site_id' => $site->id,
            'severity' => 'critical',
            'code' => 'missing_title',
            'message' => '/about missing title',
            'suggestion' => 'Add a title',
            'status' => 'open',
        ]);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('seo.tasks.generate'), ['site_id' => $site->id])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('seo_tasks', [
            'workspace_id' => $workspace->id,
            'seo_site_id' => $site->id,
            'seo_issue_id' => $issue->id,
            'status' => 'open',
        ]);

        // Second click should not invent duplicates — still success feedback
        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('seo.tasks.generate'), ['site_id' => $site->id])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(
            1,
            \App\Models\SeoTask::query()
                ->where('seo_issue_id', $issue->id)
                ->where('status', 'open')
                ->count()
        );
    }

    public function test_tab_excel_export_for_issues_and_keywords(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();
        $site = SeoSite::query()->create([
            'workspace_id' => $workspace->id,
            'domain' => 'export-tab.test',
            'status' => 'connected',
        ]);
        SeoIssue::query()->create([
            'workspace_id' => $workspace->id,
            'seo_site_id' => $site->id,
            'severity' => 'warning',
            'code' => 'missing_h1',
            'message' => 'Missing H1',
            'suggestion' => 'Add H1',
            'status' => 'open',
        ]);
        SeoKeyword::query()->create([
            'workspace_id' => $workspace->id,
            'keyword' => 'export me',
            'group_name' => 'Core',
            'position' => 5,
        ]);

        $issues = $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->get(route('seo.export', ['type' => 'issues', 'site_id' => $site->id]));
        $issues->assertOk();
        $this->assertSame('PK', substr($issues->streamedContent(), 0, 2));

        $keywords = $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->get(route('seo.export', ['type' => 'keywords', 'site_id' => $site->id]));
        $keywords->assertOk();
        $this->assertSame('PK', substr($keywords->streamedContent(), 0, 2));
    }

    public function test_seo_report_supports_period_options(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();
        $site = SeoSite::query()->create([
            'workspace_id' => $workspace->id,
            'domain' => 'periods.test',
            'status' => 'connected',
        ]);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('seo.reports.weekly'), [
                'site_id' => $site->id,
                'period' => 'today',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('seo_reports', [
            'seo_site_id' => $site->id,
            'period' => 'today',
        ]);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('seo.reports.weekly'), [
                'site_id' => $site->id,
                'period' => 'custom',
                'period_start' => '2026-07-01',
                'period_end' => '2026-07-15',
            ])
            ->assertRedirect();

        $custom = SeoReport::query()->where('seo_site_id', $site->id)->where('period', 'custom')->first();
        $this->assertNotNull($custom);
        $this->assertSame('2026-07-01', $custom->period_start->toDateString());
        $this->assertSame('2026-07-15', $custom->period_end->toDateString());
    }

    public function test_seo_report_downloads_pdf_and_excel(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();
        $site = SeoSite::query()->create([
            'workspace_id' => $workspace->id,
            'domain' => 'report-dl.test',
            'status' => 'connected',
        ]);

        $report = app(SeoTaskGenerator::class)->weeklyReport($workspace, $site);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->get(route('seo.reports.download', ['report' => $report->id, 'format' => 'pdf']))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $excel = $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->get(route('seo.reports.download', ['report' => $report->id, 'format' => 'excel']));

        $excel->assertOk();
        $this->assertStringContainsString(
            'spreadsheetml.sheet',
            (string) $excel->headers->get('content-type')
        );
        $content = $excel->streamedContent();
        $this->assertNotSame('', $content);
        // XLSX is a ZIP package
        $this->assertSame('PK', substr($content, 0, 2));
    }

    public function test_rank_tracker_and_weekly_report(): void
    {
        config(['seo.providers.ranks' => 'stub']);

        [$user, $workspace] = $this->memberWithWorkspace();
        $site = SeoSite::query()->create([
            'workspace_id' => $workspace->id,
            'domain' => 'demo.test',
            'status' => 'connected',
        ]);

        SeoKeyword::query()->create([
            'workspace_id' => $workspace->id,
            'keyword' => 'local seo',
            'group_name' => 'Core',
            'position' => 12,
        ]);

        app(SeoRankTracker::class)->track($workspace);
        $this->assertNotNull(SeoKeyword::query()->first()->last_checked_at);

        $report = app(SeoTaskGenerator::class)->weeklyReport($workspace, $site);
        $this->assertSame('weekly', $report->period);
    }

    public function test_rank_update_refuses_fake_data_without_dataforseo(): void
    {
        config(['seo.providers.ranks' => 'auto']);

        [$user, $workspace] = $this->memberWithWorkspace();
        SeoSite::query()->create([
            'workspace_id' => $workspace->id,
            'domain' => 'honest.test',
            'status' => 'connected',
        ]);
        SeoKeyword::query()->create([
            'workspace_id' => $workspace->id,
            'keyword' => 'local seo',
            'group_name' => 'Core',
            'position' => 12,
            'rank_provider' => 'stub',
        ]);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('seo.keywords.track'))
            ->assertRedirect()
            ->assertSessionHas('error');

        $keyword = SeoKeyword::query()->first();
        $this->assertSame(12, (int) $keyword->position);
        $this->assertNull($keyword->last_checked_at);
    }
}
