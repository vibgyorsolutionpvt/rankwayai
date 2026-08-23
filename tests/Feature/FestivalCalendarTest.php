<?php

namespace Tests\Feature;

use App\Models\FestivalEvent;
use App\Services\Festivals\FestivalCalendarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FestivalCalendarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('festivals:last_sync');
    }

    public function test_sync_loads_configured_year_dates(): void
    {
        config(['festivals.nager_enabled' => false, 'festivals.ics_enabled' => false]);

        $calendar = app(FestivalCalendarService::class);
        $calendar->sync([2026]);

        $ganesh = FestivalEvent::query()
            ->where('name', 'Ganesh Chaturthi')
            ->whereDate('occurs_on', '2026-09-14')
            ->first();

        $this->assertNotNull($ganesh);
        $this->assertSame('config', $ganesh->source);
    }

    public function test_sync_pulls_public_holidays_from_api(): void
    {
        config(['festivals.nager_enabled' => true, 'festivals.ics_enabled' => false, 'festivals.years' => []]);

        Http::fake([
            'date.nager.at/api/v3/PublicHolidays/2026/IN' => Http::response([
                [
                    'date' => '2026-10-02',
                    'localName' => 'Mahatma Gandhi Jayanti',
                    'name' => 'Gandhi Jayanti',
                ],
            ]),
        ]);

        app(FestivalCalendarService::class)->sync([2026]);

        $this->assertTrue(
            FestivalEvent::query()
                ->where('name', 'Mahatma Gandhi Jayanti')
                ->whereDate('occurs_on', '2026-10-02')
                ->where('source', 'api')
                ->exists()
        );
    }

    public function test_sync_pulls_events_from_ics_feed(): void
    {
        config([
            'festivals.nager_enabled' => false,
            'festivals.ics_enabled' => true,
            'festivals.years' => [],
            'festivals.ics_urls' => ['https://calendar.test/holidays.ics'],
        ]);

        $ics = <<<'ICS'
BEGIN:VCALENDAR
BEGIN:VEVENT
SUMMARY:Diwali
DTSTART;VALUE=DATE:20261108
END:VEVENT
END:VCALENDAR
ICS;

        Http::fake(['calendar.test/*' => Http::response($ics)]);

        app(FestivalCalendarService::class)->sync([2026]);

        $this->assertTrue(
            FestivalEvent::query()
                ->where('name', 'Diwali')
                ->whereDate('occurs_on', '2026-11-08')
                ->where('source', 'ics')
                ->exists()
        );
    }

    public function test_next_for_posts_only_within_window(): void
    {
        config(['festivals.nager_enabled' => false, 'festivals.ics_enabled' => false]);

        $calendar = app(FestivalCalendarService::class);

        FestivalEvent::query()->create([
            'name' => 'Far Festival',
            'occurs_on' => now()->addDays(30)->toDateString(),
            'region' => 'IN',
            'category' => 'festival',
            'source' => 'config',
            'suggested_angles' => ['Later'],
        ]);

        FestivalEvent::query()->create([
            'name' => 'Near Festival',
            'occurs_on' => now()->addDays(5)->toDateString(),
            'region' => 'IN',
            'category' => 'festival',
            'source' => 'config',
            'suggested_angles' => ['Soon'],
        ]);

        Cache::put('festivals:last_sync', now()->toIso8601String(), now()->addDay());

        $next = $calendar->nextForPosts();

        $this->assertNotNull($next);
        $this->assertSame('Near Festival', $next->name);
    }

    public function test_festivals_sync_command(): void
    {
        config(['festivals.nager_enabled' => false, 'festivals.ics_enabled' => false]);

        $this->artisan('festivals:sync', ['--year' => [2026]])
            ->assertSuccessful();

        $this->assertGreaterThan(0, FestivalEvent::query()->whereYear('occurs_on', 2026)->count());
    }
}
