<?php

namespace App\Services\Studio;

use App\Models\Brochure;
use App\Models\BrochureEvent;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class BrochureService
{
    /**
     * @return array<string, mixed>
     */
    public function present(Brochure $brochure, bool $includePrivate = true): array
    {
        $kit = $brochure->resolveBrandKit();
        $styles = $kit?->styleTokens() ?? [
            'primary_color' => '#0E9F90',
            'secondary_color' => '#0B1220',
            'accent_color' => '#F59E0B',
            'heading_font' => 'Outfit',
            'body_font' => 'Plus Jakarta Sans',
            'default_header' => null,
            'default_footer' => null,
            'default_cta_label' => 'Get in touch',
            'default_cta_url' => null,
        ];

        $payload = [
            'id' => $brochure->id,
            'title' => $brochure->title,
            'template_key' => $brochure->template_key,
            'status' => $brochure->status,
            'headline' => $brochure->headline,
            'subheadline' => $brochure->subheadline,
            'sections' => $brochure->sections ?? [],
            'enabled_sections' => $brochure->enabledSections(),
            'brand_kit_id' => $brochure->brand_kit_id,
            'logo_url' => $kit?->logo_path ? Storage::disk('public')->url($kit->logo_path) : null,
            'styles' => $styles,
            'company_name' => $brochure->workspace?->name,
            'share_url' => $brochure->publicUrl(),
            'qr_url' => route('studio.brochures.public.qr', $brochure->share_token),
            'is_public' => $brochure->is_public,
            'updated_at' => $brochure->updated_at?->timezone(config('app.timezone'))->format('d M Y, g:i A'),
        ];

        if ($includePrivate) {
            $payload['analytics'] = [
                'views' => (int) $brochure->views_count,
                'downloads' => (int) $brochure->downloads_count,
                'cta_clicks' => (int) $brochure->cta_clicks,
            ];
            $payload['created_at'] = $brochure->created_at?->timezone(config('app.timezone'))->format('d M Y, g:i A');
        }

        return $payload;
    }

    public function track(Brochure $brochure, string $event, Request $request): void
    {
        $allowed = ['view', 'download', 'cta'];
        if (! in_array($event, $allowed, true)) {
            return;
        }

        BrochureEvent::query()->create([
            'brochure_id' => $brochure->id,
            'workspace_id' => $brochure->workspace_id,
            'event' => $event,
            'ip_hash' => hash('sha256', (string) $request->ip()),
            'user_agent' => Str::limit((string) $request->userAgent(), 255, ''),
        ]);

        $column = match ($event) {
            'view' => 'views_count',
            'download' => 'downloads_count',
            'cta' => 'cta_clicks',
        };

        $brochure->increment($column);
    }

    public function pdf(Brochure $brochure): Response
    {
        $data = $this->present($brochure, false);
        $pdf = Pdf::loadView('studio.brochure-pdf', [
            'brochure' => $data,
        ])->setPaper('a4', 'portrait');

        return $pdf->download(Str::slug($brochure->title ?: 'brochure').'.pdf');
    }

    /**
     * Normalize sections payload from the editor.
     *
     * @return list<array{key: string, title: string, body: string, items: list<string>, enabled: bool}>
     */
    public function normalizeSections(mixed $sections): array
    {
        if (! is_array($sections)) {
            return [];
        }

        $normalized = [];
        foreach ($sections as $section) {
            if (! is_array($section)) {
                continue;
            }
            $items = $section['items'] ?? [];
            if (is_string($items)) {
                $items = preg_split('/\r\n|\r|\n/', $items) ?: [];
            }
            if (! is_array($items)) {
                $items = [];
            }
            $items = array_values(array_filter(array_map(
                fn ($line) => trim((string) $line),
                $items
            ), fn ($line) => $line !== ''));

            $normalized[] = [
                'key' => Str::slug((string) ($section['key'] ?? 'section')) ?: 'section',
                'title' => trim((string) ($section['title'] ?? 'Section')),
                'body' => trim((string) ($section['body'] ?? '')),
                'items' => $items,
                'enabled' => (bool) ($section['enabled'] ?? true),
            ];
        }

        return $normalized;
    }
}
