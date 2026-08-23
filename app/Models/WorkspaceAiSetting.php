<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceAiSetting extends Model
{
    protected $fillable = [
        'workspace_id',
        'monthly_budget_usd',
        'spent_usd',
        'topup_credits',
        'template_first',
        'tone',
        'caption_word_limit',
        'industry',
        'location',
        'hashtag_packs',
        'auto_daily_posts',
    ];

    protected function casts(): array
    {
        return [
            'monthly_budget_usd' => 'decimal:2',
            'spent_usd' => 'decimal:4',
            'topup_credits' => 'integer',
            'template_first' => 'boolean',
            'auto_daily_posts' => 'boolean',
            'hashtag_packs' => 'array',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function remainingBudget(): float
    {
        $plan = max(0, (float) $this->monthly_budget_usd - (float) $this->spent_usd);
        $topupUsd = ((int) ($this->topup_credits ?? 0)) / 100;

        return $plan + $topupUsd;
    }

    public function canSpend(float $cost): bool
    {
        return $this->remainingBudget() >= $cost;
    }
}
