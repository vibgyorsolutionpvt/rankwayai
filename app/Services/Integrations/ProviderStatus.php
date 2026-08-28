<?php

namespace App\Services\Integrations;

class ProviderStatus
{
    public static function snapshot(): array
    {
        return [
            'meta' => filled(config('services.meta.app_id')) && filled(config('services.meta.app_secret')),
            'linkedin' => filled(config('services.linkedin.client_id')) && filled(config('services.linkedin.client_secret')),
            'x' => filled(config('services.x.client_id')) && filled(config('services.x.client_secret')),
            'google' => filled(config('services.google.client_id')) && filled(config('services.google.client_secret')),
            'pagespeed' => filled(config('services.google.pagespeed_key')),
            'dataforseo' => filled(config('services.dataforseo.login')) && filled(config('services.dataforseo.password')),
            'browserless' => filled(config('services.browserless.token')) || filled(config('services.browserless.url')),
            'stripe' => filled(config('services.stripe.secret')),
            'razorpay' => filled(config('services.razorpay.key_id')) && filled(config('services.razorpay.key_secret')),
            'zavu' => filled(config('services.zavu.key')),
            'openai' => filled(config('ai.providers.openai.key')),
            'groq' => filled(config('ai.providers.groq.key')),
            'gemini' => filled(config('ai.providers.gemini.key')),
            'cerebras' => filled(config('ai.providers.cerebras.key')),
            'mistral' => filled(config('ai.providers.mistral.key')),
            'openrouter' => filled(config('ai.providers.openrouter.key')),
        ];
    }
}
