<?php

namespace App\Services\WhatsApp;

use App\Models\ChannelMessageTemplate;
use App\Models\Workspace;
use App\Services\Integrations\WorkspaceIntegrationService;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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
        array $headerParamValues = [],
    ): array {
        $cfg = $this->integrations->whatsappMetaConfig($workspace);
        if (! $cfg) {
            $this->logFailure($workspace, $to, [
                'reason' => 'not_configured',
                'why' => 'Workspace has not saved its own WhatsApp credentials (WhatsApp → Setup).',
            ]);

            return [
                'ok' => false,
                'id' => null,
                'error' => WorkspaceIntegrationService::whatsappNotConnectedMessage(),
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

            $savedComponents = $template->components ?? [];
            $header = $savedComponents['header'] ?? [];
            $headerMessageComponent = null;
            if (($header['format'] ?? null) === 'TEXT' && filled($header['text'] ?? null)) {
                $headerParams = [];
                if (preg_match_all('/\{\{\s*[a-z0-9_]+\s*\}\}/i', $header['text'], $matches)) {
                    foreach ($matches[0] as $index => $_placeholder) {
                        $headerParams[] = [
                            'type' => 'text',
                            'text' => (string) ($headerParamValues[$index] ?? 'Customer'),
                        ];
                    }
                }
                if ($headerParams !== []) {
                    $headerMessageComponent = [
                        'type' => 'header',
                        'parameters' => $headerParams,
                    ];
                }
            } elseif (in_array($header['format'] ?? null, ['IMAGE', 'VIDEO', 'DOCUMENT'], true)) {
                $path = $header['media_path'] ?? null;
                if (! is_string($path) || ! Storage::disk('local')->exists($path)) {
                    return [
                        'ok' => false,
                        'id' => null,
                        'error' => 'The media attached to this template is no longer available. Edit the template and upload the header again.',
                        'conversation_id' => null,
                    ];
                }

                $mediaResult = $this->uploadStoredMessageMedia(
                    $workspace,
                    $path,
                    $header['filename'] ?? basename($path),
                );
                if (! $mediaResult['ok']) {
                    return [
                        'ok' => false,
                        'id' => null,
                        'error' => $mediaResult['error'],
                        'conversation_id' => null,
                    ];
                }

                $headerType = strtolower($header['format']);
                $mediaParameter = ['id' => $mediaResult['id']];
                if ($headerType === 'document') {
                    $mediaParameter['filename'] = $header['filename'] ?? 'document.pdf';
                }
                $headerMessageComponent = [
                    'type' => 'header',
                    'parameters' => [
                        [
                            'type' => $headerType,
                            $headerType => $mediaParameter,
                        ],
                    ],
                ];
                $savedComponents['header']['media_id'] = $mediaResult['id'];
                $template->update(['components' => $savedComponents]);
            }
            if ($headerMessageComponent !== null) {
                $templatePayload['components'] = array_merge(
                    [$headerMessageComponent],
                    $templatePayload['components'] ?? [],
                );
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
            $response = $this->graph($cfg['access_token'], 20)->post($url, $payload);

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

    /**
     * Flatten Meta webhook payload into outbound delivery status rows.
     *
     * @param  array<string, mixed>  $payload
     * @return list<array{id:string,status:string,error:?string,error_meta:?array<string, mixed>}>
     */
    public function parseStatuses(array $payload): array
    {
        $out = [];
        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                foreach ($change['value']['statuses'] ?? [] as $status) {
                    $id = (string) ($status['id'] ?? '');
                    $state = strtolower((string) ($status['status'] ?? ''));
                    if ($id === '' || ! in_array($state, ['sent', 'delivered', 'read', 'failed'], true)) {
                        continue;
                    }

                    $error = null;
                    $errorMeta = null;
                    if ($state === 'failed') {
                        $first = $status['errors'][0] ?? [];
                        $code = $first['code'] ?? null;
                        $message = (string) (
                            $first['error_data']['details']
                            ?? $first['message']
                            ?? $first['title']
                            ?? 'Meta could not deliver this message.'
                        );
                        $error = Str::limit($this->formatDisplayError($code, $message, $this->explainError($code, $message, 200)), 400);
                        $errorMeta = array_filter([
                            'code' => $code,
                            'title' => $first['title'] ?? null,
                            'message' => $message,
                            'href' => $first['href'] ?? null,
                        ], fn ($v) => $v !== null);
                    }

                    $out[] = ['id' => $id, 'status' => $state, 'error' => $error, 'error_meta' => $errorMeta];
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
        array $components = [],
        ?string $headerHandle = null,
    ): array {
        $cfg = $this->integrations->whatsappMetaConfig($workspace);
        $wabaId = $cfg['waba_id'] ?? null;
        if (! $cfg || blank($wabaId)) {
            return [
                'ok' => false,
                'id' => null,
                'status' => null,
                'error' => 'WhatsApp Business Account (WABA) ID missing. Add it in WhatsApp → Setup.',
            ];
        }

        $name = $this->normalizeTemplateName($name);
        $language = $this->normalizeTemplateLanguage($language);
        $category = strtoupper($category);
        if (! in_array($category, ['UTILITY', 'MARKETING', 'AUTHENTICATION'], true)) {
            $category = 'UTILITY';
        }
        $headerFormat = $components['header']['format'] ?? 'NONE';
        if (
            in_array($headerFormat, ['IMAGE', 'VIDEO', 'DOCUMENT'], true)
            && blank($headerHandle)
        ) {
            return [
                'ok' => false,
                'id' => null,
                'status' => null,
                'error' => 'Upload the header media again before submitting this template to Meta.',
            ];
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
            'components' => $this->templateToMetaComponents($body, $components, $headerHandle),
        ];

        try {
            $response = $this->graph($cfg['access_token'], 30)->post($url, $payload);

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
     * Upload one sample file both as a WhatsApp media object for sends and as a
     * resumable upload handle for Meta's template header example.
     *
     * @return array{ok:bool, handle:?string, id:?string, error:?string}
     */
    public function uploadTemplateMedia(Workspace $workspace, UploadedFile $file): array
    {
        $cfg = $this->integrations->whatsappMetaConfig($workspace);
        if (! $cfg || blank($cfg['app_id'] ?? null)) {
            return [
                'ok' => false,
                'handle' => null,
                'id' => null,
                'error' => 'A Meta App ID is required to upload a media header. Add it in WhatsApp setup or configure META_APP_ID.',
            ];
        }

        $mime = (string) $file->getMimeType();
        $size = (int) $file->getSize();
        $name = $file->getClientOriginalName();
        $version = ltrim($cfg['api_version'], '/');
        $base = "https://graph.facebook.com/{$version}";

        try {
            $start = $this->graph($cfg['access_token'], 30)->post(
                "{$base}/{$cfg['app_id']}/uploads?file_length={$size}&file_type=".rawurlencode($mime).'&file_name='.rawurlencode($name)
            );
            if (! $start->successful() || blank($start->json('id'))) {
                return [
                    'ok' => false,
                    'handle' => null,
                    'id' => null,
                    'error' => $this->metaUploadError($start),
                ];
            }

            $fileStream = fopen($file->getRealPath(), 'rb');
            if ($fileStream === false) {
                return [
                    'ok' => false,
                    'handle' => null,
                    'id' => null,
                    'error' => 'Unable to read the uploaded header file.',
                ];
            }

            try {
                $finish = $this->graph($cfg['access_token'], 60)
                    ->withHeaders(['file_offset' => '0'])
                    ->withBody(Utils::streamFor($fileStream), $mime)
                    ->post("{$base}/{$start->json('id')}");
            } finally {
                fclose($fileStream);
            }
            if (! $finish->successful() || blank($finish->json('h'))) {
                return [
                    'ok' => false,
                    'handle' => null,
                    'id' => null,
                    'error' => $this->metaUploadError($finish),
                ];
            }

            $media = $this->uploadWhatsAppMedia(
                $workspace,
                $file->getRealPath(),
                $name,
                $mime,
            );
            if (! $media['ok']) {
                return [
                    'ok' => false,
                    'handle' => null,
                    'id' => null,
                    'error' => $media['error'],
                ];
            }

            return [
                'ok' => true,
                'handle' => (string) $finish->json('h'),
                'id' => $media['id'],
                'error' => null,
            ];
        } catch (\Throwable $e) {
            Log::channel('whatsapp')->warning('whatsapp.meta.template.media_upload.failed', [
                'workspace_id' => $workspace->id,
                'mime_type' => $mime,
                'reason' => $e->getMessage(),
            ]);

            return [
                'ok' => false,
                'handle' => null,
                'id' => null,
                'error' => Str::limit($e->getMessage(), 240),
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $options
     * @return list<array<string, mixed>>
     */
    public function templateToMetaComponents(
        string $body,
        array $options = [],
        ?string $headerHandle = null,
    ): array {
        $components = [];
        $header = $options['header'] ?? [];
        $format = $header['format'] ?? 'NONE';
        if ($format === 'TEXT' && filled($header['text'] ?? null)) {
            $text = $this->replaceNamedPlaceholders($header['text'], $headerExamples);
            $component = ['type' => 'HEADER', 'format' => 'TEXT', 'text' => $text];
            if ($headerExamples !== []) {
                $component['example'] = ['header_text' => $headerExamples];
            }
            $components[] = $component;
        } elseif (in_array($format, ['IMAGE', 'VIDEO', 'DOCUMENT'], true)) {
            if (blank($headerHandle)) {
                throw new \InvalidArgumentException('A Meta media upload handle is required for media headers.');
            }
            $components[] = [
                'type' => 'HEADER',
                'format' => $format,
                'example' => ['header_handle' => [$headerHandle]],
            ];
        }

        array_push($components, ...$this->bodyToMetaComponents($body));

        if (filled($options['footer'] ?? null)) {
            $components[] = [
                'type' => 'FOOTER',
                'text' => trim((string) $options['footer']),
            ];
        }

        $buttons = [];
        foreach (($options['buttons'] ?? []) as $button) {
            $type = strtoupper((string) ($button['type'] ?? ''));
            $item = [
                'type' => $type,
                'text' => trim((string) ($button['text'] ?? '')),
            ];
            if ($type === 'URL') {
                $item['url'] = trim((string) ($button['url'] ?? ''));
            } elseif ($type === 'PHONE_NUMBER') {
                $item['phone_number'] = trim((string) ($button['phone_number'] ?? ''));
            }
            $buttons[] = $item;
        }
        if ($buttons !== []) {
            $components[] = ['type' => 'BUTTONS', 'buttons' => $buttons];
        }

        return $components;
    }

    /**
     * @return array{ok:bool,id:?string,error:?string}
     */
    private function uploadStoredMessageMedia(
        Workspace $workspace,
        string $path,
        string $name,
    ): array {
        $absolutePath = Storage::disk('local')->path($path);
        $mime = mime_content_type($absolutePath) ?: 'application/octet-stream';

        return $this->uploadWhatsAppMedia($workspace, $absolutePath, $name, $mime);
    }

    /**
     * @return array{ok:bool,id:?string,error:?string}
     */
    private function uploadWhatsAppMedia(
        Workspace $workspace,
        string $path,
        string $name,
        string $mime,
    ): array {
        $cfg = $this->integrations->whatsappMetaConfig($workspace);
        if (! $cfg) {
            return ['ok' => false, 'id' => null, 'error' => WorkspaceIntegrationService::whatsappNotConnectedMessage()];
        }

        try {
            $fileStream = fopen($path, 'rb');
            if ($fileStream === false) {
                return ['ok' => false, 'id' => null, 'error' => 'Unable to read the uploaded header file.'];
            }

            try {
                $response = $this->graph($cfg['access_token'], 60)
                    ->attach('file', $fileStream, $name, ['Content-Type' => $mime])
                    ->post(
                        sprintf(
                            'https://graph.facebook.com/%s/%s/media',
                            ltrim($cfg['api_version'], '/'),
                            $cfg['phone_number_id'],
                        ),
                        ['messaging_product' => 'whatsapp', 'type' => $mime],
                    );
            } finally {
                fclose($fileStream);
            }

            if (! $response->successful() || blank($response->json('id'))) {
                return ['ok' => false, 'id' => null, 'error' => $this->metaUploadError($response)];
            }

            return ['ok' => true, 'id' => (string) $response->json('id'), 'error' => null];
        } catch (\Throwable $e) {
            Log::channel('whatsapp')->warning('whatsapp.meta.media_upload.failed', [
                'workspace_id' => $workspace->id,
                'mime_type' => $mime,
                'reason' => $e->getMessage(),
            ]);

            return ['ok' => false, 'id' => null, 'error' => Str::limit($e->getMessage(), 240)];
        }
    }

    private function metaUploadError(Response $response): string
    {
        $message = (string) (
            $response->json('error.message')
            ?? $response->json('error.error_user_msg')
            ?? $response->body()
        );

        return Str::limit($message !== '' ? $message : 'Meta media upload failed.', 400);
    }

    /**
     * @param  list<string>  $examples
     */
    private function replaceNamedPlaceholders(string $text, ?array &$examples = null): string
    {
        $examples = [];
        $index = 0;

        return preg_replace_callback(
            '/\{\{\s*([a-z0-9_]+)\s*\}\}/i',
            function (array $match) use (&$examples, &$index) {
                $index++;
                $examples[] = match (strtolower($match[1])) {
                    'name' => 'Anil',
                    'brand' => 'RankwayAI',
                    'phone' => '9889995999',
                    default => 'Sample'.$index,
                };

                return '{{'.$index.'}}';
            },
            $text,
        ) ?? $text;
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
            $response = $this->graph($cfg['access_token'], 20)
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
     * Confirms the phone number ID + token pair with Meta before the workspace goes live.
     *
     * @return array{ok:bool, reachable:bool, display_phone_number:?string, verified_name:?string, error:?string}
     */
    public function verifyCredentials(string $phoneNumberId, string $accessToken, string $apiVersion = 'v21.0'): array
    {
        $url = sprintf(
            'https://graph.facebook.com/%s/%s',
            ltrim($apiVersion ?: 'v21.0', '/'),
            $phoneNumberId
        );

        try {
            $response = $this->graph($accessToken, 15)->get($url, [
                'fields' => 'display_phone_number,verified_name',
            ]);
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'reachable' => false,
                'display_phone_number' => null,
                'verified_name' => null,
                'error' => Str::limit($e->getMessage(), 240),
            ];
        }

        if ($response->successful()) {
            return [
                'ok' => true,
                'reachable' => true,
                'display_phone_number' => $response->json('display_phone_number'),
                'verified_name' => $response->json('verified_name'),
                'error' => null,
            ];
        }

        $code = $response->json('error.code');
        $message = (string) ($response->json('error.message') ?? $response->body());

        return [
            'ok' => false,
            'reachable' => true,
            'display_phone_number' => null,
            'verified_name' => null,
            'error' => $this->formatDisplayError($code, Str::limit($message, 200), $this->explainError($code, $message, $response->status())),
        ];
    }

    private function graph(string $token, int $timeout): PendingRequest
    {
        return Http::withToken($token)
            ->timeout($timeout)
            ->connectTimeout(15)
            // Shared hosts often hang on AAAA lookups for graph.facebook.com.
            ->withOptions(['force_ip_resolve' => 'v4'])
            ->retry(3, 800, fn ($e) => $this->isPreSendFailure($e), throw: false)
            ->acceptJson();
    }

    /**
     * Only retry when the request never reached Meta, so a message is never sent twice.
     */
    private function isPreSendFailure(\Throwable $e): bool
    {
        if (! $e instanceof ConnectionException) {
            return false;
        }

        $message = strtolower($e->getMessage());

        return str_contains($message, 'resolving timed out')
            || str_contains($message, 'could not resolve host')
            || str_contains($message, 'connection timed out after')
            || str_contains($message, 'failed to connect');
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
            $code === 133010 || str_contains($lower, 'account not registered') => 'Number WABA mein add hai par Cloud API par register nahi hua. POST /{phone_number_id}/register with {"messaging_product":"whatsapp","pin":"<6-digit 2FA PIN>"} ek baar chalao. Display name approved hona chahiye.',
            $code === 131030 || str_contains($lower, 'not in allowed list') => 'Test/dev mode: recipient must be added under Meta → WhatsApp → API Setup → To (allowlist). Only allowlisted numbers can receive messages until production.',
            $code === 190 || str_contains($lower, 'authenticat') || str_contains($lower, 'session has expired') || str_contains($lower, 'invalid oauth') => 'Access token invalid or expired. Generate a permanent System User token (Business Settings → System users → Generate token with whatsapp_business_messaging + whatsapp_business_management) and save it in WhatsApp → Setup.',
            $code === 100 && str_contains($lower, 'parameter') => 'Meta rejected the payload (missing/invalid parameter). Check template name/language or phone format (digits only, country code).',
            $code === 131047 || str_contains($lower, 're-engagement') || str_contains($lower, '24 hour') => 'Outside 24h customer-care window — send an approved template message instead of free-form text.',
            $code === 131042 || str_contains($lower, 'payment') => 'Billing issue on the WhatsApp Business Account. Add a valid payment method in Meta Business Settings → Billing & payments.',
            $code === 131026 || str_contains($lower, 'undeliverable') => 'Recipient cannot receive this message (not on WhatsApp, old app version, or has not accepted the latest terms).',
            $code === 131049 => 'Meta held this marketing message to protect recipient engagement (per-user marketing limit). Try later or use a utility template.',
            $code === 131050 => 'Recipient has stopped marketing messages from this business.',
            $code === 131031 => 'WhatsApp Business Account is locked or restricted. Check WhatsApp Manager → Account quality.',
            $code === 132015 || $code === 132016 => 'Template is paused or disabled by Meta due to low quality. Edit or submit a new template.',
            $code === 130472 => 'Recipient is part of a Meta experiment and did not receive this marketing message.',
            $httpStatus === 401 || $httpStatus === 403 => 'Meta rejected credentials (HTTP '.$httpStatus.'). Regenerate access token and confirm phone_number_id belongs to the same app/WABA.',
            default => 'See Meta error message/code above. Check token, phone_number_id, recipient allowlist, and API version.',
        };
    }

    private function formatDisplayError(mixed $code, string $message, string $why): string
    {
        $prefix = filled($code) && ! str_contains($message, '(#'.$code.')') ? '(#'.$code.') ' : '';

        return trim($prefix.$message).' — '.$why;
    }
}
