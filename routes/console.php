<?php

use App\Support\ConfiguredSchedule;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

$scheduler = config('scheduler', []);

$register = function (string $command, ?string $when, string $default): void {
    $expression = $when ?? $default;
    if (ConfiguredSchedule::isDisabled($expression)) {
        return;
    }

    ConfiguredSchedule::apply(Schedule::command($command), $expression);
};

$register('social:publish-due', $scheduler['social_publish_due'] ?? null, 'every_minute');
$register('social:sync-metrics', $scheduler['social_sync_metrics'] ?? null, 'hourly');
$register('channels:send-due', $scheduler['channels_send_due'] ?? null, 'every_minute');
$register('seo:run-due', $scheduler['seo_run_due'] ?? null, 'hourly');
$register('rankway:recompute-ranks', $scheduler['rankway_recompute_ranks'] ?? null, '30 3 * * *');
$register('festivals:sync', $scheduler['festivals_sync'] ?? null, '0 2 1 * *');
