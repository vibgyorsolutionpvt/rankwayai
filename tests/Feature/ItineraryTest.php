<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\Itinerary;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\BillingService;
use App\Services\Studio\ItineraryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ItineraryTest extends TestCase
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

    public function test_owner_can_generate_and_edit_itinerary(): void
    {
        [$user, $workspace] = $this->ownerWorkspace();

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('studio.itineraries.store'), [
                'destination' => 'Kashmir',
                'starting_city' => 'Srinagar',
                'duration_days' => 4,
                'adults' => 2,
                'children' => 0,
                'budget_band' => 'standard',
                'hotel_category' => '3-star',
                'transport' => 'Private cab',
                'meal_preference' => 'MAP',
                'interests_text' => "Sightseeing\nFamily",
            ])
            ->assertRedirect();

        $itinerary = Itinerary::query()->where('workspace_id', $workspace->id)->first();
        $this->assertNotNull($itinerary);
        $this->assertCount(4, $itinerary->days);
        $this->assertContains($itinerary->generation_source, ['template', 'ai']);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('studio.itineraries.update', $itinerary), [
                'title' => 'Kashmir family trip',
                'status' => 'ready',
                'destination' => 'Kashmir',
                'duration_days' => 4,
                'adults' => 2,
                'children' => 1,
                'overview' => 'Updated overview',
                'inclusions_text' => "Hotels\nTransfers",
                'exclusions_text' => 'Flights',
                'pricing' => [
                    'hotel_cost' => 10000,
                    'transport_cost' => 4000,
                    'activity_cost' => 2000,
                    'meal_cost' => 3000,
                    'guide_cost' => 1000,
                    'tax' => 500,
                    'discount' => 500,
                    'markup' => 2500,
                ],
                'days' => $itinerary->days,
            ])
            ->assertRedirect();

        $itinerary->refresh();
        $this->assertSame('Kashmir family trip', $itinerary->title);
        $this->assertSame('ready', $itinerary->status);
        $this->assertSame(['Hotels', 'Transfers'], $itinerary->inclusions);

        $totals = app(ItineraryService::class)->calculateTotals(
            app(ItineraryService::class)->normalizePricing($itinerary->pricing)
        );
        $this->assertSame(20000.0, $totals['base_cost']);
        $this->assertSame(22500.0, $totals['selling_price']);
        $this->assertSame(2500.0, $totals['profit']);
    }

    public function test_can_add_and_regenerate_day(): void
    {
        [$user, $workspace] = $this->ownerWorkspace();

        $result = app(ItineraryService::class)->generate($workspace, $user->id, [
            'destination' => 'Manali',
            'duration_days' => 3,
            'adults' => 2,
        ]);
        $itinerary = $result['itinerary'];

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('studio.itineraries.regenerate-day', $itinerary), [
                'day' => 2,
                'instruction' => 'Make Day 2 more adventurous',
            ])
            ->assertRedirect();

        $this->assertCount(3, $itinerary->fresh()->days);
    }

    public function test_itinerary_workspace_isolation(): void
    {
        [$userA, $workspaceA] = $this->ownerWorkspace();
        [$userB, $workspaceB] = $this->ownerWorkspace();

        $itinerary = Itinerary::query()->create([
            'workspace_id' => $workspaceA->id,
            'created_by' => $userA->id,
            'title' => 'Secret trip',
            'destination' => 'Leh',
            'duration_days' => 5,
            'days' => [],
            'pricing' => [],
        ]);

        $this->actingAs($userB)
            ->withSession(['active_workspace_id' => $workspaceB->id])
            ->get(route('studio.itineraries.edit', $itinerary))
            ->assertNotFound();
    }
}
