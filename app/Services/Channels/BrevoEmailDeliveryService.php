<?php

namespace App\Services\Channels;

use App\Models\Workspace;
use App\Services\Integrations\WorkspaceIntegrationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class BrevoEmailDeliveryService
{
    public const FREE_DAILY_LIMIT = 300;

    private const FREE_LIMITS = [
        'brevo' => ['daily' => 300, 'monthly' => null],
        'resend' => ['daily' => 100, 'monthly' => 3000],
        'mailersend' => ['daily' => 100, 'monthly' => 500],
        'sender' => ['daily' => null, 'monthly' => 15000],
    ];

    public function __construct(
        private readonly WorkspaceIntegrationService $integrations,
        private readonly EmailHtmlSanitizer $emailHtml,
    ) {}

    public function remainingFreeDailySends(Workspace $workspace): ?int
    {
        return $this->remainingDailySends($workspace, 'brevo');
    }

    public function remainingDailySends(Workspace $workspace, string $provider): ?int
    {
        $config = $this->integrations->emailProviderConfig($workspace, $provider);
        $limit = self::FREE_LIMITS[$provider]['daily'] ?? null;
        if (! $config || ! array_key_exists($provider, self::FREE_LIMITS)) {
            return 0;
        }
        if ($limit === null) {
            return null;
        }

        $used = (int) DB::table('email_provider_daily_usages')
            ->where('provider', $provider)
            ->where('credential_hash', hash('sha256', $config['api_key']))
            ->where('usage_date', now('UTC')->toDateString())
            ->value('reserved_count');

        return max(0, $limit - $used);
    }

    public function remainingMonthlySends(Workspace $workspace, string $provider): ?int
    {
        $config = $this->integrations->emailProviderConfig($workspace, $provider);
        $limit = self::FREE_LIMITS[$provider]['monthly'] ?? null;
        if (! $config || ! array_key_exists($provider, self::FREE_LIMITS)) {
            return 0;
        }
        if ($limit === null) {
            return null;
        }

        $used = (int) DB::table('email_provider_monthly_usages')
            ->where('provider', $provider)
            ->where('credential_hash', hash('sha256', $config['api_key']))
            ->where('usage_month', now('UTC')->format('Y-m'))
            ->value('reserved_count');

        return max(0, $limit - $used);
    }

    /**
     * @return array{daily:?string,monthly:?string}|null
     */
    private function reserveSend(string $provider, string $credentialHash): ?array
    {
        $limits = self::FREE_LIMITS[$provider] ?? null;
        if (! $limits) {
            return null;
        }

        $date = now('UTC')->toDateString();
        $month = now('UTC')->format('Y-m');
        $now = now();
        $usage = ['daily' => null, 'monthly' => null];

        if ($limits['daily'] !== null) {
            DB::table('email_provider_daily_usages')->insertOrIgnore([
                'id' => (string) Str::uuid7(),
                'provider' => $provider,
                'credential_hash' => $credentialHash,
                'usage_date' => $date,
                'reserved_count' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $reserved = DB::table('email_provider_daily_usages')
                ->where('provider', $provider)
                ->where('credential_hash', $credentialHash)
                ->where('usage_date', $date)
                ->where('reserved_count', '<', $limits['daily'])
                ->increment('reserved_count');
            if ($reserved !== 1) {
                return null;
            }
            $usage['daily'] = $date;
        }

        if ($limits['monthly'] !== null) {
            DB::table('email_provider_monthly_usages')->insertOrIgnore([
                'id' => (string) Str::uuid7(),
                'provider' => $provider,
                'credential_hash' => $credentialHash,
                'usage_month' => $month,
                'reserved_count' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $reserved = DB::table('email_provider_monthly_usages')
                ->where('provider', $provider)
                ->where('credential_hash', $credentialHash)
                ->where('usage_month', $month)
                ->where('reserved_count', '<', $limits['monthly'])
                ->increment('reserved_count');
            if ($reserved !== 1) {
                $this->releaseSend($provider, $credentialHash, $usage);

                return null;
            }
            $usage['monthly'] = $month;
        }

        return $usage;
    }

    /**
     * @param  array{daily:?string,monthly:?string}  $usage
     */
    private function releaseSend(string $provider, string $credentialHash, array $usage): void
    {
        if ($usage['daily']) {
            DB::table('email_provider_daily_usages')
                ->where('provider', $provider)
                ->where('credential_hash', $credentialHash)
                ->where('usage_date', $usage['daily'])
                ->where('reserved_count', '>', 0)
                ->decrement('reserved_count');
        }

        if ($usage['monthly']) {
            DB::table('email_provider_monthly_usages')
                ->where('provider', $provider)
                ->where('credential_hash', $credentialHash)
                ->where('usage_month', $usage['monthly'])
                ->where('reserved_count', '>', 0)
                ->decrement('reserved_count');
        }
    }

    /**
     * @return array{ok:bool, id:?string, error:?string}
     */
    public function send(Workspace $workspace, string $to, string $subject, string $body): array
    {
        return $this->sendProvider($workspace, 'brevo', $to, $subject, $body);
    }

    /**
     * @return array{ok:bool,id:?string,error:?string,quota_exhausted?:bool}
     */
    public function sendProvider(
        Workspace $workspace,
        string $provider,
        string $to,
        string $subject,
        string $body,
    ): array {
        $htmlBody = $this->emailHtml->sanitize($body);
        $config = $this->integrations->emailProviderConfig($workspace, $provider);
        if (! $config) {
            return ['ok' => false, 'id' => null, 'error' => ucfirst($provider).' is not connected for this workspace.'];
        }

        $credentialHash = hash('sha256', $config['api_key']);
        $usage = $this->reserveSend($provider, $credentialHash);
        if ($usage === null) {
            return [
                'ok' => false,
                'id' => null,
                'error' => ucfirst($provider).' free-plan sending limit reached. This recipient was not sent.',
                'quota_exhausted' => true,
            ];
        }

        try {
            $response = match ($provider) {
                'brevo' => Http::withHeaders(['api-key' => $config['api_key']])
                    ->timeout(20)
                    ->acceptJson()
                    ->post('https://api.brevo.com/v3/smtp/email', [
                        'sender' => ['name' => $config['sender_name'], 'email' => $config['sender_email']],
                        'to' => [['email' => $to]],
                        'subject' => $subject !== '' ? $subject : 'Message',
                        'htmlContent' => '<html><body>'.$htmlBody.'</body></html>',
                        'textContent' => $this->plainText($htmlBody),
                    ]),
                'resend' => Http::withToken($config['api_key'])
                    ->timeout(20)
                    ->acceptJson()
                    ->post('https://api.resend.com/emails', [
                        'from' => $config['sender_name'].' <'.$config['sender_email'].'>',
                        'to' => [$to],
                        'subject' => $subject !== '' ? $subject : 'Message',
                        'html' => $htmlBody,
                        'text' => $this->plainText($htmlBody),
                    ]),
                'mailersend' => Http::withToken($config['api_key'])
                    ->timeout(20)
                    ->acceptJson()
                    ->post('https://api.mailersend.com/v1/email', [
                        'from' => ['name' => $config['sender_name'], 'email' => $config['sender_email']],
                        'to' => [['email' => $to]],
                        'subject' => $subject !== '' ? $subject : 'Message',
                        'html' => $htmlBody,
                        'text' => $this->plainText($htmlBody),
                    ]),
                'sender' => Http::withToken($config['api_key'])
                    ->timeout(20)
                    ->acceptJson()
                    ->post('https://api.sender.net/v2/message/send', [
                        'from' => ['name' => $config['sender_name'], 'email' => $config['sender_email']],
                        'to' => ['email' => $to],
                        'subject' => $subject !== '' ? $subject : 'Message',
                        'html' => $htmlBody,
                        'text' => $this->plainText($htmlBody),
                    ]),
                default => throw new \InvalidArgumentException('Unsupported email provider.'),
            };

            if ($response->successful()) {
                $messageId = match ($provider) {
                    'brevo' => $response->json('messageId'),
                    'resend' => $response->json('id'),
                    'mailersend' => $response->header('x-message-id'),
                    'sender' => $response->json('emailId'),
                };

                return [
                    'ok' => true,
                    'id' => is_string($messageId) && $messageId !== ''
                        ? $messageId
                        : $provider.'_'.Str::lower(Str::random(12)),
                    'error' => null,
                ];
            }

            if ($response->status() < 500) {
                $this->releaseSend($provider, $credentialHash, $usage);
            }
            $message = $response->json('message') ?? $response->json('error');
            $error = is_string($message)
                ? $message
                : ucfirst($provider).' rejected the email request (HTTP '.$response->status().').';
            $quotaExhausted = $response->status() === 429
                || preg_match('/quota|daily limit|monthly limit|rate.?limit|not enough credits|free plan limit/i', $error);
            if ($quotaExhausted) {
                $error = ucfirst($provider).' sending limit reached: '.$error;
            }

            return [
                'ok' => false,
                'id' => null,
                'error' => Str::limit($error, 240),
                'quota_exhausted' => (bool) $quotaExhausted,
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'id' => null, 'error' => Str::limit($e->getMessage(), 240)];
        }
    }

    private function plainText(string $html): string
    {
        $html = preg_replace('/<\/(?:p|h2|h3|li|blockquote)>/i', "\n", $html) ?? $html;
        $html = preg_replace('/<br\s*\/?>/i', "\n", $html) ?? $html;

        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
