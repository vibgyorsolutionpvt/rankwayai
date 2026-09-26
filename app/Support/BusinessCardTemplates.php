<?php

namespace App\Support;

final class BusinessCardTemplates
{
    public const DEFAULT = 'classic';

    /**
     * @return array<string, array{label: string, description: string, layout: string}>
     */
    public static function catalog(): array
    {
        return [
            'classic' => [
                'label' => 'Classic',
                'description' => 'Landscape card with left accent bar — print & share ready.',
                'layout' => 'landscape',
            ],
            'digital' => [
                'label' => 'Digital',
                'description' => 'Mobile profile card with photo upload, contacts and save CTA.',
                'layout' => 'portrait',
            ],
            'connect' => [
                'label' => 'Connect',
                'description' => 'Cover banner, photo, and quick-action buttons for call, WhatsApp and more.',
                'layout' => 'portrait',
            ],
            'modern' => [
                'label' => 'Modern',
                'description' => 'Bold gradient header and centered name.',
                'layout' => 'landscape',
            ],
            'minimal' => [
                'label' => 'Minimal',
                'description' => 'Lots of whitespace, typography first.',
                'layout' => 'landscape',
            ],
        ];
    }

    /**
     * @return list<array{value: string, label: string, description: string, layout: string}>
     */
    public static function options(): array
    {
        return collect(self::catalog())
            ->map(fn (array $meta, string $key) => [
                'value' => $key,
                'label' => $meta['label'],
                'description' => $meta['description'],
                'layout' => $meta['layout'],
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

    public static function layout(string $key): string
    {
        return self::catalog()[$key]['layout'] ?? 'landscape';
    }

    public static function usesPhoto(string $key): bool
    {
        return in_array($key, ['classic', 'digital', 'connect', 'modern', 'minimal'], true);
    }

    public static function usesCover(string $key): bool
    {
        return $key === 'connect';
    }
}
