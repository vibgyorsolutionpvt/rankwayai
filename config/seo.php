<?php

return [

    /*
    |--------------------------------------------------------------------------
    | SEO data providers
    |--------------------------------------------------------------------------
    |
    | Buy data, build UX. Prefer DataForSEO for metrics + SERP ranks.
    | "auto" / "dataforseo" = live SERP only when credentials exist.
    | Without DataForSEO we refuse to update ranks (no fake Google positions).
    | "stub" is local/tests only — never enable for customer accounts.
    |
    */

    'providers' => [
        'metrics' => env('SEO_METRICS_PROVIDER', 'dataforseo'),
        'ranks' => env('SEO_RANK_PROVIDER', 'auto'), // auto|dataforseo|stub
    ],

    'default_location' => env('SEO_DEFAULT_LOCATION', 'India'),
    'default_language' => env('SEO_DEFAULT_LANGUAGE', 'en'),
    'metrics_cache_days' => (int) env('SEO_METRICS_CACHE_DAYS', 7),
    'metrics_batch_size' => (int) env('SEO_METRICS_BATCH_SIZE', 50),

    /*
    |--------------------------------------------------------------------------
    | Public marketing site SEO (rankwayAI landing)
    |--------------------------------------------------------------------------
    */

    'marketing' => [
        // Public site URL for canonical / sitemap / OG (keep APP_URL local for OAuth).
        'public_url' => env('SEO_PUBLIC_URL', 'https://rankwayai.com'),
        'title' => env('SEO_MARKETING_TITLE', 'RankwayAI — SEO & Digital Marketing Platform'),
        'description' => env(
            'SEO_MARKETING_DESCRIPTION',
            'RankwayAI (rankwayai.com) is a marketing OS for SEO audits, Google Search Console, PageSpeed fixes, social scheduling, WhatsApp, and CRM — built for businesses and agencies.',
        ),
        'keywords' => env(
            'SEO_MARKETING_KEYWORDS',
            'RankwayAI, rankwayai.com, SEO software India, Google Search Console tool, digital marketing platform, social media scheduler, marketing automation, CRM WhatsApp',
        ),
        'og_image' => env('SEO_MARKETING_OG_IMAGE', '/img/rankwayai-logo.png'),
        'contact_email' => env('SEO_MARKETING_CONTACT_EMAIL', 'contact@rankwayai.com'),
        'contact_phone' => env('SEO_MARKETING_CONTACT_PHONE', '+91 9889995999'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Google API cooldowns (protect free quota)
    |--------------------------------------------------------------------------
    |
    | Per-site minimum wait between paid/quota Google calls so users cannot
    | spam Sync / Speed check and burn free daily limits.
    |
    */

    'google' => [
        'gsc_sync_cooldown_minutes' => (int) env('SEO_GSC_SYNC_COOLDOWN_MINUTES', 60),
        // Per-site cooldown (spam guard) + Google Cloud Console quotas for PageSpeed.
        'pagespeed_cooldown_minutes' => (int) env('SEO_PAGESPEED_COOLDOWN_MINUTES', 30),
        // Allow this many Speed checks per site inside the cooldown window, then lock for another window.
        'pagespeed_max_runs_per_window' => (int) env('SEO_PAGESPEED_MAX_RUNS_PER_WINDOW', 2),
        'pagespeed_queries_per_day' => (int) env('SEO_PAGESPEED_QUERIES_PER_DAY', 25000),
        'pagespeed_queries_per_minute' => (int) env('SEO_PAGESPEED_QUERIES_PER_MINUTE', 240),
        // Stay under Google's hard ceiling so we fail gracefully in-app first.
        'pagespeed_quota_safety_percent' => (int) env('SEO_PAGESPEED_QUOTA_SAFETY_PERCENT', 90),
    ],

    /*
    |--------------------------------------------------------------------------
    | Blog discovery → share for backlinks
    |--------------------------------------------------------------------------
    |
    | Opens the platform's compose/submit page with the blog URL prefilled.
    | User posts from their own account (no spam auto-submit to directories).
    |
    */

    'blog_share_channels' => [
        [
            'id' => 'whatsapp',
            'label' => 'WhatsApp',
            'blurb' => 'Chat share',
            'template' => 'https://wa.me/?text={text}',
        ],
        [
            'id' => 'facebook',
            'label' => 'Facebook',
            'blurb' => 'Share link',
            'template' => 'https://www.facebook.com/sharer/sharer.php?u={url}',
        ],
        [
            'id' => 'x',
            'label' => 'X',
            'blurb' => 'Post with link',
            'template' => 'https://twitter.com/intent/tweet?url={url}&text={title}',
        ],
        [
            'id' => 'linkedin',
            'label' => 'LinkedIn',
            'blurb' => 'Share update',
            'template' => 'https://www.linkedin.com/sharing/share-offsite/?url={url}',
        ],
        [
            'id' => 'threads',
            'label' => 'Threads',
            'blurb' => 'New thread',
            'template' => 'https://www.threads.net/intent/post?text={text}',
        ],
        [
            'id' => 'telegram',
            'label' => 'Telegram',
            'blurb' => 'Send message',
            'template' => 'https://t.me/share/url?url={url}&text={title}',
        ],
        [
            'id' => 'reddit',
            'label' => 'Reddit',
            'blurb' => 'Submit post',
            'template' => 'https://www.reddit.com/submit?title={title}&text={text}',
        ],
    ],

];
