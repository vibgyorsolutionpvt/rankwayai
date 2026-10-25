<?php

namespace Tests\Feature;

use App\Models\Itinerary;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ItineraryTest extends TestCase
{
    use RefreshDatabase;

    public function test_itinerary_generation_routes_are_removed_without_deleting_saved_trips(): void
    {
        $workspace = Workspace::factory()->create();
        $itinerary = Itinerary::query()->create([
            'workspace_id' => $workspace->id,
            'title' => 'Previously saved trip',
            'destination' => 'Jaipur',
            'duration_days' => 3,
            'days' => [],
            'pricing' => [],
        ]);

        foreach ([
            'studio.itineraries.index',
            'studio.itineraries.store',
            'studio.itineraries.edit',
            'studio.itineraries.update',
            'studio.itineraries.duplicate',
            'studio.itineraries.regenerate-day',
            'studio.itineraries.quotation',
            'studio.itineraries.destroy',
        ] as $routeName) {
            $this->assertFalse(Route::has($routeName), "Route [{$routeName}] should be removed.");
        }

        $this->assertDatabaseHas('itineraries', [
            'id' => $itinerary->id,
            'title' => 'Previously saved trip',
        ]);
    }
}
