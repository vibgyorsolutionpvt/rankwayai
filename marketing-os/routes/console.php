<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('social:publish-due')->everyMinute();
Schedule::command('seo:run-due')->hourly();
Schedule::command('channels:send-due')->everyMinute();
