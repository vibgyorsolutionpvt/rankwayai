<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Jobs\SyncSocialPostEngagementJob;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\SocialPublishLog;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\BillingService;
use App\Services\Social\SocialPostAnalyticsService;
use App\Services\Social\SocialPublisherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SocialPostAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_log_stores_facebook_engagement(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $workspace = Workspace::factory()->create();
        $workspace->users()->attach($user->id, ['role' => WorkspaceRole::Owner->value]);

        SocialAccount::query()->create([
            'workspace_id' => $workspace->id,
            'platform' => 'facebook',
            'account_name' => 'Demo Page',
            'account_type' => 'page',
            'connection_mode' => 'oauth',
            'external_id' => 'page_1',
            'access_token' => 'token_fb',
            'status' => 'connected',
            'connected_at' => now(),
        ]);

        $post = SocialPost::query()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $user->id,
            'title' => 'Test',
            'body' => 'Body',
            'platforms' => ['facebook'],
            'status' => 'published',
        ]);

        $log = SocialPublishLog::query()->create([
            'workspace_id' => $workspace->id,
            'social_post_id' => $post->id,
            'platform' => 'facebook',
            'status' => 'published',
            'external_post_id' => 'page_1_post_9',
            'permalink' => 'https://facebook.com/page_1_post_9',
        ]);

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'likes' => ['summary' => ['total_count' => 12]],
                'comments' => ['summary' => ['total_count' => 3]],
                'shares' => ['count' => 2],
            ]),
        ]);

        $ok = app(SocialPostAnalyticsService::class)->syncLog($log);

        $this->assertTrue($ok);
        $log->refresh();
        $this->assertSame(12, $log->metrics['likes']);
        $this->assertSame(3, $log->metrics['comments']);
        $this->assertNotNull($log->metrics_synced_at);
    }

    public function test_sync_analytics_route_refreshes_workspace_logs(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $workspace = Workspace::factory()->create();
        $workspace->users()->attach($user->id, ['role' => WorkspaceRole::Owner->value]);
        app(BillingService::class)->changePlan($workspace, 'starter', 'active');

        SocialAccount::query()->create([
            'workspace_id' => $workspace->id,
            'platform' => 'threads',
            'account_name' => '@demo',
            'account_type' => 'profile',
            'connection_mode' => 'oauth',
            'external_id' => 'th_1',
            'access_token' => 'token_th',
            'status' => 'connected',
            'connected_at' => now(),
        ]);

        $post = SocialPost::query()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $user->id,
            'title' => 'Thread post',
            'body' => 'Body',
            'platforms' => ['threads'],
            'status' => 'published',
        ]);

        SocialPublishLog::query()->create([
            'workspace_id' => $workspace->id,
            'social_post_id' => $post->id,
            'platform' => 'threads',
            'status' => 'published',
            'external_post_id' => 'media_99',
        ]);

        Http::fake([
            'graph.threads.net/*' => Http::response([
                'data' => [
                    ['name' => 'likes', 'values' => [['value' => 5]]],
                    ['name' => 'replies', 'values' => [['value' => 2]]],
                    ['name' => 'views', 'values' => [['value' => 120]]],
                ],
            ]),
        ]);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('social.analytics.sync'))
            ->assertRedirect()
            ->assertSessionHas('success');
    }

    public function test_required_scopes_include_insights_permissions(): void
    {
        $scopes = SocialPostAnalyticsService::requiredScopes();

        $this->assertContains('pages_read_engagement', $scopes['facebook']);
        $this->assertContains('instagram_manage_insights', $scopes['instagram']);
        $this->assertContains('threads_manage_insights', $scopes['threads']);
    }

    public function test_aggregate_logs_keeps_per_platform_metrics(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        $post = SocialPost::query()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $user->id,
            'title' => 'Multi',
            'body' => 'Body',
            'platforms' => ['facebook', 'instagram'],
            'status' => 'published',
        ]);

        $fbLog = SocialPublishLog::query()->create([
            'workspace_id' => $workspace->id,
            'social_post_id' => $post->id,
            'platform' => 'facebook',
            'status' => 'published',
            'external_post_id' => 'fb_1',
            'metrics' => ['likes' => 10, 'comments' => 2, 'views' => 0],
            'metrics_synced_at' => now(),
        ]);

        $igLog = SocialPublishLog::query()->create([
            'workspace_id' => $workspace->id,
            'social_post_id' => $post->id,
            'platform' => 'instagram',
            'status' => 'published',
            'external_post_id' => 'ig_1',
            'metrics' => ['likes' => 25, 'comments' => 4, 'views' => 300],
            'metrics_synced_at' => now(),
        ]);

        $aggregate = app(SocialPostAnalyticsService::class)->aggregateLogs(collect([$fbLog, $igLog]));

        $this->assertSame(35, $aggregate['likes']);
        $this->assertSame(6, $aggregate['comments']);
        $this->assertSame(300, $aggregate['views']);
        $this->assertSame(10, $aggregate['by_platform']['facebook']['likes']);
        $this->assertSame(25, $aggregate['by_platform']['instagram']['likes']);
        $this->assertSame(300, $aggregate['by_platform']['instagram']['views']);
    }

    public function test_sync_post_syncs_all_logs_for_post(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();

        SocialAccount::query()->create([
            'workspace_id' => $workspace->id,
            'platform' => 'facebook',
            'account_name' => 'Page',
            'account_type' => 'page',
            'connection_mode' => 'oauth',
            'external_id' => 'page_1',
            'access_token' => 'token',
            'status' => 'connected',
            'connected_at' => now(),
        ]);

        $post = SocialPost::query()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $user->id,
            'body' => 'Body',
            'platforms' => ['facebook'],
            'status' => 'published',
        ]);

        SocialPublishLog::query()->create([
            'workspace_id' => $workspace->id,
            'social_post_id' => $post->id,
            'platform' => 'facebook',
            'status' => 'published',
            'external_post_id' => 'page_1_99',
        ]);

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'likes' => ['summary' => ['total_count' => 4]],
                'comments' => ['summary' => ['total_count' => 1]],
            ]),
        ]);

        $count = app(SocialPostAnalyticsService::class)->syncPost($post);

        $this->assertSame(1, $count);
    }

    public function test_live_publish_queues_engagement_sync_job(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();

        $media = \App\Models\MediaAsset::query()->create([
            'workspace_id' => $workspace->id,
            'uploaded_by' => $user->id,
            'disk' => 'public',
            'path' => 'https://cdn.example.com/poster.jpg',
            'original_name' => 'poster.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 1000,
            'status' => 'ready',
        ]);

        SocialAccount::query()->create([
            'workspace_id' => $workspace->id,
            'platform' => 'facebook',
            'account_name' => 'Page',
            'account_type' => 'page',
            'connection_mode' => 'oauth',
            'external_id' => 'page_1',
            'access_token' => 'token',
            'status' => 'connected',
            'connected_at' => now(),
        ]);

        $post = SocialPost::query()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $user->id,
            'body' => 'Caption',
            'platforms' => ['facebook'],
            'status' => 'publishing',
            'media_asset_id' => $media->id,
        ]);

        Http::fake([
            'graph.facebook.com/*' => Http::sequence()
                ->push(['id' => 'photo_1', 'post_id' => 'page_1_55'])
                ->push(['permalink_url' => 'https://facebook.com/page_1_55']),
        ]);

        config(['social.metrics_sync_delay_minutes' => 3]);

        app(SocialPublisherService::class)->publish($post);

        Queue::assertPushed(SyncSocialPostEngagementJob::class, function (SyncSocialPostEngagementJob $job) use ($post) {
            return $job->socialPostId === $post->id;
        });
    }
}
