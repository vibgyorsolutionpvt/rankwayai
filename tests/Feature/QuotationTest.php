<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\Itinerary;
use App\Models\Quotation;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuotationTest extends TestCase
{
    use RefreshDatabase;

    private function ownerWorkspace(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create([
            'name' => 'Vibgyor Holidays',
            'industry' => 'Travel Agency',
            'city' => 'Lucknow',
        ]);
        $workspace->users()->attach($user->id, ['role' => WorkspaceRole::Owner->value]);
        app(BillingService::class)->changePlan($workspace, 'starter', 'active');

        return [$user, $workspace];
    }

    public function test_owner_can_create_edit_and_share_quotation(): void
    {
        [$user, $workspace] = $this->ownerWorkspace();

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('studio.quotations.store'), [
                'title' => 'Kashmir family quote',
                'customer_name' => 'Anil',
                'customer_email' => 'anil@example.com',
                'travellers' => 4,
                'tax_percent' => 5,
                'status' => 'draft',
            ])
            ->assertRedirect();

        $quotation = Quotation::query()->where('workspace_id', $workspace->id)->first();
        $this->assertNotNull($quotation);
        $this->assertNotEmpty($quotation->share_token);
        $this->assertStringStartsWith('QT-', $quotation->number);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('studio.quotations.update', $quotation), [
                'title' => 'Kashmir family quote',
                'status' => 'sent',
                'customer_name' => 'Anil',
                'customer_email' => 'anil@example.com',
                'travellers' => 4,
                'duration_days' => 6,
                'destination' => 'Kashmir',
                'trip_title' => 'Kashmir 6D',
                'currency' => 'INR',
                'discount_amount' => 1000,
                'tax_percent' => 5,
                'payment_terms' => '50% advance',
                'valid_until' => now()->addDays(10)->toDateString(),
                'inclusions_text' => "Hotels\nTransfers",
                'exclusions_text' => 'Flights',
                'is_public' => true,
                'line_items' => [
                    [
                        'description' => 'Package for 4',
                        'qty' => 1,
                        'unit_price' => 45000,
                    ],
                ],
            ])
            ->assertRedirect();

        $quotation->refresh();
        $this->assertSame('sent', $quotation->status);
        $this->assertSame(45000.0, (float) $quotation->subtotal);
        $this->assertSame(1000.0, (float) $quotation->discount_amount);
        $this->assertEqualsWithDelta(2200.0, (float) $quotation->tax_amount, 0.01); // 5% of 44000
        $this->assertEqualsWithDelta(46200.0, (float) $quotation->total, 0.01);
        $this->assertSame(['Hotels', 'Transfers'], $quotation->inclusions);

        $this->get(route('studio.quotations.public', $quotation->share_token))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Studio/Quotations/Public')
                ->where('quotation.number', $quotation->number));

        $this->assertSame(1, $quotation->fresh()->views_count);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->get(route('studio.quotations.pdf', $quotation))
            ->assertOk();
    }

    public function test_generate_quotation_from_itinerary(): void
    {
        [$user, $workspace] = $this->ownerWorkspace();

        $itinerary = Itinerary::query()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $user->id,
            'title' => 'Kashmir 4D',
            'status' => 'ready',
            'destination' => 'Kashmir',
            'duration_days' => 4,
            'adults' => 2,
            'children' => 0,
            'days' => [
                ['day' => 1, 'title' => 'Arrival', 'activities' => []],
            ],
            'inclusions' => ['Hotels'],
            'exclusions' => ['Flights'],
            'pricing' => [
                'hotel_cost' => 10000,
                'transport_cost' => 4000,
                'activity_cost' => 2000,
                'meal_cost' => 3000,
                'guide_cost' => 1000,
                'tax' => 0,
                'discount' => 500,
                'markup' => 2500,
            ],
            'currency' => 'INR',
            'generation_source' => 'template',
        ]);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('studio.itineraries.quotation', $itinerary))
            ->assertRedirect();

        $quotation = Quotation::query()->where('workspace_id', $workspace->id)->first();
        $this->assertNotNull($quotation);
        $this->assertSame($itinerary->id, $quotation->itinerary_id);
        $this->assertSame('Kashmir', $quotation->destination);
        $this->assertGreaterThanOrEqual(4, count($quotation->line_items));
        $this->assertSame(500.0, (float) $quotation->discount_amount);
    }

    public function test_draft_quotation_is_not_public(): void
    {
        [$user, $workspace] = $this->ownerWorkspace();

        $quotation = Quotation::query()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $user->id,
            'number' => 'QT-TEST-001',
            'title' => 'Hidden',
            'status' => 'draft',
            'line_items' => [['description' => 'X', 'qty' => 1, 'unit_price' => 100, 'amount' => 100]],
            'subtotal' => 100,
            'total' => 100,
            'is_public' => true,
        ]);

        $this->get(route('studio.quotations.public', $quotation->share_token))->assertNotFound();
    }

    public function test_quotation_workspace_isolation(): void
    {
        [$userA, $workspaceA] = $this->ownerWorkspace();
        [$userB, $workspaceB] = $this->ownerWorkspace();

        $quotation = Quotation::query()->create([
            'workspace_id' => $workspaceA->id,
            'created_by' => $userA->id,
            'number' => 'QT-A-001',
            'title' => 'A only',
            'status' => 'sent',
            'line_items' => [['description' => 'X', 'qty' => 1, 'unit_price' => 100, 'amount' => 100]],
            'subtotal' => 100,
            'total' => 100,
            'is_public' => true,
        ]);

        $this->actingAs($userB)
            ->withSession(['active_workspace_id' => $workspaceB->id])
            ->get(route('studio.quotations.edit', $quotation))
            ->assertNotFound();
    }
}
