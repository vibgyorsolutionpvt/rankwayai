<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\SeoSite;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Seo\SeoCrawlerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SeoSitemapMapTest extends TestCase
{
    use RefreshDatabase;

    public function test_site_map_reads_urls_from_sitemap_xml(): void
    {
        Http::fake([
            'https://map.test/sitemap.xml' => Http::response(
                '<?xml version="1.0" encoding="UTF-8"?>'
                .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
                .'<url><loc>https://map.test/</loc><priority>1.0</priority><lastmod>2026-08-01</lastmod></url>'
                .'<url><loc>https://map.test/about</loc><priority>0.8</priority></url>'
                .'<url><loc>https://map.test/services</loc></url>'
                .'</urlset>',
                200
            ),
        ]);

        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        $workspace->users()->attach($user->id, ['role' => WorkspaceRole::Owner->value]);
        $site = SeoSite::query()->create([
            'workspace_id' => $workspace->id,
            'domain' => 'map.test',
            'status' => 'connected',
            'sitemap_url' => 'https://map.test/sitemap.xml',
        ]);

        $map = app(SeoCrawlerService::class)->sitemapMap($site, true);

        $this->assertSame('sitemap', $map['source']);
        $this->assertSame('https://map.test/sitemap.xml', $map['sitemap_url']);
        $this->assertNull($map['error']);
        $this->assertCount(3, $map['nodes']);
        $this->assertSame('https://map.test/about', $map['nodes'][1]['url']);
        $this->assertSame('0.8', $map['nodes'][1]['priority']);
    }

    public function test_seo_index_passes_sitemap_nodes(): void
    {
        Http::fake([
            'https://map2.test/sitemap.xml' => Http::response(
                '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
                .'<url><loc>https://map2.test/contact</loc></url></urlset>',
                200
            ),
        ]);

        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        $workspace->users()->attach($user->id, ['role' => WorkspaceRole::Owner->value]);
        $site = SeoSite::query()->create([
            'workspace_id' => $workspace->id,
            'domain' => 'map2.test',
            'status' => 'connected',
            'sitemap_url' => 'https://map2.test/sitemap.xml',
        ]);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->get(route('seo.index', ['site' => $site->id, 'tab' => 'map', 'refresh_sitemap' => 1]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Seo/Index')
                ->where('architecture.source', 'sitemap')
                ->has('architecture.nodes', 1)
                ->where('architecture.nodes.0.url', 'https://map2.test/contact'));
    }
}
