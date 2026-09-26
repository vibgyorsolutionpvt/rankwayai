<?php

namespace App\Services\Studio;

use App\Models\BusinessCard;
use App\Models\BusinessCardEvent;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class BusinessCardService
{
    /**
     * @return array<string, mixed>
     */
    public function present(BusinessCard $card, bool $includePrivate = true): array
    {
        $kit = $card->resolveBrandKit();
        $styles = $kit?->styleTokens() ?? [
            'primary_color' => '#0E9F90',
            'secondary_color' => '#0B1220',
            'accent_color' => '#F59E0B',
            'heading_font' => 'Outfit',
            'body_font' => 'Plus Jakarta Sans',
            'brand_tone' => 'professional',
            'default_header' => null,
            'default_footer' => null,
            'default_cta_label' => null,
            'default_cta_url' => null,
        ];

        $templateKey = $card->template_key === 'bold' ? 'classic' : $card->template_key;

        $brandLogoUrl = $this->publicUrl($kit?->logo_path);
        $customLogoUrl = $this->publicUrl($card->logo_path);

        $payload = [
            'id' => (string) $card->id,
            'title' => $card->title,
            'template_key' => $templateKey,
            'status' => $card->status,
            'person_name' => $card->person_name,
            'person_title' => $card->person_title,
            'pronouns' => $card->pronouns,
            'person_photo_url' => $this->publicUrl($card->person_photo_path),
            'cover_image_url' => $this->publicUrl($card->cover_image_path),
            'company_name' => $card->company_name ?: $card->workspace?->name,
            'tagline' => $card->tagline,
            'phone' => $card->phone,
            'whatsapp' => $card->whatsapp ?: $card->phone,
            'email' => $card->email,
            'website' => $card->website,
            'address' => $card->address,
            'social_links' => $card->social_links ?? [],
            'brand_kit_id' => $card->brand_kit_id,
            'logo_url' => $customLogoUrl ?: $brandLogoUrl,
            'brand_logo_url' => $brandLogoUrl,
            'has_custom_logo' => filled($card->logo_path),
            'styles' => $styles,
            'share_url' => $card->publicUrl(),
            'qr_url' => $this->qrImageUrl($card->publicUrl()),
            'is_public' => $card->is_public,
            'updated_at' => $card->updated_at?->timezone(config('app.timezone'))->format('d M Y, g:i A'),
        ];

        if ($includePrivate) {
            $payload['analytics'] = [
                'views' => (int) $card->views_count,
                'phone_clicks' => (int) $card->phone_clicks,
                'whatsapp_clicks' => (int) $card->whatsapp_clicks,
                'email_clicks' => (int) $card->email_clicks,
                'website_clicks' => (int) $card->website_clicks,
            ];
            $payload['created_at'] = $card->created_at?->timezone(config('app.timezone'))->format('d M Y, g:i A');
        }

        return $payload;
    }

    public function track(BusinessCard $card, string $event, Request $request): void
    {
        $allowed = ['view', 'phone', 'whatsapp', 'email', 'website'];
        if (! in_array($event, $allowed, true)) {
            return;
        }

        BusinessCardEvent::query()->create([
            'business_card_id' => $card->id,
            'workspace_id' => $card->workspace_id,
            'event' => $event,
            'ip_hash' => hash('sha256', (string) $request->ip()),
            'user_agent' => Str::limit((string) $request->userAgent(), 255, ''),
        ]);

        $column = match ($event) {
            'view' => 'views_count',
            'phone' => 'phone_clicks',
            'whatsapp' => 'whatsapp_clicks',
            'email' => 'email_clicks',
            'website' => 'website_clicks',
        };

        $card->increment($column);
    }

    public function pdf(BusinessCard $card): Response
    {
        $data = $this->present($card, false);
        $pdf = Pdf::loadView('studio.business-card-pdf', [
            'card' => $data,
            'template' => $card->template_key,
        ])->setPaper([0, 0, 252, 144], 'landscape');

        $filename = Str::slug($card->title ?: 'business-card').'.pdf';

        return $pdf->download($filename);
    }

    public function qrImageUrl(string $url): string
    {
        return 'https://api.qrserver.com/v1/create-qr-code/?size=240x240&data='.urlencode($url);
    }

    private function publicUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return Storage::disk('public')->url($path);
    }
}
