<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformMenu extends Model
{
    protected $fillable = [
        'key',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }
}
