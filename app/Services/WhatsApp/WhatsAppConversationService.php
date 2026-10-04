<?php

namespace App\Services\WhatsApp;

use App\Models\ChannelCampaign;
use App\Models\ChannelCampaignRecipient;
use App\Models\ChannelMessageTemplate;
use App\Models\CrmLead;
use App\Models\User;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use App\Models\Workspace;
use App\Services\Channels\ChannelTemplateService;
use App\Services\Integrations\WorkspaceIntegrationService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class WhatsAppConversationService
{
    public function __construct(
        private WorkspaceIntegrationService $integrations,
        private ChannelTemplateService $templates,
        private MetaWhatsAppCloudService $meta,
    ) {}

    public function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?: '';
        if ($digits === '') {
            return trim($phone);
        }

        return str_starts_with($phone, '+') ? '+'.$digits : '+'.$digits;
    }

    public function findOrCreate(
        Workspace $workspace,
        string $phone,
        ?string $contactName = null,
        ?int $crmLeadId = null
    ): WhatsappConversation {
        $phone = $this->normalizePhone($phone);

        $conversation = WhatsappConversation::query()->firstOrNew([
            'workspace_id' => $workspace->id,
            'phone' => $phone,
        ]);

        if (! $conversation->exists) {
            $lead = $crmLeadId
                ? CrmLead::query()->where('workspace_id', $workspace->id)->whereKey($crmLeadId)->first()
                : CrmLead::query()
                    ->where('workspace_id', $workspace->id)
                    ->where(function ($q) use ($phone) {
                        $q->where('phone', $phone)
                            ->orWhere('phone', ltrim($phone, '+'));
                    })
                    ->first();

            $conversation->fill([
                'crm_lead_id' => $lead?->id,
                'contact_name' => $contactName ?: $lead?->name,
                'status' => 'open',
                'unread_count' => 0,
            ]);
            $conversation->save();
        } else {
            $dirty = false;
            if ($contactName && blank($conversation->contact_name)) {
                $conversation->contact_name = $contactName;
                $dirty = true;
            }
            if ($crmLeadId && ! $conversation->crm_lead_id) {
                $conversation->crm_lead_id = $crmLeadId;
                $dirty = true;
            }
            if ($dirty) {
                $conversation->save();
            }
        }

        return $conversation->fresh();
    }

    /**
     * @return array{ok:bool, message:?WhatsappMessage, error:?string, conversation:WhatsappConversation}
     */
    public function sendOutbound(
        Workspace $workspace,
        WhatsappConversation $conversation,
        string $body,
        ?User $user = null,
        ?ChannelMessageTemplate $template = null,
        bool $asTemplate = false
    ): array {
        if (WhatsappConversation::phoneIsOptedOut($workspace->id, $conversation->phone)) {
            return [
                'ok' => false,
                'message' => null,
                'error' => 'This contact opted out of WhatsApp messages. Sending is paused for one month.',
                'conversation' => $conversation,
            ];
        }

        $body = trim($body);
        if ($body === '' && ! ($asTemplate && $template)) {
            return ['ok' => false, 'message' => null, 'error' => 'Message body is required.', 'conversation' => $conversation];
        }

        $provider = $this->integrations->whatsappProvider($workspace);
        if ($provider === 'none') {
            return [
                'ok' => false,
                'message' => null,
                'error' => WorkspaceIntegrationService::whatsappNotConnectedMessage(),
                'conversation' => $conversation,
            ];
        }

        $lead = $conversation->lead;
        $sourceBody = ($asTemplate && $template) ? (string) $template->body : $body;
        if ($sourceBody === '') {
            $sourceBody = $body;
        }
        $rendered = $this->templates->render($sourceBody !== '' ? $sourceBody : ' ', $workspace, $lead);
        $tokenMap = $this->templates->tokens($workspace, null, $lead);
        if (filled($conversation->contact_name)) {
            $tokenMap['name'] = (string) $conversation->contact_name;
        }

        $bodyParams = [];
        if ($asTemplate && $template) {
            $bodyParams = $this->meta->resolveBodyParamValues((string) $template->body, $tokenMap);
        }

        $delivery = match ($provider) {
            'meta' => $this->meta->sendText(
                $workspace,
                $conversation->phone,
                $rendered,
                $template,
                $asTemplate,
                $bodyParams
            ),
            default => $this->deliverViaZavu($workspace, $conversation->phone, $rendered, $template, $asTemplate),
        };

        $msg = WhatsappMessage::query()->create([
            'whatsapp_conversation_id' => $conversation->id,
            'user_id' => $user?->id,
            'direction' => 'outbound',
            'body' => $rendered,
            'status' => $delivery['ok'] ? 'sent' : 'failed',
            'provider_message_id' => $delivery['id'],
            'template_name' => $template?->name,
            'meta' => array_filter([
                'provider' => $provider,
                'as_template' => $asTemplate,
                'external_conversation_id' => $delivery['conversation_id'] ?? null,
                'error' => $delivery['error_meta'] ?? null,
            ], fn ($v) => $v !== null),
            'error_message' => $delivery['error'],
            'sent_at' => now(),
        ]);

        $conversation->update([
            'last_message_preview' => Str::limit($rendered, 140),
            'last_message_at' => now(),
            'status' => 'open',
            'external_conversation_id' => $delivery['conversation_id']
                ?? $conversation->external_conversation_id,
        ]);

        return [
            'ok' => $delivery['ok'],
            'message' => $msg,
            'error' => $delivery['error'],
            'conversation' => $conversation->fresh(),
        ];
    }

    /**
     * Campaign recipients only get an inbox thread once Meta confirms delivery.
     */
    public function recordCampaignMessage(
        Workspace $workspace,
        ChannelCampaign $campaign,
        ChannelCampaignRecipient $recipient,
        string $status
    ): WhatsappMessage {
        $recipient->loadMissing('lead');
        $lead = $recipient->lead;
        $body = $this->templates->render((string) $campaign->body, $workspace, $lead);
        $conversation = $this->findOrCreate(
            $workspace,
            (string) $recipient->to,
            $lead?->name,
            $recipient->crm_lead_id
        );

        $msg = WhatsappMessage::query()->create([
            'whatsapp_conversation_id' => $conversation->id,
            'user_id' => $campaign->created_by,
            'channel_campaign_id' => $campaign->id,
            'direction' => 'outbound',
            'body' => $body,
            'status' => $status,
            'provider_message_id' => $recipient->provider_message_id,
            'template_name' => $campaign->whatsappTemplate?->name,
            'meta' => [
                'campaign_id' => $campaign->id,
                'campaign_name' => $campaign->name,
            ],
            'sent_at' => $recipient->sent_at ?? now(),
        ]);

        $conversation->update(array_filter([
            'last_message_preview' => Str::limit($body, 140),
            'last_message_at' => $recipient->sent_at ?? now(),
            'status' => 'open',
            'assigned_user_id' => $conversation->assigned_user_id ?: $campaign->created_by,
        ], fn ($v) => $v !== null));

        return $msg;
    }

    public function assignIfUnassigned(WhatsappConversation $conversation, ?User $user): void
    {
        if ($user && ! $conversation->assigned_user_id) {
            $conversation->update(['assigned_user_id' => $user->id]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function ingestInbound(Workspace $workspace, array $payload): ?WhatsappMessage
    {
        $from = (string) (
            $payload['from']
            ?? $payload['data']['from']
            ?? $payload['message']['from']
            ?? ''
        );
        $text = (string) (
            $payload['text']
            ?? $payload['data']['text']
            ?? $payload['message']['text']
            ?? ''
        );
        $providerId = $payload['id']
            ?? $payload['data']['id']
            ?? $payload['message']['id']
            ?? null;
        $externalConv = $payload['conversationId']
            ?? $payload['data']['conversationId']
            ?? $payload['message']['conversationId']
            ?? null;

        if (blank($from) || blank($text)) {
            return null;
        }

        if ($providerId) {
            $existing = WhatsappMessage::query()->where('provider_message_id', (string) $providerId)->first();
            if ($existing) {
                return $existing;
            }
        }

        $name = $payload['contact_name']
            ?? $payload['data']['contact_name']
            ?? $payload['message']['fromName']
            ?? null;

        $conversation = $this->findOrCreate($workspace, $from, is_string($name) ? $name : null);
        if ($externalConv) {
            $conversation->update(['external_conversation_id' => (string) $externalConv]);
        }

        $msg = WhatsappMessage::query()->create([
            'whatsapp_conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'body' => $text,
            'status' => 'received',
            'provider_message_id' => $providerId ? (string) $providerId : null,
            'meta' => ['raw' => $payload],
            'sent_at' => now(),
        ]);

        $conversation->update([
            'last_message_preview' => Str::limit($text, 140),
            'last_message_at' => now(),
            'unread_count' => $conversation->unread_count + 1,
            'window_expires_at' => now()->addHours(24),
            'opted_out_until' => $this->isOptOutKeyword($text)
                ? now()->addMonth()
                : $conversation->opted_out_until,
            'status' => 'open',
        ]);

        return $msg;
    }

    private function isOptOutKeyword(string $text): bool
    {
        return preg_match('/^(?:STOP(?:\s+ALL)?|UNSUBSCRIBE|CANCEL|END|QUIT)[.!?\s]*$/i', trim($text)) === 1;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<WhatsappMessage>
     */
    public function ingestMetaWebhook(Workspace $workspace, array $payload): array
    {
        $created = [];
        foreach ($this->meta->parseInbound($payload) as $row) {
            $msg = $this->ingestInbound($workspace, $row);
            if ($msg) {
                $created[] = $msg;
            }
        }

        foreach ($this->meta->parseStatuses($payload) as $status) {
            $this->applyDeliveryStatus($workspace, $status);
        }

        return $created;
    }

    /**
     * @param  array{id:string,status:string,error:?string,error_meta:?array<string, mixed>}  $status
     */
    public function applyDeliveryStatus(Workspace $workspace, array $status): void
    {
        $rank = ['sent' => 1, 'delivered' => 2, 'read' => 3];
        $next = $status['status'];

        $message = WhatsappMessage::query()
            ->where('provider_message_id', $status['id'])
            ->whereHas('conversation', fn ($q) => $q->where('workspace_id', $workspace->id))
            ->first();

        if ($message && $message->direction === 'outbound') {
            if ($next === 'failed') {
                $message->update([
                    'status' => 'failed',
                    'error_message' => $status['error'],
                    'meta' => array_merge($message->meta ?? [], ['delivery_error' => $status['error_meta']]),
                ]);
            } elseif (($rank[$next] ?? 0) > ($rank[$message->status] ?? 0)) {
                $message->update(['status' => $next, 'error_message' => null]);
            }
        }

        $recipient = ChannelCampaignRecipient::query()
            ->where('provider_message_id', $status['id'])
            ->whereHas('campaign', fn ($q) => $q->where('workspace_id', $workspace->id))
            ->first();

        if ($recipient) {
            if ($next === 'failed') {
                $recipient->update(['status' => 'failed', 'error_message' => $status['error']]);
            } elseif (($rank[$next] ?? 0) > ($rank[$recipient->status] ?? 0)) {
                $recipient->update(['status' => $next, 'error_message' => null]);
            }

            if ($recipient->wasChanged('status') && $campaign = $recipient->campaign) {
                $campaign->update([
                    'sent_count' => $campaign->recipients()->whereIn('status', ['sent', 'delivered', 'read'])->count(),
                    'failed_count' => $campaign->recipients()->where('status', 'failed')->count(),
                ]);
            }

            if (! $message && in_array($recipient->status, ['delivered', 'read'], true) && $recipient->campaign) {
                $this->recordCampaignMessage($workspace, $recipient->campaign, $recipient, $recipient->status);
            }
        }
    }

    public function markRead(WhatsappConversation $conversation): void
    {
        if ($conversation->unread_count > 0) {
            $conversation->update(['unread_count' => 0]);
        }
    }

    /**
     * @return array{ok:bool, id:?string, error:?string, conversation_id:?string}
     */
    private function deliverViaZavu(
        Workspace $workspace,
        string $to,
        string $text,
        ?ChannelMessageTemplate $template,
        bool $asTemplate
    ): array {
        $templateComponents = $template?->components ?? [];
        $headerFormat = $templateComponents['header']['format'] ?? 'NONE';
        if (
            $asTemplate
            && (
                $headerFormat !== 'NONE'
                || ! empty($templateComponents['buttons'])
            )
        ) {
            return [
                'ok' => false,
                'id' => null,
                'error' => 'Advanced WhatsApp template components require the Meta Cloud API provider.',
                'conversation_id' => null,
            ];
        }
        if ($asTemplate && filled($templateComponents['footer'] ?? null)) {
            $text = rtrim($text)."\n\n".trim((string) $templateComponents['footer']);
        }

        $key = $this->integrations->zavuKey($workspace);
        if (blank($key)) {
            return ['ok' => false, 'id' => null, 'error' => 'Zavu API key missing.', 'conversation_id' => null];
        }

        $payload = [
            'to' => $to,
            'channel' => 'whatsapp',
            'text' => $text,
            'idempotencyKey' => 'wa_'.Str::uuid()->toString(),
            'metadata' => [
                'workspace_id' => (string) $workspace->id,
            ],
        ];

        if ($asTemplate && $template) {
            $payload['messageType'] = 'template';
            $payload['content'] = [
                'templateId' => $template->name,
            ];
        }

        try {
            $response = Http::withToken($key)
                ->timeout(20)
                ->acceptJson()
                ->post(rtrim($this->integrations->zavuBaseUrl($workspace), '/').'/v1/messages', $payload);

            if ($response->successful()) {
                return [
                    'ok' => true,
                    'id' => (string) (
                        $response->json('message.id')
                        ?? $response->json('id')
                        ?? 'zavu_'.Str::random(8)
                    ),
                    'error' => null,
                    'conversation_id' => $response->json('message.conversationId')
                        ?? $response->json('conversationId'),
                ];
            }

            return [
                'ok' => false,
                'id' => null,
                'error' => Str::limit($response->json('error.message') ?? $response->body(), 240),
                'conversation_id' => null,
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'id' => null,
                'error' => Str::limit($e->getMessage(), 240),
                'conversation_id' => null,
            ];
        }
    }
}
