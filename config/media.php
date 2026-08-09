<?php

return [
    /*
    | Media library disk. Use "public" locally or "s3" for S3-compatible storage.
    | CDN/public URL comes from the disk's url config.
    */
    'disk' => env('MEDIA_DISK', env('FILESYSTEM_DISK', 'public')),
];
