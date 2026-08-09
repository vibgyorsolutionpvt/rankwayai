<?php

namespace App\Services\Billing;

use App\Models\AiUsageLog;
use App\Models\ChannelCampaign;
use App\Models\Workspace;
use App\Models\WorkspaceSubscription;
use Carbon\CarbonInterface;

class UsageMeterService
{
    public const HISTORY_TODAY = 'today';

    public const HISTORY_7D = '7d';

    public const HISTORY_30D = '30d';

    public function __construct(private CreditWalletService $wallet) {}

    /**
     * Client-facing usage vs plan limits for the current month.
     *
     * @return array{
     *   period:string,
     *   plan:string,
     *   ai:array{
     *     used:int,
     *     limit:int,
     *     topup:int,
     *     available:int,
     *     pct:float,
     *     unit:string,
     *     label:string,
     *     allowed:bool
     *   },
     *   channel_sends:array{used:int,limit:int,pct:float,label:string,allowed:bool}
     * }
     */
    public function forWorkspace(Workspace $workspace, WorkspaceSubscription $subscription): array
    {
        $limits = $subscription->limits ?? [];
        $sendLimit = (int) ($limits['channel_sends_month'] ?? 0);
        $aiAllowed = (bool) ($limits['ai'] ?? false);
        $apiAllowed = (bool) ($limits['api'] ?? false);

        $snap = $this->wallet->snapshot($workspace, $subscription);
        // Purchased top-up credits unlock the full product (same as paid plan access).
        if ($snap['topup'] > 0) {
            $aiAllowed = true;
            $apiAllowed = true;
        }
        $periodStart = now()->startOfMonth();

        $sendsUsed = (int) ChannelCampaign::query()
            ->where('workspace_id', $workspace->id)
            ->where('created_at', '>=', $periodStart)
            ->sum('sent_count');

        $planLimit = $snap['plan_limit'];
        $planUsed = $snap['plan_used'];
        $available = $snap['available'];

        return [
            'period' => $periodStart->format('F Y'),
            'plan' => $subscription->plan,
            'ai' => [
                'used' => $planUsed,
                'limit' => $planLimit,
                'topup' => $snap['topup'],
                'available' => $available,
                'pct' => $planLimit > 0 ? min(100, round(($planUsed / $planLimit) * 100, 1)) : 0,
                'unit' => 'credits',
                'label' => 'AI credits',
                'allowed' => $aiAllowed,
            ],
            'channel_sends' => [
                'used' => $sendsUsed,
                'limit' => $sendLimit,
                'pct' => $sendLimit > 0 ? min(100, round(($sendsUsed / $sendLimit) * 100, 1)) : 0,
                'label' => 'Channel sends',
                'allowed' => $apiAllowed,
            ],
        ];
    }

    /**
     * AI usage history for a period with per-member totals.
     *
     * @return array{
     *   period:string,
     *   label:string,
     *   from:string,
     *   totals:array{credits:int,tokens:int,events:int},
     *   members:list<array{user_id:?int,name:string,email:?string,credits:int,tokens:int,events:int}>,
     *   activities:list<array{action:string,member:string,credits:int,tokens:int,at:?string}>
     * }
     */
    public function aiHistory(Workspace $workspace, string $period = self::HISTORY_7D): array
    {
        $period = $this->normalizeHistoryPeriod($period);
        $from = $this->historyStart($period);
        $tz = config('app.timezone');

        $logs = AiUsageLog::query()
            ->with(['user:id,name,email'])
            ->where('workspace_id', $workspace->id)
            ->where('created_at', '>=', $from)
            ->latest()
            ->limit(150)
            ->get();

        $memberMap = [];
        $totalCredits = 0;
        $totalTokens = 0;

        foreach ($logs as $row) {
            $credits = CreditPackCatalog::costToCredits((float) $row->cost_usd);
            $tokens = (int) $row->tokens;
            $totalCredits += $credits;
            $totalTokens += $tokens;

            $key = $row->user_id ?: 0;
            if (! isset($memberMap[$key])) {
                $memberMap[$key] = [
                    'user_id' => $row->user_id,
                    'name' => $row->user?->name ?: 'System / unknown',
                    'email' => $row->user?->email,
                    'credits' => 0,
                    'tokens' => 0,
                    'events' => 0,
                ];
            }
            $memberMap[$key]['credits'] += $credits;
            $memberMap[$key]['tokens'] += $tokens;
            $memberMap[$key]['events']++;
        }

        $members = collect($memberMap)
            ->sortByDesc('credits')
            ->values()
            ->all();

        $activities = $logs->take(50)->map(fn (AiUsageLog $row) => [
            'action' => $row->action,
            'member' => $row->user?->name ?: 'System / unknown',
            'credits' => CreditPackCatalog::costToCredits((float) $row->cost_usd),
            'tokens' => (int) $row->tokens,
            'at' => $row->created_at?->timezone($tz)->format('d M, g:i A'),
        ])->all();

        return [
            'period' => $period,
            'label' => $this->historyLabel($period),
            'from' => $from->timezone($tz)->format('d M Y'),
            'totals' => [
                'credits' => $totalCredits,
                'tokens' => $totalTokens,
                'events' => $logs->count(),
            ],
            'members' => $members,
            'activities' => $activities,
        ];
    }

    public function normalizeHistoryPeriod(string $period): string
    {
        return match ($period) {
            self::HISTORY_TODAY, '1d' => self::HISTORY_TODAY,
            self::HISTORY_30D, '30' => self::HISTORY_30D,
            default => self::HISTORY_7D,
        };
    }

    private function historyStart(string $period): CarbonInterface
    {
        return match ($period) {
            self::HISTORY_TODAY => now()->startOfDay(),
            self::HISTORY_30D => now()->subDays(29)->startOfDay(),
            default => now()->subDays(6)->startOfDay(),
        };
    }

    private function historyLabel(string $period): string
    {
        return match ($period) {
            self::HISTORY_TODAY => 'Today',
            self::HISTORY_30D => 'Last 30 days',
            default => 'Last 7 days',
        };
    }
}
