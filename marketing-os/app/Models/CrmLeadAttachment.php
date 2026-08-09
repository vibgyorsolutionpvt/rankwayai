<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class CrmLeadAttachment extends Model
{
    protected $fillable = [
        'workspace_id',
        'crm_lead_id',
        'uploaded_by',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size',
        'kind',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
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

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public static function kindFromMime(?string $mime, ?string $extension = null): string
    {
        $mime = strtolower((string) $mime);
        $extension = strtolower((string) $extension);

        if (str_starts_with($mime, 'image/') || in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
            return 'image';
        }

        if ($mime === 'application/pdf' || $extension === 'pdf') {
            return 'pdf';
        }

        if (
            in_array($extension, ['xls', 'xlsx', 'csv'], true)
            || str_contains($mime, 'spreadsheet')
            || str_contains($mime, 'excel')
            || $mime === 'text/csv'
        ) {
            return 'spreadsheet';
        }

        return 'file';
    }

    public function url(): ?string
    {
        if (! $this->path) {
            return null;
        }

        return Storage::disk($this->disk)->url($this->path);
    }

    /**
     * @return array<string, mixed>
     */
    public function toClientArray(): array
    {
        return [
            'id' => $this->id,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            'kind' => $this->kind,
            'url' => $this->url(),
            'uploaded_by' => $this->uploader?->name,
            'created_at' => $this->created_at?->timezone(config('app.timezone'))->format('d M Y, g:i A'),
        ];
    }
}
