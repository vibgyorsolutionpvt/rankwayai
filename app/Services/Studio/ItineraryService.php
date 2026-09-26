<?php

namespace App\Services\Studio;

use App\Models\AiUsageLog;
use App\Models\Itinerary;
use App\Models\Workspace;
use App\Services\Ai\AiProviderRouter;
use App\Services\Billing\CreditWalletService;
use Illuminate\Support\Str;

class ItineraryService
{
    public function __construct(
        private AiProviderRouter $ai,
        private CreditWalletService $credits,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function present(Itinerary $itinerary): array
    {
        $pricing = $this->normalizePricing($itinerary->pricing ?? []);
        $totals = $this->calculateTotals($pricing);

        return [
            'id' => $itinerary->id,
            'title' => $itinerary->title,
            'status' => $itinerary->status,
            'destination' => $itinerary->destination,
            'starting_city' => $itinerary->starting_city,
            'duration_days' => (int) $itinerary->duration_days,
            'travel_start' => $itinerary->travel_start?->toDateString(),
            'travel_end' => $itinerary->travel_end?->toDateString(),
            'adults' => (int) $itinerary->adults,
            'children' => (int) $itinerary->children,
            'budget_band' => $itinerary->budget_band,
            'hotel_category' => $itinerary->hotel_category,
            'transport' => $itinerary->transport,
            'interests' => $itinerary->interests ?? [],
            'interests_text' => implode("\n", $itinerary->interests ?? []),
            'meal_preference' => $itinerary->meal_preference,
            'special_requirements' => $itinerary->special_requirements,
            'overview' => $itinerary->overview,
            'days' => $this->normalizeDays($itinerary->days ?? []),
            'inclusions' => $itinerary->inclusions ?? [],
            'inclusions_text' => implode("\n", $itinerary->inclusions ?? []),
            'exclusions' => $itinerary->exclusions ?? [],
            'exclusions_text' => implode("\n", $itinerary->exclusions ?? []),
            'pricing' => $pricing,
            'totals' => $totals,
            'currency' => $itinerary->currency ?: 'INR',
            'generation_source' => $itinerary->generation_source,
            'share_url' => url('/i/'.$itinerary->share_token),
            'is_public' => $itinerary->is_public,
            'updated_at' => $itinerary->updated_at?->timezone(config('app.timezone'))->format('d M Y, g:i A'),
            'created_at' => $itinerary->created_at?->timezone(config('app.timezone'))->format('d M Y, g:i A'),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, message: string, itinerary?: Itinerary}
     */
    public function generate(Workspace $workspace, ?int $userId, array $input): array
    {
        $daysCount = max(1, min(21, (int) ($input['duration_days'] ?? 3)));
        $destination = trim((string) ($input['destination'] ?? ''));
        if ($destination === '') {
            return ['ok' => false, 'message' => 'Destination is required.'];
        }

        $cost = 0.03;
        if (! $this->credits->canSpend($workspace, $cost) && $this->ai->anyConfigured()) {
            return ['ok' => false, 'message' => 'Not enough AI credits to generate an itinerary.'];
        }

        $generated = null;
        $source = 'template';

        if ($this->ai->anyConfigured() && $this->credits->canSpend($workspace, $cost)) {
            try {
                $generated = $this->generateWithAi($workspace, $input, $daysCount);
                if ($generated) {
                    $source = 'ai';
                    $this->logUsage($workspace, $userId, $cost, $this->ai->activeName(), [
                        'destination' => $destination,
                        'days' => $daysCount,
                    ]);
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        if (! $generated) {
            $generated = $this->templateItinerary($workspace, $input, $daysCount);
            $source = 'template';
        }

        $pricing = $this->normalizePricing($input['pricing'] ?? $this->defaultPricing($input, $daysCount));

        $itinerary = Itinerary::query()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $userId,
            'brand_kit_id' => $workspace->resolveBrandKit()?->id,
            'title' => $generated['title'] ?? ($destination.' '.$daysCount.'D itinerary'),
            'status' => 'draft',
            'destination' => $destination,
            'starting_city' => trim((string) ($input['starting_city'] ?? '')) ?: null,
            'duration_days' => $daysCount,
            'travel_start' => $input['travel_start'] ?? null,
            'travel_end' => $input['travel_end'] ?? null,
            'adults' => max(1, (int) ($input['adults'] ?? 2)),
            'children' => max(0, (int) ($input['children'] ?? 0)),
            'budget_band' => $input['budget_band'] ?? 'standard',
            'hotel_category' => $input['hotel_category'] ?? '3-star',
            'transport' => $input['transport'] ?? 'Private cab',
            'interests' => $this->linesToList($input['interests'] ?? $input['interests_text'] ?? null),
            'meal_preference' => $input['meal_preference'] ?? 'MAP',
            'special_requirements' => trim((string) ($input['special_requirements'] ?? '')) ?: null,
            'overview' => $generated['overview'] ?? null,
            'days' => $generated['days'] ?? [],
            'inclusions' => $generated['inclusions'] ?? [],
            'exclusions' => $generated['exclusions'] ?? [],
            'pricing' => $pricing,
            'currency' => $input['currency'] ?? 'INR',
            'generation_source' => $source,
            'is_public' => false,
        ]);

        return [
            'ok' => true,
            'message' => $source === 'ai' ? 'AI itinerary generated.' : 'Template itinerary created (edit freely).',
            'itinerary' => $itinerary,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function applyEditorPayload(Itinerary $itinerary, array $data): Itinerary
    {
        $itinerary->fill([
            'title' => $data['title'] ?? $itinerary->title,
            'status' => $data['status'] ?? $itinerary->status,
            'destination' => $data['destination'] ?? $itinerary->destination,
            'starting_city' => $data['starting_city'] ?? $itinerary->starting_city,
            'duration_days' => max(1, min(21, (int) ($data['duration_days'] ?? $itinerary->duration_days))),
            'travel_start' => $data['travel_start'] ?? $itinerary->travel_start,
            'travel_end' => $data['travel_end'] ?? $itinerary->travel_end,
            'adults' => max(1, (int) ($data['adults'] ?? $itinerary->adults)),
            'children' => max(0, (int) ($data['children'] ?? $itinerary->children)),
            'budget_band' => $data['budget_band'] ?? $itinerary->budget_band,
            'hotel_category' => $data['hotel_category'] ?? $itinerary->hotel_category,
            'transport' => $data['transport'] ?? $itinerary->transport,
            'interests' => $this->linesToList($data['interests_text'] ?? $data['interests'] ?? null) ?? $itinerary->interests,
            'meal_preference' => $data['meal_preference'] ?? $itinerary->meal_preference,
            'special_requirements' => $data['special_requirements'] ?? $itinerary->special_requirements,
            'overview' => $data['overview'] ?? $itinerary->overview,
            'days' => $this->normalizeDays($data['days'] ?? $itinerary->days ?? []),
            'inclusions' => $this->linesToList($data['inclusions_text'] ?? $data['inclusions'] ?? null) ?? $itinerary->inclusions,
            'exclusions' => $this->linesToList($data['exclusions_text'] ?? $data['exclusions'] ?? null) ?? $itinerary->exclusions,
            'pricing' => $this->normalizePricing($data['pricing'] ?? $itinerary->pricing ?? []),
            'currency' => $data['currency'] ?? $itinerary->currency,
            'is_public' => array_key_exists('is_public', $data) ? (bool) $data['is_public'] : $itinerary->is_public,
        ])->save();

        return $itinerary->fresh();
    }

    /**
     * Regenerate a single day via AI or template.
     *
     * @return array{ok: bool, message: string, day?: array<string, mixed>}
     */
    public function regenerateDay(Itinerary $itinerary, int $dayNumber, ?string $instruction = null): array
    {
        $days = $this->normalizeDays($itinerary->days ?? []);
        $index = collect($days)->search(fn ($d) => (int) ($d['day'] ?? 0) === $dayNumber);
        if ($index === false) {
            return ['ok' => false, 'message' => 'Day not found.'];
        }

        $workspace = $itinerary->workspace;
        $cost = 0.015;
        $day = null;

        if ($workspace && $this->ai->anyConfigured() && $this->credits->canSpend($workspace, $cost)) {
            try {
                $day = $this->regenerateDayWithAi($itinerary, $dayNumber, $instruction);
                if ($day) {
                    $this->logUsage($workspace, null, $cost, $this->ai->activeName(), [
                        'itinerary_id' => $itinerary->id,
                        'day' => $dayNumber,
                    ]);
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        if (! $day) {
            $day = $this->templateDay(
                $dayNumber,
                (string) $itinerary->destination,
                (string) ($itinerary->hotel_category ?: '3-star'),
                (string) ($itinerary->transport ?: 'Private cab'),
                (string) ($itinerary->meal_preference ?: 'MAP')
            );
            if ($instruction) {
                $day['notes'] = trim(($day['notes'] ?? '').' '.$instruction);
            }
        }

        $days[$index] = $day;
        $itinerary->update(['days' => array_values($days)]);

        return ['ok' => true, 'message' => 'Day regenerated.', 'day' => $day];
    }

    /**
     * @param  array<string, mixed>  $pricing
     * @return array{base_cost: float, selling_price: float, profit: float, margin: float}
     */
    public function calculateTotals(array $pricing): array
    {
        $base = (float) ($pricing['hotel_cost'] ?? 0)
            + (float) ($pricing['transport_cost'] ?? 0)
            + (float) ($pricing['activity_cost'] ?? 0)
            + (float) ($pricing['meal_cost'] ?? 0)
            + (float) ($pricing['guide_cost'] ?? 0)
            + (float) ($pricing['tax'] ?? 0)
            - (float) ($pricing['discount'] ?? 0);

        $markup = (float) ($pricing['markup'] ?? 0);
        $selling = $base + $markup;
        $profit = $selling - $base;
        $margin = $selling > 0 ? round(($profit / $selling) * 100, 2) : 0.0;

        return [
            'base_cost' => round(max(0, $base), 2),
            'selling_price' => round(max(0, $selling), 2),
            'profit' => round($profit, 2),
            'margin' => $margin,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{title: string, overview: string, days: list<array<string, mixed>>, inclusions: list<string>, exclusions: list<string>}|null
     */
    private function generateWithAi(Workspace $workspace, array $input, int $daysCount): ?array
    {
        $provider = $this->ai->resolve();
        if (! $provider) {
            return null;
        }

        $interests = $this->linesToList($input['interests'] ?? $input['interests_text'] ?? null) ?? [];
        $system = 'You are a travel itinerary planner for Indian agencies. Return ONLY valid JSON. No markdown. Do not invent exact hotel prices. Keep activities realistic.';
        $user = 'Create a '.$daysCount.'-day itinerary.

Destination: '.($input['destination'] ?? '').'
Starting city: '.($input['starting_city'] ?? 'same as destination').'
Adults: '.($input['adults'] ?? 2).', Children: '.($input['children'] ?? 0).'
Budget: '.($input['budget_band'] ?? 'standard').'
Hotel category: '.($input['hotel_category'] ?? '3-star').'
Transport: '.($input['transport'] ?? 'Private cab').'
Meal preference: '.($input['meal_preference'] ?? 'MAP').'
Interests: '.(implode(', ', $interests) ?: 'sightseeing').'
Special requirements: '.($input['special_requirements'] ?? 'none').'
Business: '.$workspace->name.' ('.($workspace->resolvedIndustry() ?: 'travel').')

JSON shape:
{
  "title": "...",
  "overview": "2-3 sentences",
  "days": [
    {
      "day": 1,
      "title": "...",
      "location": "...",
      "summary": "...",
      "activities": [{"title":"...","time":"Morning|Afternoon|Evening","notes":"..."}],
      "hotel": {"name":"...","category":"..."},
      "meals": ["Breakfast","Dinner"],
      "transport": "...",
      "notes": ""
    }
  ],
  "inclusions": ["..."],
  "exclusions": ["..."]
}
Exactly '.$daysCount.' days. day numbers 1..'.$daysCount.'.';

        $completion = $provider->complete($system, $user, 2200);
        $decoded = $this->decodeJson((string) ($completion->text ?? ''));
        if (! is_array($decoded) || empty($decoded['days']) || ! is_array($decoded['days'])) {
            return null;
        }

        return [
            'title' => (string) ($decoded['title'] ?? ($input['destination'].' itinerary')),
            'overview' => (string) ($decoded['overview'] ?? ''),
            'days' => $this->normalizeDays($decoded['days']),
            'inclusions' => $this->linesToList($decoded['inclusions'] ?? null) ?? [],
            'exclusions' => $this->linesToList($decoded['exclusions'] ?? null) ?? [],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function regenerateDayWithAi(Itinerary $itinerary, int $dayNumber, ?string $instruction): ?array
    {
        $provider = $this->ai->resolve();
        if (! $provider) {
            return null;
        }

        $system = 'Return ONLY valid JSON for one itinerary day. No markdown.';
        $user = 'Regenerate day '.$dayNumber.' for destination '.$itinerary->destination.'.
Hotel category: '.$itinerary->hotel_category.'
Transport: '.$itinerary->transport.'
Meals: '.$itinerary->meal_preference.'
Instruction: '.($instruction ?: 'Improve this day with better flow.').'

JSON:
{
  "day": '.$dayNumber.',
  "title": "...",
  "location": "...",
  "summary": "...",
  "activities": [{"title":"...","time":"Morning","notes":"..."}],
  "hotel": {"name":"...","category":"..."},
  "meals": ["Breakfast","Dinner"],
  "transport": "...",
  "notes": ""
}';

        $completion = $provider->complete($system, $user, 900);
        $decoded = $this->decodeJson((string) ($completion->text ?? ''));
        if (! is_array($decoded) || empty($decoded['title'])) {
            return null;
        }

        $normalized = $this->normalizeDays([$decoded]);

        return $normalized[0] ?? null;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{title: string, overview: string, days: list<array<string, mixed>>, inclusions: list<string>, exclusions: list<string>}
     */
    private function templateItinerary(Workspace $workspace, array $input, int $daysCount): array
    {
        $destination = (string) ($input['destination'] ?? 'Destination');
        $hotel = (string) ($input['hotel_category'] ?? '3-star');
        $transport = (string) ($input['transport'] ?? 'Private cab');
        $meals = (string) ($input['meal_preference'] ?? 'MAP');
        $days = [];
        for ($i = 1; $i <= $daysCount; $i++) {
            $days[] = $this->templateDay($i, $destination, $hotel, $transport, $meals);
        }

        return [
            'title' => $destination.' '.$daysCount.' Days / '.max(0, $daysCount - 1).' Nights',
            'overview' => "A curated {$daysCount}-day plan for {$destination}, prepared by {$workspace->name}. Edit days, hotels and pricing as needed.",
            'days' => $days,
            'inclusions' => [
                'Accommodation as per itinerary',
                'Daily breakfast (as per meal plan)',
                'Transfers mentioned in the plan',
            ],
            'exclusions' => [
                'Airfare / train tickets',
                'Personal expenses',
                'Anything not listed in inclusions',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function templateDay(int $day, string $destination, string $hotel, string $transport, string $meals): array
    {
        $labels = [
            1 => ['Arrival & orientation', 'Check-in and local orientation'],
            2 => ['Highlights day', 'Main sightseeing and experiences'],
            3 => ['Culture & leisure', 'Local culture with free time'],
        ];
        $pick = $labels[min($day, 3)];

        return [
            'day' => $day,
            'title' => $pick[0],
            'location' => $destination,
            'summary' => $pick[1].' in '.$destination.'.',
            'activities' => [
                ['title' => $day === 1 ? 'Arrival transfer' : 'Morning sightseeing', 'time' => 'Morning', 'notes' => ''],
                ['title' => $day === 1 ? 'Hotel check-in' : 'Afternoon activity', 'time' => 'Afternoon', 'notes' => ''],
                ['title' => 'Evening at leisure', 'time' => 'Evening', 'notes' => ''],
            ],
            'hotel' => ['name' => $hotel.' stay in '.$destination, 'category' => $hotel],
            'meals' => $meals === 'CP' ? ['Breakfast'] : ($meals === 'AP' ? ['Breakfast', 'Lunch', 'Dinner'] : ['Breakfast', 'Dinner']),
            'transport' => $transport,
            'notes' => '',
        ];
    }

    /**
     * @param  mixed  $days
     * @return list<array<string, mixed>>
     */
    public function normalizeDays(mixed $days): array
    {
        if (! is_array($days)) {
            return [];
        }

        $out = [];
        foreach (array_values($days) as $i => $day) {
            if (! is_array($day)) {
                continue;
            }
            $activities = [];
            foreach ($day['activities'] ?? [] as $activity) {
                if (! is_array($activity)) {
                    continue;
                }
                $title = trim((string) ($activity['title'] ?? ''));
                if ($title === '') {
                    continue;
                }
                $activities[] = [
                    'title' => $title,
                    'time' => trim((string) ($activity['time'] ?? '')) ?: null,
                    'notes' => trim((string) ($activity['notes'] ?? '')) ?: null,
                ];
            }

            $hotel = is_array($day['hotel'] ?? null) ? $day['hotel'] : [];
            $meals = $day['meals'] ?? [];
            if (is_string($meals)) {
                $meals = preg_split('/\r\n|\r|\n|,/', $meals) ?: [];
            }

            $out[] = [
                'day' => (int) ($day['day'] ?? ($i + 1)),
                'title' => trim((string) ($day['title'] ?? ('Day '.($i + 1)))),
                'location' => trim((string) ($day['location'] ?? '')) ?: null,
                'summary' => trim((string) ($day['summary'] ?? '')) ?: null,
                'activities' => $activities,
                'hotel' => [
                    'name' => trim((string) ($hotel['name'] ?? '')) ?: null,
                    'category' => trim((string) ($hotel['category'] ?? '')) ?: null,
                ],
                'meals' => array_values(array_filter(array_map(fn ($m) => trim((string) $m), (array) $meals))),
                'transport' => trim((string) ($day['transport'] ?? '')) ?: null,
                'notes' => trim((string) ($day['notes'] ?? '')) ?: null,
            ];
        }

        usort($out, fn ($a, $b) => $a['day'] <=> $b['day']);

        return $out;
    }

    /**
     * @param  array<string, mixed>  $pricing
     * @return array<string, float>
     */
    public function normalizePricing(array $pricing): array
    {
        $keys = ['hotel_cost', 'transport_cost', 'activity_cost', 'meal_cost', 'guide_cost', 'tax', 'discount', 'markup'];
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = round(max(0, (float) ($pricing[$key] ?? 0)), 2);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, float>
     */
    private function defaultPricing(array $input, int $daysCount): array
    {
        $band = (string) ($input['budget_band'] ?? 'standard');
        $perNight = match ($band) {
            'economy' => 2500,
            'premium' => 7000,
            'luxury' => 14000,
            default => 4500,
        };
        $nights = max(1, $daysCount - 1);
        $travellers = max(1, (int) ($input['adults'] ?? 2) + (int) ($input['children'] ?? 0));

        return $this->normalizePricing([
            'hotel_cost' => $perNight * $nights,
            'transport_cost' => 3000 * $daysCount,
            'activity_cost' => 1500 * $daysCount,
            'meal_cost' => 800 * $travellers * $daysCount,
            'guide_cost' => 1000 * $daysCount,
            'tax' => 0,
            'discount' => 0,
            'markup' => round(($perNight * $nights) * 0.15, 2),
        ]);
    }

    /**
     * @return list<string>|null
     */
    private function linesToList(mixed $raw): ?array
    {
        if (is_array($raw)) {
            $items = array_values(array_filter(array_map(fn ($v) => trim((string) $v), $raw)));

            return $items !== [] ? $items : null;
        }

        $text = trim((string) ($raw ?? ''));
        if ($text === '') {
            return null;
        }

        $items = preg_split('/\r\n|\r|\n|,/', $text) ?: [];
        $items = array_values(array_filter(array_map(fn ($v) => trim((string) $v), $items)));

        return $items !== [] ? $items : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJson(string $text): ?array
    {
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
        $text = preg_replace('/\s*```$/', '', $text) ?? $text;
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{.*\}/s', $text, $m)) {
            $decoded = json_decode($m[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function logUsage(Workspace $workspace, ?int $userId, float $cost, string $provider, array $meta): void
    {
        AiUsageLog::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $userId,
            'action' => 'itinerary_generation',
            'provider' => $provider,
            'tokens' => 0,
            'cost_usd' => $cost,
            'meta' => $meta,
        ]);

        $this->credits->spend($workspace, $cost);
    }
}
