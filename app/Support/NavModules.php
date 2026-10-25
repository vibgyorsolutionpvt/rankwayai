<?php

namespace App\Support;

final class NavModules
{
    /**
     * Client sidebar modules (key => meta).
     *
     * @return array<string, array{label:string, route:string, match:string, icon:string, tone:string, group:string, params?:array<string, string>, comingSoon?:bool}>
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
            'email' => [
                'label' => 'Email',
                'route' => 'email.index',
                'match' => 'email.*',
                'icon' => 'channels',
                'tone' => 'sky',
                'group' => 'Grow',
            ],
            'sms' => [
                'label' => 'SMS',
                'route' => '#',
                'match' => 'coming-soon.sms',
                'icon' => 'channels',
                'tone' => 'sky',
                'group' => 'Grow',
                'comingSoon' => true,
            ],
            'lead_forms' => [
                'label' => 'Lead Forms',
                'route' => '#',
                'match' => 'coming-soon.lead-forms',
                'icon' => 'funnels',
                'tone' => 'fuchsia',
                'group' => 'Sell',
                'comingSoon' => true,
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
            'automations' => [
                'label' => 'Automations',
                'route' => '#',
                'match' => 'coming-soon.automations',
                'icon' => 'crm',
                'tone' => 'fuchsia',
                'group' => 'Sell',
                'comingSoon' => true,
            ],
            'customers' => [
                'label' => 'Customers',
                'route' => '#',
                'match' => 'coming-soon.customers',
                'icon' => 'crm',
                'tone' => 'sky',
                'group' => 'Sell',
                'comingSoon' => true,
            ],
            'quotations' => [
                'label' => 'Quotations',
                'route' => 'studio.quotations.index',
                'match' => 'studio.quotations.*',
                'icon' => 'studio',
                'tone' => 'violet',
                'group' => 'Sell',
            ],
            'proposals' => [
                'label' => 'Proposals',
                'route' => '#',
                'match' => 'coming-soon.proposals',
                'icon' => 'studio',
                'tone' => 'fuchsia',
                'group' => 'Sell',
                'comingSoon' => true,
            ],
            'bookings' => [
                'label' => 'Bookings',
                'route' => '#',
                'match' => 'coming-soon.bookings',
                'icon' => 'crm',
                'tone' => 'amber',
                'group' => 'Sell',
                'comingSoon' => true,
            ],
            'payments' => [
                'label' => 'Payments',
                'route' => '#',
                'match' => 'coming-soon.payments',
                'icon' => 'platform',
                'tone' => 'emerald',
                'group' => 'Sell',
                'comingSoon' => true,
            ],
            'business_card' => [
                'label' => 'Business Card',
                'route' => 'studio.cards.index',
                'match' => 'studio.cards.*',
                'icon' => 'studio',
                'tone' => 'violet',
                'group' => 'Work',
            ],
            'brochure' => [
                'label' => 'Brochure',
                'route' => 'studio.brochures.index',
                'match' => 'studio.brochures.*',
                'icon' => 'media',
                'tone' => 'sky',
                'group' => 'Work',
            ],
            'catalogue' => [
                'label' => 'Catalogue',
                'route' => '#',
                'match' => 'coming-soon.catalogue',
                'icon' => 'blog',
                'tone' => 'fuchsia',
                'group' => 'Work',
                'comingSoon' => true,
            ],
            'document_studio' => [
                'label' => 'Document Studio',
                'route' => '#',
                'match' => 'coming-soon.document-studio',
                'icon' => 'studio',
                'tone' => 'rose',
                'group' => 'Work',
                'comingSoon' => true,
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

    /**
     * @return array<string, array{label:string,tone:string,children:list<string>}>
     */
    public static function menuGroups(): array
    {
        return [
            'business_section' => [
                'label' => 'Business',
                'tone' => 'rose',
                'children' => ['business', 'brand'],
            ],
            'marketing' => [
                'label' => 'Marketing',
                'tone' => 'fuchsia',
                'children' => ['social', 'seo', 'blog', 'channels', 'whatsapp', 'email', 'sms'],
            ],
            'leads' => [
                'label' => 'Leads',
                'tone' => 'amber',
                'children' => ['lead_forms', 'funnels', 'crm', 'automations'],
            ],
            'sales' => [
                'label' => 'Sales',
                'tone' => 'violet',
                'children' => ['customers', 'quotations', 'proposals', 'bookings', 'payments'],
            ],
            'business_studio' => [
                'label' => 'Business Studio',
                'tone' => 'emerald',
                'children' => ['business_card', 'brochure', 'catalogue', 'document_studio'],
            ],
        ];
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

        if (str_starts_with($routeName, 'email.')) {
            return 'email';
        }

        if (str_starts_with($routeName, 'studio.quotations.')) {
            return 'quotations';
        }

        if (str_starts_with($routeName, 'studio.cards.')) {
            return 'business_card';
        }

        if (str_starts_with($routeName, 'studio.brochures.')) {
            return 'brochure';
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
