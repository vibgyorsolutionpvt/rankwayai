<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class FestivalEvent extends Model
{
    use HasUuids;

    protected $fillable = [
        'name',
        'occurs_on',
        'region',
        'category',
        'source',
        'suggested_angles',
    ];

    protected function casts(): array
    {
        return [
            'occurs_on' => 'date',
            'suggested_angles' => 'array',
        ];
    }
}
