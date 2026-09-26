<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\CrmLead;
use App\Models\Quotation;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadScoringAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private function ownerWorkspace(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create([
            'name' => 'Vibgyor Holidays',
            'industry' => 'Travel Agency',
        ]);
        $workspace->users()->attach($user->id, ['role' => WorkspaceRole::Owner->value]);
        app(BillingService::class)->changePlan($workspace, 'starter', 'active');

        return [$user, $workspace];
    }

    public function test_owner_can_score_lead_and_get_follow_up(): void
    {
        [$user, $workspace] = $this->ownerWorkspace();

        $lead = CrmLead::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Priya',
            'email' => 'priya@example.com',
            'phone' => '+919876543210',
            'stage' => 'qualified',
            'source' => 'whatsapp',
            'value_cents' => 5000000,
            'notes' => 'Need Kashmir for 4 people in December, budget around 50k.',
        ]);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('crm.score', $lead))
            ->assertRedirect();

        $lead->refresh();
        $this->assertNotNull($lead->score);
        $this->assertGreaterThanOrEqual(71, $lead->score);
        $this->assertSame('hot', $lead->score_band);
        $this->assertNotEmpty($lead->score_reason);
        $this->assertContains($lead->score_source, ['heuristic', 'ai']);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('crm.follow-up', $lead))
            ->assertRedirect();

        $lead->refresh();
        $this->assertNotEmpty($lead->follow_up_suggestion);
        $this->assertNotEmpty($lead->next_action);
        $this->assertNotNull($lead->follow_up_due_at);
    }

    public function test_analytics_page_shows_workspace_snapshot(): void
    {
        [$user, $workspace] = $this->ownerWorkspace();

        CrmLead::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Hot lead',
            'stage' => 'qualified',
            'score' => 88,
            'score_band' => 'hot',
            'value_cents' => 100000,
        ]);

        Quotation::query()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $user->id,
            'number' => 'QT-TEST-100',
            'title' => 'Test quote',
            'status' => 'sent',
            'line_items' => [['description' => 'Package', 'qty' => 1, 'unit_price' => 20000, 'amount' => 20000]],
            'subtotal' => 20000,
            'total' => 20000,
            'is_public' => true,
        ]);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->get(route('analytics.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Analytics/Index')
                ->where('snapshot.leads.total', 1)
                ->where('snapshot.leads.hot', 1)
                ->where('snapshot.quotations.total', 1));
    }

    public function test_scoring_is_workspace_isolated(): void
    {
        [$userA, $workspaceA] = $this->ownerWorkspace();
        [$userB, $workspaceB] = $this->ownerWorkspace();

        $lead = CrmLead::query()->create([
            'workspace_id' => $workspaceA->id,
            'name' => 'A only',
            'stage' => 'new',
        ]);

        $this->actingAs($userB)
            ->withSession(['active_workspace_id' => $workspaceB->id])
            ->post(route('crm.score', $lead))
            ->assertNotFound();
    }
}
