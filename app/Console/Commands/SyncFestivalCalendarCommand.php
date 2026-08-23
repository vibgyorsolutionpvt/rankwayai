<?php

namespace App\Console\Commands;

use App\Services\Festivals\FestivalCalendarService;
use Illuminate\Console\Command;

class SyncFestivalCalendarCommand extends Command
{
    protected $signature = 'festivals:sync {--year=* : Years to sync (default: current + next)}';

    protected $description = 'Sync marketing calendar from config + ICS feeds + public holiday API';

    public function handle(FestivalCalendarService $calendar): int
    {
        $years = array_map('intval', (array) $this->option('year'));
        if ($years === []) {
            $years = [now()->year, now()->year + 1];
        }

        $unknown = array_diff($years, $calendar->configuredYears());
        if ($unknown !== []) {
            $this->warn('No config for year(s): '.implode(', ', $unknown));
        }

        $count = $calendar->sync($years);
        $this->info("Synced {$count} festival row(s) for ".implode(', ', $years).'.');

        return self::SUCCESS;
    }
}
