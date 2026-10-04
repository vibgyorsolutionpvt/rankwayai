<?php

namespace App\Jobs;

use App\Models\ChannelCampaign;
use App\Services\Channels\ChannelCampaignService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendChannelCampaignJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 80;

    public function __construct(public int $campaignId) {}

    public function handle(ChannelCampaignService $service): void
    {
        $campaign = ChannelCampaign::query()->find($this->campaignId);
        if (! $campaign || in_array($campaign->status, ['sent', 'cancelled'], true)) {
            return;
        }

        $service->send($campaign, 25);

        $campaign->refresh();
        if (
            $campaign->status === 'sending'
            && $campaign->recipients()->where('status', 'pending')->exists()
        ) {
            self::dispatch($campaign->id);
        }
    }
}
