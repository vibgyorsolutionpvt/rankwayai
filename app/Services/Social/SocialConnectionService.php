<?php

namespace App\Services\Social;

use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Services\Integrations\WorkspaceIntegrationService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SocialConnectionService
{
    private const GRAPH = 'https://graph.facebook.com/v19.0';

    private const THREADS_GRAPH = 'https://graph.threads.net/v1.0';

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
            'threads' => $p['meta'] ? 'oauth' : 'sandbox',
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

    public function oauthAuthorizeUrl(
        Workspace $workspace,
        string $platform,
        string $accountType = 'page',
        ?string $preferredName = null
    ): ?string {
        $modes = $this->modes($workspace);
        if (($modes[$platform] ?? 'sandbox') !== 'oauth') {
            return null;
        }

        $state = base64_encode(json_encode([
            'workspace_id' => $workspace->id,
            'platform' => $platform,
            'account_type' => $accountType,
            'preferred_name' => $preferredName ? trim($preferredName) : null,
            'nonce' => Str::random(16),
        ]));

        return match ($platform) {
            'facebook', 'instagram' => 'https://www.facebook.com/v19.0/dialog/oauth?'.http_build_query([
                'client_id' => $this->integrations->socialCredential($workspace, 'meta', 'app_id'),
                'redirect_uri' => route('social.oauth.callback', ['platform' => $platform]),
                'state' => $state,
                'scope' => $platform === 'instagram'
                    ? 'pages_show_list,pages_read_engagement,instagram_basic,instagram_content_publish,business_management'
                    : 'pages_show_list,pages_read_engagement,pages_manage_posts,business_management',
            ]),
            'threads' => 'https://threads.com/oauth/authorize?'.http_build_query([
                'client_id' => $this->integrations->socialCredential($workspace, 'meta', 'threads_app_id'),
                'redirect_uri' => route('social.oauth.callback', ['platform' => 'threads']),
                'scope' => 'threads_basic,threads_content_publish',
                'response_type' => 'code',
                'state' => $state,
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
     * @return array{
     *   status: 'connected'|'pick_page'|'failed',
     *   account?: SocialAccount,
     *   platform?: string,
     *   account_type?: string,
     *   expires_in?: int,
     *   pages?: list<array{id:string,name:string,access_token:string,instagram?:array{id:string,username:string}|null}>,
     *   message?: string,
     *   preferred_name?: ?string
     * }
     */
    public function handleOAuthCallback(
        Workspace $workspace,
        string $platform,
        string $code,
        string $accountType = 'page',
        ?string $preferredName = null
    ): array {
        if ($platform === 'facebook' || $platform === 'instagram') {
            $bundle = $this->fetchMetaPages($workspace, $code, $platform);
            if (! $bundle) {
                $account = $this->connectSandbox($workspace, $platform, ucfirst($platform).' account', $accountType);
                $account->update([
                    'connection_mode' => 'sandbox',
                    'last_error' => 'OAuth token exchange failed — sandbox token used. Check provider credentials.',
                    'health' => 'warning',
                ]);

                return ['status' => 'failed', 'account' => $account->fresh(), 'message' => 'OAuth failed'];
            }

            $pages = $bundle['pages'];
            if ($platform === 'instagram') {
                $pages = array_values(array_filter($pages, fn (array $p) => ! empty($p['instagram']['id'])));
            }

            if ($pages === []) {
                return [
                    'status' => 'failed',
                    'message' => $platform === 'instagram'
                        ? 'No Instagram Business account linked to your Pages.'
                        : 'No Facebook Pages found for this Meta login.',
                ];
            }

            $hint = trim((string) ($preferredName ?: $workspace->name));
            $matched = $this->matchMetaPage($pages, $hint, $platform);

            if ($matched) {
                $account = $this->connectMetaPage($workspace, $platform, $accountType, $matched, $bundle['expires_in']);

                return ['status' => 'connected', 'account' => $account];
            }

            if (count($pages) === 1) {
                $account = $this->connectMetaPage($workspace, $platform, $accountType, $pages[0], $bundle['expires_in']);

                return ['status' => 'connected', 'account' => $account];
            }

            return [
                'status' => 'pick_page',
                'platform' => $platform,
                'account_type' => $accountType,
                'expires_in' => $bundle['expires_in'],
                'pages' => $pages,
                'preferred_name' => $hint !== '' ? $hint : null,
            ];
        }

        $token = $this->exchangeToken($workspace, $platform, $code);

        if ($token && $platform === 'threads') {
            $account = $workspace->socialAccounts()->firstOrNew([
                'platform' => 'threads',
                'external_id' => (string) $token['id'],
            ]);
            $account->account_name = (string) ($token['name'] ?? 'Threads');
            $account->account_type = $accountType;
        } else {
            $account = $workspace->socialAccounts()->firstOrNew([
                'platform' => $platform,
                'account_name' => ($token['name'] ?? ucfirst($platform).' account'),
                'account_type' => $accountType,
            ]);
        }
        $account->workspace_id = $workspace->id;
        $account->account_type = $accountType;
        $account->connection_mode = $token ? 'oauth' : 'sandbox';
        $account->save();

        if ($token) {
            $account->update([
                'status' => 'connected',
                'health' => 'healthy',
                'last_error' => null,
                'external_id' => (string) ($token['id'] ?? ('oauth_'.uniqid())),
                'access_token' => $token['access_token'],
                'refresh_token' => $token['refresh_token'] ?? null,
                'token_expires_at' => isset($token['expires_in'])
                    ? now()->addSeconds((int) $token['expires_in'])
                    : now()->addDays(60),
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

        return [
            'status' => $token ? 'connected' : 'failed',
            'account' => $account->fresh(),
            'message' => $token ? null : 'OAuth failed',
        ];
    }

    /**
     * @param  list<array{id:string,name:string,access_token:string,instagram?:?array{id:string,username:string}}>  $pages
     * @return array{id:string,name:string,access_token:string,instagram?:?array{id:string,username:string}}|null
     */
    public function matchMetaPage(array $pages, string $hint, string $platform = 'facebook'): ?array
    {
        $hint = strtolower(trim($hint));
        if ($hint === '' || $pages === []) {
            return null;
        }

        $normalize = static fn (string $value): string => preg_replace('/[^a-z0-9]+/', '', strtolower($value)) ?: '';

        $hintNorm = $normalize($hint);
        $scored = [];

        foreach ($pages as $page) {
            $name = (string) ($page['name'] ?? '');
            $igUser = (string) ($page['instagram']['username'] ?? '');
            $nameNorm = $normalize($name);
            $igNorm = $normalize($igUser);
            $score = 0;

            if ($nameNorm !== '' && $nameNorm === $hintNorm) {
                $score = 100;
            } elseif ($platform === 'instagram' && $igNorm !== '' && $igNorm === $hintNorm) {
                $score = 95;
            } elseif ($nameNorm !== '' && ($hintNorm !== '' && (str_contains($nameNorm, $hintNorm) || str_contains($hintNorm, $nameNorm)))) {
                $score = 80;
            } elseif ($igNorm !== '' && ($hintNorm !== '' && (str_contains($igNorm, $hintNorm) || str_contains($hintNorm, $igNorm)))) {
                $score = 75;
            } else {
                $tokens = preg_split('/\s+/', $hint) ?: [];
                foreach ($tokens as $token) {
                    $tokenNorm = $normalize($token);
                    if (strlen($tokenNorm) < 4) {
                        continue;
                    }
                    if (str_contains($nameNorm, $tokenNorm) || str_contains($igNorm, $tokenNorm)) {
                        $score = max($score, 60);
                    }
                }
            }

            if ($score > 0) {
                $scored[] = ['score' => $score, 'page' => $page];
            }
        }

        if ($scored === []) {
            return null;
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        // Only auto-pick when clearly unique enough — avoid CityConnect vs Vibgyor mixups.
        if ($scored[0]['score'] < 60) {
            return null;
        }

        if (isset($scored[1]) && $scored[1]['score'] === $scored[0]['score']) {
            return null;
        }

        return $scored[0]['page'];
    }

    /**
     * @param  array{id:string,name:string,access_token:string,instagram?:array{id:string,username:string}|null}  $page
     */
    public function connectMetaPage(
        Workspace $workspace,
        string $platform,
        string $accountType,
        array $page,
        int $expiresIn = 5184000
    ): SocialAccount {
        if ($platform === 'instagram') {
            $ig = $page['instagram'] ?? null;
            if (! is_array($ig) || blank($ig['id'] ?? null)) {
                throw new \InvalidArgumentException('Selected page has no Instagram Business account.');
            }
            $externalId = (string) $ig['id'];
            $name = (string) ($ig['username'] ?? $page['name'] ?? 'Instagram');
        } else {
            $externalId = (string) $page['id'];
            $name = (string) ($page['name'] ?? 'Facebook Page');
        }

        $account = $workspace->socialAccounts()->firstOrNew([
            'platform' => $platform,
            'external_id' => $externalId,
        ]);
        $account->workspace_id = $workspace->id;
        $account->account_name = $name;
        $account->account_type = $accountType;
        $account->connection_mode = 'oauth';
        $account->save();

        $account->update([
            'status' => 'connected',
            'health' => 'healthy',
            'last_error' => null,
            'external_id' => $externalId,
            'access_token' => (string) $page['access_token'],
            'refresh_token' => null,
            'token_expires_at' => now()->addSeconds(max(3600, $expiresIn)),
            'connected_at' => now(),
        ]);

        return $account->fresh();
    }

    /**
     * @return array{expires_in:int, pages:list<array{id:string,name:string,access_token:string,instagram:?array{id:string,username:string}}>}|null
     */
    private function fetchMetaPages(Workspace $workspace, string $code, string $platform): ?array
    {
        $appId = (string) $this->integrations->socialCredential($workspace, 'meta', 'app_id');
        $appSecret = (string) $this->integrations->socialCredential($workspace, 'meta', 'app_secret');

        $response = Http::asForm()->post(self::GRAPH.'/oauth/access_token', [
            'client_id' => $appId,
            'client_secret' => $appSecret,
            'redirect_uri' => route('social.oauth.callback', ['platform' => $platform]),
            'code' => $code,
        ]);

        if (! $response->successful() || blank($response->json('access_token'))) {
            return null;
        }

        $userToken = (string) $response->json('access_token');
        $expiresIn = (int) ($response->json('expires_in') ?? 0);

        $long = Http::get(self::GRAPH.'/oauth/access_token', [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $appId,
            'client_secret' => $appSecret,
            'fb_exchange_token' => $userToken,
        ]);
        if ($long->successful() && filled($long->json('access_token'))) {
            $userToken = (string) $long->json('access_token');
            $expiresIn = (int) ($long->json('expires_in') ?? 5184000);
        }

        $pages = Http::withToken($userToken)->get(self::GRAPH.'/me/accounts', [
            'fields' => 'id,name,access_token,instagram_business_account{id,username}',
            'limit' => 50,
        ]);

        if (! $pages->successful()) {
            return null;
        }

        $list = $pages->json('data') ?? [];
        if (! is_array($list) || $list === []) {
            return null;
        }

        $mapped = [];
        foreach ($list as $page) {
            if (! is_array($page) || blank($page['id'] ?? null) || blank($page['access_token'] ?? null)) {
                continue;
            }
            $ig = $page['instagram_business_account'] ?? null;
            $mapped[] = [
                'id' => (string) $page['id'],
                'name' => (string) ($page['name'] ?? 'Facebook Page'),
                'access_token' => (string) $page['access_token'],
                'instagram' => is_array($ig) && filled($ig['id'] ?? null)
                    ? ['id' => (string) $ig['id'], 'username' => (string) ($ig['username'] ?? '')]
                    : null,
            ];
        }

        return [
            'expires_in' => $expiresIn > 0 ? $expiresIn : 5184000,
            'pages' => $mapped,
        ];
    }

    private function exchangeToken(Workspace $workspace, string $platform, string $code): ?array
    {
        try {
            return match ($platform) {
                'threads' => $this->exchangeThreads($workspace, $code),
                'linkedin' => $this->exchangeLinkedIn($workspace, $code),
                'x' => $this->exchangeX($workspace, $code),
                default => null,
            };
        } catch (\Throwable) {
            return null;
        }
    }

    private function exchangeThreads(Workspace $workspace, string $code): ?array
    {
        // Meta appends #_ to redirect URLs — strip if present.
        $code = rtrim($code, '#_');
        $code = str_replace('#_', '', $code);

        $clientId = (string) $this->integrations->socialCredential($workspace, 'meta', 'threads_app_id');
        $clientSecret = (string) $this->integrations->socialCredential($workspace, 'meta', 'threads_app_secret');
        $redirectUri = route('social.oauth.callback', ['platform' => 'threads']);

        $response = Http::asForm()->post('https://graph.threads.net/oauth/access_token', [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri,
            'code' => $code,
        ]);

        if (! $response->successful() || blank($response->json('access_token'))) {
            return null;
        }

        $userToken = (string) $response->json('access_token');
        $userId = (string) ($response->json('user_id') ?? '');
        $expiresIn = 3600;

        $long = Http::get('https://graph.threads.net/access_token', [
            'grant_type' => 'th_exchange_token',
            'client_secret' => $clientSecret,
            'access_token' => $userToken,
        ]);
        if ($long->successful() && filled($long->json('access_token'))) {
            $userToken = (string) $long->json('access_token');
            $expiresIn = (int) ($long->json('expires_in') ?? 5184000);
        }

        $username = 'Threads';
        if ($userId !== '') {
            $me = Http::get(self::THREADS_GRAPH.'/'.rawurlencode($userId), [
                'fields' => 'id,username',
                'access_token' => $userToken,
            ]);
            if ($me->successful() && filled($me->json('username'))) {
                $username = '@'.$me->json('username');
            } elseif ($me->successful() && filled($me->json('id'))) {
                $userId = (string) $me->json('id');
            }
        } else {
            $me = Http::get(self::THREADS_GRAPH.'/me', [
                'fields' => 'id,username',
                'access_token' => $userToken,
            ]);
            if ($me->successful()) {
                $userId = (string) ($me->json('id') ?? '');
                if (filled($me->json('username'))) {
                    $username = '@'.$me->json('username');
                }
            }
        }

        if ($userId === '') {
            return null;
        }

        return [
            'access_token' => $userToken,
            'expires_in' => $expiresIn > 0 ? $expiresIn : 5184000,
            'id' => $userId,
            'name' => $username,
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
