<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class MediaAsset extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'workspace_id',
        'uploaded_by',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size',
        'folder',
        'tags',
        'variants',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'variants' => 'array',
            'size' => 'integer',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function url(?string $variant = null): ?string
    {
        $path = $variant && ! empty($this->variants[$variant])
            ? $this->variants[$variant]
            : $this->path;

        if (! $path) {
            return null;
        }

        return Storage::disk($this->disk)->url($path);
    }

    public function toLibraryArray(): array
    {
        return [
            'id' => $this->id,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            'folder' => $this->folder ?: 'Unsorted',
            'tags' => $this->tags ?? [],
            'status' => $this->status ?? 'ready',
            'url' => $this->url(),
            'thumb_url' => $this->url('thumb') ?: $this->url(),
            'medium_url' => $this->url('medium') ?: $this->url(),
            'cdn_url' => $this->url(),
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}
