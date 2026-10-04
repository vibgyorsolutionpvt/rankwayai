<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class CrmLeadGroup extends Model
{
    protected $fillable = [
        'workspace_id',
        'created_by',
        'name',
        'status',
        'total_rows',
        'created_count',
        'updated_count',
        'skipped_count',
        'error_message',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function leads(): BelongsToMany
    {
        return $this->belongsToMany(CrmLead::class, 'crm_lead_group_members')
            ->withTimestamps();
    }
}
