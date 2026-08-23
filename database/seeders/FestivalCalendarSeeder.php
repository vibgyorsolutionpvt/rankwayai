<?php

namespace Database\Seeders;

use App\Services\Festivals\FestivalCalendarService;
use Illuminate\Database\Seeder;

class FestivalCalendarSeeder extends Seeder
{
    public function run(): void
    {
        $calendar = app(FestivalCalendarService::class);
        $years = $calendar->configuredYears();

        if ($years === []) {
            return;
        }

        $calendar->sync($calendar->configuredYears());
    }
}
