<?php

namespace App\Services\Studio;

use App\Models\Itinerary;
use App\Models\Quotation;
use App\Models\Workspace;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class QuotationService
{
    public function __construct(
        private ItineraryService $itineraries,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function present(Quotation $quotation, bool $includePrivate = true): array
    {
        $kit = $quotation->resolveBrandKit();
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

        $whatsapp = $this->whatsappShareUrl($quotation);
        $email = $this->emailShareUrl($quotation);

        $payload = [
            'id' => $quotation->id,
            'number' => $quotation->number,
            'title' => $quotation->title,
            'status' => $quotation->status,
            'customer_name' => $quotation->customer_name,
            'customer_email' => $quotation->customer_email,
            'customer_phone' => $quotation->customer_phone,
            'customer_company' => $quotation->customer_company,
            'trip_title' => $quotation->trip_title,
            'destination' => $quotation->destination,
            'travellers' => (int) $quotation->travellers,
            'duration_days' => $quotation->duration_days ? (int) $quotation->duration_days : null,
            'currency' => $quotation->currency ?: 'INR',
            'line_items' => $quotation->line_items ?? [],
            'subtotal' => (float) $quotation->subtotal,
            'discount_amount' => (float) $quotation->discount_amount,
            'tax_percent' => (float) $quotation->tax_percent,
            'tax_amount' => (float) $quotation->tax_amount,
            'total' => (float) $quotation->total,
            'payment_terms' => $quotation->payment_terms,
            'valid_until' => $quotation->valid_until?->toDateString(),
            'notes' => $quotation->notes,
            'inclusions' => $quotation->inclusions ?? [],
            'inclusions_text' => implode("\n", $quotation->inclusions ?? []),
            'exclusions' => $quotation->exclusions ?? [],
            'exclusions_text' => implode("\n", $quotation->exclusions ?? []),
            'itinerary_id' => $quotation->itinerary_id,
            'crm_lead_id' => $quotation->crm_lead_id,
            'brand_kit_id' => $quotation->brand_kit_id,
            'share_url' => $quotation->publicUrl(),
            'whatsapp_url' => $whatsapp,
            'email_url' => $email,
            'is_public' => $quotation->is_public,
            'company_name' => $quotation->workspace?->name,
            'logo_url' => $kit?->logo_path ? Storage::disk('public')->url($kit->logo_path) : null,
            'styles' => $styles,
            'updated_at' => $quotation->updated_at?->timezone(config('app.timezone'))->format('d M Y, g:i A'),
        ];

        if ($includePrivate) {
            $payload['analytics'] = [
                'views' => (int) $quotation->views_count,
                'downloads' => (int) $quotation->downloads_count,
            ];
            $payload['created_at'] = $quotation->created_at?->timezone(config('app.timezone'))->format('d M Y, g:i A');
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(Workspace $workspace, ?int $userId, array $input, ?Itinerary $fromItinerary = null): Quotation
    {
        $lineItems = $this->normalizeLineItems($input['line_items'] ?? []);
        if ($lineItems === [] && $fromItinerary) {
            $lineItems = $this->lineItemsFromItinerary($fromItinerary);
        }
        if ($lineItems === []) {
            $lineItems = [[
                'description' => 'Package',
                'qty' => 1.0,
                'unit_price' => 0.0,
                'amount' => 0.0,
            ]];
        }

        $discount = max(0, (float) ($input['discount_amount'] ?? 0));
        $taxPercent = max(0, min(100, (float) ($input['tax_percent'] ?? 0)));
        $totals = $this->computeTotals($lineItems, $discount, $taxPercent);

        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '' && $fromItinerary) {
            $title = 'Quotation — '.$fromItinerary->title;
        }
        if ($title === '') {
            $title = 'Quotation';
        }

        return Quotation::query()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $userId,
            'itinerary_id' => $fromItinerary?->id ?? ($input['itinerary_id'] ?? null),
            'crm_lead_id' => $input['crm_lead_id'] ?? null,
            'brand_kit_id' => $input['brand_kit_id'] ?? $workspace->resolveBrandKit()?->id,
            'number' => $this->nextNumber($workspace),
            'title' => $title,
            'status' => $input['status'] ?? 'draft',
            'customer_name' => $input['customer_name'] ?? null,
            'customer_email' => $input['customer_email'] ?? null,
            'customer_phone' => $input['customer_phone'] ?? null,
            'customer_company' => $input['customer_company'] ?? null,
            'trip_title' => $input['trip_title'] ?? ($fromItinerary?->title),
            'destination' => $input['destination'] ?? ($fromItinerary?->destination),
            'travellers' => max(1, (int) ($input['travellers'] ?? ($fromItinerary?->travellersCount() ?? 1))),
            'duration_days' => isset($input['duration_days'])
                ? (int) $input['duration_days']
                : ($fromItinerary?->duration_days),
            'currency' => strtoupper((string) ($input['currency'] ?? $fromItinerary?->currency ?? 'INR')),
            'line_items' => $totals['line_items'],
            'subtotal' => $totals['subtotal'],
            'discount_amount' => $totals['discount_amount'],
            'tax_percent' => $totals['tax_percent'],
            'tax_amount' => $totals['tax_amount'],
            'total' => $totals['total'],
            'payment_terms' => $input['payment_terms'] ?? '50% advance to confirm. Balance before travel.',
            'valid_until' => $input['valid_until'] ?? now()->addDays(14)->toDateString(),
            'notes' => $input['notes'] ?? null,
            'inclusions' => $this->linesToList($input['inclusions'] ?? $input['inclusions_text'] ?? $fromItinerary?->inclusions),
            'exclusions' => $this->linesToList($input['exclusions'] ?? $input['exclusions_text'] ?? $fromItinerary?->exclusions),
            'is_public' => array_key_exists('is_public', $input) ? (bool) $input['is_public'] : true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function applyEditorPayload(Quotation $quotation, array $data): Quotation
    {
        $lineItems = $this->normalizeLineItems($data['line_items'] ?? []);
        $discount = max(0, (float) ($data['discount_amount'] ?? 0));
        $taxPercent = max(0, min(100, (float) ($data['tax_percent'] ?? 0)));
        $totals = $this->computeTotals($lineItems, $discount, $taxPercent);

        $quotation->fill([
            'title' => $data['title'],
            'status' => $data['status'],
            'customer_name' => $data['customer_name'] ?? null,
            'customer_email' => $data['customer_email'] ?? null,
            'customer_phone' => $data['customer_phone'] ?? null,
            'customer_company' => $data['customer_company'] ?? null,
            'trip_title' => $data['trip_title'] ?? null,
            'destination' => $data['destination'] ?? null,
            'travellers' => max(1, (int) ($data['travellers'] ?? 1)),
            'duration_days' => isset($data['duration_days']) ? (int) $data['duration_days'] : null,
            'currency' => strtoupper((string) ($data['currency'] ?? 'INR')),
            'line_items' => $totals['line_items'],
            'subtotal' => $totals['subtotal'],
            'discount_amount' => $totals['discount_amount'],
            'tax_percent' => $totals['tax_percent'],
            'tax_amount' => $totals['tax_amount'],
            'total' => $totals['total'],
            'payment_terms' => $data['payment_terms'] ?? null,
            'valid_until' => $data['valid_until'] ?? null,
            'notes' => $data['notes'] ?? null,
            'inclusions' => $this->linesToList($data['inclusions'] ?? $data['inclusions_text'] ?? null),
            'exclusions' => $this->linesToList($data['exclusions'] ?? $data['exclusions_text'] ?? null),
            'is_public' => array_key_exists('is_public', $data) ? (bool) $data['is_public'] : true,
            'crm_lead_id' => $data['crm_lead_id'] ?? $quotation->crm_lead_id,
        ])->save();

        return $quotation->fresh();
    }

    public function track(Quotation $quotation, string $event): void
    {
        if ($event === 'view') {
            $quotation->increment('views_count');
        } elseif ($event === 'download') {
            $quotation->increment('downloads_count');
        }
    }

    public function pdf(Quotation $quotation): Response
    {
        $data = $this->present($quotation, false);
        $pdf = Pdf::loadView('studio.quotation-pdf', [
            'quotation' => $data,
            'workspace' => $quotation->workspace,
            'generated_at' => now()->timezone(config('app.timezone'))->format('d M Y, g:i A'),
        ])->setPaper('a4', 'portrait');

        return $pdf->download(Str::slug($quotation->number ?: 'quotation').'.pdf');
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{line_items: list<array{description:string,qty:float,unit_price:float,amount:float}>, subtotal: float, discount_amount: float, tax_percent: float, tax_amount: float, total: float}
     */
    public function computeTotals(array $items, float $discount = 0, float $taxPercent = 0): array
    {
        $normalized = $this->normalizeLineItems($items);
        $subtotal = array_sum(array_column($normalized, 'amount'));
        $discount = max(0, min($subtotal, $discount));
        $taxable = max(0, $subtotal - $discount);
        $taxPercent = max(0, min(100, $taxPercent));
        $tax = round($taxable * ($taxPercent / 100), 2);

        return [
            'line_items' => $normalized,
            'subtotal' => round($subtotal, 2),
            'discount_amount' => round($discount, 2),
            'tax_percent' => $taxPercent,
            'tax_amount' => $tax,
            'total' => round($taxable + $tax, 2),
        ];
    }

    /**
     * @param  mixed  $items
     * @return list<array{description:string,qty:float,unit_price:float,amount:float}>
     */
    public function normalizeLineItems(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        $out = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $description = trim((string) ($item['description'] ?? ''));
            if ($description === '') {
                continue;
            }
            $qty = max(0.01, (float) ($item['qty'] ?? 1));
            $unit = max(0, (float) ($item['unit_price'] ?? $item['unit'] ?? 0));
            $out[] = [
                'description' => Str::limit($description, 240, ''),
                'qty' => round($qty, 2),
                'unit_price' => round($unit, 2),
                'amount' => round($qty * $unit, 2),
            ];
        }

        return $out;
    }

    /**
     * @return list<array{description:string,qty:float,unit_price:float,amount:float}>
     */
    public function lineItemsFromItinerary(Itinerary $itinerary): array
    {
        $pricing = $this->itineraries->normalizePricing($itinerary->pricing ?? []);
        $totals = $this->itineraries->calculateTotals($pricing);
        $travellers = max(1, $itinerary->travellersCount());

        $map = [
            'hotel_cost' => 'Accommodation',
            'transport_cost' => 'Transport',
            'activity_cost' => 'Activities & sightseeing',
            'meal_cost' => 'Meals',
            'guide_cost' => 'Guide / escort',
        ];

        $items = [];
        foreach ($map as $key => $label) {
            $amount = (float) ($pricing[$key] ?? 0);
            if ($amount <= 0) {
                continue;
            }
            $items[] = [
                'description' => $label,
                'qty' => 1.0,
                'unit_price' => $amount,
                'amount' => $amount,
            ];
        }

        $markup = (float) ($pricing['markup'] ?? 0);
        if ($markup > 0) {
            $items[] = [
                'description' => 'Service & coordination',
                'qty' => 1.0,
                'unit_price' => $markup,
                'amount' => $markup,
            ];
        }

        if ($items === []) {
            $selling = (float) ($totals['selling_price'] ?? 0);
            $items[] = [
                'description' => ($itinerary->destination ?: 'Trip').' package ('.$travellers.' travellers)',
                'qty' => 1.0,
                'unit_price' => $selling,
                'amount' => $selling,
            ];
        }

        return $items;
    }

    public function nextNumber(Workspace $workspace): string
    {
        $prefix = 'QT-'.now()->timezone(config('app.timezone'))->format('Ymd').'-';
        $last = Quotation::query()
            ->where('workspace_id', $workspace->id)
            ->where('number', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->value('number');

        $seq = 1;
        if (is_string($last) && preg_match('/-(\d+)$/', $last, $m)) {
            $seq = ((int) $m[1]) + 1;
        }

        return $prefix.str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
    }

    private function whatsappShareUrl(Quotation $quotation): string
    {
        $text = ($quotation->title ?: 'Quotation')."\n".$quotation->publicUrl();
        $phone = preg_replace('/\D+/', '', (string) ($quotation->customer_phone ?? '')) ?: '';

        if ($phone !== '') {
            return 'https://wa.me/'.$phone.'?text='.rawurlencode($text);
        }

        return 'https://wa.me/?text='.rawurlencode($text);
    }

    private function emailShareUrl(Quotation $quotation): string
    {
        $subject = rawurlencode($quotation->title ?: 'Quotation '.$quotation->number);
        $body = rawurlencode("Please find your quotation:\n".$quotation->publicUrl());
        $to = $quotation->customer_email ? rawurlencode($quotation->customer_email) : '';

        return 'mailto:'.$to.'?subject='.$subject.'&body='.$body;
    }

    /**
     * @return list<string>|null
     */
    private function linesToList(mixed $raw): ?array
    {
        if ($raw === null) {
            return null;
        }
        if (is_array($raw)) {
            return array_values(array_filter(array_map(
                static fn ($line) => trim((string) $line),
                $raw
            )));
        }

        $lines = preg_split('/\r\n|\r|\n/', (string) $raw) ?: [];

        return array_values(array_filter(array_map('trim', $lines)));
    }
}
