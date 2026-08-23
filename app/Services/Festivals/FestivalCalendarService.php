<?php

namespace App\Services\Festivals;

use App\Models\FestivalEvent;
use App\Services\Festivals\Providers\IcsCalendarProvider;
use App\Services\Festivals\Providers\NagerPublicHolidayProvider;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class FestivalCalendarService
{
    public function postWindowDays(): int
    {
        return max(1, (int) config('festivals.post_window_days', 14));
    }

    public function listWindowDays(): int
    {
        return max(7, (int) config('festivals.list_window_days', 45));
    }

    /**
     * @return list<int>
     */
    public function configuredYears(): array
    {
        return array_map('intval', array_keys(config('festivals.years', [])));
    }

    /**
     * @param  list<int>|null  $years
     */
    public function sync(?array $years = null): int
    {
        $years ??= [now()->year, now()->year + 1];
        $regions = config('festivals.regions', ['IN']);
        $count = 0;

        foreach ($years as $year) {
            foreach ($this->collectEventsForYear((int) $year) as $event) {
                foreach ($regions as $region) {
                    if ($this->upsertEvent($event, $region, (int) $year)) {
                        $count++;
                    }
                }
            }
        }

        Cache::put('festivals:last_sync', now()->toIso8601String(), now()->addDay());

        return $count;
    }

    /** Refresh from calendar/API at most once per day. */
    public function ensureSynced(): void
    {
        if (Cache::has('festivals:last_sync')) {
            return;
        }

        $this->sync([now()->year, now()->year + 1]);
    }

    public function nextForPosts(?string $region = 'IN'): ?FestivalEvent
    {
        $this->ensureSynced();

        $start = now()->startOfDay()->toDateString();
        $end = now()->addDays($this->postWindowDays())->toDateString();

        return FestivalEvent::query()
            ->when($region, fn ($q) => $q->where('region', $region))
            ->whereBetween('occurs_on', [$start, $end])
            ->orderBy('occurs_on')
            ->first();
    }

    /**
     * @return Collection<int, FestivalEvent>
     */
    public function upcoming(?string $region = 'IN', ?int $daysAhead = null): Collection
    {
        $this->ensureSynced();

        $daysAhead ??= $this->listWindowDays();
        $start = now()->startOfDay()->toDateString();
        $end = now()->addDays($daysAhead)->toDateString();

        return FestivalEvent::query()
            ->when($region, fn ($q) => $q->where('region', $region))
            ->whereBetween('occurs_on', [$start, $end])
            ->orderBy('occurs_on')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    public function toListItem(FestivalEvent $festival, ?FestivalEvent $nextForPosts = null): array
    {
        $occurs = $festival->occurs_on?->timezone(config('app.timezone'))->startOfDay();
        $today = now()->timezone(config('app.timezone'))->startOfDay();
        $daysUntil = $occurs ? (int) $today->diffInDays($occurs, false) : null;

        return [
            'id' => $festival->id,
            'name' => $festival->name,
            'date_label' => $occurs?->format('d M Y'),
            'category' => $festival->category,
            'source' => $festival->source ?? 'config',
            'source_label' => $this->sourceLabel($festival->source ?? 'config'),
            'days_until' => $daysUntil,
            'days_label' => $this->daysLabel($daysUntil),
            'in_post_window' => $nextForPosts?->id === $festival->id,
            'suggested_angle' => $festival->suggested_angles[0] ?? null,
        ];
    }

    /**
     * @return list<FestivalEventData>
     */
    private function collectEventsForYear(int $year): array
    {
        $events = [];

        foreach ($this->eventsForYear($year) as $row) {
            $events[] = new FestivalEventData(
                name: $row['name'],
                occurs_on: $row['occurs_on'],
                category: $row['category'],
                suggested_angles: $row['suggested_angles'],
                source: 'config',
            );
        }

        if (config('festivals.nager_enabled', true)) {
            $events = array_merge(
                $events,
                app(NagerPublicHolidayProvider::class)->forYear($year, (string) config('festivals.country_code', 'IN'))
            );
        }

        if (config('festivals.ics_enabled', true)) {
            foreach ((array) config('festivals.ics_urls', []) as $url) {
                if (! is_string($url) || trim($url) === '') {
                    continue;
                }
                $events = array_merge($events, app(IcsCalendarProvider::class)->forYear($url, $year));
            }
        }

        return $this->dedupeEvents($events);
    }

    /**
     * @param  list<FestivalEventData>  $events
     * @return list<FestivalEventData>
     */
    private function dedupeEvents(array $events): array
    {
        $priority = ['config' => 3, 'ics' => 2, 'api' => 1];
        $map = [];

        foreach ($events as $event) {
            $key = mb_strtolower($event->name).'|'.$event->occurs_on;
            $existing = $map[$key] ?? null;
            if (
                ! $existing
                || ($priority[$event->source] ?? 0) > ($priority[$existing->source] ?? 0)
            ) {
                $map[$key] = $event;
            }
        }

        return array_values($map);
    }

    private function upsertEvent(FestivalEventData $event, string $region, int $year): bool
    {
        $existing = FestivalEvent::query()
            ->where('region', $region)
            ->whereYear('occurs_on', $year)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($event->name)])
            ->first();

        $priority = ['config' => 3, 'ics' => 2, 'api' => 1];
        if (
            $existing
            && ($priority[$existing->source ?? 'config'] ?? 0) > ($priority[$event->source] ?? 0)
        ) {
            return false;
        }

        FestivalEvent::query()
            ->where('region', $region)
            ->whereYear('occurs_on', $year)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($event->name)])
            ->whereDate('occurs_on', '!=', $event->occurs_on)
            ->delete();

        FestivalEvent::query()->updateOrCreate(
            [
                'name' => $event->name,
                'occurs_on' => $event->occurs_on,
                'region' => $region,
            ],
            array_filter([
                'category' => $event->category,
                'suggested_angles' => $event->suggested_angles,
                'source' => Schema::hasColumn('festival_events', 'source') ? $event->source : null,
            ], fn ($value) => $value !== null)
        );

        return true;
    }

    /**
     * @return list<array{name:string,occurs_on:string,category:string,suggested_angles:list<string>}>
     */
    private function eventsForYear(int $year): array
    {
        $events = config("festivals.years.{$year}", []);

        return is_array($events) ? $events : [];
    }

    private function daysLabel(?int $daysUntil): string
    {
        if ($daysUntil === null) {
            return '';
        }

        if ($daysUntil === 0) {
            return 'Today';
        }

        if ($daysUntil === 1) {
            return 'Tomorrow';
        }

        if ($daysUntil > 1) {
            return "{$daysUntil} days away";
        }

        return abs($daysUntil).' days ago';
    }

    private function sourceLabel(string $source): string
    {
        return match ($source) {
            'ics' => 'Calendar feed',
            'api' => 'Public holidays API',
            default => 'Marketing calendar',
        };
    }
}
