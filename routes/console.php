<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('social:publish-due')->everyMinute();
Schedule::command('seo:run-due')->hourly();
Schedule::command('rankway:recompute-ranks')->dailyAt('03:30');
Schedule::command('channels:send-due')->everyMinute();
Schedule::command('festivals:sync')->monthlyOn(1, '02:00');
