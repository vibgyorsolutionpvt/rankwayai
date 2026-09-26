<?php

namespace App\Support;

/**
 * Controlled business verticals for workspace Business Profile.
 * Extensible — add keys without changing the data model.
 */
final class BusinessTypes
{
    /**
     * @return array<string, string> key => label
     */
    public static function catalog(): array
    {
        return [
            'travel_agency' => 'Travel Agency',
            'tour_operator' => 'Tour Operator',
            'real_estate' => 'Real Estate',
            'education' => 'Education',
            'hotel' => 'Hotel',
            'restaurant' => 'Restaurant',
            'it_software' => 'IT / Software',
            'digital_agency' => 'Digital Agency',
            'consultant' => 'Consultant',
            'local_service' => 'Local Service',
            'other' => 'Other',
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return collect(self::catalog())
            ->map(fn (string $label, string $key) => [
                'value' => $key,
                'label' => $label,
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

    public static function label(?string $key): ?string
    {
        if ($key === null || $key === '') {
            return null;
        }

        return self::catalog()[$key] ?? null;
    }

    public static function isValid(?string $key): bool
    {
        return $key !== null && array_key_exists($key, self::catalog());
    }

    /**
     * Map a free-text industry (legacy) to the closest business_type key.
     */
    public static function inferFromIndustry(?string $industry): ?string
    {
        $needle = mb_strtolower(trim((string) $industry));
        if ($needle === '') {
            return null;
        }

        $aliases = [
            'travel_agency' => ['travel', 'holiday', 'tour agency', 'tourism'],
            'tour_operator' => ['tour operator', 'tours'],
            'real_estate' => ['real estate', 'property', 'realty'],
            'education' => ['education', 'school', 'coaching', 'college'],
            'hotel' => ['hotel', 'resort', 'hospitality'],
            'restaurant' => ['restaurant', 'cafe', 'food'],
            'it_software' => ['it ', 'software', 'saas', 'tech'],
            'digital_agency' => ['digital agency', 'marketing agency', 'seo agency'],
            'consultant' => ['consultant', 'consulting'],
            'local_service' => ['local service', 'local business'],
        ];

        foreach ($aliases as $key => $words) {
            foreach ($words as $word) {
                if (str_contains($needle, $word)) {
                    return $key;
                }
            }
        }

        return 'other';
    }
}
