<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Public media base URL (social publish)
    |--------------------------------------------------------------------------
    |
    | Meta/Instagram fetch image URLs from their servers. On localhost the
    | default APP_URL is not reachable. Set this to your public https tunnel
    | (ngrok, Cloudflare Tunnel, production domain) so /storage/... paths work.
    |
    */
    'public_media_base_url' => env('SOCIAL_PUBLIC_URL'),

    /*
    |--------------------------------------------------------------------------
    | Simulate publish on local (dev only)
    |--------------------------------------------------------------------------
    |
    | When true on APP_ENV=local, Publish now marks the post published without
    | calling Meta — useful to test draft → approve → publish flow locally.
    | Production always uses live publish when https images are available.
    |
    */
    'simulate_publish' => env('SOCIAL_SIMULATE_PUBLISH', false),

    /*
    |--------------------------------------------------------------------------
    | Engagement metrics after publish
    |--------------------------------------------------------------------------
    |
    | After a live publish, a queued job fetches likes/comments/views from Meta.
    | Delay gives Meta time to process the post before insights are readable.
    |
    */
    'metrics_sync_delay_minutes' => (int) env('SOCIAL_METRICS_SYNC_DELAY_MINUTES', 3),

];
