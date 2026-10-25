<?php

namespace App\Services\Billing;

use App\Models\AiUsageLog;
use App\Models\ChannelCampaign;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

class WhatsAppCreditService
{
    public const CREDITS_PER_MESSAGE = 1;

    public const CREDITS_PER_TEMPLATE = 5;

    /** Campaigns above this many recipients pay half rate. */
    public const BULK_THRESHOLD = 2000;

    public const BULK_RATE = 0.5;

    public const OUT_OF_CREDITS_MESSAGE = 'Not enough credits to send WhatsApp messages. Recharge from Billing.';

    private const ACCEPTED_STATUSES = ['sent', 'delivered', 'read'];

    public function __construct(private CreditWalletService $wallet) {}

    public function creditsFor(int $messages): int
    {
        return max(0, $messages) * self::CREDITS_PER_MESSAGE;
    }

    public function isBulk(int $recipients): bool
    {
        return $recipients > self::BULK_THRESHOLD;
    }

    public function isBulkCampaign(ChannelCampaign $campaign): bool
    {
        return $this->isBulk((int) $campaign->recipient_count);
    }

    public function campaignCreditsFor(int $messages, bool $bulk): int
    {
        $rate = self::CREDITS_PER_MESSAGE * ($bulk ? self::BULK_RATE : 1);

        return (int) ceil(max(0, $messages) * $rate);
    }

    public function available(Workspace $workspace): int
    {
        return (int) $this->wallet->snapshot($workspace)['available'];
    }

    public function canSend(Workspace $workspace, int $messages = 1): bool
    {
        return $this->available($workspace) >= $this->creditsFor($messages);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function charge(Workspace $workspace, ?string $userId, string $action, int $messages, array $meta = []): void
    {
        $this->spendCredits($workspace, $userId, $action, $this->creditsFor($messages), array_merge(['messages' => $messages], $meta));
    }

    public function acceptedCount(ChannelCampaign $campaign): int
    {
        return $campaign->recipients()->whereIn('status', self::ACCEPTED_STATUSES)->count();
    }

    /**
     * Bring the campaign's charged credits in line with messages Meta accepted:
     * charges new successes and refunds messages that later failed.
     */
    public function settleCampaign(Workspace $workspace, ChannelCampaign $campaign): void
    {
        DB::transaction(function () use ($workspace, $campaign) {
            $locked = ChannelCampaign::query()->lockForUpdate()->find($campaign->id);
            if (! $locked) {
                return;
            }

            $accepted = $this->acceptedCount($locked);
            $bulk = $this->isBulkCampaign($locked);
            $target = $this->campaignCreditsFor($accepted, $bulk);
            $delta = $target - (int) $locked->credits_charged;
            if ($delta === 0) {
                return;
            }

            $meta = [
                'campaign_id' => $locked->id,
                'campaign_name' => $locked->name,
                'bulk_rate' => $bulk,
            ];

            if ($delta > 0) {
                $this->spendCredits($workspace, $locked->created_by, 'whatsapp_campaign', $delta, $meta);
            } else {
                $this->refundCredits($workspace, $locked->created_by, -$delta, $meta);
            }

            $locked->update(['credits_charged' => $target]);
            $campaign->credits_charged = $target;
        });
    }

    public function canSubmitTemplate(Workspace $workspace): bool
    {
        return $this->available($workspace) >= self::CREDITS_PER_TEMPLATE;
    }

    public function templateCreditError(Workspace $workspace): string
    {
        return 'Submitting a template to Meta costs '.self::CREDITS_PER_TEMPLATE.' credits, but only '
            .number_format($this->available($workspace)).' are available. Recharge from Billing.';
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function chargeTemplate(Workspace $workspace, ?string $userId, array $meta = []): void
    {
        $this->spendCredits($workspace, $userId, 'whatsapp_template', self::CREDITS_PER_TEMPLATE, $meta);
    }

    /**
     * Give back credits for messages Meta accepted but later reported as failed.
     *
     * @param  array<string, mixed>  $meta
     */
    public function refund(Workspace $workspace, ?string $userId, int $messages, array $meta = []): void
    {
        $this->refundCredits($workspace, $userId, $this->creditsFor($messages), array_merge(['messages' => $messages], $meta));
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function spendCredits(Workspace $workspace, ?string $userId, string $action, int $credits, array $meta): void
    {
        if ($credits <= 0) {
            return;
        }

        $costUsd = CreditPackCatalog::creditsToUsd($credits);

        AiUsageLog::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $userId,
            'action' => $action,
            'provider' => 'whatsapp',
            'tokens' => 0,
            'cost_usd' => $costUsd,
            'meta' => $meta,
        ]);

        $this->wallet->spend($workspace, $costUsd);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function refundCredits(Workspace $workspace, ?string $userId, int $credits, array $meta): void
    {
        if ($credits <= 0) {
            return;
        }

        AiUsageLog::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $userId,
            'action' => 'whatsapp_refund',
            'provider' => 'whatsapp',
            'tokens' => 0,
            'cost_usd' => -CreditPackCatalog::creditsToUsd($credits),
            'meta' => $meta,
        ]);

        $this->wallet->refund($workspace, $credits);
    }
}
