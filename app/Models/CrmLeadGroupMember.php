<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\Pivot;

class CrmLeadGroupMember extends Pivot
{
    use HasUuids;

    protected $table = 'crm_lead_group_members';
}
