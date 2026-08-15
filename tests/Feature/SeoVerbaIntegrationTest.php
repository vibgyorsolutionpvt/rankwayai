<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\CmsConnection;
use App\Models\SeoBlogPost;
use App\Models\SeoSite;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SeoVerbaIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_signup_creates_pages_for_each_domain(): void
    {
        config([
            'services.verba.base_url' => 'https://verba.test',
            'services.verba.public_url' => 'https://verba.test',
        ]);

        Http::fake([
            'https://verba.test/api/register' => Http::response([
                'token' => '1|test-token',
                'token_type' => 'Bearer',
                'user' => ['id' => 9, 'name' => 'Ada Lovelace', 'email' => 'ada@example.com'],
            ], 201),
            'https://verba.test/api/user' => Http::response([
                'data' => ['id' => 9, 'name' => 'Ada Lovelace', 'email' => 'ada@example.com'],
            ], 200),
            'https://verba.test/api/pages*' => Http::sequence()
                ->push(['data' => []], 200)
                ->push([
                    'data' => [
                        'id' => 3,
                        'name' => 'Verba Shop — acme.test',
                        'slug' => 'verba-shop-acme-test',
                        'username' => 'acme_test',
                    ],
                ], 201)
                ->push([
                    'data' => [
                        'id' => 4,
                        'name' => 'Verba Shop — beta.test',
                        'slug' => 'verba-shop-beta-test',
                        'username' => 'beta_test',
                    ],
                ], 201),
        ]);

        [$user, $workspace] = $this->workspaceWithCmsAccess();
        $siteA = SeoSite::query()->create([
            'workspace_id' => $workspace->id,
            'domain' => 'acme.test',
            'status' => 'connected',
        ]);
        $siteB = SeoSite::query()->create([
            'workspace_id' => $workspace->id,
            'domain' => 'beta.test',
            'status' => 'connected',
        ]);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('blog.verba.connect'), [
                'mode' => 'signup',
                'password' => 'password',
                'password_confirmation' => 'password',
            ])
            ->assertRedirect();

        $connection = CmsConnection::query()
            ->where('workspace_id', $workspace->id)
            ->where('provider', 'verba')
            ->first();

        $this->assertNotNull($connection);
        $this->assertSame('1|test-token', $connection->credentials['token'] ?? null);
        $this->assertSame('ada@example.com', $connection->credentials['email'] ?? null);
        $this->assertArrayHasKey((string) $siteA->id, $connection->credentials['site_pages'] ?? []);
        $this->assertArrayHasKey((string) $siteB->id, $connection->credentials['site_pages'] ?? []);
        $this->assertSame(
            'verba-shop-acme-test',
            $connection->credentials['site_pages'][(string) $siteA->id]['slug'] ?? null
        );
    }

    public function test_publish_blog_post_uses_site_page_slug(): void
    {
        config([
            'services.verba.base_url' => 'https://verba.test',
            'services.verba.public_url' => 'https://verba.test',
        ]);

        Http::fake([
            'https://verba.test/api/pages/demo-page/posts' => Http::response([
                'data' => [
                    'id' => 9,
                    'title' => 'Hello',
                    'slug' => 'hello',
                    'body' => '<p>Hi</p>',
                ],
            ], 201),
        ]);

        [$user, $workspace] = $this->workspaceWithCmsAccess();
        $site = SeoSite::query()->create([
            'workspace_id' => $workspace->id,
            'domain' => 'demo.test',
            'status' => 'connected',
        ]);
        $post = SeoBlogPost::query()->create([
            'seo_site_id' => $site->id,
            'url' => 'https://demo.test/blog/hello',
            'url_hash' => hash('sha256', 'https://demo.test/blog/hello'),
            'title' => 'Hello',
            'excerpt' => 'Hi there',
            'source' => 'demo',
        ]);

        CmsConnection::query()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'verba',
            'label' => 'Verba',
            'base_url' => 'https://verba.test',
            'credentials' => [
                'base_url' => 'https://verba.test',
                'token' => '1|test-token',
                'page_slug' => 'fallback',
                'public_url' => 'https://verba.test',
                'site_pages' => [
                    (string) $site->id => [
                        'domain' => 'demo.test',
                        'slug' => 'demo-page',
                        'name' => 'Demo',
                        'username' => 'demo_test',
                    ],
                ],
            ],
            'status' => 'active',
            'last_tested_at' => now(),
        ]);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('blog.posts.verba', $post))
            ->assertRedirect();

        Http::assertSent(function ($request) {
            return $request->url() === 'https://verba.test/api/pages/demo-page/posts'
                && $request->hasHeader('Authorization', 'Bearer 1|test-token')
                && ($request['title'] ?? null) === 'Hello';
        });

        $post->refresh();
        $this->assertNotNull($post->verba_published_at);
        $this->assertNotNull($post->verba_published_url);

        Http::fake();

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('blog.posts.verba', $post))
            ->assertRedirect()
            ->assertSessionHas('success');

        Http::assertNothingSent();
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function workspaceWithCmsAccess(): array
    {
        $user = User::factory()->create([
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
        ]);
        $workspace = Workspace::factory()->create(['name' => 'Verba Shop']);
        $workspace->users()->attach($user->id, ['role' => WorkspaceRole::Owner->value]);
        app(BillingService::class)->changePlan($workspace, 'starter', 'active');

        return [$user, $workspace];
    }
}
