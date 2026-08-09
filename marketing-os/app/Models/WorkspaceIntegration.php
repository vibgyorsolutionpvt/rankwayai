<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceIntegration extends Model
{
    protected $fillable = [
        'workspace_id',
        'category',
        'provider',
        'enabled',
        'status',
        'credentials',
        'meta',
        'last_error',
        'connected_at',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'credentials' => 'encrypted:array',
            'meta' => 'array',
            'connected_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function credential(string $key, mixed $default = null): mixed
    {
        $creds = $this->credentials ?? [];

        return $creds[$key] ?? $default;
    }

    /**
     * Safe payload for Inertia (secrets masked).
     *
     * @return array<string, mixed>
     */
    public function toClientArray(array $fieldDefs): array
    {
        $creds = $this->credentials ?? [];
        $fields = [];

        foreach ($fieldDefs as $field) {
            $key = $field['key'];
            $secret = (bool) ($field['secret'] ?? false);
            $value = $creds[$key] ?? null;
            $fields[$key] = [
                'configured' => filled($value),
                'hint' => $secret
                    ? (filled($value) ? '••••'.substr((string) $value, -4) : null)
                    : $value,
            ];
        }

        return [
            'id' => $this->id,
            'category' => $this->category,
            'provider' => $this->provider,
            'enabled' => (bool) $this->enabled,
            'status' => $this->status,
            'connected' => $this->status === 'connected' && $this->enabled,
            'fields' => $fields,
            'last_error' => $this->last_error,
            'connected_at' => $this->connected_at?->timezone(config('app.timezone'))->format('d M Y, g:i A'),
            'updated_at' => $this->updated_at?->timezone(config('app.timezone'))->format('d M Y, g:i A'),
        ];
    }
}
