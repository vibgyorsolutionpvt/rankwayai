<?php

namespace App\Support;

/**
 * Brand voice options for kits / AI document generation.
 */
final class BrandTones
{
    /**
     * @return array<string, string>
     */
    public static function catalog(): array
    {
        return [
            'professional' => 'Professional',
            'friendly' => 'Friendly',
            'bold' => 'Bold',
            'luxury' => 'Luxury',
            'playful' => 'Playful',
            'trustworthy' => 'Trustworthy',
            'warm' => 'Warm',
            'minimal' => 'Minimal',
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
}
