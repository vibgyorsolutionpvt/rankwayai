<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\SeoBlogPost;
use App\Models\SeoSite;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Seo\SeoBlogDiscoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SeoBlogDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_reads_blog_posts_from_rss_feed(): void
    {
        Http::fake([
            'https://blogsite.test/feed' => Http::response(
                '<?xml version="1.0"?>'
                .'<rss version="2.0"><channel>'
                .'<title>Blog</title>'
                .'<item>'
                .'<title>Ship faster with React</title>'
                .'<link>https://blogsite.test/blog/ship-faster</link>'
                .'<description>Tips for SPAs</description>'
                .'<pubDate>Mon, 10 Aug 2026 10:00:00 GMT</pubDate>'
                .'</item>'
                .'<item>'
                .'<title>SEO for SPAs</title>'
                .'<link>https://blogsite.test/blog/seo-spas</link>'
                .'</item>'
                .'</channel></rss>',
                200
            ),
        ]);

        $site = $this->makeSite('blogsite.test');

        $result = app(SeoBlogDiscoveryService::class)->sync($site);

        $this->assertSame(2, $result['count']);
        $this->assertSame('rss', $result['source']);
        $this->assertDatabaseHas('seo_blog_posts', [
            'seo_site_id' => $site->id,
            'title' => 'Ship faster with React',
        ]);
    }

    public function test_sync_falls_back_to_sitemap_blog_urls(): void
    {
        Http::fake([
            'https://mapblog.test/feed' => Http::response('Not a feed', 404),
            'https://mapblog.test/rss' => Http::response('Not a feed', 404),
            'https://mapblog.test/feed.xml' => Http::response('Not a feed', 404),
            'https://mapblog.test/rss.xml' => Http::response('Not a feed', 404),
            'https://mapblog.test/atom.xml' => Http::response('Not a feed', 404),
            'https://mapblog.test/blog/feed' => Http::response('Not a feed', 404),
            'https://mapblog.test/blog/rss.xml' => Http::response('Not a feed', 404),
            'https://mapblog.test/index.xml' => Http::response('Not a feed', 404),
            'https://www.mapblog.test/feed' => Http::response('Not a feed', 404),
            'https://mapblog.test/sitemap.xml' => Http::response(
                '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
                .'<url><loc>https://mapblog.test/</loc></url>'
                .'<url><loc>https://mapblog.test/about</loc></url>'
                .'<url><loc>https://mapblog.test/blog/hello-world</loc><lastmod>2026-08-01</lastmod></url>'
                .'<url><loc>https://mapblog.test/blog/second-post</loc></url>'
                .'</urlset>',
                200
            ),
        ]);

        $site = $this->makeSite('mapblog.test', 'https://mapblog.test/sitemap.xml');

        $result = app(SeoBlogDiscoveryService::class)->sync($site);

        $this->assertSame(2, $result['count']);
        $this->assertSame('sitemap', $result['source']);
        $this->assertTrue(
            SeoBlogPost::query()->where('seo_site_id', $site->id)->where('url', 'like', '%/blog/%')->count() === 2
        );
    }

    public function test_sync_loads_demo_posts_when_nothing_found(): void
    {
        Http::fake([
            '*' => Http::response('Not found', 404),
        ]);

        $site = $this->makeSite('emptyblog.test');

        $result = app(SeoBlogDiscoveryService::class)->sync($site);

        $this->assertSame(4, $result['count']);
        $this->assertSame('demo', $result['source']);
    }

    public function test_share_opens_reddit_and_logs_share(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        $workspace->users()->attach($user->id, ['role' => WorkspaceRole::Owner->value]);
        $site = SeoSite::query()->create([
            'workspace_id' => $workspace->id,
            'domain' => 'share.test',
            'status' => 'connected',
        ]);
        $post = SeoBlogPost::query()->create([
            'seo_site_id' => $site->id,
            'url' => 'https://share.test/blog/one',
            'url_hash' => hash('sha256', 'https://share.test/blog/one'),
            'title' => 'One',
            'source' => 'rss',
        ]);

        $response = $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('seo.blogs.share', $post), ['channel' => 'reddit']);

        $response->assertRedirect();
        $this->assertStringContainsString('text=', (string) session('share_open_url'));
        $this->assertStringContainsString(rawurlencode('https://share.test/blog/one'), (string) session('share_open_url'));

        $this->assertDatabaseHas('seo_blog_shares', [
            'seo_blog_post_id' => $post->id,
            'channel' => 'reddit',
        ]);
        $this->assertSame(1, $post->fresh()->share_count);
    }

    private function makeSite(string $domain, ?string $sitemap = null): SeoSite
    {
        $workspace = Workspace::factory()->create();

        return SeoSite::query()->create([
            'workspace_id' => $workspace->id,
            'domain' => $domain,
            'status' => 'connected',
            'sitemap_url' => $sitemap,
        ]);
    }
}
