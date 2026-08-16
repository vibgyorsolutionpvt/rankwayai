<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Jobs\PublishSocialPostJob;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Social\SocialPublisherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SocialSchedulerTest extends TestCase
{
    use RefreshDatabase;

    private function memberWithWorkspace(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        $workspace->users()->attach($user->id, ['role' => WorkspaceRole::Owner->value]);

        return [$user, $workspace];
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
        [$user, $workspace] = $this->memberWithWorkspace();

        \Illuminate\Support\Facades\Http::fake([
            'graph.facebook.com/*' => \Illuminate\Support\Facades\Http::sequence()
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

        $post = SocialPost::query()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $user->id,
            'title' => 'Go live',
            'body' => 'Body',
            'platforms' => ['facebook'],
            'status' => 'publishing',
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
}
