<?php

namespace App\Services\WhatsApp;

use App\Models\Workspace;
use App\Models\WorkspaceIntegration;
use App\Services\Integrations\WorkspaceIntegrationService;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * WhatsApp Embedded Signup: the customer connects their own WABA + number through the
 * RankwayAI Meta app popup; we exchange the code for their business token and store it
 * on their workspace only.
 */
class MetaEmbeddedSignupService
{
    public function __construct(private WorkspaceIntegrationService $integrations) {}

    public function enabled(): bool
    {
        return filled(config('services.meta.app_id'))
            && filled(config('services.meta.app_secret'))
            && filled(config('services.meta.whatsapp_es_config_id'));
    }

    /**
     * Public values the browser needs to launch the popup.
     *
     * @return array{enabled:bool, app_id:?string, config_id:?string, graph_version:string}
     */
    public function clientConfig(): array
    {
        return [
            'enabled' => $this->enabled(),
            'app_id' => $this->enabled() ? (string) config('services.meta.app_id') : null,
            'config_id' => $this->enabled() ? (string) config('services.meta.whatsapp_es_config_id') : null,
            'graph_version' => $this->version(),
        ];
    }

    /**
     * @return array{ok:bool, error:?string, registered:bool, display_phone_number:?string, verified_name:?string}
     */
    public function complete(
        Workspace $workspace,
        string $code,
        ?string $wabaId,
        ?string $phoneNumberId,
        ?string $pin = null,
    ): array {
        if (! $this->enabled()) {
            return $this->fail('WhatsApp Embedded Signup is not configured on this server.');
        }

        $token = $this->exchangeCode($code);
        if (! $token['ok']) {
            return $this->fail('Meta login could not be completed: '.$token['error']);
        }
        $accessToken = $token['access_token'];

        if (blank($wabaId) || blank($phoneNumberId)) {
            $resolved = $this->resolveAssets($accessToken, $wabaId);
            $wabaId = $wabaId ?: $resolved['waba_id'];
            $phoneNumberId = $phoneNumberId ?: $resolved['phone_number_id'];
        }

        if (blank($wabaId) || blank($phoneNumberId)) {
            return $this->fail('Meta did not share a WhatsApp Business Account and phone number. Please finish every step in the popup (including adding a phone number) and try again.');
        }

        if ($this->integrations->whatsappPhoneIdUsedElsewhere($workspace, $phoneNumberId)) {
            return $this->fail('This WhatsApp number is already connected to another workspace.');
        }

        $subscribe = $this->graph($accessToken)->post($this->url($wabaId.'/subscribed_apps'));
        if (! $subscribe->successful()) {
            return $this->fail('Could not subscribe RankwayAI to your WhatsApp Business Account: '.$this->errorText($subscribe));
        }

        $pin = filled($pin) ? (string) $pin : (string) random_int(100000, 999999);
        $register = $this->graph($accessToken)->post($this->url($phoneNumberId.'/register'), [
            'messaging_product' => 'whatsapp',
            'pin' => $pin,
        ]);
        $registered = $register->successful();
        $registerError = $registered ? null : $this->errorText($register);

        $phone = $this->graph($accessToken)->get($this->url($phoneNumberId), [
            'fields' => 'display_phone_number,verified_name',
        ]);
        $display = $phone->successful() ? $phone->json('display_phone_number') : null;
        $verifiedName = $phone->successful() ? $phone->json('verified_name') : null;

        $existing = $this->integrations->getRecord($workspace, 'whatsapp_meta');
        $creds = [
            'phone_number_id' => $phoneNumberId,
            'waba_id' => $wabaId,
            'access_token' => $accessToken,
            'api_version' => $this->version(),
        ];
        if (blank($existing?->credential('verify_token'))) {
            $creds['verify_token'] = Str::random(32);
        }
        if (filled($display) && blank($existing?->credential('business_phone'))) {
            $creds['business_phone'] = '+'.ltrim(preg_replace('/[^\d+]/', '', (string) $display), '+');
        }
        if (filled($verifiedName) && blank($existing?->credential('business_display_name'))) {
            $creds['business_display_name'] = (string) $verifiedName;
        }

        $row = $this->integrations->upsert($workspace, 'whatsapp_meta', $creds, true);
        $this->finalize($row, $registered, $registerError, $pin, $display, $verifiedName);

        if (! $registered) {
            Log::channel('whatsapp')->warning('whatsapp.embedded_signup.register_failed', [
                'workspace_id' => $workspace->id,
                'error' => $registerError,
            ]);

            return [
                'ok' => false,
                'error' => 'Number connected, but Meta did not activate it for sending: '.$registerError
                    .' If this number already has a WhatsApp two-step verification PIN, enter it and click "Activate number".',
                'registered' => false,
                'display_phone_number' => $display,
                'verified_name' => $verifiedName,
            ];
        }

        return [
            'ok' => true,
            'error' => null,
            'registered' => true,
            'display_phone_number' => $display,
            'verified_name' => $verifiedName,
        ];
    }

    /**
     * Retry Cloud API registration with the customer's existing two-step PIN.
     *
     * @return array{ok:bool, error:?string}
     */
    public function register(Workspace $workspace, string $pin): array
    {
        $row = $this->integrations->getRecord($workspace, 'whatsapp_meta');
        $phoneId = (string) ($row?->credential('phone_number_id') ?: '');
        $token = (string) ($row?->credential('access_token') ?: '');
        if ($phoneId === '' || $token === '') {
            return ['ok' => false, 'error' => 'Connect WhatsApp first.'];
        }

        $response = $this->graph($token)->post($this->url($phoneId.'/register'), [
            'messaging_product' => 'whatsapp',
            'pin' => $pin,
        ]);

        if (! $response->successful()) {
            $error = $this->errorText($response);
            $row->update(['last_error' => $error]);

            return ['ok' => false, 'error' => $error];
        }

        $this->finalize($row, true, null, $pin, $row->credential('verified_phone'), $row->credential('verified_name'));

        return ['ok' => true, 'error' => null];
    }

    private function finalize(
        WorkspaceIntegration $row,
        bool $registered,
        ?string $registerError,
        string $pin,
        ?string $display,
        ?string $verifiedName,
    ): void {
        $row->update([
            'credentials' => array_merge($row->credentials ?? [], array_filter([
                'connected_via' => 'embedded_signup',
                'onboarding_status' => $registered ? 'connected' : 'needs_pin',
                'registration_pin' => $registered ? $pin : null,
                'verified_phone' => $display,
                'verified_name' => $verifiedName,
            ], fn ($v) => filled($v))),
            'status' => $registered ? 'connected' : 'error',
            'last_error' => $registerError,
            'connected_at' => $registered ? ($row->connected_at ?: now()) : null,
        ]);
    }

    /**
     * @return array{ok:bool, access_token:?string, error:?string}
     */
    private function exchangeCode(string $code): array
    {
        $response = $this->http()->get($this->url('oauth/access_token'), [
            'client_id' => config('services.meta.app_id'),
            'client_secret' => config('services.meta.app_secret'),
            'code' => $code,
        ]);

        $token = $response->json('access_token');
        if (! $response->successful() || blank($token)) {
            return ['ok' => false, 'access_token' => null, 'error' => $this->errorText($response)];
        }

        return ['ok' => true, 'access_token' => (string) $token, 'error' => null];
    }

    /**
     * Fallback when the popup's session info message did not reach the browser.
     *
     * @return array{waba_id:?string, phone_number_id:?string}
     */
    private function resolveAssets(string $accessToken, ?string $wabaId): array
    {
        if (blank($wabaId)) {
            $debug = $this->http()->get($this->url('debug_token'), [
                'input_token' => $accessToken,
                'access_token' => config('services.meta.app_id').'|'.config('services.meta.app_secret'),
            ]);

            foreach ((array) $debug->json('data.granular_scopes', []) as $scope) {
                if (($scope['scope'] ?? '') === 'whatsapp_business_management' && ! empty($scope['target_ids'])) {
                    $wabaId = (string) $scope['target_ids'][0];
                    break;
                }
            }
        }

        if (blank($wabaId)) {
            return ['waba_id' => null, 'phone_number_id' => null];
        }

        $numbers = $this->graph($accessToken)->get($this->url($wabaId.'/phone_numbers'), [
            'fields' => 'id,display_phone_number,verified_name',
        ]);

        return [
            'waba_id' => $wabaId,
            'phone_number_id' => $numbers->json('data.0.id') ? (string) $numbers->json('data.0.id') : null,
        ];
    }

    private function http(): PendingRequest
    {
        return Http::timeout(20)
            ->connectTimeout(15)
            // Shared hosts often hang on AAAA lookups for graph.facebook.com.
            ->withOptions(['force_ip_resolve' => 'v4'])
            ->acceptJson();
    }

    private function graph(string $token): PendingRequest
    {
        return $this->http()->withToken($token);
    }

    private function url(string $path): string
    {
        return 'https://graph.facebook.com/'.$this->version().'/'.ltrim($path, '/');
    }

    private function version(): string
    {
        return ltrim((string) (config('services.meta.whatsapp_api_version') ?: 'v25.0'), '/');
    }

    private function errorText(Response $response): string
    {
        $code = $response->json('error.code');
        $message = (string) ($response->json('error.error_user_msg')
            ?? $response->json('error.message')
            ?? Str::limit($response->body(), 200));

        $prefix = filled($code) && ! str_contains($message, '(#'.$code.')') ? '(#'.$code.') ' : '';

        return trim($prefix.$message);
    }

    /**
     * @return array{ok:false, error:string, registered:false, display_phone_number:null, verified_name:null}
     */
    private function fail(string $error): array
    {
        return [
            'ok' => false,
            'error' => $error,
            'registered' => false,
            'display_phone_number' => null,
            'verified_name' => null,
        ];
    }
}
