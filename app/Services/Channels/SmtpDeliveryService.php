<?php

namespace App\Services\Channels;

use App\Mail\ChannelCampaignMail;
use App\Models\Workspace;
use App\Services\Integrations\WorkspaceIntegrationService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class SmtpDeliveryService
{
    public function __construct(private WorkspaceIntegrationService $integrations) {}

    /**
     * @return array{ok:bool, id:?string, error:?string}
     */
    public function send(Workspace $workspace, string $to, string $subject, string $body): array
    {
        $cfg = $this->integrations->smtpConfig($workspace);
        if (! $cfg) {
            return ['ok' => false, 'id' => null, 'error' => 'SMTP is not configured for this workspace.'];
        }

        $mailer = 'workspace_smtp_'.$workspace->id;
        $scheme = match ($cfg['encryption']) {
            'ssl' => 'smtps',
            default => 'smtp',
        };

        config([
            "mail.mailers.{$mailer}" => [
                'transport' => 'smtp',
                'scheme' => $scheme,
                'host' => $cfg['host'],
                'port' => $cfg['port'],
                'username' => $cfg['username'],
                'password' => $cfg['password'],
                'timeout' => 20,
            ],
        ]);

        try {
            $manager = app('mail.manager');
            if (method_exists($manager, 'purge')) {
                $manager->purge($mailer);
            }

            Mail::mailer($mailer)
                ->to($to)
                ->send(
                    (new ChannelCampaignMail(
                        $subject !== '' ? $subject : 'Message',
                        $body
                    ))->from($cfg['from_address'], $cfg['from_name'] ?? null)
                );

            return [
                'ok' => true,
                'id' => 'smtp_'.Str::lower(Str::random(12)),
                'error' => null,
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'id' => null,
                'error' => Str::limit($e->getMessage(), 240),
            ];
        }
    }
}
