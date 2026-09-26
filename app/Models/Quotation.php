<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Quotation extends Model
{
    use HasUuids;

    protected $fillable = [
        'workspace_id',
        'created_by',
        'itinerary_id',
        'crm_lead_id',
        'brand_kit_id',
        'number',
        'title',
        'status',
        'customer_name',
        'customer_email',
        'customer_phone',
        'customer_company',
        'trip_title',
        'destination',
        'travellers',
        'duration_days',
        'currency',
        'line_items',
        'subtotal',
        'discount_amount',
        'tax_percent',
        'tax_amount',
        'total',
        'payment_terms',
        'valid_until',
        'notes',
        'inclusions',
        'exclusions',
        'share_token',
        'is_public',
        'views_count',
        'downloads_count',
    ];

    protected function casts(): array
    {
        return [
            'line_items' => 'array',
            'inclusions' => 'array',
            'exclusions' => 'array',
            'subtotal' => 'float',
            'discount_amount' => 'float',
            'tax_percent' => 'float',
            'tax_amount' => 'float',
            'total' => 'float',
            'valid_until' => 'date',
            'is_public' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Quotation $quotation): void {
            if (blank($quotation->share_token)) {
                $quotation->share_token = Str::lower(Str::random(40));
            }
        });
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function brandKit(): BelongsTo
    {
        return $this->belongsTo(BrandKit::class);
    }

    public function itinerary(): BelongsTo
    {
        return $this->belongsTo(Itinerary::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(CrmLead::class, 'crm_lead_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isPublished(): bool
    {
        return $this->is_public && in_array($this->status, ['sent', 'accepted'], true);
    }

    public function publicUrl(): string
    {
        return url('/q/'.$this->share_token);
    }

    public function resolveBrandKit(): ?BrandKit
    {
        return $this->brandKit ?: $this->workspace?->resolveBrandKit();
    }
}
