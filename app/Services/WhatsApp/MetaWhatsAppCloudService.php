<?php

namespace App\Services\WhatsApp;

use App\Models\ChannelMessageTemplate;
use App\Models\Workspace;
use App\Services\Integrations\WorkspaceIntegrationService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MetaWhatsAppCloudService
{
    public function __construct(private WorkspaceIntegrationService $integrations) {}

    /**
     * @param  list<string>  $bodyParamValues  Ordered text values for {{1}}, {{2}}, ...
     * @return array{ok:bool, id:?string, error:?string, conversation_id:?string, error_meta?:array<string, mixed>|null}
     */
    public function sendText(
        Workspace $workspace,
        string $to,
        string $text,
        ?ChannelMessageTemplate $template = null,
        bool $asTemplate = false,
        array $bodyParamValues = [],
    ): array {
        $cfg = $this->integrations->whatsappMetaConfig($workspace);
        if (! $cfg) {
            $this->logFailure($workspace, $to, [
                'reason' => 'not_configured',
                'why' => 'META_WA_* env or workspace whatsapp_meta credentials missing.',
            ]);

            return [
                'ok' => false,
                'id' => null,
                'error' => 'Meta WhatsApp Cloud API is not configured.',
                'conversation_id' => null,
                'error_meta' => ['reason' => 'not_configured'],
            ];
        }

        $toDigits = preg_replace('/\D+/', '', $to) ?: $to;
        $url = sprintf(
            'https://graph.facebook.com/%s/%s/messages',
            ltrim($cfg['api_version'], '/'),
            $cfg['phone_number_id']
        );

        if ($asTemplate && $template) {
            $templatePayload = [
                'name' => $template->name,
                'language' => [
                    'code' => $this->normalizeTemplateLanguage($template->language ?: 'en_US'),
                ],
            ];

            $params = [];
            foreach ($bodyParamValues as $value) {
                $params[] = [
                    'type' => 'text',
                    'text' => (string) ($value !== '' ? $value : '—'),
                ];
            }
            // If caller didn't pass values, derive count from local body placeholders.
            if ($params === [] && preg_match_all('/\{\{\s*[a-z0-9_]+\s*\}\}/i', $template->body ?? '') > 0) {
                $count = preg_match_all('/\{\{\s*[a-z0-9_]+\s*\}\}/i', $template->body ?? '');
                for ($i = 0; $i < $count; $i++) {
                    $params[] = ['type' => 'text', 'text' => 'Customer'];
                }
            }
            if ($params !== []) {
                $templatePayload['components'] = [
                    [
                        'type' => 'body',
                        'parameters' => $params,
                    ],
                ];
            }

            $payload = [
                'messaging_product' => 'whatsapp',
                'to' => $toDigits,
                'type' => 'template',
                'template' => $templatePayload,
            ];
        } elseif ($asTemplate) {
            // No local template selected — use Meta's default test template so the
            // message actually delivers (free-form text often will not outside a session).
            $payload = [
                'messaging_product' => 'whatsapp',
                'to' => $toDigits,
                'type' => 'template',
                'template' => [
                    'name' => 'hello_world',
                    'language' => [
                        'code' => 'en_US',
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

                Log::channel('whatsapp')->info('whatsapp.meta.send.ok', [
                    'workspace_id' => $workspace->id,
                    'to' => $this->maskPhone($toDigits),
                    'phone_number_id' => $cfg['phone_number_id'],
                    'api_version' => $cfg['api_version'],
                    'provider_message_id' => $id,
                    'as_template' => $asTemplate,
                    'payload_type' => $payload['type'] ?? null,
                    'template_name' => $payload['template']['name'] ?? null,
                ]);

                return [
                    'ok' => true,
                    'id' => (string) $id,
                    'error' => null,
                    'conversation_id' => $response->json('contacts.0.wa_id'),
                    'error_meta' => null,
                ];
            }

            $errorBody = $response->json('error') ?? [];
            $code = $errorBody['code'] ?? $response->json('error.code');
            $subcode = $errorBody['error_subcode'] ?? $response->json('error.error_subcode');
            $type = $errorBody['type'] ?? $response->json('error.type');
            $fbtrace = $errorBody['fbtrace_id'] ?? $response->json('error.fbtrace_id');
            $message = (string) (
                $errorBody['message']
                ?? $errorBody['error_user_msg']
                ?? $response->json('error.message')
                ?? $response->body()
            );

            $why = $this->explainError($code, $message, $response->status());
            $display = $this->formatDisplayError($code, $message, $why);

            $errorMeta = [
                'http_status' => $response->status(),
                'code' => $code,
                'error_subcode' => $subcode,
                'type' => $type,
                'fbtrace_id' => $fbtrace,
                'message' => $message,
                'why' => $why,
                'phone_number_id' => $cfg['phone_number_id'],
                'api_version' => $cfg['api_version'],
                'to' => $this->maskPhone($toDigits),
                'as_template' => $asTemplate,
            ];

            $this->logFailure($workspace, $toDigits, $errorMeta);

            return [
                'ok' => false,
                'id' => null,
                'error' => Str::limit($display, 400),
                'conversation_id' => null,
                'error_meta' => $errorMeta,
            ];
        } catch (\Throwable $e) {
            $errorMeta = [
                'reason' => 'exception',
                'message' => $e->getMessage(),
                'why' => 'Network or server exception while calling Meta Graph API.',
                'to' => $this->maskPhone($toDigits),
            ];
            $this->logFailure($workspace, $toDigits, $errorMeta);

            return [
                'ok' => false,
                'id' => null,
                'error' => Str::limit($e->getMessage(), 240),
                'conversation_id' => null,
                'error_meta' => $errorMeta,
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

    /**
     * Create a WhatsApp message template on the connected WABA (App Review / management).
     *
     * @return array{ok:bool, id:?string, status:?string, error:?string}
     */
    public function createMessageTemplate(
        Workspace $workspace,
        string $name,
        string $body,
        string $category = 'UTILITY',
        string $language = 'en_US',
    ): array {
        $cfg = $this->integrations->whatsappMetaConfig($workspace);
        $wabaId = $cfg['waba_id'] ?? null;
        if (! $cfg || blank($wabaId)) {
            return [
                'ok' => false,
                'id' => null,
                'status' => null,
                'error' => 'Meta WABA ID missing. Set META_WA_WABA_ID in .env.',
            ];
        }

        $name = $this->normalizeTemplateName($name);
        $language = $this->normalizeTemplateLanguage($language);
        $category = strtoupper($category);
        if (! in_array($category, ['UTILITY', 'MARKETING', 'AUTHENTICATION'], true)) {
            $category = 'UTILITY';
        }

        $url = sprintf(
            'https://graph.facebook.com/%s/%s/message_templates',
            ltrim($cfg['api_version'], '/'),
            $wabaId
        );

        $payload = [
            'name' => $name,
            'language' => $language,
            'category' => $category,
            'allow_category_change' => true,
            'components' => $this->bodyToMetaComponents($body),
        ];

        try {
            $response = Http::withToken($cfg['access_token'])
                ->timeout(30)
                ->acceptJson()
                ->post($url, $payload);

            if ($response->successful()) {
                $id = $response->json('id');
                $status = $response->json('status') ?? 'PENDING';

                Log::channel('whatsapp')->info('whatsapp.meta.template.create.ok', [
                    'workspace_id' => $workspace->id,
                    'waba_id' => $wabaId,
                    'name' => $name,
                    'meta_template_id' => $id,
                    'status' => $status,
                ]);

                return [
                    'ok' => true,
                    'id' => $id ? (string) $id : null,
                    'status' => is_string($status) ? $status : 'PENDING',
                    'error' => null,
                ];
            }

            $message = (string) (
                $response->json('error.message')
                ?? $response->json('error.error_user_msg')
                ?? $response->body()
            );
            $code = $response->json('error.code');

            Log::channel('whatsapp')->warning('whatsapp.meta.template.create.failed', [
                'workspace_id' => $workspace->id,
                'waba_id' => $wabaId,
                'name' => $name,
                'http_status' => $response->status(),
                'code' => $code,
                'message' => $message,
                'body' => Str::limit($response->body(), 500),
            ]);

            return [
                'ok' => false,
                'id' => null,
                'status' => null,
                'error' => Str::limit((filled($code) ? '(#'.$code.') ' : '').$message, 400),
            ];
        } catch (\Throwable $e) {
            Log::channel('whatsapp')->warning('whatsapp.meta.template.create.failed', [
                'workspace_id' => $workspace->id,
                'reason' => 'exception',
                'message' => $e->getMessage(),
            ]);

            return [
                'ok' => false,
                'id' => null,
                'status' => null,
                'error' => Str::limit($e->getMessage(), 240),
            ];
        }
    }

    /**
     * Fetch current Meta review status for a template ID.
     *
     * @return array{ok:bool, status:?string, name:?string, language:?string, error:?string}
     */
    public function retrieveTemplateStatus(Workspace $workspace, string $metaTemplateId): array
    {
        $cfg = $this->integrations->whatsappMetaConfig($workspace);
        if (! $cfg || blank($metaTemplateId)) {
            return [
                'ok' => false,
                'status' => null,
                'name' => null,
                'language' => null,
                'error' => 'Meta WhatsApp is not configured.',
            ];
        }

        $url = sprintf(
            'https://graph.facebook.com/%s/%s',
            ltrim($cfg['api_version'], '/'),
            $metaTemplateId
        );

        try {
            $response = Http::withToken($cfg['access_token'])
                ->timeout(20)
                ->acceptJson()
                ->get($url, ['fields' => 'status,name,language,rejected_reason']);

            if ($response->successful()) {
                $status = $response->json('status');

                Log::channel('whatsapp')->info('whatsapp.meta.template.status', [
                    'workspace_id' => $workspace->id,
                    'meta_template_id' => $metaTemplateId,
                    'status' => $status,
                    'name' => $response->json('name'),
                ]);

                return [
                    'ok' => true,
                    'status' => is_string($status) ? $status : null,
                    'name' => $response->json('name'),
                    'language' => $response->json('language'),
                    'error' => null,
                ];
            }

            $message = (string) (
                $response->json('error.message')
                ?? $response->body()
            );

            return [
                'ok' => false,
                'status' => null,
                'name' => null,
                'language' => null,
                'error' => Str::limit($message, 300),
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'status' => null,
                'name' => null,
                'language' => null,
                'error' => Str::limit($e->getMessage(), 240),
            ];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function bodyToMetaComponents(string $body): array
    {
        $examples = [];
        $index = 0;
        $text = preg_replace_callback(
            '/\{\{\s*([a-z0-9_]+)\s*\}\}/i',
            function (array $m) use (&$index, &$examples) {
                $index++;
                $key = strtolower($m[1]);
                $examples[] = match ($key) {
                    'name' => 'Anil',
                    'brand' => 'RankwayAI',
                    'phone' => '9889995999',
                    'cta' => 'Book now',
                    'cta_url' => 'https://rankwayai.com',
                    default => 'Sample'.$index,
                };

                return '{{'.$index.'}}';
            },
            $body
        ) ?? $body;

        $component = [
            'type' => 'BODY',
            'text' => $text,
        ];
        if ($examples !== []) {
            $component['example'] = [
                'body_text' => [$examples],
            ];
        }

        return [$component];
    }

    public function normalizeTemplateName(string $name): string
    {
        $name = strtolower(trim($name));
        $name = preg_replace('/[^a-z0-9_]+/', '_', $name) ?: 'template';
        $name = trim($name, '_');

        return $name !== '' ? $name : 'template';
    }

    /**
     * Map local {{name}} placeholders to ordered Meta body param values.
     *
     * @param  array<string, string>  $tokenMap
     * @return list<string>
     */
    public function resolveBodyParamValues(string $body, array $tokenMap = []): array
    {
        $values = [];
        if (! preg_match_all('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', $body, $matches)) {
            return [];
        }

        foreach ($matches[1] as $rawKey) {
            $key = strtolower((string) $rawKey);
            $value = $tokenMap[$key] ?? match ($key) {
                'name' => 'there',
                'brand' => 'RankwayAI',
                'phone' => '',
                'cta' => 'Get started',
                'cta_url' => '',
                default => '—',
            };
            $values[] = trim((string) $value) !== '' ? trim((string) $value) : '—';
        }

        return $values;
    }

    private function normalizeTemplateLanguage(string $language): string
    {
        $language = trim($language);
        if ($language === '') {
            return 'en_US';
        }
        // Meta expects locales like en_US; local drafts often store "en".
        if (! str_contains($language, '_')) {
            return match (strtolower($language)) {
                'en' => 'en_US',
                'hi' => 'hi_IN',
                default => $language.'_US',
            };
        }

        return $language;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function logFailure(Workspace $workspace, string $to, array $context): void
    {
        Log::channel('whatsapp')->warning('whatsapp.meta.send.failed', array_merge([
            'workspace_id' => $workspace->id,
            'workspace' => $workspace->name,
            'to' => $this->maskPhone($to),
        ], $context));
    }

    private function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?: $phone;
        if (strlen($digits) <= 4) {
            return '***';
        }

        return str_repeat('*', max(0, strlen($digits) - 4)).substr($digits, -4);
    }

    private function explainError(mixed $code, string $message, int $httpStatus): string
    {
        $code = is_numeric($code) ? (int) $code : null;
        $lower = strtolower($message);

        return match (true) {
            $code === 132001 || str_contains($lower, 'does not exist in the translation') => 'Meta pe yeh template name + language exist/approved nahi. Templates tab se Submit to Meta karo, approval ka wait karo, ya Send as template ON karke hello_world use karo (dropdown Free-form). Language en_US hona chahiye jaisa Meta pe hai.',
            $code === 132000 || (str_contains($lower, 'template') && str_contains($lower, 'param')) => 'Template parameters missing/mismatch. Body placeholders Meta template se match hone chahiye.',
            $code === 131030 || str_contains($lower, 'not in allowed list') => 'Test/dev mode: recipient must be added under Meta → WhatsApp → API Setup → To (allowlist). Only allowlisted numbers can receive messages until production.',
            $code === 190 || str_contains($lower, 'authenticat') || str_contains($lower, 'session has expired') || str_contains($lower, 'invalid oauth') => 'Access token invalid or expired. Meta → Step 1 Try it out → Generate access token → update META_WA_ACCESS_TOKEN in .env → php artisan config:clear.',
            $code === 100 && str_contains($lower, 'parameter') => 'Meta rejected the payload (missing/invalid parameter). Check template name/language or phone format (digits only, country code).',
            $code === 131047 || str_contains($lower, 're-engagement') || str_contains($lower, '24 hour') => 'Outside 24h customer-care window — send an approved template message instead of free-form text.',
            $httpStatus === 401 || $httpStatus === 403 => 'Meta rejected credentials (HTTP '.$httpStatus.'). Regenerate access token and confirm phone_number_id belongs to the same app/WABA.',
            default => 'See Meta error message/code above. Check token, phone_number_id, recipient allowlist, and API version.',
        };
    }

    private function formatDisplayError(mixed $code, string $message, string $why): string
    {
        $prefix = filled($code) ? '(#'.$code.') ' : '';

        return trim($prefix.$message).' — '.$why;
    }
}
