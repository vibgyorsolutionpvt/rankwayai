<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Laravel scheduler (.env)
    |--------------------------------------------------------------------------
    |
    | Requires server cron: * * * * * php artisan schedule:run
    |
    | Presets: every_minute, every_five_minutes, every_fifteen_minutes,
    |          every_thirty_minutes, hourly, daily
    | Or a 5-field cron expression, e.g. "0/10 * * * *" (every 10 minutes)
    | Set to "disabled" to turn a task off.
    |
    */

    'social_publish_due' => env('SCHEDULE_SOCIAL_PUBLISH_DUE', 'every_minute'),

    'social_sync_metrics' => env('SCHEDULE_SOCIAL_SYNC_METRICS', 'hourly'),

    'channels_send_due' => env('SCHEDULE_CHANNELS_SEND_DUE', 'every_minute'),

    'seo_run_due' => env('SCHEDULE_SEO_RUN_DUE', 'hourly'),

    'rankway_recompute_ranks' => env('SCHEDULE_RANKWAY_RECOMPUTE_RANKS', '30 3 * * *'),

    'festivals_sync' => env('SCHEDULE_FESTIVALS_SYNC', '0 2 1 * *'),

];
