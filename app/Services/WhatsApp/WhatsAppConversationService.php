<?php

namespace App\Services\WhatsApp;

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
        $body = trim($body);
        if ($body === '') {
            return ['ok' => false, 'message' => null, 'error' => 'Message body is required.', 'conversation' => $conversation];
        }

        $lead = $conversation->lead;
        $rendered = $this->templates->render($body, $workspace, $lead);

        $provider = $this->integrations->whatsappProvider($workspace);

        $delivery = match ($provider) {
            'sandbox' => [
                'ok' => true,
                'id' => 'sandbox_wa_'.Str::lower(Str::random(10)),
                'error' => null,
                'conversation_id' => null,
            ],
            'meta' => $this->meta->sendText($workspace, $conversation->phone, $rendered, $template, $asTemplate),
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
            'meta' => [
                'provider' => $provider,
                'as_template' => $asTemplate,
                'external_conversation_id' => $delivery['conversation_id'] ?? null,
            ],
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
            'status' => 'open',
        ]);

        return $msg;
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

        return $created;
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
