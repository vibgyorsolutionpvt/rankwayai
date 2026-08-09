<?php

namespace App\Services\Channels;

use App\Models\ChannelCampaign;
use App\Models\ChannelCampaignRecipient;
use App\Models\CrmLead;
use App\Models\Workspace;
use App\Services\Channels\Rcs\RcsDeliveryService;
use App\Services\Channels\Rcs\RcsProviderCatalog;
use App\Services\Integrations\WorkspaceIntegrationService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ChannelCampaignService
{
    public function __construct(
        private readonly ChannelTemplateService $templates,
        private readonly RcsDeliveryService $rcs,
        private readonly SmtpDeliveryService $smtp,
        private readonly WorkspaceIntegrationService $integrations,
        private readonly \App\Services\WhatsApp\MetaWhatsAppCloudService $metaWhatsApp,
    ) {}

    /**
     * Resolve delivery provider for a channel.
     * Email: SMTP → Zavu → sandbox.
     * WhatsApp: Meta Cloud API → Zavu → sandbox.
     */
    public function provider(?Workspace $workspace = null, string $channel = 'whatsapp'): string
    {
        if ($channel === 'email' && $workspace && $this->integrations->hasSmtp($workspace)) {
            return 'smtp';
        }

        if ($channel === 'whatsapp' && $workspace) {
            return $this->integrations->whatsappProvider($workspace);
        }

        if ($workspace && filled($this->integrations->zavuKey($workspace))) {
            return 'zavu';
        }

        return filled(config('services.zavu.key')) ? 'zavu' : 'sandbox';
    }

    /**
     * @return array{whatsapp:string,email:string}
     */
    public function messagingProviders(?Workspace $workspace = null): array
    {
        return [
            'whatsapp' => $this->provider($workspace, 'whatsapp'),
            'email' => $this->provider($workspace, 'email'),
        ];
    }

    /**
     * @return list<array{id:string,label:string,ready:bool,driver:string}>
     */
    public function rcsProviders(?Workspace $workspace = null): array
    {
        return RcsProviderCatalog::available($workspace);
    }

    /**
     * @param  list<int>|null  $leadIds
     */
    public function create(
        Workspace $workspace,
        int $userId,
        array $data,
        ?array $leadIds = null
    ): ChannelCampaign {
        $provider = $data['channel'] === 'rcs'
            ? RcsProviderCatalog::normalize($data['rcs_provider'] ?? null, $workspace)
            : $this->provider($workspace, $data['channel']);

        $campaign = ChannelCampaign::query()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $userId,
            'name' => $data['name'],
            'channel' => $data['channel'],
            'subject' => $data['subject'] ?? null,
            'body' => $data['body'],
            'status' => ($data['scheduled_at'] ?? null) ? 'scheduled' : 'draft',
            'scheduled_at' => $data['scheduled_at'] ?? null,
            'provider' => $provider,
        ]);

        $this->attachRecipients($campaign, $leadIds);

        return $campaign->fresh('recipients');
    }

    /**
     * @param  list<int>|null  $leadIds
     */
    public function attachRecipients(ChannelCampaign $campaign, ?array $leadIds = null): void
    {
        $query = CrmLead::query()->where('workspace_id', $campaign->workspace_id);

        if ($leadIds) {
            $query->whereIn('id', $leadIds);
        } else {
            $query->whereIn('stage', ['new', 'contacted', 'qualified']);
        }

        $leads = $query->get();
        $count = 0;

        foreach ($leads as $lead) {
            $to = $lead->destinationFor($campaign->channel);
            if (blank($to)) {
                continue;
            }

            ChannelCampaignRecipient::query()->create([
                'channel_campaign_id' => $campaign->id,
                'crm_lead_id' => $lead->id,
                'to' => $to,
                'status' => 'pending',
            ]);
            $count++;
        }

        $campaign->update(['recipient_count' => $count]);
    }

    /**
     * @return array{ok:bool, message:string, campaign:ChannelCampaign}
     */
    public function send(ChannelCampaign $campaign): array
    {
        $campaign->loadMissing('recipients');

        if ($campaign->recipients->isEmpty()) {
            $campaign->update([
                'status' => 'failed',
                'failure_reason' => 'No recipients with a valid '.$campaign->channel.' destination.',
            ]);

            return ['ok' => false, 'message' => $campaign->failure_reason, 'campaign' => $campaign];
        }

        if ($campaign->channel === 'rcs') {
            $workspace = $campaign->workspace ?? Workspace::query()->find($campaign->workspace_id);
            $campaign->update([
                'status' => 'sending',
                'provider' => RcsProviderCatalog::normalize($campaign->provider, $workspace),
            ]);
        } else {
            $workspace = $campaign->workspace ?? Workspace::query()->find($campaign->workspace_id);
            $campaign->update([
                'status' => 'sending',
                'provider' => $this->provider($workspace, $campaign->channel),
            ]);
        }

        $sent = 0;
        $failed = 0;

        foreach ($campaign->recipients->where('status', 'pending') as $recipient) {
            $result = $this->deliver($campaign->fresh(), $recipient);
            if ($result['ok']) {
                $recipient->update([
                    'status' => 'sent',
                    'provider_message_id' => $result['id'],
                    'sent_at' => now(),
                    'error_message' => null,
                ]);
                $sent++;
                if ($recipient->crm_lead_id) {
                    CrmLead::query()->whereKey($recipient->crm_lead_id)->update([
                        'last_contacted_at' => now(),
                        'stage' => 'contacted',
                    ]);
                }
            } else {
                $recipient->update([
                    'status' => 'failed',
                    'error_message' => $result['error'],
                ]);
                $failed++;
            }
        }

        $campaign->update([
            'sent_count' => $sent,
            'failed_count' => $failed,
            'sent_at' => now(),
            'status' => $failed > 0 && $sent === 0 ? 'failed' : 'sent',
            'failure_reason' => $failed > 0 && $sent === 0 ? 'All recipients failed' : null,
        ]);

        return [
            'ok' => $sent > 0,
            'message' => "Sent {$sent}, failed {$failed} via {$campaign->fresh()->provider}",
            'campaign' => $campaign->fresh(),
        ];
    }

    /**
     * @return array{ok:bool, id:?string, error:?string}
     */
    private function deliver(ChannelCampaign $campaign, ChannelCampaignRecipient $recipient): array
    {
        $campaign->loadMissing('workspace', 'recipients.lead');
        $lead = $recipient->lead ?? ($recipient->crm_lead_id
            ? CrmLead::query()->find($recipient->crm_lead_id)
            : null);
        $workspace = $campaign->workspace ?? Workspace::query()->find($campaign->workspace_id);
        $body = $workspace
            ? $this->templates->render($campaign->body, $workspace, $lead)
            : $campaign->body;
        $subject = $campaign->subject && $workspace
            ? $this->templates->render($campaign->subject, $workspace, $lead)
            : $campaign->subject;

        if ($campaign->channel === 'rcs') {
            return $this->rcs->send(
                (string) $campaign->provider,
                (string) $recipient->to,
                $body,
                [
                    'atlas_campaign_id' => (string) $campaign->id,
                    'atlas_recipient_id' => (string) $recipient->id,
                ],
                $workspace
            );
        }

        $provider = $this->provider($workspace, $campaign->channel);

        if ($provider === 'sandbox') {
            return [
                'ok' => true,
                'id' => 'sandbox_'.Str::lower(Str::random(12)),
                'error' => null,
            ];
        }

        if ($campaign->channel === 'email' && $provider === 'smtp' && $workspace) {
            return $this->smtp->send(
                $workspace,
                (string) $recipient->to,
                (string) ($subject ?: 'Message'),
                $body
            );
        }

        if ($campaign->channel === 'whatsapp' && $provider === 'meta' && $workspace) {
            $result = $this->metaWhatsApp->sendText($workspace, (string) $recipient->to, $body);

            return [
                'ok' => $result['ok'],
                'id' => $result['id'],
                'error' => $result['error'],
            ];
        }

        $payload = [
            'to' => $recipient->to,
            'channel' => $campaign->channel,
            'text' => $body,
            'idempotencyKey' => 'camp_'.$campaign->id.'_rec_'.$recipient->id,
        ];

        if ($campaign->channel === 'email' && filled($subject)) {
            $payload['subject'] = $subject;
        }

        try {
            $response = Http::withToken($this->integrations->zavuKey($workspace))
                ->timeout(20)
                ->acceptJson()
                ->post(rtrim($this->integrations->zavuBaseUrl($workspace), '/').'/v1/messages', $payload);

            if ($response->successful()) {
                $id = $response->json('id')
                    ?? $response->json('message.id')
                    ?? 'zavu_'.Str::random(8);

                return ['ok' => true, 'id' => (string) $id, 'error' => null];
            }

            return [
                'ok' => false,
                'id' => null,
                'error' => Str::limit($response->json('error.message') ?? $response->body(), 240),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'id' => null, 'error' => Str::limit($e->getMessage(), 240)];
        }
    }
}
