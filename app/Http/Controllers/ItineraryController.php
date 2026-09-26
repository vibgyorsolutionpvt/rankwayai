<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesWorkspace;
use App\Models\Itinerary;
use App\Services\Studio\ItineraryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ItineraryController extends Controller
{
    use ResolvesWorkspace;

    public function index(Request $request, ItineraryService $itineraries): Response
    {
        $workspace = $this->workspace($request);

        return Inertia::render('Studio/Itineraries/Index', [
            'workspace' => ['id' => $workspace->id, 'name' => $workspace->name],
            'itineraries' => $workspace->itineraries()
                ->latest('id')
                ->get()
                ->map(fn (Itinerary $item) => [
                    'id' => $item->id,
                    'title' => $item->title,
                    'destination' => $item->destination,
                    'duration_days' => $item->duration_days,
                    'status' => $item->status,
                    'generation_source' => $item->generation_source,
                    'updated_at' => $item->updated_at?->timezone(config('app.timezone'))->format('d M Y, g:i A'),
                    'totals' => $itineraries->calculateTotals($itineraries->normalizePricing($item->pricing ?? [])),
                    'currency' => $item->currency,
                ]),
            'defaults' => [
                'destination' => '',
                'starting_city' => $workspace->resolvedCity(),
                'duration_days' => 5,
                'adults' => 2,
                'children' => 0,
                'budget_band' => 'standard',
                'hotel_category' => '3-star',
                'transport' => 'Private cab',
                'meal_preference' => 'MAP',
            ],
        ]);
    }

    public function store(Request $request, ItineraryService $itineraries): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);

        $data = $request->validate([
            'destination' => ['required', 'string', 'max:120'],
            'starting_city' => ['nullable', 'string', 'max:120'],
            'duration_days' => ['required', 'integer', 'min:1', 'max:21'],
            'travel_start' => ['nullable', 'date'],
            'travel_end' => ['nullable', 'date', 'after_or_equal:travel_start'],
            'adults' => ['required', 'integer', 'min:1', 'max:50'],
            'children' => ['nullable', 'integer', 'min:0', 'max:50'],
            'budget_band' => ['nullable', 'string', Rule::in(['economy', 'standard', 'premium', 'luxury'])],
            'hotel_category' => ['nullable', 'string', 'max:40'],
            'transport' => ['nullable', 'string', 'max:80'],
            'interests_text' => ['nullable', 'string', 'max:2000'],
            'meal_preference' => ['nullable', 'string', 'max:80'],
            'special_requirements' => ['nullable', 'string', 'max:2000'],
            'currency' => ['nullable', 'string', 'size:3'],
        ]);

        $result = $itineraries->generate($workspace, $request->user()->id, $data);
        if (! ($result['ok'] ?? false)) {
            return back()->with('error', $result['message'] ?? 'Could not generate itinerary.');
        }

        return redirect()
            ->route('studio.itineraries.edit', $result['itinerary'])
            ->with('success', $result['message']);
    }

    public function edit(Request $request, Itinerary $itinerary, ItineraryService $itineraries): Response
    {
        $workspace = $this->workspace($request);
        abort_unless($itinerary->workspace_id === $workspace->id, 404);

        return Inertia::render('Studio/Itineraries/Edit', [
            'workspace' => ['id' => $workspace->id, 'name' => $workspace->name],
            'itinerary' => $itineraries->present($itinerary),
            'can_view_profit' => in_array($workspace->roleFor($request->user())?->value ?? '', ['owner', 'admin'], true),
        ]);
    }

    public function update(Request $request, Itinerary $itinerary, ItineraryService $itineraries): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);
        abort_unless($itinerary->workspace_id === $workspace->id, 404);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'status' => ['required', 'string', Rule::in(['draft', 'ready', 'archived'])],
            'destination' => ['required', 'string', 'max:120'],
            'starting_city' => ['nullable', 'string', 'max:120'],
            'duration_days' => ['required', 'integer', 'min:1', 'max:21'],
            'travel_start' => ['nullable', 'date'],
            'travel_end' => ['nullable', 'date', 'after_or_equal:travel_start'],
            'adults' => ['required', 'integer', 'min:1', 'max:50'],
            'children' => ['nullable', 'integer', 'min:0', 'max:50'],
            'budget_band' => ['nullable', 'string', 'max:40'],
            'hotel_category' => ['nullable', 'string', 'max:40'],
            'transport' => ['nullable', 'string', 'max:80'],
            'interests_text' => ['nullable', 'string', 'max:2000'],
            'meal_preference' => ['nullable', 'string', 'max:80'],
            'special_requirements' => ['nullable', 'string', 'max:2000'],
            'overview' => ['nullable', 'string', 'max:5000'],
            'inclusions_text' => ['nullable', 'string', 'max:5000'],
            'exclusions_text' => ['nullable', 'string', 'max:5000'],
            'currency' => ['nullable', 'string', 'size:3'],
            'is_public' => ['sometimes', 'boolean'],
            'pricing' => ['nullable', 'array'],
            'pricing.hotel_cost' => ['nullable', 'numeric', 'min:0'],
            'pricing.transport_cost' => ['nullable', 'numeric', 'min:0'],
            'pricing.activity_cost' => ['nullable', 'numeric', 'min:0'],
            'pricing.meal_cost' => ['nullable', 'numeric', 'min:0'],
            'pricing.guide_cost' => ['nullable', 'numeric', 'min:0'],
            'pricing.tax' => ['nullable', 'numeric', 'min:0'],
            'pricing.discount' => ['nullable', 'numeric', 'min:0'],
            'pricing.markup' => ['nullable', 'numeric', 'min:0'],
            'days' => ['nullable', 'array'],
            'days.*.day' => ['nullable', 'integer', 'min:1'],
            'days.*.title' => ['nullable', 'string', 'max:160'],
            'days.*.location' => ['nullable', 'string', 'max:120'],
            'days.*.summary' => ['nullable', 'string', 'max:2000'],
            'days.*.transport' => ['nullable', 'string', 'max:120'],
            'days.*.notes' => ['nullable', 'string', 'max:2000'],
            'days.*.meals' => ['nullable'],
            'days.*.hotel' => ['nullable', 'array'],
            'days.*.activities' => ['nullable', 'array'],
        ]);

        $itineraries->applyEditorPayload($itinerary, $data);

        return back()->with('success', 'Itinerary saved.');
    }

    public function destroy(Request $request, Itinerary $itinerary): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);
        abort_unless($itinerary->workspace_id === $workspace->id, 404);

        $itinerary->delete();

        return redirect()
            ->route('studio.itineraries.index')
            ->with('success', 'Itinerary deleted.');
    }

    public function duplicate(Request $request, Itinerary $itinerary): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);
        abort_unless($itinerary->workspace_id === $workspace->id, 404);

        $copy = $itinerary->replicate(['share_token']);
        $copy->title = $itinerary->title.' (copy)';
        $copy->status = 'draft';
        $copy->created_by = $request->user()->id;
        $copy->share_token = null;
        $copy->generation_source = 'manual';
        $copy->save();

        return redirect()
            ->route('studio.itineraries.edit', $copy)
            ->with('success', 'Itinerary duplicated.');
    }

    public function regenerateDay(Request $request, Itinerary $itinerary, ItineraryService $itineraries): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);
        abort_unless($itinerary->workspace_id === $workspace->id, 404);

        $data = $request->validate([
            'day' => ['required', 'integer', 'min:1', 'max:21'],
            'instruction' => ['nullable', 'string', 'max:500'],
        ]);

        $result = $itineraries->regenerateDay($itinerary, (int) $data['day'], $data['instruction'] ?? null);

        return back()->with(
            ($result['ok'] ?? false) ? 'success' : 'error',
            $result['message'] ?? 'Could not regenerate day.'
        );
    }
}
