<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrmQuotation extends Model
{
    protected $fillable = [
        'workspace_id',
        'crm_lead_id',
        'created_by',
        'number',
        'title',
        'status',
        'currency',
        'line_items',
        'subtotal_cents',
        'tax_percent',
        'tax_cents',
        'total_cents',
        'notes',
        'valid_until',
    ];

    protected function casts(): array
    {
        return [
            'line_items' => 'array',
            'tax_percent' => 'float',
            'subtotal_cents' => 'integer',
            'tax_cents' => 'integer',
            'total_cents' => 'integer',
            'valid_until' => 'date',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(CrmLead::class, 'crm_lead_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @param  list<array{description?:string, qty?:int|float, unit_cents?:int}>  $items
     * @return array{line_items: list<array{description:string, qty:float, unit_cents:int, total_cents:int}>, subtotal_cents:int, tax_cents:int, total_cents:int}
     */
    public static function computeTotals(array $items, float $taxPercent = 0): array
    {
        $normalized = [];
        $subtotal = 0;

        foreach ($items as $item) {
            $description = trim((string) ($item['description'] ?? ''));
            if ($description === '') {
                continue;
            }
            $qty = max(0.01, (float) ($item['qty'] ?? 1));
            $unit = max(0, (int) ($item['unit_cents'] ?? 0));
            $lineTotal = (int) round($qty * $unit);
            $subtotal += $lineTotal;
            $normalized[] = [
                'description' => $description,
                'qty' => $qty,
                'unit_cents' => $unit,
                'total_cents' => $lineTotal,
            ];
        }

        $taxPercent = max(0, min(100, $taxPercent));
        $tax = (int) round($subtotal * ($taxPercent / 100));

        return [
            'line_items' => $normalized,
            'subtotal_cents' => $subtotal,
            'tax_cents' => $tax,
            'total_cents' => $subtotal + $tax,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toClientArray(): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'title' => $this->title,
            'status' => $this->status,
            'currency' => $this->currency,
            'line_items' => $this->line_items ?? [],
            'subtotal_cents' => $this->subtotal_cents,
            'tax_percent' => $this->tax_percent,
            'tax_cents' => $this->tax_cents,
            'total_cents' => $this->total_cents,
            'notes' => $this->notes,
            'valid_until' => $this->valid_until?->format('Y-m-d'),
            'created_at' => $this->created_at?->timezone(config('app.timezone'))->format('d M Y'),
        ];
    }
}
