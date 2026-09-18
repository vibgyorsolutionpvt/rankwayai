<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default AI provider
    |--------------------------------------------------------------------------
    |
    | Prefer a paid primary (openai). Free-tier providers are optional backups only —
    | chaining every free API on each prompt burns quotas and is not production-viable.
    |
    | auto = first configured in `priority`
    | Or force: openai, openrouter, groq, gemini, mistral, cerebras, template
    |
    */
    'default' => env('AI_PROVIDER', 'openai'),

    /*
    | Paid first. Free APIs only as backups if the primary fails.
    */
    'priority' => [
        'openai',
        'openrouter',
        'groq',
        'gemini',
        'mistral',
        'cerebras',
    ],

    'failover' => [
        // Primary + 1 backup is enough. Do not spray every free key.
        'max_attempts' => (int) env('AI_FAILOVER_MAX_ATTEMPTS', 2),
        'cooldown_seconds' => (int) env('AI_FAILOVER_COOLDOWN', 900),
        'sticky_ttl_seconds' => (int) env('AI_STICKY_TTL', 3600),
    ],

    'providers' => [

        'openai' => [
            'label' => 'OpenAI (recommended)',
            'tier' => 'paid',
            'key' => env('OPENAI_API_KEY'),
            'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        ],

        'openrouter' => [
            'label' => 'OpenRouter (paid backup)',
            'tier' => 'paid',
            'key' => env('OPENROUTER_API_KEY'),
            'model' => env('OPENROUTER_MODEL', 'openai/gpt-4o-mini'),
            'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
            'http_referer' => env('APP_URL', 'http://localhost'),
            'app_name' => env('APP_NAME', 'rankwayAI'),
        ],

        'groq' => [
            'label' => 'Groq (optional free backup)',
            'tier' => 'free',
            'key' => env('GROQ_API_KEY'),
            'model' => env('GROQ_MODEL', 'openai/gpt-oss-20b'),
            'base_url' => env('GROQ_BASE_URL', 'https://api.groq.com/openai/v1'),
        ],

        'gemini' => [
            'label' => 'Gemini (optional free backup)',
            'tier' => 'free',
            'key' => env('GEMINI_API_KEY'),
            'model' => env('GEMINI_MODEL', 'gemini-flash-latest'),
            'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        ],

        'mistral' => [
            'label' => 'Mistral (optional free backup)',
            'tier' => 'free',
            'key' => env('MISTRAL_API_KEY'),
            'model' => env('MISTRAL_MODEL', 'open-mistral-nemo'),
            'base_url' => env('MISTRAL_BASE_URL', 'https://api.mistral.ai/v1'),
        ],

        'cerebras' => [
            'label' => 'Cerebras (optional free backup)',
            'tier' => 'free',
            'key' => env('CEREBRAS_API_KEY'),
            'model' => env('CEREBRAS_MODEL', 'llama3.1-8b'),
            'base_url' => env('CEREBRAS_BASE_URL', 'https://api.cerebras.ai/v1'),
        ],
    ],

    /*
    | Estimated USD charged against workspace AI budget per action.
    */
    'costs' => [
        'template' => 0.002,
        'openai' => 0.02,
        'openrouter' => 0.02,
        'groq' => 0.004,
        'cerebras' => 0.004,
        'gemini' => 0.004,
        'mistral' => 0.004,
    ],

];
