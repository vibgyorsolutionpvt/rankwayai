<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\AiGeneration;
use App\Models\AiUsageLog;
use App\Models\FestivalEvent;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceAiSetting;
use App\Services\Ai\AiContentService;
use App\Services\Billing\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AiLayerTest extends TestCase
{
    use RefreshDatabase;

    private function memberWithWorkspace(string $plan = 'starter'): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['name' => 'Atlas Demo']);
        $workspace->users()->attach($user->id, ['role' => WorkspaceRole::Owner->value]);
        app(BillingService::class)->changePlan($workspace, $plan, 'active');

        WorkspaceAiSetting::query()->updateOrCreate(
            ['workspace_id' => $workspace->id],
            [
                'industry' => 'Travel agency',
                'location' => 'Lucknow',
                'tone' => 'mixed',
            ]
        );

        return [$user, $workspace];
    }

    /** @return array<string, mixed> */
    private function validPostInput(array $overrides = []): array
    {
        return array_merge([
            'brief' => 'Promote our Lucknow to Goa monsoon travel package with 15% off for families',
            'offer' => 'Book now — 15% off',
        ], $overrides);
    }

    public function test_ai_studio_page_loads(): void
    {
        config(['festivals.nager_enabled' => false, 'festivals.ics_enabled' => false]);
        \Illuminate\Support\Facades\Cache::forget('festivals:last_sync');

        [$user, $workspace] = $this->memberWithWorkspace();

        FestivalEvent::query()->create([
            'name' => 'Test Fest',
            'occurs_on' => now()->addDays(3)->toDateString(),
            'region' => 'IN',
            'category' => 'festival',
            'suggested_angles' => ['Offer'],
        ]);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->get(route('ai.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Ai/Index')
                ->has('credits')
                ->has('festivals')
                ->where('setup_complete', true));
    }

    public function test_generate_today_creates_drafts_and_logs_cost(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('ai.generate-today'), $this->validPostInput())
            ->assertRedirect(route('social.index', ['status' => 'draft']))
            ->assertSessionHas('success');

        $this->assertSame(1, SocialPost::query()->where('workspace_id', $workspace->id)->count());
        $this->assertSame(1, SocialPost::query()->where('status', 'draft')->where('requires_approval', true)->count());
        $this->assertDatabaseHas('ai_generations', [
            'workspace_id' => $workspace->id,
            'type' => 'today_pack',
            'status' => 'ready',
        ]);
        $this->assertDatabaseHas('ai_usage_logs', [
            'workspace_id' => $workspace->id,
            'action' => 'generate_today',
            'provider' => 'template',
        ]);

        $settings = WorkspaceAiSetting::query()->where('workspace_id', $workspace->id)->first();
        $this->assertNotNull($settings);
        $this->assertGreaterThan(0, (float) $settings->spent_usd);

        if (function_exists('imagecreatetruecolor')) {
            SocialPost::query()
                ->where('workspace_id', $workspace->id)
                ->get()
                ->each(fn (SocialPost $post) => $this->assertNotNull(
                    $post->media_asset_id,
                    'All social drafts should include a generated poster image',
                ));
        }
    }

    public function test_budget_blocks_generation(): void
    {
        Queue::fake();
        [$user, $workspace] = $this->memberWithWorkspace();

        $sub = app(BillingService::class)->subscription($workspace);
        $planBudget = (float) ($sub->limits['ai_budget_usd'] ?? 20);

        WorkspaceAiSetting::query()->updateOrCreate(
            ['workspace_id' => $workspace->id],
            [
                'monthly_budget_usd' => $planBudget,
                'spent_usd' => $planBudget,
                'topup_credits' => 0,
                'template_first' => true,
                'tone' => 'mixed',
                'industry' => 'Travel agency',
                'location' => 'Lucknow',
            ]
        );

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('ai.generate-today'), $this->validPostInput())
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(0, SocialPost::query()->count());
        $this->assertSame(0, AiGeneration::query()->count());
        $this->assertSame(0, AiUsageLog::query()->count());
    }

    public function test_topup_credits_allow_generation_when_plan_exhausted(): void
    {
        Queue::fake();
        [$user, $workspace] = $this->memberWithWorkspace();

        $sub = app(BillingService::class)->subscription($workspace);
        $planBudget = (float) ($sub->limits['ai_budget_usd'] ?? 20);

        WorkspaceAiSetting::query()->updateOrCreate(
            ['workspace_id' => $workspace->id],
            [
                'monthly_budget_usd' => $planBudget,
                'spent_usd' => $planBudget,
                'topup_credits' => 500,
                'template_first' => true,
                'tone' => 'mixed',
                'industry' => 'Travel agency',
                'location' => 'Lucknow',
            ]
        );

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('ai.generate-today'), $this->validPostInput())
            ->assertRedirect(route('social.index', ['status' => 'draft']))
            ->assertSessionHas('success');

        $settings = WorkspaceAiSetting::query()->where('workspace_id', $workspace->id)->first();
        $this->assertLessThan(500, (int) $settings->topup_credits);
        $this->assertSame($planBudget, (float) $settings->spent_usd);
    }

    public function test_free_plan_blocks_ai_generation(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace('free');

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('ai.generate-today'), $this->validPostInput())
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(0, SocialPost::query()->count());
        $this->assertSame(0, AiGeneration::query()->count());
    }

    public function test_free_plan_can_use_ai_with_topup_credits(): void
    {
        Queue::fake();
        [$user, $workspace] = $this->memberWithWorkspace('free');

        WorkspaceAiSetting::query()->updateOrCreate(
            ['workspace_id' => $workspace->id],
            [
                'monthly_budget_usd' => 0,
                'spent_usd' => 0,
                'topup_credits' => 500,
                'template_first' => true,
                'tone' => 'mixed',
                'industry' => 'Travel agency',
                'location' => 'Lucknow',
            ]
        );

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('ai.generate-today'), $this->validPostInput())
            ->assertRedirect(route('social.index', ['status' => 'draft']))
            ->assertSessionHas('success');

        $settings = WorkspaceAiSetting::query()->where('workspace_id', $workspace->id)->first();
        $this->assertLessThan(500, (int) $settings->topup_credits);
        $this->assertGreaterThan(0, SocialPost::query()->count());
    }

    public function test_generate_requires_brief(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('ai.generate-today'), [])
            ->assertSessionHasErrors(['brief']);

        $this->assertSame(0, SocialPost::query()->count());
    }

    public function test_generate_requires_business_details(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();

        WorkspaceAiSetting::query()->where('workspace_id', $workspace->id)->update([
            'industry' => 'local business',
            'location' => 'India',
        ]);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('ai.generate-today'), $this->validPostInput())
            ->assertSessionHasErrors(['industry', 'location']);

        $this->assertSame(0, SocialPost::query()->count());
    }

    public function test_generate_saves_business_details_in_same_request(): void
    {
        Queue::fake();
        [$user, $workspace] = $this->memberWithWorkspace();

        WorkspaceAiSetting::query()->where('workspace_id', $workspace->id)->update([
            'industry' => 'local business',
            'location' => 'India',
        ]);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('ai.generate-today'), $this->validPostInput([
                'industry' => 'IT company',
                'location' => 'Delhi',
                'tone' => 'english',
            ]))
            ->assertRedirect(route('social.index', ['status' => 'draft']))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('workspace_ai_settings', [
            'workspace_id' => $workspace->id,
            'industry' => 'IT company',
            'location' => 'Delhi',
            'tone' => 'english',
        ]);
        $this->assertDatabaseHas('workspaces', [
            'id' => $workspace->id,
            'industry' => 'IT company',
            'city' => 'Delhi',
        ]);
        $this->assertSame(1, SocialPost::query()->where('workspace_id', $workspace->id)->count());
    }

    public function test_ai_uses_saved_workspace_profile_without_reposting_business_fields(): void
    {
        Queue::fake();
        [$user, $workspace] = $this->memberWithWorkspace();

        $workspace->update([
            'industry' => 'Travel agency',
            'city' => 'Lucknow',
        ]);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->get(route('ai.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('setup_complete', true)
                ->where('workspace.has_business_profile', true));

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('ai.generate-today'), $this->validPostInput())
            ->assertRedirect(route('social.index', ['status' => 'draft']));
    }

    public function test_generate_only_targets_connected_enabled_platforms(): void
    {
        Queue::fake();
        [$user, $workspace] = $this->memberWithWorkspace();

        $workspace->update([
            'enabled_social_platforms' => ['facebook', 'instagram', 'threads', 'linkedin'],
        ]);

        foreach (['facebook', 'instagram', 'threads'] as $platform) {
            SocialAccount::query()->create([
                'workspace_id' => $workspace->id,
                'platform' => $platform,
                'account_type' => 'page',
                'connection_mode' => 'oauth',
                'account_name' => ucfirst($platform).' page',
                'status' => 'connected',
                'external_id' => 'ext_'.$platform,
                'connected_at' => now(),
                'health' => 'healthy',
            ]);
        }

        $allowed = app(AiContentService::class)->publishPlatforms($workspace->fresh());
        $this->assertSame(['facebook', 'instagram', 'threads'], $allowed);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('ai.generate-today'), $this->validPostInput())
            ->assertRedirect(route('social.index', ['status' => 'draft']))
            ->assertSessionHas('success');

        $posts = SocialPost::query()->where('workspace_id', $workspace->id)->get();
        $this->assertSame(1, $posts->count());

        foreach ($posts as $post) {
            $this->assertNotContains('linkedin', $post->platforms);
            foreach ($post->platforms as $platform) {
                $this->assertContains($platform, ['facebook', 'instagram', 'threads']);
            }
        }
    }

    public function test_generate_respects_draft_count(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('ai.generate-today'), $this->validPostInput(['draft_count' => 3]))
            ->assertRedirect(route('social.index', ['status' => 'draft']))
            ->assertSessionHas('success');

        $this->assertSame(3, SocialPost::query()->where('workspace_id', $workspace->id)->count());
    }

    public function test_preview_respects_word_limit(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();

        $response = $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->postJson(route('ai.preview-today'), [
                'brief' => 'Goa family packages 15% off for summer holidays',
                'word_limit' => 50,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('word_limit', 50);

        foreach ($response->json('previews') as $post) {
            $contentWords = (int) ($post['word_count'] ?? 0);
            $this->assertGreaterThanOrEqual(25, $contentWords);
            $this->assertLessThanOrEqual(55, $contentWords);
            $this->assertGreaterThan($contentWords, $this->wordCountHelper((string) $post['body']));
        }
    }

    private function wordCountHelper(string $text): int
    {
        preg_match_all('/\S+/u', trim(strip_tags($text)), $matches);

        return count($matches[0] ?? []);
    }

    public function test_preview_includes_workspace_contact_details(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();

        $workspace->update([
            'phone' => '+91 9876543210',
            'email' => 'hello@vibgyor.com',
            'website' => 'https://vibgyor.com',
        ]);

        $response = $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->postJson(route('ai.preview-today'), [
                'brief' => 'Goa family packages 15% off for summer',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $body = (string) $response->json('previews.0.body');
        $this->assertStringContainsString('9876543210', $body);
        $this->assertStringContainsString('hello@vibgyor.com', $body);
        $this->assertStringContainsString('vibgyor.com', $body);
    }

    public function test_preview_with_festival_includes_festival_and_hashtags(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();

        $festival = FestivalEvent::query()->create([
            'name' => 'Independence Day',
            'occurs_on' => now()->addDays(5)->toDateString(),
            'region' => 'IN',
            'category' => 'festival',
            'suggested_angles' => ['Patriotic travel deals'],
        ]);

        $response = $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->postJson(route('ai.preview-today'), [
                'festival_id' => $festival->id,
                'brief' => 'Goa family packages 15% off',
                'draft_count' => 3,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $previews = $response->json('previews');
        $this->assertCount(3, $previews);

        foreach ($previews as $post) {
            $this->assertStringContainsString('Independence Day', $post['body']);
            $this->assertMatchesRegularExpression('/#\w+/', $post['body']);
        }
    }

    public function test_preview_today_returns_captions_without_credits(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->postJson(route('ai.preview-today'), [
                'brief' => 'Promote our Lucknow to Goa monsoon travel package with 15% off',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonCount(1, 'previews')
            ->assertJsonPath('brief', 'Promote our Lucknow to Goa monsoon travel package with 15% off');

        $this->assertSame(0, SocialPost::query()->count());
        $this->assertSame(0, AiUsageLog::query()->where('action', 'preview_today')->count());
    }

    public function test_write_blog_article_returns_full_html(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();

        $result = app(AiContentService::class)->writeBlogArticle(
            $workspace,
            'Goa family trip packages from Noida',
            $user->id,
        );

        $this->assertTrue($result['ok']);
        $this->assertNotEmpty($result['article']['title']);
        $this->assertStringContainsString('<h2>', $result['article']['body_html']);
        $this->assertStringContainsString('<p>', $result['article']['body_html']);
        $this->assertGreaterThan(40, str_word_count(strip_tags($result['article']['body_html'])));
        $this->assertDatabaseHas('ai_generations', [
            'workspace_id' => $workspace->id,
            'type' => 'blog_article',
        ]);
        $this->assertDatabaseHas('ai_usage_logs', [
            'workspace_id' => $workspace->id,
            'action' => 'blog_article',
        ]);
    }

    public function test_blog_outline_and_seo_metas(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('ai.blog-outline'), ['topic' => 'Local SEO basics'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('ai.seo-metas'), ['page_title' => 'Best plumber near me'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(2, AiGeneration::query()->where('workspace_id', $workspace->id)->count());
        $this->assertDatabaseHas('ai_generations', [
            'workspace_id' => $workspace->id,
            'type' => 'blog',
            'title' => 'Local SEO basics',
        ]);
        $this->assertDatabaseHas('ai_generations', [
            'workspace_id' => $workspace->id,
            'type' => 'seo_meta',
            'title' => 'Best plumber near me',
        ]);
    }

    public function test_today_page_loads_with_priority_order(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->get(route('today'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Today/Index'));
    }
}
