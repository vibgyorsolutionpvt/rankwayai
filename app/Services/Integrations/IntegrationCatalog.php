<?php

namespace App\Services\Integrations;

class IntegrationCatalog
{
    /**
     * @return list<array{
     *   id:string,
     *   category:string,
     *   label:string,
     *   blurb:string,
     *   fields:list<array{key:string,label:string,secret?:bool,required?:bool,placeholder?:string}>
     * }>
     */
    public static function definitions(): array
    {
        return [
            [
                'id' => 'meta',
                'category' => 'social',
                'label' => 'Meta (Facebook / Instagram / Threads)',
                'blurb' => 'OAuth app for Facebook Pages, Instagram Business, and Threads.',
                'fields' => [
                    ['key' => 'app_id', 'label' => 'App ID', 'required' => true],
                    ['key' => 'app_secret', 'label' => 'App secret', 'secret' => true, 'required' => true],
                    ['key' => 'threads_app_id', 'label' => 'Threads App ID', 'placeholder' => 'App Settings → Basic (optional if same as App ID)'],
                    ['key' => 'threads_app_secret', 'label' => 'Threads App secret', 'secret' => true, 'placeholder' => 'Optional if same as App secret'],
                ],
            ],
            [
                'id' => 'linkedin',
                'category' => 'social',
                'label' => 'LinkedIn',
                'blurb' => 'OAuth app for LinkedIn company pages.',
                'fields' => [
                    ['key' => 'client_id', 'label' => 'Client ID', 'required' => true],
                    ['key' => 'client_secret', 'label' => 'Client secret', 'secret' => true, 'required' => true],
                ],
            ],
            [
                'id' => 'x',
                'category' => 'social',
                'label' => 'X (Twitter)',
                'blurb' => 'OAuth app for X posting.',
                'fields' => [
                    ['key' => 'client_id', 'label' => 'Client ID', 'required' => true],
                    ['key' => 'client_secret', 'label' => 'Client secret', 'secret' => true, 'required' => true],
                ],
            ],
            [
                'id' => 'whatsapp_meta',
                'category' => 'messaging',
                'label' => 'WhatsApp Business (Meta Cloud API)',
                'blurb' => 'Direct Meta WhatsApp Cloud API — Phone Number ID, access token, and webhook verify token.',
                'fields' => [
                    ['key' => 'phone_number_id', 'label' => 'Phone number ID', 'required' => true, 'placeholder' => 'From Meta Developer Console'],
                    ['key' => 'waba_id', 'label' => 'WABA ID', 'placeholder' => 'WhatsApp Business Account ID'],
                    ['key' => 'access_token', 'label' => 'Access token', 'secret' => true, 'required' => true],
                    ['key' => 'app_secret', 'label' => 'App secret', 'secret' => true, 'placeholder' => 'For webhook signature check'],
                    ['key' => 'verify_token', 'label' => 'Webhook verify token', 'required' => true, 'placeholder' => 'Any secret string you choose'],
                    ['key' => 'api_version', 'label' => 'Graph API version', 'placeholder' => 'v21.0'],
                ],
            ],
            [
                'id' => 'zavu',
                'category' => 'messaging',
                'label' => 'WhatsApp fallback (Zavu)',
                'blurb' => 'Optional fallback if Meta Cloud API is not configured. Also used for email if SMTP is missing.',
                'fields' => [
                    ['key' => 'api_key', 'label' => 'API key', 'secret' => true, 'required' => true],
                    ['key' => 'base_url', 'label' => 'Base URL', 'placeholder' => 'https://api.zavu.dev'],
                    ['key' => 'webhook_secret', 'label' => 'Webhook secret', 'secret' => true, 'placeholder' => 'For inbound conversations'],
                ],
            ],
            [
                'id' => 'smtp',
                'category' => 'messaging',
                'label' => 'Email (Custom SMTP)',
                'blurb' => 'Your own SMTP server for email campaigns (Gmail, SES, Mailgun, company mail).',
                'fields' => [
                    ['key' => 'host', 'label' => 'SMTP host', 'required' => true, 'placeholder' => 'smtp.example.com'],
                    ['key' => 'port', 'label' => 'Port', 'required' => true, 'placeholder' => '587'],
                    [
                        'key' => 'encryption',
                        'label' => 'Encryption',
                        'type' => 'select',
                        'required' => true,
                        'options' => [
                            ['value' => 'tls', 'label' => 'TLS (STARTTLS)'],
                            ['value' => 'ssl', 'label' => 'SSL / SMTPS'],
                            ['value' => 'none', 'label' => 'None'],
                        ],
                    ],
                    ['key' => 'username', 'label' => 'Username', 'required' => true],
                    ['key' => 'password', 'label' => 'Password', 'secret' => true, 'required' => true],
                    ['key' => 'from_address', 'label' => 'From email', 'required' => true, 'placeholder' => 'hello@example.com'],
                    ['key' => 'from_name', 'label' => 'From name', 'placeholder' => 'Your brand'],
                ],
            ],
            [
                'id' => 'jio',
                'category' => 'rcs',
                'label' => 'Jio RCS',
                'blurb' => 'Jio Business Messaging / RCS agent credentials.',
                'fields' => [
                    ['key' => 'base_url', 'label' => 'API base URL', 'required' => true, 'placeholder' => 'https://api.jio.com/rcs'],
                    ['key' => 'client_id', 'label' => 'Client ID', 'required' => true],
                    ['key' => 'client_secret', 'label' => 'Client secret', 'secret' => true, 'required' => true],
                    ['key' => 'agent_id', 'label' => 'Agent / Bot ID'],
                ],
            ],
            [
                'id' => 'airtel',
                'category' => 'rcs',
                'label' => 'Airtel RCS',
                'blurb' => 'Airtel RCS Business Messaging credentials.',
                'fields' => [
                    ['key' => 'base_url', 'label' => 'API base URL', 'required' => true],
                    ['key' => 'client_id', 'label' => 'Client ID', 'required' => true],
                    ['key' => 'client_secret', 'label' => 'Client secret', 'secret' => true, 'required' => true],
                    ['key' => 'agent_id', 'label' => 'Agent / Bot ID'],
                ],
            ],
            [
                'id' => 'vi',
                'category' => 'rcs',
                'label' => 'Vi RCS',
                'blurb' => 'Vi (Vodafone Idea) RCS credentials.',
                'fields' => [
                    ['key' => 'base_url', 'label' => 'API base URL', 'required' => true],
                    ['key' => 'client_id', 'label' => 'Client ID', 'required' => true],
                    ['key' => 'client_secret', 'label' => 'Client secret', 'secret' => true, 'required' => true],
                    ['key' => 'agent_id', 'label' => 'Agent / Bot ID'],
                ],
            ],
            [
                'id' => 'google_gsc',
                'category' => 'seo',
                'label' => 'Google Search Console',
                'blurb' => 'OAuth app to connect sites to Search Console. Used by the Connect GSC button in SEO.',
                'fields' => [
                    ['key' => 'client_id', 'label' => 'OAuth Client ID', 'required' => true, 'placeholder' => '….apps.googleusercontent.com'],
                    ['key' => 'client_secret', 'label' => 'OAuth Client secret', 'secret' => true, 'required' => true],
                ],
            ],
            [
                'id' => 'google_pagespeed',
                'category' => 'seo',
                'label' => 'PageSpeed Insights',
                'blurb' => 'API key for Speed check / Core Web Vitals in SEO.',
                'fields' => [
                    [
                        'key' => 'api_key',
                        'label' => 'PageSpeed API key',
                        'secret' => true,
                        'required' => true,
                        'placeholder' => 'From Google Cloud Console',
                    ],
                ],
            ],
        ];
    }

    public static function find(string $provider): ?array
    {
        foreach (self::definitions() as $def) {
            if ($def['id'] === $provider) {
                return $def;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function ids(): array
    {
        return array_map(fn ($d) => $d['id'], self::definitions());
    }

    /**
     * @return array<string, string>
     */
    public static function categories(): array
    {
        return [
            'social' => 'Social media',
            'messaging' => 'WhatsApp & Email',
            'rcs' => 'RCS carriers',
            'seo' => 'SEO / Google',
        ];
    }
}
