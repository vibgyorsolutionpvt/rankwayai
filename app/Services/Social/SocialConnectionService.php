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

        $igScopes = ['pages_show_list', 'instagram_basic', 'instagram_content_publish'];
        if (config('social.meta_request_instagram_insights', false)) {
            $igScopes[] = 'instagram_manage_insights';
        }

        return match ($platform) {
            'facebook', 'instagram' => 'https://www.facebook.com/v19.0/dialog/oauth?'.http_build_query([
                'client_id' => $this->integrations->socialCredential($workspace, 'meta', 'app_id'),
                'redirect_uri' => $this->oauthRedirectUri($platform),
                'state' => $state,
                'scope' => $platform === 'instagram'
                    ? implode(',', $igScopes)
                    : 'pages_show_list,pages_manage_posts,pages_read_engagement',
            ]),
            'threads' => 'https://threads.net/oauth/authorize?'.http_build_query([
                'client_id' => $this->integrations->socialCredential($workspace, 'meta', 'threads_app_id'),
                'redirect_uri' => $this->oauthRedirectUri('threads'),
                'scope' => 'threads_basic,threads_content_publish,threads_manage_insights',
                'response_type' => 'code',
                'state' => $state,
            ]),
            'linkedin' => 'https://www.linkedin.com/oauth/v2/authorization?'.http_build_query([
                'response_type' => 'code',
                'client_id' => $this->integrations->socialCredential($workspace, 'linkedin', 'client_id'),
                'redirect_uri' => $this->oauthRedirectUri('linkedin'),
                'state' => $state,
                'scope' => 'w_member_social r_organization_social',
            ]),
            'x' => 'https://twitter.com/i/oauth2/authorize?'.http_build_query([
                'response_type' => 'code',
                'client_id' => $this->integrations->socialCredential($workspace, 'x', 'client_id'),
                'redirect_uri' => $this->oauthRedirectUri('x'),
                'scope' => 'tweet.read tweet.write users.read offline.access',
                'state' => $state,
                'code_challenge' => 'challenge',
                'code_challenge_method' => 'plain',
            ]),
            default => null,
        };
    }

    public function oauthRedirectUri(string $platform): string
    {
        $root = rtrim((string) config('app.url'), '/');

        return $root.'/social/oauth/'.$platform.'/callback';
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
            'redirect_uri' => $this->oauthRedirectUri($platform),
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
        $redirectUri = $this->oauthRedirectUri('threads');

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
            'redirect_uri' => $this->oauthRedirectUri('linkedin'),
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
                'redirect_uri' => $this->oauthRedirectUri('x'),
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

    /**
     * Test the health of a social account connection against the live platform API.
     *
     * @return array{ok:bool,health:string,message:string}
     */
    public function testConnection(SocialAccount $account): array
    {
        if ($account->status !== 'connected') {
            return [
                'ok' => false,
                'health' => 'error',
                'message' => 'Account is marked as disconnected. Reconnect to restore access.',
            ];
        }

        if (blank($account->access_token)) {
            $msg = 'Missing access token. Please reconnect this account.';
            $account->update(['health' => 'error', 'last_error' => $msg]);

            return [
                'ok' => false,
                'health' => 'error',
                'message' => $msg,
            ];
        }

        if ($account->connection_mode === 'sandbox') {
            $account->update(['health' => 'healthy', 'last_error' => null]);

            return [
                'ok' => true,
                'health' => 'healthy',
                'message' => 'Sandbox mode: Test connection is active and ready.',
            ];
        }

        if ($account->token_expires_at && $account->token_expires_at->isPast()) {
            $msg = 'Access token expired on '.$account->token_expires_at->toFormattedDateString().'. Please click Reconnect.';
            $account->update(['health' => 'error', 'last_error' => $msg]);

            return [
                'ok' => false,
                'health' => 'error',
                'message' => $msg,
            ];
        }

        return match ($account->platform) {
            'facebook' => $this->testFacebookConnection($account),
            'instagram' => $this->testInstagramConnection($account),
            'threads' => $this->testThreadsConnection($account),
            'linkedin' => $this->testLinkedInConnection($account),
            'x' => $this->testXConnection($account),
            default => [
                'ok' => true,
                'health' => 'healthy',
                'message' => 'Connection active.',
            ],
        };
    }

    private function testFacebookConnection(SocialAccount $account): array
    {
        $pageId = (string) $account->external_id;
        $token = (string) $account->access_token;

        try {
            $response = Http::timeout(15)->get(self::GRAPH.'/'.rawurlencode($pageId), [
                'fields' => 'id,name,link,category',
                'access_token' => $token,
            ]);

            if ($response->successful() && filled($response->json('id'))) {
                $pageName = (string) ($response->json('name') ?? $account->account_name);
                $account->update([
                    'health' => 'healthy',
                    'last_error' => null,
                    'account_name' => $pageName ?: $account->account_name,
                ]);

                $expires = $account->token_expires_at
                    ? ' (Token valid until '.$account->token_expires_at->format('M j, Y').')'
                    : '';

                return [
                    'ok' => true,
                    'health' => 'healthy',
                    'message' => "Facebook connection healthy! Connected to Page '{$pageName}' (ID: {$pageId}){$expires}.",
                ];
            }

            $errorMsg = $this->parseMetaError($response->json(), $response->body());
            $account->update([
                'health' => 'error',
                'last_error' => $errorMsg,
            ]);

            return [
                'ok' => false,
                'health' => 'error',
                'message' => "Facebook connection failed: {$errorMsg}",
            ];
        } catch (\Throwable $e) {
            $msg = 'Facebook connection check failed: '.$e->getMessage();
            $account->update(['health' => 'error', 'last_error' => $msg]);

            return ['ok' => false, 'health' => 'error', 'message' => $msg];
        }
    }

    private function testInstagramConnection(SocialAccount $account): array
    {
        $igUserId = (string) $account->external_id;
        $token = (string) $account->access_token;

        try {
            $response = Http::timeout(15)->get(self::GRAPH.'/'.rawurlencode($igUserId), [
                'fields' => 'id,username,name',
                'access_token' => $token,
            ]);

            if ($response->successful() && filled($response->json('id'))) {
                $username = (string) ($response->json('username') ?? $response->json('name') ?? $account->account_name);
                $displayName = $username ? (str_starts_with($username, '@') ? $username : '@'.$username) : $account->account_name;
                $account->update([
                    'health' => 'healthy',
                    'last_error' => null,
                    'account_name' => $displayName,
                ]);

                $expires = $account->token_expires_at
                    ? ' (Token valid until '.$account->token_expires_at->format('M j, Y').')'
                    : '';

                return [
                    'ok' => true,
                    'health' => 'healthy',
                    'message' => "Instagram connection healthy! Connected as {$displayName} (ID: {$igUserId}){$expires}.",
                ];
            }

            $errorMsg = $this->parseMetaError($response->json(), $response->body());
            $account->update([
                'health' => 'error',
                'last_error' => $errorMsg,
            ]);

            return [
                'ok' => false,
                'health' => 'error',
                'message' => "Instagram connection failed: {$errorMsg}",
            ];
        } catch (\Throwable $e) {
            $msg = 'Instagram connection check failed: '.$e->getMessage();
            $account->update(['health' => 'error', 'last_error' => $msg]);

            return ['ok' => false, 'health' => 'error', 'message' => $msg];
        }
    }

    private function testThreadsConnection(SocialAccount $account): array
    {
        $userId = (string) $account->external_id;
        $token = (string) $account->access_token;

        try {
            // First try with the stored numeric user ID, or fallback to 'me'
            $endpoint = $userId !== ''
                ? self::THREADS_GRAPH.'/'.rawurlencode($userId)
                : self::THREADS_GRAPH.'/me';

            $response = Http::timeout(15)->get($endpoint, [
                'fields' => 'id,username',
                'access_token' => $token,
            ]);

            // If querying by numeric userId gave Error 24 / not exist, try with /me
            if (! $response->successful() && $userId !== '') {
                $errJson = $response->json();
                $errCode = (int) ($errJson['error']['code'] ?? 0);
                if ($errCode === 24 || str_contains((string) ($errJson['error']['message'] ?? ''), 'does not exist')) {
                    $retryMe = Http::timeout(15)->get(self::THREADS_GRAPH.'/me', [
                        'fields' => 'id,username',
                        'access_token' => $token,
                    ]);
                    if ($retryMe->successful()) {
                        $response = $retryMe;
                        if (filled($retryMe->json('id'))) {
                            $account->update(['external_id' => (string) $retryMe->json('id')]);
                        }
                    }
                }
            }

            if ($response->successful() && (filled($response->json('id')) || filled($response->json('username')))) {
                $username = (string) ($response->json('username') ?? $account->account_name);
                $displayName = $username ? (str_starts_with($username, '@') ? $username : '@'.$username) : $account->account_name;
                $account->update([
                    'health' => 'healthy',
                    'last_error' => null,
                    'account_name' => $displayName,
                ]);

                $expires = $account->token_expires_at
                    ? ' (Token valid until '.$account->token_expires_at->format('M j, Y').')'
                    : '';

                return [
                    'ok' => true,
                    'health' => 'healthy',
                    'message' => "Threads connection healthy! Connected as {$displayName}{$expires}.",
                ];
            }

            $errorMsg = $this->parseMetaError($response->json(), $response->body());
            $account->update([
                'health' => 'error',
                'last_error' => $errorMsg,
            ]);

            return [
                'ok' => false,
                'health' => 'error',
                'message' => "Threads connection failed: {$errorMsg}",
            ];
        } catch (\Throwable $e) {
            $msg = 'Threads connection check failed: '.$e->getMessage();
            $account->update(['health' => 'error', 'last_error' => $msg]);

            return ['ok' => false, 'health' => 'error', 'message' => $msg];
        }
    }

    private function testLinkedInConnection(SocialAccount $account): array
    {
        $token = (string) $account->access_token;

        try {
            $response = Http::timeout(15)->withToken($token)->get('https://api.linkedin.com/v2/userinfo');

            if ($response->successful()) {
                $name = (string) ($response->json('name') ?? $account->account_name);
                $account->update([
                    'health' => 'healthy',
                    'last_error' => null,
                    'account_name' => $name,
                ]);

                return [
                    'ok' => true,
                    'health' => 'healthy',
                    'message' => "LinkedIn connection healthy! Connected as '{$name}'.",
                ];
            }

            $msg = $response->json('message') ?? 'LinkedIn token invalid or expired. Please reconnect.';
            $account->update(['health' => 'error', 'last_error' => $msg]);

            return ['ok' => false, 'health' => 'error', 'message' => "LinkedIn connection failed: {$msg}"];
        } catch (\Throwable $e) {
            $msg = 'LinkedIn check failed: '.$e->getMessage();
            $account->update(['health' => 'error', 'last_error' => $msg]);

            return ['ok' => false, 'health' => 'error', 'message' => $msg];
        }
    }

    private function testXConnection(SocialAccount $account): array
    {
        $token = (string) $account->access_token;

        try {
            $response = Http::timeout(15)->withToken($token)->get('https://api.twitter.com/2/users/me');

            if ($response->successful() && filled($response->json('data.id'))) {
                $username = (string) ($response->json('data.username') ?? '');
                $displayName = $username ? '@'.$username : $account->account_name;
                $account->update([
                    'health' => 'healthy',
                    'last_error' => null,
                    'account_name' => $displayName,
                ]);

                return [
                    'ok' => true,
                    'health' => 'healthy',
                    'message' => "X connection healthy! Connected as {$displayName}.",
                ];
            }

            $msg = $response->json('detail') ?? $response->json('title') ?? 'X token invalid or expired. Please reconnect.';
            $account->update(['health' => 'error', 'last_error' => $msg]);

            return ['ok' => false, 'health' => 'error', 'message' => "X connection failed: {$msg}"];
        } catch (\Throwable $e) {
            $msg = 'X check failed: '.$e->getMessage();
            $account->update(['health' => 'error', 'last_error' => $msg]);

            return ['ok' => false, 'health' => 'error', 'message' => $msg];
        }
    }

    private function parseMetaError(mixed $json, string $body): string
    {
        if (is_array($json) && isset($json['error'])) {
            $code = (int) ($json['error']['code'] ?? 0);
            $subcode = (int) ($json['error']['error_subcode'] ?? 0);
            $msg = $json['error']['message'] ?? $json['error']['error_user_msg'] ?? null;

            if ($code === 190) {
                return 'Meta session expired or password changed (Error 190). Please click Reconnect to sign in again.';
            }

            if ($code === 200) {
                return 'Meta permission error (Error 200). Ensure the user has admin or editor role on this Facebook Page.';
            }

            if ($code === 100) {
                return 'Invalid Page or User ID on Meta (Error 100). Please reconnect.';
            }

            if ($code === 24 || $subcode === 4279009) {
                return 'Account ID or resource not found on Threads (Error 24). Please reconnect your Threads account.';
            }

            if (is_string($msg) && $msg !== '') {
                return $msg;
            }
        }

        return 'Meta API error: '.Str::limit($body, 180);
    }
}
