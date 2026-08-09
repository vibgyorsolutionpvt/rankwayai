<?php

namespace App\Services\WhatsApp;

use App\Models\ChannelMessageTemplate;
use App\Models\Workspace;
use App\Services\Integrations\WorkspaceIntegrationService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class MetaWhatsAppCloudService
{
    public function __construct(private WorkspaceIntegrationService $integrations) {}

    /**
     * @return array{ok:bool, id:?string, error:?string, conversation_id:?string}
     */
    public function sendText(
        Workspace $workspace,
        string $to,
        string $text,
        ?ChannelMessageTemplate $template = null,
        bool $asTemplate = false
    ): array {
        $cfg = $this->integrations->whatsappMetaConfig($workspace);
        if (! $cfg) {
            return ['ok' => false, 'id' => null, 'error' => 'Meta WhatsApp Cloud API is not configured.', 'conversation_id' => null];
        }

        $toDigits = preg_replace('/\D+/', '', $to) ?: $to;
        $url = sprintf(
            'https://graph.facebook.com/%s/%s/messages',
            ltrim($cfg['api_version'], '/'),
            $cfg['phone_number_id']
        );

        if ($asTemplate && $template) {
            $payload = [
                'messaging_product' => 'whatsapp',
                'to' => $toDigits,
                'type' => 'template',
                'template' => [
                    'name' => $template->name,
                    'language' => [
                        'code' => $template->language ?: 'en',
                    ],
                ],
            ];
        } else {
            $payload = [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $toDigits,
                'type' => 'text',
                'text' => [
                    'preview_url' => false,
                    'body' => $text,
                ],
            ];
        }

        try {
            $response = Http::withToken($cfg['access_token'])
                ->timeout(20)
                ->acceptJson()
                ->post($url, $payload);

            if ($response->successful()) {
                $id = $response->json('messages.0.id')
                    ?? $response->json('messages.0.message_id')
                    ?? 'wamid_'.Str::random(10);

                return [
                    'ok' => true,
                    'id' => (string) $id,
                    'error' => null,
                    'conversation_id' => $response->json('contacts.0.wa_id'),
                ];
            }

            $error = $response->json('error.message')
                ?? $response->json('error.error_user_msg')
                ?? $response->body();

            return [
                'ok' => false,
                'id' => null,
                'error' => Str::limit((string) $error, 240),
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

    /**
     * Flatten Meta webhook payload into inbound message rows.
     *
     * @param  array<string, mixed>  $payload
     * @return list<array{from:string,text:string,id:?string,contact_name:?string}>
     */
    public function parseInbound(array $payload): array
    {
        $out = [];
        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];
                if (($change['field'] ?? '') !== 'messages' && empty($value['messages'])) {
                    continue;
                }

                $names = [];
                foreach ($value['contacts'] ?? [] as $contact) {
                    $waId = (string) ($contact['wa_id'] ?? '');
                    if ($waId !== '') {
                        $names[$waId] = $contact['profile']['name'] ?? null;
                    }
                }

                foreach ($value['messages'] ?? [] as $message) {
                    $from = (string) ($message['from'] ?? '');
                    $type = (string) ($message['type'] ?? 'text');
                    $text = match ($type) {
                        'text' => (string) ($message['text']['body'] ?? ''),
                        'button' => (string) ($message['button']['text'] ?? $message['button']['payload'] ?? ''),
                        'interactive' => (string) (
                            $message['interactive']['button_reply']['title']
                            ?? $message['interactive']['list_reply']['title']
                            ?? ''
                        ),
                        default => '['.$type.']',
                    };

                    if ($from === '' || $text === '') {
                        continue;
                    }

                    $out[] = [
                        'from' => str_starts_with($from, '+') ? $from : '+'.$from,
                        'text' => $text,
                        'id' => isset($message['id']) ? (string) $message['id'] : null,
                        'contact_name' => $names[$from] ?? $names[ltrim($from, '+')] ?? null,
                    ];
                }
            }
        }

        return $out;
    }

    public function verifySignature(Workspace $workspace, string $rawBody, ?string $signatureHeader): bool
    {
        $cfg = $this->integrations->whatsappMetaConfig($workspace);
        $secret = $cfg['app_secret'] ?? null;
        if (blank($secret)) {
            // No secret configured — allow (dev) but prefer setting app_secret in production.
            return true;
        }

        if (blank($signatureHeader) || ! str_starts_with($signatureHeader, 'sha256=')) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $rawBody, (string) $secret);

        return hash_equals($expected, $signatureHeader);
    }
}
