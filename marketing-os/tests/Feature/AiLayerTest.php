<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Jobs\GeneratePosterVariantsJob;
use App\Models\AiGeneration;
use App\Models\AiUsageLog;
use App\Models\FestivalEvent;
use App\Models\SocialPost;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceAiSetting;
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

        return [$user, $workspace];
    }

    public function test_ai_studio_page_loads(): void
    {
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
                ->has('budget')
                ->has('festivals', 1)
                ->has('ai_providers', 7)
                ->where('active_ai_provider', 'template'));
    }

    public function test_generate_today_creates_drafts_and_logs_cost(): void
    {
        Queue::fake();
        [$user, $workspace] = $this->memberWithWorkspace();

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('ai.generate-today'))
            ->assertRedirect();

        $this->assertSame(3, SocialPost::query()->where('workspace_id', $workspace->id)->count());
        $this->assertSame(3, SocialPost::query()->where('status', 'draft')->where('requires_approval', true)->count());
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

        Queue::assertPushed(GeneratePosterVariantsJob::class, 3);
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
            ]
        );

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('ai.generate-today'))
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
            ]
        );

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('ai.generate-today'))
            ->assertRedirect()
            ->assertSessionHas('success');

        $settings = WorkspaceAiSetting::query()->where('workspace_id', $workspace->id)->first();
        $this->assertLessThan(500, (int) $settings->topup_credits);
        $this->assertSame($planBudget, (float) $settings->spent_usd);
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
    }

    public function test_free_plan_blocks_ai_generation(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace('free');

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('ai.generate-today'))
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
            ]
        );

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('ai.generate-today'))
            ->assertRedirect()
            ->assertSessionHas('success');

        $settings = WorkspaceAiSetting::query()->where('workspace_id', $workspace->id)->first();
        $this->assertLessThan(500, (int) $settings->topup_credits);
        $this->assertGreaterThan(0, SocialPost::query()->count());
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
