<?php

namespace App\Console\Commands;

use App\Jobs\SendChannelCampaignJob;
use App\Models\ChannelCampaign;
use Illuminate\Console\Command;

class SendDueChannelCampaignsCommand extends Command
{
    protected $signature = 'channels:send-due';

    protected $description = 'Queue send jobs for due scheduled WhatsApp/Email campaigns';

    public function handle(): int
    {
        $due = ChannelCampaign::query()
            ->where('status', 'scheduled')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->limit(50)
            ->get();

        foreach ($due as $campaign) {
            SendChannelCampaignJob::dispatch($campaign->id);
            $this->line('Queued campaign #'.$campaign->id);
        }

        $this->info('Queued '.$due->count().' campaign(s)');

        return self::SUCCESS;
    }
}
