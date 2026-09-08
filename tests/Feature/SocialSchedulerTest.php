<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Jobs\GeneratePosterVariantsJob;
use App\Jobs\PublishSocialPostJob;
use App\Models\MediaAsset;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\BillingService;
use App\Services\Social\SocialPublisherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SocialSchedulerTest extends TestCase
{
    use RefreshDatabase;

    private function samplePublicMediaUrl(): string
    {
        return 'https://example.com/sample-post.jpg';
    }

    private function memberWithWorkspace(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        $workspace->users()->attach($user->id, ['role' => WorkspaceRole::Owner->value]);
        app(BillingService::class)->changePlan($workspace, 'starter', 'active');

        return [$user, $workspace];
    }

    public function test_poster_job_attaches_image_for_all_social_drafts(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD extension required for poster generation.');
        }

        [$user, $workspace] = $this->memberWithWorkspace();

        $post = SocialPost::query()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $user->id,
            'title' => 'Janmashtami offer',
            'body' => 'Celebrate with Vibgyor Holidays',
            'platforms' => ['instagram', 'facebook'],
            'status' => 'draft',
            'requires_approval' => true,
        ]);

        GeneratePosterVariantsJob::dispatchSync($post->id);

        $post->refresh();
        $this->assertNotNull($post->media_asset_id);
        $this->assertNotEmpty($post->poster_variants);
        $this->assertNotNull($post->media);
        $this->assertSame('image/jpeg', $post->media->mime_type);
    }

    public function test_approve_blocked_without_media(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();

        $post = SocialPost::query()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $user->id,
            'title' => 'No image draft',
            'body' => 'Caption only',
            'platforms' => ['instagram', 'facebook'],
            'status' => 'draft',
            'requires_approval' => true,
        ]);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('social.posts.approve', $post))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertNull($post->fresh()->approved_at);
    }

    public function test_approve_succeeds_with_media(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();
        app(BillingService::class)->changePlan($workspace, 'starter', 'active');

        $asset = MediaAsset::query()->create([
            'workspace_id' => $workspace->id,
            'uploaded_by' => $user->id,
            'disk' => 'public',
            'path' => 'media/test.jpg',
            'original_name' => 'test.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 1000,
            'status' => 'ready',
        ]);

        $post = SocialPost::query()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $user->id,
            'title' => 'With image',
            'body' => 'Caption',
            'platforms' => ['instagram'],
            'status' => 'draft',
            'requires_approval' => true,
            'media_asset_id' => $asset->id,
        ]);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('social.posts.approve', $post))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertNotNull($post->fresh()->approved_at);
    }

    public function test_publish_now_blocked_until_approved(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();
        app(BillingService::class)->changePlan($workspace, 'starter', 'active');

        $post = SocialPost::query()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $user->id,
            'title' => 'Needs review',
            'body' => 'Draft body',
            'platforms' => ['instagram'],
            'status' => 'draft',
            'requires_approval' => true,
        ]);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('social.posts.publish', $post))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame('draft', $post->fresh()->status);
        $this->assertNull($post->fresh()->approved_at);
    }

    public function test_failed_platforms_detects_partial_publish(): void
    {
        $post = new SocialPost([
            'platforms' => ['facebook', 'instagram'],
            'permalinks' => ['facebook' => 'https://facebook.com/example'],
            'status' => 'published',
        ]);

        $publisher = app(SocialPublisherService::class);

        $this->assertSame(['instagram'], $publisher->failedPlatforms($post));
        $this->assertTrue($publisher->hasPublishFailures($post));
    }

    public function test_platform_statuses_for_partial_publish(): void
    {
        $post = new SocialPost([
            'platforms' => ['facebook', 'instagram'],
            'permalinks' => ['facebook' => 'https://facebook.com/example'],
            'status' => 'published',
            'requires_approval' => false,
            'media_asset_id' => 1,
        ]);

        $publisher = app(SocialPublisherService::class);
        $statuses = $publisher->platformStatuses($post);

        $this->assertSame('published', $statuses[0]['status']);
        $this->assertSame('failed', $statuses[1]['status']);
        $this->assertTrue($statuses[1]['can_resend']);
    }

    public function test_connect_schedule_publish_flow(): void
    {
        Queue::fake();
        [$user, $workspace] = $this->memberWithWorkspace();

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('social.accounts.store'), [
                'platform' => 'instagram',
                'account_name' => 'Demo IG',
            ])
            ->assertRedirect();

        $account = SocialAccount::query()->first();
        $this->assertSame('connected', $account->status);
        $this->assertSame('healthy', $account->health);
        $this->assertNotEmpty($account->access_token);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('social.posts.store'), [
                'title' => 'Launch',
                'body' => 'Hello world',
                'platforms' => ['instagram'],
                'delivery' => 'schedule',
                'scheduled_at' => now()->addHour()->toDateTimeString(),
                'generate_posters' => false,
                'public_media_url' => $this->samplePublicMediaUrl(),
            ])
            ->assertRedirect();

        $post = SocialPost::query()->first();
        $this->assertSame('scheduled', $post->status);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->get(route('social.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Social/Index')
                ->has('calendar.days')
                ->has('posts.data', 1));

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('social.posts.publish', $post))
            ->assertRedirect();

        Queue::assertPushed(PublishSocialPostJob::class);
    }

    public function test_scheduled_post_can_be_updated(): void
    {
        Queue::fake();
        [$user, $workspace] = $this->memberWithWorkspace();

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('social.posts.store'), [
                'title' => 'Old',
                'body' => 'Old body',
                'platforms' => ['instagram'],
                'delivery' => 'schedule',
                'scheduled_at' => now()->addDay()->toDateTimeString(),
                'generate_posters' => false,
                'public_media_url' => $this->samplePublicMediaUrl(),
            ])
            ->assertRedirect();

        $post = SocialPost::query()->first();

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->patch(route('social.posts.update', $post), [
                'title' => 'Updated',
                'body' => 'New caption',
                'platforms' => ['instagram', 'facebook'],
                'delivery' => 'schedule',
                'scheduled_at' => now()->addDays(2)->toDateTimeString(),
                'generate_posters' => false,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $post->refresh();
        $this->assertSame('Updated', $post->title);
        $this->assertSame('New caption', $post->body);
        $this->assertSame(['instagram', 'facebook'], $post->platforms);
        $this->assertSame('scheduled', $post->status);
    }

    public function test_publisher_writes_permalinks(): void
    {
        Queue::fake([\App\Jobs\SyncSocialPostEngagementJob::class]);
        [$user, $workspace] = $this->memberWithWorkspace();

        \Illuminate\Support\Facades\Http::fake([
            'graph.facebook.com/*' => \Illuminate\Support\Facades\Http::sequence()
                ->push(['id' => 'photo_999'], 200)
                ->push(['id' => '111_222'], 200)
                ->push(['permalink_url' => 'https://www.facebook.com/111/posts/222'], 200),
        ]);

        SocialAccount::query()->create([
            'workspace_id' => $workspace->id,
            'platform' => 'facebook',
            'account_name' => 'Page',
            'account_type' => 'page',
            'connection_mode' => 'oauth',
            'status' => 'connected',
            'health' => 'healthy',
            'external_id' => '111',
            'access_token' => 'page-token-test',
            'connected_at' => now(),
        ]);

        $asset = MediaAsset::query()->create([
            'workspace_id' => $workspace->id,
            'uploaded_by' => $user->id,
            'disk' => 'public',
            'path' => $this->samplePublicMediaUrl(),
            'original_name' => 'sample-post.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 0,
            'folder' => 'Test',
            'status' => 'ready',
        ]);

        $post = SocialPost::query()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $user->id,
            'title' => 'Go live',
            'body' => 'Body',
            'platforms' => ['facebook'],
            'status' => 'publishing',
            'media_asset_id' => $asset->id,
        ]);

        $result = app(SocialPublisherService::class)->publish($post);
        $post->refresh();

        $this->assertTrue($result['ok']);
        $this->assertSame('published', $post->status);
        $this->assertArrayHasKey('facebook', $post->permalinks);
        $this->assertDatabaseHas('social_publish_logs', [
            'social_post_id' => $post->id,
            'platform' => 'facebook',
            'status' => 'published',
        ]);
    }

    public function test_posts_list_is_paginated(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();

        for ($i = 1; $i <= 12; $i++) {
            SocialPost::query()->create([
                'workspace_id' => $workspace->id,
                'created_by' => $user->id,
                'title' => "Post {$i}",
                'body' => "Body {$i}",
                'platforms' => ['instagram'],
                'status' => 'draft',
            ]);
        }

        // Seed one published so status filter can be tested
        SocialPost::query()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $user->id,
            'title' => 'Live post',
            'body' => 'Published body',
            'platforms' => ['facebook'],
            'status' => 'published',
        ]);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->get(route('social.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Social/Index')
                ->has('posts.data', 12)
                ->where('posts.current_page', 1)
                ->where('posts.last_page', 2)
                ->where('posts.total', 13)
                ->where('filters.view', 'posts')
                ->where('filters.counts.draft', 12)
                ->where('filters.counts.published', 1));

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->get(route('social.index', ['page' => 2]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('posts.data', 1)
                ->where('posts.current_page', 2));

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->get(route('social.index', ['status' => 'published', 'platform' => 'facebook']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('posts.data', 1)
                ->where('filters.status', 'published')
                ->where('filters.platform', 'facebook')
                ->where('posts.data.0.title', 'Live post'));

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->get(route('social.index', ['view' => 'calendar']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.view', 'calendar')
                ->has('calendar.days'));
    }

    public function test_posts_queue_puts_todays_scheduled_first_and_filters_by_date(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();
        app(BillingService::class)->changePlan($workspace, 'starter', 'active');

        $today = now()->setTime(14, 0);
        $tomorrow = now()->addDay()->setTime(10, 0);
        $lastWeek = now()->subDays(7)->setTime(9, 0);

        $older = SocialPost::query()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $user->id,
            'title' => 'Tomorrow scheduled',
            'body' => 'Body',
            'platforms' => ['instagram'],
            'status' => 'scheduled',
            'scheduled_at' => $tomorrow,
            'created_at' => now()->subHour(),
        ]);

        $todayPost = SocialPost::query()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $user->id,
            'title' => 'Today scheduled',
            'body' => 'Body',
            'platforms' => ['facebook'],
            'status' => 'scheduled',
            'scheduled_at' => $today,
            'created_at' => now()->subHours(2),
        ]);

        SocialPost::query()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $user->id,
            'title' => 'Last week',
            'body' => 'Body',
            'platforms' => ['facebook'],
            'status' => 'scheduled',
            'scheduled_at' => $lastWeek,
            'created_at' => now()->subDays(2),
        ]);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->get(route('social.index', ['view' => 'posts', 'status' => 'scheduled']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('posts.data.0.id', $todayPost->id)
                ->where('posts.data.1.id', $older->id));

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->get(route('social.index', [
                'view' => 'posts',
                'date_preset' => 'today',
                'date_field' => 'scheduled',
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('posts.data', 1)
                ->where('posts.data.0.id', $todayPost->id)
                ->where('filters.date_preset', 'today'));
    }

    public function test_post_creation_defaults_to_publish_to_story_true(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('social.posts.store'), [
                'title' => 'Story Post',
                'body' => 'Testing story flag',
                'platforms' => ['facebook', 'instagram', 'threads'],
                'delivery' => 'draft',
                'generate_posters' => false,
                'public_media_url' => $this->samplePublicMediaUrl(),
            ])
            ->assertRedirect();

        $post = SocialPost::query()->latest('id')->first();
        $this->assertNotNull($post);
        $this->assertTrue($post->publish_to_story);
        $this->assertEquals(['facebook', 'instagram', 'threads'], $post->platforms);
    }

    public function test_publisher_publishes_facebook_story_when_enabled(): void
    {
        Queue::fake([\App\Jobs\SyncSocialPostEngagementJob::class]);
        [$user, $workspace] = $this->memberWithWorkspace();

        \Illuminate\Support\Facades\Http::fake([
            'graph.facebook.com/*/photos' => \Illuminate\Support\Facades\Http::response(['id' => 'photo_123'], 200),
            'graph.facebook.com/*/feed' => \Illuminate\Support\Facades\Http::response(['id' => 'page_feed_456'], 200),
            'graph.facebook.com/*/photo_stories' => \Illuminate\Support\Facades\Http::response(['post_id' => 'story_789'], 200),
            'graph.facebook.com/*' => \Illuminate\Support\Facades\Http::response(['permalink_url' => 'https://facebook.com/posts/456'], 200),
        ]);

        SocialAccount::query()->create([
            'workspace_id' => $workspace->id,
            'platform' => 'facebook',
            'account_name' => 'FB Page',
            'account_type' => 'page',
            'connection_mode' => 'oauth',
            'status' => 'connected',
            'health' => 'healthy',
            'external_id' => 'fb_page_1',
            'access_token' => 'page-token',
            'connected_at' => now(),
        ]);

        $asset = MediaAsset::query()->create([
            'workspace_id' => $workspace->id,
            'uploaded_by' => $user->id,
            'disk' => 'public',
            'path' => $this->samplePublicMediaUrl(),
            'original_name' => 'sample.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 0,
            'status' => 'ready',
        ]);

        $post = SocialPost::query()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $user->id,
            'title' => 'FB Feed & Story',
            'body' => 'Caption text',
            'platforms' => ['facebook'],
            'status' => 'publishing',
            'publish_to_story' => true,
            'media_asset_id' => $asset->id,
        ]);

        $result = app(SocialPublisherService::class)->publish($post);
        $post->refresh();

        $this->assertTrue($result['ok']);
        $this->assertArrayHasKey('facebook', $post->permalinks);
        $this->assertArrayHasKey('facebook_story', $post->permalinks);
        $this->assertStringContainsString('story_789', $post->permalinks['facebook_story']);
    }

    public function test_publisher_publishes_instagram_story_when_enabled(): void
    {
        Queue::fake([\App\Jobs\SyncSocialPostEngagementJob::class]);
        [$user, $workspace] = $this->memberWithWorkspace();

        \Illuminate\Support\Facades\Http::fake([
            'graph.facebook.com/*/media' => \Illuminate\Support\Facades\Http::sequence()
                ->push(['id' => 'ig_container_feed'], 200)
                ->push(['id' => 'ig_container_story'], 200),
            'graph.facebook.com/*/media_publish' => \Illuminate\Support\Facades\Http::sequence()
                ->push(['id' => 'ig_media_feed'], 200)
                ->push(['id' => 'ig_media_story'], 200),
            'graph.facebook.com/ig_container_*' => \Illuminate\Support\Facades\Http::response(['status_code' => 'FINISHED'], 200),
            'graph.facebook.com/ig_media_*' => \Illuminate\Support\Facades\Http::response(['permalink' => 'https://instagram.com/p/feed123'], 200),
            'graph.facebook.com/*' => \Illuminate\Support\Facades\Http::response(['status_code' => 'FINISHED', 'permalink' => 'https://instagram.com/p/feed123'], 200),
        ]);

        SocialAccount::query()->create([
            'workspace_id' => $workspace->id,
            'platform' => 'instagram',
            'account_name' => 'IG Business',
            'account_type' => 'page',
            'connection_mode' => 'oauth',
            'status' => 'connected',
            'health' => 'healthy',
            'external_id' => 'ig_user_1',
            'access_token' => 'ig-token',
            'connected_at' => now(),
        ]);

        $asset = MediaAsset::query()->create([
            'workspace_id' => $workspace->id,
            'uploaded_by' => $user->id,
            'disk' => 'public',
            'path' => $this->samplePublicMediaUrl(),
            'original_name' => 'sample.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 0,
            'status' => 'ready',
        ]);

        $post = SocialPost::query()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $user->id,
            'title' => 'IG Feed & Story',
            'body' => 'IG Caption',
            'platforms' => ['instagram'],
            'status' => 'publishing',
            'publish_to_story' => true,
            'media_asset_id' => $asset->id,
        ]);

        $result = app(SocialPublisherService::class)->publish($post);
        $post->refresh();

        $this->assertTrue($result['ok']);
        $this->assertArrayHasKey('instagram', $post->permalinks);
        $this->assertArrayHasKey('instagram_story', $post->permalinks);
    }

    public function test_publisher_logs_story_error_when_facebook_story_fails(): void
    {
        Queue::fake([\App\Jobs\SyncSocialPostEngagementJob::class]);
        [$user, $workspace] = $this->memberWithWorkspace();

        \Illuminate\Support\Facades\Http::fake([
            'graph.facebook.com/*/photos' => \Illuminate\Support\Facades\Http::response(['id' => 'photo_123'], 200),
            'graph.facebook.com/*/feed' => \Illuminate\Support\Facades\Http::response(['id' => 'page_feed_456'], 200),
            'graph.facebook.com/*/photo_stories*' => \Illuminate\Support\Facades\Http::response([
                'error' => [
                    'message' => 'The photo must be unpublished to create a story.',
                    'type' => 'OAuthException',
                    'code' => 100,
                ],
            ], 400),
            'graph.facebook.com/*' => \Illuminate\Support\Facades\Http::response(['permalink_url' => 'https://facebook.com/posts/456'], 200),
        ]);

        SocialAccount::query()->create([
            'workspace_id' => $workspace->id,
            'platform' => 'facebook',
            'account_name' => 'FB Page',
            'account_type' => 'page',
            'connection_mode' => 'oauth',
            'status' => 'connected',
            'health' => 'healthy',
            'external_id' => 'fb_page_1',
            'access_token' => 'page-token',
            'connected_at' => now(),
        ]);

        $asset = MediaAsset::query()->create([
            'workspace_id' => $workspace->id,
            'uploaded_by' => $user->id,
            'disk' => 'public',
            'path' => $this->samplePublicMediaUrl(),
            'original_name' => 'sample.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 0,
            'status' => 'ready',
        ]);

        $post = SocialPost::query()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $user->id,
            'title' => 'FB Feed & Story Fail Test',
            'body' => 'Caption text',
            'platforms' => ['facebook'],
            'status' => 'publishing',
            'publish_to_story' => true,
            'media_asset_id' => $asset->id,
        ]);

        $result = app(SocialPublisherService::class)->publish($post);
        $post->refresh();

        $this->assertTrue($result['ok']);
        $this->assertArrayHasKey('facebook', $post->permalinks);
        $this->assertArrayNotHasKey('facebook_story', $post->permalinks);

        // Verify SocialPublishLog has the story error logged
        $storyLog = \App\Models\SocialPublishLog::query()
            ->where('social_post_id', $post->id)
            ->where('platform', 'facebook_story')
            ->first();

        $this->assertNotNull($storyLog);
        $this->assertSame('failed', $storyLog->status);
        $this->assertStringContainsString('The photo must be unpublished', $storyLog->error);

        // Verify platformStatuses includes the story failure pill
        $statuses = app(SocialPublisherService::class)->platformStatuses($post);
        $storyStatus = collect($statuses)->firstWhere('platform', 'facebook_story');
        $this->assertNotNull($storyStatus);
        $this->assertSame('failed', $storyStatus['status']);
        $this->assertStringContainsString('The photo must be unpublished', $storyStatus['error']);
    }

    public function test_social_account_test_connection_healthy_for_sandbox(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();

        $account = SocialAccount::query()->create([
            'workspace_id' => $workspace->id,
            'platform' => 'facebook',
            'account_name' => 'Demo Sandbox',
            'account_type' => 'page',
            'connection_mode' => 'sandbox',
            'status' => 'connected',
            'health' => 'healthy',
            'external_id' => 'stub_123',
            'access_token' => 'stub_token',
        ]);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('social.accounts.test', $account->id))
            ->assertSessionHas('success');

        $this->assertSame('healthy', $account->fresh()->health);
        $this->assertNull($account->fresh()->last_error);
    }

    public function test_social_account_test_connection_facebook_healthy(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();

        \Illuminate\Support\Facades\Http::fake([
            'graph.facebook.com/v19.0/fb_page_123*' => \Illuminate\Support\Facades\Http::response([
                'id' => 'fb_page_123',
                'name' => 'My Live Brand Page',
            ], 200),
        ]);

        $account = SocialAccount::query()->create([
            'workspace_id' => $workspace->id,
            'platform' => 'facebook',
            'account_name' => 'Old Name',
            'account_type' => 'page',
            'connection_mode' => 'oauth',
            'status' => 'connected',
            'health' => 'healthy',
            'external_id' => 'fb_page_123',
            'access_token' => 'live_token_abc',
            'token_expires_at' => now()->addDays(30),
        ]);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('social.accounts.test', $account->id))
            ->assertSessionHas('success');

        $this->assertSame('healthy', $account->fresh()->health);
        $this->assertSame('My Live Brand Page', $account->fresh()->account_name);
        $this->assertNull($account->fresh()->last_error);
    }

    public function test_social_account_test_connection_catches_meta_error_190(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();

        \Illuminate\Support\Facades\Http::fake([
            'graph.facebook.com/v19.0/fb_page_invalid*' => \Illuminate\Support\Facades\Http::response([
                'error' => [
                    'message' => 'Error validating access token: The session has been invalidated.',
                    'type' => 'OAuthException',
                    'code' => 190,
                    'error_subcode' => 460,
                ],
            ], 400),
        ]);

        $account = SocialAccount::query()->create([
            'workspace_id' => $workspace->id,
            'platform' => 'facebook',
            'account_name' => 'Expired Page',
            'account_type' => 'page',
            'connection_mode' => 'oauth',
            'status' => 'connected',
            'health' => 'healthy',
            'external_id' => 'fb_page_invalid',
            'access_token' => 'bad_token',
        ]);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('social.accounts.test', $account->id))
            ->assertSessionHas('error');

        $this->assertSame('error', $account->fresh()->health);
        $this->assertStringContainsString('Error 190', $account->fresh()->last_error);
    }

    public function test_social_account_test_all_connections(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();

        SocialAccount::query()->create([
            'workspace_id' => $workspace->id,
            'platform' => 'facebook',
            'account_name' => 'Sandbox 1',
            'account_type' => 'page',
            'connection_mode' => 'sandbox',
            'status' => 'connected',
            'health' => 'healthy',
            'external_id' => 'stub_1',
            'access_token' => 'stub_token_1',
        ]);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('social.accounts.test-all'))
            ->assertSessionHas('success');
    }

    public function test_health_check_endpoint_returns_clean_when_healthy(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();

        SocialAccount::query()->create([
            'workspace_id' => $workspace->id,
            'platform' => 'facebook',
            'account_name' => 'Healthy Sandbox',
            'account_type' => 'page',
            'connection_mode' => 'sandbox',
            'status' => 'connected',
            'health' => 'healthy',
            'external_id' => 'stub_h1',
            'access_token' => 'stub_token_h1',
        ]);

        $response = $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->postJson(route('social.accounts.health-check'));

        $response->assertOk()
            ->assertJson([
                'checked' => true,
                'has_issues' => false,
                'total_checked' => 1,
                'failed_accounts' => [],
            ]);
    }

    public function test_health_check_endpoint_returns_failed_accounts_and_reconnect_url(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();

        \Illuminate\Support\Facades\Http::fake([
            'graph.facebook.com/v19.0/fb_page_broken*' => \Illuminate\Support\Facades\Http::response([
                'error' => [
                    'message' => 'Error validating access token: The session has been invalidated.',
                    'type' => 'OAuthException',
                    'code' => 190,
                    'error_subcode' => 460,
                ],
            ], 400),
        ]);

        SocialAccount::query()->create([
            'workspace_id' => $workspace->id,
            'platform' => 'facebook',
            'account_name' => 'Broken Page',
            'account_type' => 'page',
            'connection_mode' => 'oauth',
            'status' => 'connected',
            'health' => 'healthy',
            'external_id' => 'fb_page_broken',
            'access_token' => 'invalid_token',
        ]);

        $response = $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->postJson(route('social.accounts.health-check'));

        $response->assertOk()
            ->assertJson([
                'checked' => true,
                'has_issues' => true,
                'total_checked' => 1,
            ]);

        $this->assertCount(1, $response->json('failed_accounts'));
        $failed = $response->json('failed_accounts.0');
        $this->assertSame('facebook', $failed['platform']);
        $this->assertSame('Broken Page', $failed['account_name']);
        $this->assertStringContainsString('Error 190', $failed['error_message']);
        $this->assertNotEmpty($failed['reconnect_url']);
    }

    public function test_login_triggers_check_social_on_login(): void
    {
        $user = \App\Models\User::factory()->create(['password' => bcrypt('password123')]);
        $workspace = \App\Models\Workspace::factory()->create();
        $workspace->users()->attach($user->id, ['role' => \App\Enums\WorkspaceRole::Owner->value]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password123',
        ]);

        $response->assertSessionHas('check_social_on_login', true);
    }

    public function test_workspace_switch_triggers_check_social_on_workspace_switch(): void
    {
        [$user, $workspace1] = $this->memberWithWorkspace();
        $workspace2 = \App\Models\Workspace::factory()->create();
        $workspace2->users()->attach($user->id, ['role' => \App\Enums\WorkspaceRole::Owner->value]);

        $response = $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace1->id])
            ->post(route('workspaces.switch', $workspace2), ['redirect' => 'back']);

        $response->assertRedirect();
        $response->assertSessionHas('active_workspace_id', $workspace2->id);
        $response->assertSessionHas('check_social_on_workspace_switch', true);
    }

    public function test_health_check_catches_disconnected_accounts(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();

        SocialAccount::query()->create([
            'workspace_id' => $workspace->id,
            'platform' => 'facebook',
            'account_name' => 'Disconnected Page',
            'account_type' => 'page',
            'connection_mode' => 'oauth',
            'status' => 'disconnected',
            'health' => 'error',
            'external_id' => 'fb_page_disc',
            'access_token' => 'some_token',
        ]);

        $response = $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->postJson(route('social.accounts.health-check'));

        $response->assertOk()
            ->assertJson([
                'checked' => true,
                'has_issues' => true,
                'total_checked' => 1,
            ]);

        $this->assertCount(1, $response->json('failed_accounts'));
        $failed = $response->json('failed_accounts.0');
        $this->assertSame('disconnected', $failed['status']);
        $this->assertStringContainsString('disconnected', strtolower($failed['error_message']));
    }

    public function test_publisher_threads_retries_transient_not_exist(): void
    {
        Queue::fake([\App\Jobs\SyncSocialPostEngagementJob::class]);
        [$user, $workspace] = $this->memberWithWorkspace();

        \Illuminate\Support\Facades\Http::fake([
            'https://graph.threads.net/v1.0/th_user_1/threads' => \Illuminate\Support\Facades\Http::response(['id' => 'container_123'], 200),
            'https://graph.threads.net/v1.0/container_123*' => \Illuminate\Support\Facades\Http::response(['id' => 'container_123', 'status' => 'FINISHED'], 200),
            'https://graph.threads.net/v1.0/th_user_1/threads_publish' => \Illuminate\Support\Facades\Http::sequence()
                ->push([
                    'error' => [
                        'message' => 'The requested resource does not exist',
                        'type' => 'OAuthException',
                        'code' => 24,
                        'error_subcode' => 4279009,
                    ],
                ], 400)
                ->push(['id' => 'thread_post_999'], 200),
            'https://graph.threads.net/v1.0/thread_post_999*' => \Illuminate\Support\Facades\Http::response([
                'id' => 'thread_post_999',
                'permalink' => 'https://www.threads.net/@demo/post/999',
            ], 200),
        ]);

        $account = SocialAccount::query()->create([
            'workspace_id' => $workspace->id,
            'platform' => 'threads',
            'account_name' => '@demo',
            'connection_mode' => 'oauth',
            'status' => 'connected',
            'health' => 'healthy',
            'external_id' => 'th_user_1',
            'access_token' => 'threads-token-test',
            'connected_at' => now(),
        ]);

        $post = SocialPost::query()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $user->id,
            'title' => 'Threads Test',
            'body' => 'Testing threads post propagation',
            'platforms' => ['threads'],
            'status' => 'publishing',
        ]);

        $result = app(SocialPublisherService::class)->publish($post);

        $this->assertTrue($result['ok'], 'Threads publishing succeeded via retry: '.($result['message'] ?? ''));
        $this->assertArrayHasKey('threads', $result['permalinks']);
        $this->assertSame('https://www.threads.net/@demo/post/999', $result['permalinks']['threads']);
    }
}
