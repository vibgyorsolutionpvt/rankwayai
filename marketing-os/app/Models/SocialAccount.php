<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialAccount extends Model
{
    protected $fillable = [
        'workspace_id',
        'platform',
        'account_type',
        'connection_mode',
        'account_name',
        'status',
        'external_id',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'connected_at',
        'health',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'connected_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function markConnected(?string $externalId = null): void
    {
        $this->update([
            'status' => 'connected',
            'health' => 'healthy',
            'last_error' => null,
            'external_id' => $externalId ?: ($this->external_id ?: 'stub_'.uniqid()),
            'access_token' => 'stub_access_'.bin2hex(random_bytes(16)),
            'refresh_token' => 'stub_refresh_'.bin2hex(random_bytes(12)),
            'token_expires_at' => now()->addDays(60),
            'connected_at' => now(),
        ]);
    }

    public function markDisconnected(?string $error = null): void
    {
        $this->update([
            'status' => 'disconnected',
            'health' => $error ? 'error' : 'unknown',
            'last_error' => $error,
            'access_token' => null,
            'refresh_token' => null,
            'token_expires_at' => null,
        ]);
    }
}
