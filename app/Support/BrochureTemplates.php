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
     * Default section blueprint seeded from workspace business profile.
     *
     * @param  array<string, mixed>  $profile
     * @return list<array{key: string, title: string, body: string, items: list<string>, enabled: bool}>
     */
    public static function defaultSections(array $profile, string $template = 'agency'): array
    {
        $services = array_values(array_filter($profile['services'] ?? []));
        $products = array_values(array_filter($profile['products'] ?? []));
        $about = trim((string) ($profile['description'] ?? ''));
        $company = (string) ($profile['business_name'] ?? 'Our business');

        $sections = [
            [
                'key' => 'cover',
                'title' => $company,
                'body' => (string) ($profile['tagline'] ?? 'Your trusted partner'),
                'items' => [],
                'enabled' => true,
            ],
            [
                'key' => 'about',
                'title' => 'About us',
                'body' => $about !== '' ? $about : "{$company} helps customers with quality service and trusted delivery.",
                'items' => [],
                'enabled' => true,
            ],
            [
                'key' => 'services',
                'title' => 'Services',
                'body' => 'What we offer',
                'items' => $services !== [] ? $services : ['Consultation', 'Custom solutions', 'Ongoing support'],
                'enabled' => true,
            ],
            [
                'key' => 'products',
                'title' => $template === 'travel' ? 'Packages' : 'Products',
                'body' => $template === 'travel' ? 'Popular trips' : 'Featured offerings',
                'items' => $products !== [] ? $products : ['Starter package', 'Premium package'],
                'enabled' => true,
            ],
            [
                'key' => 'why_us',
                'title' => 'Why choose us',
                'body' => '',
                'items' => [
                    'Experienced team',
                    'Transparent pricing',
                    'Customer-first support',
                ],
                'enabled' => true,
            ],
            [
                'key' => 'testimonials',
                'title' => 'Testimonials',
                'body' => '',
                'items' => [
                    '“Great service and clear communication.” — Happy customer',
                ],
                'enabled' => $template !== 'minimal',
            ],
            [
                'key' => 'contact',
                'title' => 'Contact',
                'body' => 'Ready to get started? Reach out today.',
                'items' => array_values(array_filter([
                    $profile['phone'] ?? null,
                    $profile['email'] ?? null,
                    $profile['website'] ?? null,
                    $profile['address'] ?? null,
                ])),
                'enabled' => true,
            ],
        ];

        return $sections;
    }
}
