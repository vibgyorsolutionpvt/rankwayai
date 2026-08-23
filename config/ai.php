<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default AI provider
    |--------------------------------------------------------------------------
    |
    | auto = first configured in priority (free tiers first, paid last)
    | Or force: groq, cerebras, gemini, mistral, openrouter, openai, template
    |
    */
    'default' => env('AI_PROVIDER', 'auto'),

    /*
    | Free / cheap first → paid last. Failover walks this list.
    */
    'priority' => [
        'groq',
        'cerebras',
        'gemini',
        'mistral',
        'openrouter',
        'openai',
    ],

    'providers' => [

        'groq' => [
            'label' => 'Groq (free tier)',
            'tier' => 'free',
            'key' => env('GROQ_API_KEY'),
            'model' => env('GROQ_MODEL', 'llama-3.3-70b-versatile'),
            'base_url' => env('GROQ_BASE_URL', 'https://api.groq.com/openai/v1'),
        ],

        'cerebras' => [
            'label' => 'Cerebras (free tier)',
            'tier' => 'free',
            'key' => env('CEREBRAS_API_KEY'),
            'model' => env('CEREBRAS_MODEL', 'llama-3.3-70b'),
            'base_url' => env('CEREBRAS_BASE_URL', 'https://api.cerebras.ai/v1'),
        ],

        'gemini' => [
            'label' => 'Gemini (free tier)',
            'tier' => 'free',
            'key' => env('GEMINI_API_KEY'),
            'model' => env('GEMINI_MODEL', 'gemini-2.0-flash'),
            'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        ],

        'mistral' => [
            'label' => 'Mistral (free tier)',
            'tier' => 'free',
            'key' => env('MISTRAL_API_KEY'),
            'model' => env('MISTRAL_MODEL', 'mistral-small-latest'),
            'base_url' => env('MISTRAL_BASE_URL', 'https://api.mistral.ai/v1'),
        ],

        'openrouter' => [
            'label' => 'OpenRouter (free models)',
            'tier' => 'free',
            'key' => env('OPENROUTER_API_KEY'),
            // Pick a :free model — https://openrouter.ai/models?q=free
            'model' => env('OPENROUTER_MODEL', 'meta-llama/llama-3.3-70b-instruct:free'),
            'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
            'http_referer' => env('APP_URL', 'http://localhost'),
            'app_name' => env('APP_NAME', 'rankwayAI'),
        ],

        'openai' => [
            'label' => 'OpenAI (paid)',
            'tier' => 'paid',
            'key' => env('OPENAI_API_KEY'),
            'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        ],
    ],

    /*
    | Estimated USD charged against workspace AI budget per action.
    | Free-tier drivers stay low so paid plans stay profitable.
    */
    'costs' => [
        'template' => 0.002,
        'groq' => 0.004,
        'cerebras' => 0.004,
        'gemini' => 0.004,
        'mistral' => 0.004,
        'openrouter' => 0.004,
        'openai' => 0.02,
    ],

];
