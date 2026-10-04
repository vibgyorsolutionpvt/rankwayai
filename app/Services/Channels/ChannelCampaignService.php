<?php

namespace App\Services\Channels;

use App\Models\ChannelCampaign;
use App\Models\ChannelCampaignRecipient;
use App\Models\CrmLead;
use App\Models\WhatsappConversation;
use App\Models\Workspace;
use App\Services\Channels\Rcs\RcsDeliveryService;
use App\Services\Channels\Rcs\RcsProviderCatalog;
use App\Services\Integrations\WorkspaceIntegrationService;
use App\Services\WhatsApp\MetaWhatsAppCloudService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ChannelCampaignService
{
    public const LEAD_STAGES = ['new', 'contacted', 'qualified', 'won', 'lost'];

    public const DEFAULT_AUDIENCE_STAGES = ['new', 'contacted', 'qualified'];

    public function __construct(
        private readonly ChannelTemplateService $templates,
        private readonly RcsDeliveryService $rcs,
        private readonly SmtpDeliveryService $smtp,
        private readonly WorkspaceIntegrationService $integrations,
        private readonly MetaWhatsAppCloudService $metaWhatsApp,
    ) {}

    /**
     * Resolve delivery provider for a channel.
     * Email: SMTP → Zavu → sandbox.
     * WhatsApp: workspace Meta Cloud API → workspace Zavu → none (blocked).
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
            'whatsapp_template_id' => $data['whatsapp_template_id'] ?? null,
            'crm_lead_group_id' => $data['crm_lead_group_id'] ?? null,
            'subject' => $data['subject'] ?? null,
            'body' => $data['body'],
            'status' => ($data['scheduled_at'] ?? null) ? 'scheduled' : 'draft',
            'scheduled_at' => $data['scheduled_at'] ?? null,
            'provider' => $provider,
        ]);

        $this->attachRecipients($campaign, $leadIds, $data['audience'] ?? []);

        return $campaign->fresh();
    }

    /**
     * @param  list<int>|null  $leadIds
     * @param  array{stages?:list<string>, sources?:list<string>, custom_field?:?string, custom_value?:?string}  $filters
     */
    public function attachRecipients(ChannelCampaign $campaign, ?array $leadIds = null, array $filters = []): void
    {
        $count = 0;
        $batch = [];
        $flush = function () use (&$batch, &$count) {
            if ($batch !== []) {
                ChannelCampaignRecipient::query()->insert($batch);
                $count += count($batch);
                $batch = [];
            }
        };

        foreach ($this->audience($campaign->workspace_id, $campaign->channel, $campaign->crm_lead_group_id, $leadIds, $filters) as [$lead, $to, $skip]) {
            if ($skip !== null) {
                continue;
            }
            $now = now();
            $batch[] = [
                'channel_campaign_id' => $campaign->id,
                'crm_lead_id' => $lead->id,
                'to' => $to,
                'status' => 'pending',
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if (count($batch) >= 500) {
                $flush();
            }
        }
        $flush();

        $campaign->update(['recipient_count' => $count]);
    }

    /**
     * @param  list<int>|null  $leadIds
     * @param  array{stages?:list<string>, sources?:list<string>, custom_field?:?string, custom_value?:?string}  $filters
     * @return array{matched:int, recipients:int, duplicates:int, opted_out:int}
     */
    public function audienceSummary(int $workspaceId, string $channel, ?int $groupId, ?array $leadIds, array $filters = []): array
    {
        $summary = ['matched' => 0, 'recipients' => 0, 'duplicates' => 0, 'opted_out' => 0];
        foreach ($this->audience($workspaceId, $channel, $groupId, $leadIds, $filters) as [, , $skip]) {
            $summary['matched']++;
            match ($skip) {
                null => $summary['recipients']++,
                'duplicate' => $summary['duplicates']++,
                'opted_out' => $summary['opted_out']++,
            };
        }

        return $summary;
    }

    /**
     * Every lead with a destination, flagged when it repeats an earlier number/email
     * or has opted out of WhatsApp. "All leads" mode defaults to open pipeline stages.
     *
     * @param  list<int>|null  $leadIds
     * @param  array{stages?:list<string>, sources?:list<string>, custom_field?:?string, custom_value?:?string}  $filters
     * @return \Generator<int, array{0:CrmLead, 1:string, 2:?string}>
     */
    private function audience(int $workspaceId, string $channel, ?int $groupId, ?array $leadIds, array $filters): \Generator
    {
        $column = $channel === 'email' ? 'email' : 'phone';
        $query = CrmLead::query()
            ->where('workspace_id', $workspaceId)
            ->whereNotNull($column)
            ->where($column, '!=', '');

        if ($groupId) {
            $query->whereHas('groups', fn ($groups) => $groups->whereKey($groupId));
        } elseif ($leadIds) {
            $query->whereIn('id', $leadIds);
        } else {
            $stages = array_key_exists('stages', $filters)
                ? array_values(array_intersect((array) $filters['stages'], self::LEAD_STAGES))
                : self::DEFAULT_AUDIENCE_STAGES;
            $query->whereIn('stage', $stages);

            $sources = array_values(array_filter((array) ($filters['sources'] ?? []), 'filled'));
            if ($sources !== []) {
                $query->whereIn('source', $sources);
            }

            $field = (string) ($filters['custom_field'] ?? '');
            $value = trim((string) ($filters['custom_value'] ?? ''));
            if ($value !== '' && preg_match('/^[a-z0-9_]{1,60}$/', $field)) {
                $query->where('custom_fields->'.$field, $value);
            }
        }

        $optedOut = $channel === 'whatsapp'
            ? WhatsappConversation::query()
                ->where('workspace_id', $workspaceId)
                ->where('opted_out_until', '>', now())
                ->pluck('phone')
                ->mapWithKeys(fn ($phone) => [$this->destinationKey('whatsapp', (string) $phone) => true])
                ->all()
            : [];

        $seen = [];
        foreach ($query->select(['id', 'phone', 'email'])->lazyById(1000) as $lead) {
            $to = (string) $lead->destinationFor($channel);
            $key = $this->destinationKey($channel, $to);
            if ($key === '') {
                continue;
            }

            if (isset($seen[$key])) {
                yield [$lead, $to, 'duplicate'];
            } elseif (isset($optedOut[$key])) {
                $seen[$key] = true;
                yield [$lead, $to, 'opted_out'];
            } else {
                $seen[$key] = true;
                yield [$lead, $to, null];
            }
        }
    }

    private function destinationKey(string $channel, string $to): string
    {
        return $channel === 'email'
            ? strtolower(trim($to))
            : (preg_replace('/\D+/', '', $to) ?: '');
    }

    /**
     * @return array{ok:bool, message:string, campaign:ChannelCampaign}
     */
    public function send(ChannelCampaign $campaign, ?int $recipientLimit = null): array
    {
        $pendingQuery = $campaign->recipients()
            ->where('status', 'pending')
            ->orderBy('id');
        if (! $campaign->recipients()->exists()) {
            $campaign->update([
                'status' => 'failed',
                'failure_reason' => 'No recipients with a valid '.$campaign->channel.' destination.',
            ]);

            return ['ok' => false, 'message' => $campaign->failure_reason, 'campaign' => $campaign];
        }

        if ($campaign->channel === 'whatsapp') {
            $workspace = $campaign->workspace ?? Workspace::query()->find($campaign->workspace_id);
            if (! $workspace || ! $this->integrations->whatsappConnected($workspace)) {
                $campaign->update([
                    'status' => 'failed',
                    'provider' => 'none',
                    'failure_reason' => WorkspaceIntegrationService::whatsappNotConnectedMessage(),
                ]);

                return ['ok' => false, 'message' => $campaign->failure_reason, 'campaign' => $campaign->fresh()];
            }
            if ($campaign->whatsapp_template_id && $this->provider($workspace, 'whatsapp') !== 'meta') {
                $campaign->update([
                    'status' => 'failed',
                    'failure_reason' => 'Approved WhatsApp template campaigns require the Meta Cloud API provider.',
                ]);

                return ['ok' => false, 'message' => $campaign->failure_reason, 'campaign' => $campaign->fresh()];
            }
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

        $pendingRecipients = $pendingQuery
            ->when($recipientLimit !== null, fn ($query) => $query->limit(max(1, $recipientLimit)))
            ->get();
        foreach ($pendingRecipients as $recipient) {
            $result = $this->deliver($campaign, $recipient);
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

        $sent = $campaign->recipients()->whereIn('status', ['sent', 'delivered', 'read'])->count();
        $failed = $campaign->recipients()->where('status', 'failed')->count();
        $hasPending = $campaign->recipients()->where('status', 'pending')->exists();
        $campaign->update([
            'sent_count' => $sent,
            'failed_count' => $failed,
            'sent_at' => $hasPending ? null : now(),
            'status' => $hasPending ? 'sending' : ($failed > 0 && $sent === 0 ? 'failed' : 'sent'),
            'failure_reason' => ! $hasPending && $failed > 0 && $sent === 0 ? 'All recipients failed' : null,
        ]);

        return [
            'ok' => $hasPending || $sent > 0,
            'message' => $hasPending
                ? "Campaign in progress: {$sent} sent, {$failed} failed"
                : "Sent {$sent}, failed {$failed} via {$campaign->fresh()->provider}",
            'campaign' => $campaign->fresh(),
        ];
    }

    /**
     * @return array{ok:bool, id:?string, error:?string}
     */
    private function deliver(ChannelCampaign $campaign, ChannelCampaignRecipient $recipient): array
    {
        $campaign->loadMissing('workspace');
        $recipient->loadMissing('lead');
        $lead = $recipient->lead;
        $workspace = $campaign->workspace ?? Workspace::query()->find($campaign->workspace_id);
        if (
            $campaign->channel === 'whatsapp'
            && $workspace
            && WhatsappConversation::phoneIsOptedOut($workspace->id, (string) $recipient->to)
        ) {
            return [
                'ok' => false,
                'id' => null,
                'error' => 'Recipient opted out of WhatsApp messages for one month.',
            ];
        }

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

        if ($campaign->channel === 'whatsapp' && $provider === 'none') {
            return ['ok' => false, 'id' => null, 'error' => WorkspaceIntegrationService::whatsappNotConnectedMessage()];
        }

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
            $template = null;
            $bodyParams = [];
            $headerParams = [];
            if ($campaign->whatsapp_template_id) {
                $template = $campaign->whatsappTemplate;
                if (
                    ! $template
                    || $template->workspace_id !== $campaign->workspace_id
                    || $template->channel !== 'whatsapp'
                    || $template->wa_status !== 'approved'
                ) {
                    return [
                        'ok' => false,
                        'id' => null,
                        'error' => 'The selected WhatsApp template is no longer available or approved.',
                    ];
                }

                $tokenMap = $this->templates->tokens($workspace, null, $lead);
                $bodyParams = $this->metaWhatsApp->resolveBodyParamValues($template->body, $tokenMap);
                $headerText = $template->components['header']['text'] ?? '';
                if (is_string($headerText) && $headerText !== '') {
                    $headerParams = $this->metaWhatsApp->resolveBodyParamValues($headerText, $tokenMap);
                }
            }

            $result = $this->metaWhatsApp->sendText(
                $workspace,
                (string) $recipient->to,
                $body,
                $template,
                $template !== null,
                $bodyParams,
                $headerParams,
            );

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
