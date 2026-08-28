<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\AiGeneration;
use App\Models\AiUsageLog;
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
    private function validComposeInput(array $overrides = []): array
    {
        return array_merge([
            'prompt' => 'Promote our Lucknow to Goa monsoon travel package with 15% off for families',
            'offer' => 'Book now — 15% off',
        ], $overrides);
    }

    public function test_legacy_ai_index_redirects_to_smm_compose(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->get(route('ai.index'))
            ->assertRedirect(route('social.index', ['view' => 'compose']));
    }

    public function test_smm_compose_page_includes_ai_context(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->get(route('social.index', ['view' => 'compose']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Social/Index')
                ->has('ai_context')
                ->where('ai_context.industry', 'Travel agency')
                ->where('ai_context.location', 'Lucknow'));
    }

    public function test_compose_ai_fills_draft_flash_without_creating_post(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('social.compose.ai'), $this->validComposeInput())
            ->assertRedirect(route('social.index', ['view' => 'compose']))
            ->assertSessionHas('success')
            ->assertSessionHas('ai_compose')
            ->assertSessionHas('ai_prompt');

        $draft = session('ai_compose');
        $this->assertIsArray($draft);
        $this->assertNotEmpty($draft['title'] ?? null);
        $this->assertNotEmpty($draft['body'] ?? null);
        $this->assertIsArray($draft['platforms'] ?? null);
        $this->assertSame(
            $this->validComposeInput()['prompt'],
            session('ai_prompt'),
        );

        $this->assertSame(0, SocialPost::query()->where('workspace_id', $workspace->id)->count());
        $this->assertDatabaseHas('ai_generations', [
            'workspace_id' => $workspace->id,
            'type' => 'social_compose',
            'status' => 'ready',
        ]);
        $this->assertDatabaseHas('social_compose_prompt_histories', [
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'prompt' => $this->validComposeInput()['prompt'],
        ]);
        $history = \App\Models\SocialComposePromptHistory::query()
            ->where('workspace_id', $workspace->id)
            ->latest('id')
            ->first();
        $this->assertNotNull($history);
        $this->assertNotEmpty($history->provider);
        // Live providers store URL+response; template fallback still records provider.
        if ($history->provider !== 'template') {
            $this->assertNotEmpty($history->api_url);
            $this->assertTrue(
                filled($history->response_text) || filled($history->response_payload),
            );
        }
        $this->assertDatabaseHas('ai_usage_logs', [
            'workspace_id' => $workspace->id,
            'action' => 'social_compose',
        ]);
        $this->assertNotNull(
            AiUsageLog::query()->where('workspace_id', $workspace->id)->where('action', 'social_compose')->value('provider')
        );

        $account = \App\Models\BillingAccount::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($account);
        $this->assertGreaterThan(0, (float) $account->spent_usd);
    }

    public function test_compose_prompt_history_can_be_cleared(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();

        \App\Models\SocialComposePromptHistory::remember(
            $workspace,
            $user->id,
            'Promote our Lucknow to Goa monsoon travel package with 15% off for families',
            'Book now',
        );

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->delete(route('social.compose.prompt-history.clear'))
            ->assertRedirect(route('social.index', ['view' => 'compose']));

        $this->assertSame(
            0,
            \App\Models\SocialComposePromptHistory::query()->where('workspace_id', $workspace->id)->count(),
        );
    }

    public function test_compose_ai_uses_settings_industry_in_template_body(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();

        $prompt = 'company ki service ke sath social media ke lie post likho';

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('social.compose.ai'), [
                'prompt' => $prompt,
                'offer' => 'Book a call',
            ])
            ->assertSessionHas('ai_compose');

        $title = (string) session('ai_compose.title');
        $body = (string) session('ai_compose.body');

        $this->assertNotSame(mb_strtolower($prompt), mb_strtolower($title));
        $this->assertStringNotContainsString($prompt, $title);
        $this->assertStringNotContainsString($prompt, $body);
        $this->assertDoesNotMatchRegularExpression('/company\s+service/i', $title);
        $this->assertDoesNotMatchRegularExpression('/with\s+Atlas Demo$/i', $title);
        $this->assertDoesNotMatchRegularExpression('/^Book a call\s*[—\-]/i', $title);
        $this->assertStringContainsString('Atlas Demo', $body);
        $this->assertDoesNotMatchRegularExpression('/If IT Company feels noisy/i', $body);
        $this->assertGreaterThanOrEqual(25, str_word_count(strip_tags($body)));
    }

    public function test_budget_blocks_compose(): void
    {
        Queue::fake();
        [$user, $workspace] = $this->memberWithWorkspace();

        $sub = app(BillingService::class)->subscription($workspace);
        $planBudget = (float) ($sub->limits['ai_budget_usd'] ?? 20);

        $account = \App\Models\BillingAccount::query()->where('user_id', $user->id)->first();
        $account?->update([
            'spent_usd' => $planBudget,
            'topup_credits' => 0,
        ]);

        WorkspaceAiSetting::query()->updateOrCreate(
            ['workspace_id' => $workspace->id],
            [
                'monthly_budget_usd' => $planBudget,
                'template_first' => true,
                'tone' => 'mixed',
                'industry' => 'Travel agency',
                'location' => 'Lucknow',
            ]
        );

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('social.compose.ai'), $this->validComposeInput())
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(0, AiGeneration::query()->count());
        $this->assertSame(0, AiUsageLog::query()->count());
        $this->assertNull(session('ai_compose'));
    }

    public function test_topup_credits_allow_compose_when_plan_exhausted(): void
    {
        Queue::fake();
        [$user, $workspace] = $this->memberWithWorkspace();

        $sub = app(BillingService::class)->subscription($workspace);
        $planBudget = (float) ($sub->limits['ai_budget_usd'] ?? 20);

        $account = \App\Models\BillingAccount::query()->where('user_id', $user->id)->first();
        $account?->update([
            'spent_usd' => $planBudget,
            'topup_credits' => 500,
        ]);

        WorkspaceAiSetting::query()->updateOrCreate(
            ['workspace_id' => $workspace->id],
            [
                'monthly_budget_usd' => $planBudget,
                'template_first' => true,
                'tone' => 'mixed',
                'industry' => 'Travel agency',
                'location' => 'Lucknow',
            ]
        );

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('social.compose.ai'), $this->validComposeInput())
            ->assertRedirect(route('social.index', ['view' => 'compose']))
            ->assertSessionHas('success')
            ->assertSessionHas('ai_compose');

        $account = \App\Models\BillingAccount::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($account);
        $this->assertLessThan(500, (int) $account->topup_credits);
        $this->assertSame($planBudget, (float) $account->spent_usd);
    }

    public function test_free_plan_blocks_ai_compose(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace('free');

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('social.compose.ai'), $this->validComposeInput())
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(0, AiGeneration::query()->count());
        $this->assertNull(session('ai_compose'));
    }

    public function test_free_plan_can_compose_with_topup_credits(): void
    {
        Queue::fake();
        [$user, $workspace] = $this->memberWithWorkspace('free');

        \App\Models\BillingAccount::query()->where('user_id', $user->id)->update([
            'topup_credits' => 500,
            'spent_usd' => 0,
        ]);

        WorkspaceAiSetting::query()->updateOrCreate(
            ['workspace_id' => $workspace->id],
            [
                'monthly_budget_usd' => 0,
                'template_first' => true,
                'tone' => 'mixed',
                'industry' => 'Travel agency',
                'location' => 'Lucknow',
            ]
        );

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('social.compose.ai'), $this->validComposeInput())
            ->assertRedirect(route('social.index', ['view' => 'compose']))
            ->assertSessionHas('success')
            ->assertSessionHas('ai_compose');

        $account = \App\Models\BillingAccount::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($account);
        $this->assertLessThan(500, (int) $account->topup_credits);
    }

    public function test_compose_requires_prompt(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('social.compose.ai'), [])
            ->assertSessionHasErrors(['prompt']);

        $this->assertNull(session('ai_compose'));
    }

    public function test_compose_includes_workspace_contact_details(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();

        $workspace->update([
            'phone' => '+91 9876543210',
            'email' => 'hello@vibgyor.com',
            'website' => 'https://vibgyor.com',
        ]);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('social.compose.ai'), $this->validComposeInput())
            ->assertSessionHas('ai_compose');

        $body = (string) session('ai_compose.body');
        $this->assertStringContainsString('9876543210', $body);
        $this->assertStringContainsString('hello@vibgyor.com', $body);
        $this->assertStringContainsString('vibgyor.com', $body);
    }

    public function test_compose_targets_connected_enabled_platforms(): void
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
            ->post(route('social.compose.ai'), $this->validComposeInput())
            ->assertRedirect(route('social.index', ['view' => 'compose']))
            ->assertSessionHas('ai_compose');

        $platforms = session('ai_compose.platforms');
        $this->assertIsArray($platforms);
        $this->assertNotContains('linkedin', $platforms);
        foreach ($platforms as $platform) {
            $this->assertContains($platform, ['facebook', 'instagram', 'threads']);
        }
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
