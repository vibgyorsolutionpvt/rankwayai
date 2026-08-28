<?php

namespace App\Support;

final class NavModules
{
    /**
     * Client sidebar modules (key => meta).
     *
     * @return array<string, array{label:string, route:string, match:string, icon:string, tone:string, params?:array<string, string>}>
     */
    public static function catalog(): array
    {
        return [
            'today' => [
                'label' => 'Today',
                'route' => 'today',
                'match' => 'today',
                'icon' => 'today',
                'tone' => 'amber',
            ],
            'brand' => [
                'label' => 'Brand',
                'route' => 'brand.edit',
                'match' => 'brand.*',
                'icon' => 'brand',
                'tone' => 'rose',
            ],
            'media' => [
                'label' => 'Media',
                'route' => 'media.index',
                'match' => 'media.*',
                'icon' => 'media',
                'tone' => 'sky',
            ],
            'social' => [
                'label' => 'SMM',
                'route' => 'social.index',
                'params' => ['view' => 'posts'],
                'match' => 'social.*',
                'icon' => 'social',
                'tone' => 'fuchsia',
            ],
            'seo' => [
                'label' => 'SEO',
                'route' => 'seo.index',
                'match' => 'seo.*',
                'icon' => 'seo',
                'tone' => 'emerald',
            ],
            'blog' => [
                'label' => 'Blog',
                'route' => 'blog.index',
                'match' => 'blog.*',
                'icon' => 'blog',
                'tone' => 'sky',
            ],
            'channels' => [
                'label' => 'Channels',
                'route' => 'channels.index',
                'match' => 'channels.*',
                'icon' => 'social',
                'tone' => 'sky',
            ],
            'whatsapp' => [
                'label' => 'WhatsApp',
                'route' => 'whatsapp.index',
                'match' => 'whatsapp.*',
                'icon' => 'social',
                'tone' => 'emerald',
            ],
            'crm' => [
                'label' => 'CRM',
                'route' => 'crm.index',
                'match' => 'crm.*',
                'icon' => 'workspace',
                'tone' => 'amber',
            ],
            'funnels' => [
                'label' => 'Funnels',
                'route' => 'funnels.index',
                'match' => 'funnels.*',
                'icon' => 'media',
                'tone' => 'fuchsia',
            ],
            'billing' => [
                'label' => 'Billing',
                'route' => 'billing.index',
                'match' => 'billing.*',
                'icon' => 'platform',
                'tone' => 'emerald',
            ],
            'settings' => [
                'label' => 'Settings',
                'route' => 'settings.index',
                'match' => 'settings.*',
                'icon' => 'platform',
                'tone' => 'signal',
            ],
        ];
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::catalog());
    }

    public static function fromRouteName(?string $routeName): ?string
    {
        if (! $routeName) {
            return null;
        }

        if (str_starts_with($routeName, 'integrations.')) {
            return 'settings';
        }

        foreach (self::catalog() as $key => $meta) {
            $match = $meta['match'];
            if (str_ends_with($match, '.*')) {
                $prefix = substr($match, 0, -2);
                if ($routeName === $prefix || str_starts_with($routeName, $prefix.'.')) {
                    return $key;
                }
            } elseif ($routeName === $match) {
                return $key;
            }
        }

        return null;
    }
}