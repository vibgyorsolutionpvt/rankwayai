<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class PlatformSetting extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    protected $fillable = [
        'key',
        'value',
    ];

    public static function getValue(string $key, ?string $default = null): ?string
    {
        $all = static::cached();

        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public static function setValue(string $key, ?string $value): void
    {
        static::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );

        Cache::forget('platform_settings');
    }

    /** @return array<string, ?string> */
    public static function cached(): array
    {
        return Cache::remember('platform_settings', 300, function () {
            return static::query()->pluck('value', 'key')->all();
        });
    }
}
