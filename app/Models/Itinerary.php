<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Itinerary extends Model
{
    use HasUuids;

    protected $fillable = [
        'workspace_id',
        'created_by',
        'brand_kit_id',
        'title',
        'status',
        'destination',
        'starting_city',
        'duration_days',
        'travel_start',
        'travel_end',
        'adults',
        'children',
        'budget_band',
        'hotel_category',
        'transport',
        'interests',
        'meal_preference',
        'special_requirements',
        'overview',
        'days',
        'inclusions',
        'exclusions',
        'pricing',
        'currency',
        'generation_source',
        'share_token',
        'is_public',
    ];

    protected function casts(): array
    {
        return [
            'travel_start' => 'date',
            'travel_end' => 'date',
            'interests' => 'array',
            'days' => 'array',
            'inclusions' => 'array',
            'exclusions' => 'array',
            'pricing' => 'array',
            'is_public' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Itinerary $itinerary): void {
            if (blank($itinerary->share_token)) {
                $itinerary->share_token = Str::lower(Str::random(40));
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function travellersCount(): int
    {
        return max(1, (int) $this->adults + (int) $this->children);
    }
}
