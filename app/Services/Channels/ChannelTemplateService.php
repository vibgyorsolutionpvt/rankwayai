<?php

namespace App\Services\Channels;

use App\Models\BrandKit;
use App\Models\CrmLead;
use App\Models\Workspace;

class ChannelTemplateService
{
    /**
     * Replace {{placeholders}} using brand kit + optional lead.
     * Supported: name, brand, cta, cta_url, phone, email, website
     */
    public function render(string $text, Workspace $workspace, ?CrmLead $lead = null): string
    {
        $brand = $workspace->resolveBrandKit();
        $map = $this->tokens($workspace, $brand, $lead);

        return preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/i', function (array $m) use ($map) {
            $key = strtolower($m[1]);

            return array_key_exists($key, $map) ? (string) $map[$key] : $m[0];
        }, $text) ?? $text;
    }

    /**
     * @return array<string, string>
     */
    public function tokens(Workspace $workspace, ?BrandKit $brand = null, ?CrmLead $lead = null): array
    {
        $brand ??= $workspace->resolveBrandKit();

        return [
            'name' => $lead?->name ?: 'there',
            'brand' => $workspace->name,
            'cta' => $brand?->default_cta_label ?: 'Get started',
            'cta_url' => $brand?->default_cta_url ?: '',
            'phone' => $brand?->phone ?: '',
            'email' => $brand?->email ?: '',
            'website' => $brand?->website_url ?: '',
        ];
    }
}
