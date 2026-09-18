<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Laravel scheduler (.env)
    |--------------------------------------------------------------------------
    |
    | ACTIVE server cron (keep this):
    |   * * * * * php artisan schedule:run
    |
    | Tasks below stay in code — set env to "disabled" (inactive) or a preset
    | to turn back on later. Do not delete keys.
    |
    | Queue drain cron stays optional/inactive (see deploy docs) — publish-now
    | drains after HTTP response so it is not required.
    |
    | Presets: every_minute, every_five_minutes, every_fifteen_minutes,
    |          every_thirty_minutes, hourly, daily
    | Or a 5-field cron expression, e.g. "0/10 * * * *"
    | Set to "disabled" to leave registered but inactive.
    |
    */

    // ACTIVE by default
    'social_publish_due' => env('SCHEDULE_SOCIAL_PUBLISH_DUE', 'every_minute'),

    // INACTIVE by default — enable anytime via .env (do not remove)
    'social_sync_metrics' => env('SCHEDULE_SOCIAL_SYNC_METRICS', 'disabled'),

    'channels_send_due' => env('SCHEDULE_CHANNELS_SEND_DUE', 'disabled'),

    'seo_run_due' => env('SCHEDULE_SEO_RUN_DUE', 'disabled'),

    'rankway_recompute_ranks' => env('SCHEDULE_RANKWAY_RECOMPUTE_RANKS', 'disabled'),

    'festivals_sync' => env('SCHEDULE_FESTIVALS_SYNC', 'disabled'),

];
