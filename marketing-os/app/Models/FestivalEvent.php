<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FestivalEvent extends Model
{
    protected $fillable = [
        'name',
        'occurs_on',
        'region',
        'category',
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
