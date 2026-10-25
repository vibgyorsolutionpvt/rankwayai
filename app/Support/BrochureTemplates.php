<?php

namespace App\Support;

final class BrochureTemplates
{
    /**
     * @return array<string, array{label: string, description: string}>
     */
    public static function catalog(): array
    {
        return [
            'agency' => [
                'label' => 'Agency',
                'description' => 'Cover, about, services, why us, contact.',
            ],
            'travel' => [
                'label' => 'Travel',
                'description' => 'Packages-first layout for tours & holidays.',
            ],
            'service' => [
                'label' => 'Service',
                'description' => 'Local service brochure with offers & CTA.',
            ],
            'minimal' => [
                'label' => 'Minimal',
                'description' => 'Clean typography, fewer sections.',
            ],
        ];
    }

    /**
     * @return list<array{value: string, label: string, description: string}>
     */
    public static function options(): array
    {
        return collect(self::catalog())
            ->map(fn (array $meta, string $key) => [
                'value' => $key,
                'label' => $meta['label'],
                'description' => $meta['description'],
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::catalog());
    }

    /**
     * Industry-based starter copy used only when the workspace profile has no services.
     *
     * @return array{services: list<string>, why: list<string>, tagline: string}
     */
    public static function industryDefaults(string $industry, string $template): array
    {
        $key = strtolower($industry.' '.$template);
        $packs = [
            'travel|tour|holiday' => [
                'services' => ['Domestic & international tour packages', 'Flight & hotel bookings', 'Visa & passport assistance', 'Custom group & family trips', 'Honeymoon & weekend getaways'],
                'why' => ['Handpicked stays and trusted local partners', 'Transparent, all-inclusive pricing', '24/7 on-trip support', 'Itineraries tailored to your budget'],
                'tagline' => 'Journeys planned around you',
            ],
            'it |software|consult|web|digital|tech|agency' => [
                'services' => ['Website & web app development', 'Mobile app development', 'SEO & digital marketing', 'Custom software & automation', 'IT consulting & support'],
                'why' => ['Experienced developers and designers', 'On-time delivery with clear milestones', 'Scalable, secure solutions', 'Dedicated post-launch support'],
                'tagline' => 'Technology that grows your business',
            ],
            'restaurant|food|cafe|hotel' => [
                'services' => ['Dine-in & takeaway', 'Home delivery', 'Catering & events', 'Party & table bookings'],
                'why' => ['Fresh ingredients, authentic taste', 'Hygienic kitchen', 'Quick service', 'Great value for money'],
                'tagline' => 'Fresh food, served with care',
            ],
            'clinic|hospital|health|dental|doctor' => [
                'services' => ['Consultation & check-ups', 'Diagnostics & lab tests', 'Treatment & follow-up care', 'Online appointments'],
                'why' => ['Qualified, caring professionals', 'Modern equipment', 'Clear and honest guidance', 'Easy appointment booking'],
                'tagline' => 'Care you can trust',
            ],
            'school|institute|coaching|education|training' => [
                'services' => ['Expert-led courses', 'Doubt-solving & mentoring', 'Mock tests & assessments', 'Career guidance'],
                'why' => ['Experienced faculty', 'Small batches, personal attention', 'Proven results', 'Flexible timings'],
                'tagline' => 'Learn. Grow. Succeed.',
            ],
            'real estate|property|builder' => [
                'services' => ['Residential & commercial properties', 'Buying, selling & renting', 'Site visits & legal assistance', 'Home loan guidance'],
                'why' => ['Verified listings', 'Transparent deals', 'Local market expertise', 'End-to-end paperwork support'],
                'tagline' => 'Find a place you will love',
            ],
        ];

        foreach ($packs as $pattern => $pack) {
            foreach (explode('|', $pattern) as $needle) {
                if (str_contains($key, $needle)) {
                    return $pack;
                }
            }
        }

        return [
            'services' => ['Consultation', 'Custom solutions', 'Ongoing support'],
            'why' => ['Experienced, dedicated team', 'Transparent pricing', 'Quick response and clear communication', 'Customer-first support'],
            'tagline' => 'Your trusted partner',
        ];
    }

    /**
     * Default section blueprint seeded from the workspace business profile.
     * Sections without real data fall back to neutral copy instead of dummy content.
     *
     * @param  array<string, mixed>  $profile
     * @return list<array{key: string, title: string, body: string, items: list<string>, enabled: bool}>
     */
    public static function defaultSections(array $profile, string $template = 'agency'): array
    {
        $clean = fn ($list) => array_values(array_filter(array_map(
            fn ($v) => trim((string) $v),
            is_array($list) ? $list : []
        ), fn ($v) => $v !== ''));

        $services = $clean($profile['services'] ?? []);
        $products = $clean($profile['products'] ?? []);
        $about = trim((string) ($profile['description'] ?? ''));
        $company = (string) ($profile['business_name'] ?? 'Our business');
        $industry = trim((string) ($profile['industry'] ?? ''));
        $audience = trim((string) ($profile['target_audience'] ?? ''));
        $hours = trim((string) ($profile['working_hours'] ?? ''));
        $location = implode(', ', array_filter([
            $profile['city'] ?? null,
            $profile['state'] ?? null,
            $profile['country'] ?? null,
        ]));
        $isTravel = $template === 'travel';
        $defaults = self::industryDefaults($industry, $template);

        $highlights = array_values(array_filter([
            $industry !== '' ? "Industry: {$industry}" : null,
            $location !== '' ? "Based in {$location}" : null,
            $audience !== '' ? "Built for {$audience}" : null,
            $hours !== '' ? "Open: {$hours}" : null,
            $services !== [] ? count($services).' '.(count($services) === 1 ? 'service' : 'services').' offered' : null,
        ]));

        $socials = [];
        foreach ((array) ($profile['social_links'] ?? []) as $network => $url) {
            if (is_string($url) && trim($url) !== '') {
                $socials[] = ucfirst((string) $network).': '.trim($url);
            }
        }

        $contact = array_values(array_filter([
            ! empty($profile['phone']) ? 'Call: '.$profile['phone'] : null,
            ! empty($profile['whatsapp']) ? 'WhatsApp: '.$profile['whatsapp'] : null,
            ! empty($profile['email']) ? 'Email: '.$profile['email'] : null,
            ! empty($profile['website']) ? 'Web: '.$profile['website'] : null,
            ! empty($profile['address']) ? 'Visit: '.implode(', ', array_filter([
                $profile['address'], $profile['city'] ?? null, $profile['postal_code'] ?? null,
            ])) : null,
            $hours !== '' ? 'Hours: '.$hours : null,
            ...$socials,
        ]));

        $aboutFallback = $industry !== ''
            ? "{$company} ({$industry})".($location !== '' ? " serves customers from {$location}" : ' serves its customers').' with quality service and trusted delivery.'
            : "{$company} helps customers with quality service and trusted delivery.";

        return [
            [
                'key' => 'cover',
                'title' => $company,
                'body' => (string) (($profile['tagline'] ?? '') ?: $defaults['tagline']),
                'items' => [],
                'enabled' => true,
            ],
            [
                'key' => 'about',
                'title' => 'About us',
                'body' => $about !== '' ? $about : $aboutFallback,
                'items' => $highlights,
                'enabled' => true,
            ],
            [
                'key' => 'services',
                'title' => $isTravel ? 'What we arrange' : 'Our services',
                'body' => 'What we offer',
                'items' => $services !== [] ? $services : $defaults['services'],
                'enabled' => true,
            ],
            [
                'key' => 'products',
                'title' => $isTravel ? 'Popular packages' : 'Featured offerings',
                'body' => $isTravel ? 'Popular trips' : 'Handpicked for you',
                'items' => $products,
                'enabled' => $products !== [],
            ],
            [
                'key' => 'why_us',
                'title' => 'Why choose us',
                'body' => '',
                'items' => $audience !== '' ? [...array_slice($defaults['why'], 0, 3), "Tailored for {$audience}"] : $defaults['why'],
                'enabled' => $template !== 'minimal',
            ],
            [
                'key' => 'contact',
                'title' => 'Get in touch',
                'body' => 'Ready to get started? Reach out today.',
                'items' => $contact,
                'enabled' => true,
            ],
        ];
    }
}
