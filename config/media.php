<?php

return [
    /*
    | Media library disk. Use "public" locally or "s3" for S3-compatible storage.
    | CDN/public URL comes from the disk's url config.
    */
    'disk' => env('MEDIA_DISK', env('FILESYSTEM_DISK', 'public')),

    /** Max upload size in kilobytes (2 MB). */
    'max_kb' => (int) env('MEDIA_MAX_KB', 2048),

    /** Allowed image extensions for now. */
    'allowed_mimes' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
];
