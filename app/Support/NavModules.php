<?php

namespace App\Support;

final class NavModules
{
    /**
     * Client sidebar modules (key => meta).
     *
     * @return array<string, array{label:string, route:string, match:string, icon:string, tone:string, group:string, params?:array<string, string>}>
     */
    public static function catalog(): array
    {
        return [
            'today' => [
                'label' => 'Dashboard',
                'route' => 'dashboard',
                'match' => 'dashboard',
                'icon' => 'today',
                'tone' => 'amber',
                'group' => 'Work',
            ],
            'business' => [
                'label' => 'Business Profile',
                'route' => 'business.edit',
                'match' => 'business.*',
                'icon' => 'platform',
                'tone' => 'signal',
                'group' => 'Work',
            ],
            'brand' => [
                'label' => 'Brand',
                'route' => 'brand.edit',
                'match' => 'brand.*',
                'icon' => 'brand',
                'tone' => 'rose',
                'group' => 'Work',
            ],
            'studio' => [
                'label' => 'Studio',
                'route' => 'studio.cards.index',
                'match' => 'studio.*',
                'icon' => 'studio',
                'tone' => 'violet',
                'group' => 'Work',
            ],
            'media' => [
                'label' => 'Media',
                'route' => 'media.index',
                'match' => 'media.*',
                'icon' => 'media',
                'tone' => 'sky',
                'group' => 'Work',
            ],
            'social' => [
                'label' => 'Social',
                'route' => 'social.index',
                'params' => ['view' => 'calendar'],
                'match' => 'social.*',
                'icon' => 'social',
                'tone' => 'fuchsia',
                'group' => 'Grow',
            ],
            'seo' => [
                'label' => 'SEO',
                'route' => 'seo.index',
                'match' => 'seo.*',
                'icon' => 'seo',
                'tone' => 'emerald',
                'group' => 'Grow',
            ],
            'blog' => [
                'label' => 'Blog',
                'route' => 'blog.index',
                'match' => 'blog.*',
                'icon' => 'blog',
                'tone' => 'sky',
                'group' => 'Grow',
            ],
            'channels' => [
                'label' => 'Channels',
                'route' => 'channels.index',
                'match' => 'channels.*',
                'icon' => 'channels',
                'tone' => 'sky',
                'group' => 'Grow',
            ],
            'funnels' => [
                'label' => 'Funnels',
                'route' => 'funnels.index',
                'match' => 'funnels.*',
                'icon' => 'funnels',
                'tone' => 'fuchsia',
                'group' => 'Grow',
            ],
            'whatsapp' => [
                'label' => 'WhatsApp',
                'route' => 'whatsapp.index',
                'match' => 'whatsapp.*',
                'icon' => 'whatsapp',
                'tone' => 'emerald',
                'group' => 'Sell',
            ],
            'crm' => [
                'label' => 'Leads',
                'route' => 'crm.index',
                'match' => 'crm.*',
                'icon' => 'crm',
                'tone' => 'amber',
                'group' => 'Sell',
            ],
            'analytics' => [
                'label' => 'Analytics',
                'route' => 'analytics.index',
                'match' => 'analytics.*',
                'icon' => 'analytics',
                'tone' => 'emerald',
                'group' => 'Sell',
            ],
            'billing' => [
                'label' => 'Billing',
                'route' => 'billing.index',
                'match' => 'billing.*',
                'icon' => 'platform',
                'tone' => 'emerald',
                'group' => 'Account',
            ],
            'settings' => [
                'label' => 'Settings',
                'route' => 'settings.index',
                'match' => 'settings.*',
                'icon' => 'platform',
                'tone' => 'signal',
                'group' => 'Account',
            ],
        ];
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::catalog());
    }

    /** Preferred sidebar group order. */
    public static function groupOrder(): array
    {
        return ['Work', 'Grow', 'Sell', 'Account', 'Admin'];
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
