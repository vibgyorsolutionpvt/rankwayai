<?php

namespace App\Services\Social;

use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Services\Integrations\WorkspaceIntegrationService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SocialConnectionService
{
    public function __construct(private WorkspaceIntegrationService $integrations) {}

    public function modes(?Workspace $workspace = null): array
    {
        if ($workspace) {
            return $this->integrations->socialModes($workspace);
        }

        $p = \App\Services\Integrations\ProviderStatus::snapshot();

        return [
            'facebook' => $p['meta'] ? 'oauth' : 'sandbox',
            'instagram' => $p['meta'] ? 'oauth' : 'sandbox',
            'linkedin' => $p['linkedin'] ? 'oauth' : 'sandbox',
            'x' => $p['x'] ? 'oauth' : 'sandbox',
        ];
    }

    public function connectSandbox(
        Workspace $workspace,
        string $platform,
        string $accountName,
        string $accountType = 'page'
    ): SocialAccount {
        $account = $workspace->socialAccounts()->firstOrNew([
            'platform' => $platform,
            'account_name' => $accountName,
            'account_type' => $accountType,
        ]);
        $account->workspace_id = $workspace->id;
        $account->account_type = $accountType;
        $account->connection_mode = 'sandbox';
        $account->save();
        $account->markConnected();

        return $account->fresh();
    }

    public function oauthAuthorizeUrl(Workspace $workspace, string $platform, string $accountType = 'page'): ?string
    {
        $modes = $this->modes($workspace);
        if (($modes[$platform] ?? 'sandbox') !== 'oauth') {
            return null;
        }

        $state = base64_encode(json_encode([
            'workspace_id' => $workspace->id,
            'platform' => $platform,
            'account_type' => $accountType,
            'nonce' => Str::random(16),
        ]));

        return match ($platform) {
            'facebook', 'instagram' => 'https://www.facebook.com/v19.0/dialog/oauth?'.http_build_query([
                'client_id' => $this->integrations->socialCredential($workspace, 'meta', 'app_id'),
                'redirect_uri' => route('social.oauth.callback', ['platform' => $platform]),
                'state' => $state,
                'scope' => $platform === 'instagram'
                    ? 'instagram_basic,pages_show_list,pages_read_engagement'
                    : 'pages_manage_posts,pages_read_engagement,pages_show_list',
            ]),
            'linkedin' => 'https://www.linkedin.com/oauth/v2/authorization?'.http_build_query([
                'response_type' => 'code',
                'client_id' => $this->integrations->socialCredential($workspace, 'linkedin', 'client_id'),
                'redirect_uri' => route('social.oauth.callback', ['platform' => 'linkedin']),
                'state' => $state,
                'scope' => 'w_member_social r_organization_social',
            ]),
            'x' => 'https://twitter.com/i/oauth2/authorize?'.http_build_query([
                'response_type' => 'code',
                'client_id' => $this->integrations->socialCredential($workspace, 'x', 'client_id'),
                'redirect_uri' => route('social.oauth.callback', ['platform' => 'x']),
                'scope' => 'tweet.read tweet.write users.read offline.access',
                'state' => $state,
                'code_challenge' => 'challenge',
                'code_challenge_method' => 'plain',
            ]),
            default => null,
        };
    }

    /**
     * Exchange code when OAuth apps are configured. Falls back to labeled sandbox if token exchange fails.
     */
    public function handleOAuthCallback(Workspace $workspace, string $platform, string $code, string $accountType = 'page'): SocialAccount
    {
        $token = $this->exchangeToken($workspace, $platform, $code);
        $account = $workspace->socialAccounts()->firstOrNew([
            'platform' => $platform,
            'account_name' => ($token['name'] ?? ucfirst($platform).' account'),
            'account_type' => $accountType,
        ]);
        $account->workspace_id = $workspace->id;
        $account->account_type = $accountType;
        $account->connection_mode = $token ? 'oauth' : 'sandbox';
        $account->save();

        if ($token) {
            $account->update([
                'status' => 'connected',
                'health' => 'healthy',
                'last_error' => null,
                'external_id' => $token['id'] ?? ('oauth_'.uniqid()),
                'access_token' => $token['access_token'],
                'refresh_token' => $token['refresh_token'] ?? null,
                'token_expires_at' => isset($token['expires_in']) ? now()->addSeconds((int) $token['expires_in']) : now()->addDays(60),
                'connected_at' => now(),
            ]);
        } else {
            $account->markConnected();
            $account->update([
                'connection_mode' => 'sandbox',
                'last_error' => 'OAuth token exchange failed — sandbox token used. Check provider credentials.',
                'health' => 'warning',
            ]);
        }

        return $account->fresh();
    }

    private function exchangeToken(Workspace $workspace, string $platform, string $code): ?array
    {
        try {
            return match ($platform) {
                'facebook', 'instagram' => $this->exchangeMeta($workspace, $code, $platform),
                'linkedin' => $this->exchangeLinkedIn($workspace, $code),
                'x' => $this->exchangeX($workspace, $code),
                default => null,
            };
        } catch (\Throwable) {
            return null;
        }
    }

    private function exchangeMeta(Workspace $workspace, string $code, string $platform): ?array
    {
        $response = Http::asForm()->post('https://graph.facebook.com/v19.0/oauth/access_token', [
            'client_id' => $this->integrations->socialCredential($workspace, 'meta', 'app_id'),
            'client_secret' => $this->integrations->socialCredential($workspace, 'meta', 'app_secret'),
            'redirect_uri' => route('social.oauth.callback', ['platform' => $platform]),
            'code' => $code,
        ]);

        if (! $response->successful() || blank($response->json('access_token'))) {
            return null;
        }

        return [
            'access_token' => $response->json('access_token'),
            'expires_in' => $response->json('expires_in'),
            'id' => 'meta_'.Str::random(8),
            'name' => 'Meta Page',
        ];
    }

    private function exchangeLinkedIn(Workspace $workspace, string $code): ?array
    {
        $response = Http::asForm()->post('https://www.linkedin.com/oauth/v2/accessToken', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => route('social.oauth.callback', ['platform' => 'linkedin']),
            'client_id' => $this->integrations->socialCredential($workspace, 'linkedin', 'client_id'),
            'client_secret' => $this->integrations->socialCredential($workspace, 'linkedin', 'client_secret'),
        ]);

        if (! $response->successful() || blank($response->json('access_token'))) {
            return null;
        }

        return [
            'access_token' => $response->json('access_token'),
            'refresh_token' => $response->json('refresh_token'),
            'expires_in' => $response->json('expires_in'),
            'id' => 'li_'.Str::random(8),
            'name' => 'LinkedIn',
        ];
    }

    private function exchangeX(Workspace $workspace, string $code): ?array
    {
        $response = Http::asForm()
            ->withBasicAuth(
                (string) $this->integrations->socialCredential($workspace, 'x', 'client_id'),
                (string) $this->integrations->socialCredential($workspace, 'x', 'client_secret')
            )
            ->post('https://api.twitter.com/2/oauth2/token', [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => route('social.oauth.callback', ['platform' => 'x']),
                'code_verifier' => 'challenge',
            ]);

        if (! $response->successful() || blank($response->json('access_token'))) {
            return null;
        }

        return [
            'access_token' => $response->json('access_token'),
            'refresh_token' => $response->json('refresh_token'),
            'expires_in' => $response->json('expires_in'),
            'id' => 'x_'.Str::random(8),
            'name' => 'X account',
        ];
    }
}
