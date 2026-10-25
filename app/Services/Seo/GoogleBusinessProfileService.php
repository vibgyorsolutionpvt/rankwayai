<?php

namespace App\Services\Seo;

use App\Models\SeoSite;
use App\Services\Integrations\WorkspaceIntegrationService;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class GoogleBusinessProfileService
{
    public const SCOPE = 'https://www.googleapis.com/auth/business.manage';

    public function __construct(private WorkspaceIntegrationService $integrations) {}

    public function configured(SeoSite $site): bool
    {
        return $this->integrations->googleGscConfig($site->workspace) !== null;
    }

    public function authorizeUrl(SeoSite $site): string
    {
        $config = $this->integrations->googleGscConfig($site->workspace);
        $state = base64_encode(json_encode(['site_id' => $site->id, 'nonce' => Str::random(12)]));

        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => $config['client_id'],
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function connect(SeoSite $site, string $code): array
    {
        if (! $this->configured($site)) {
            return ['ok' => false, 'message' => 'Add the Google OAuth client under Settings → Providers → SEO / Google first.'];
        }

        $tokens = $this->exchangeCode($site, $code);
        if (! $tokens) {
            return ['ok' => false, 'message' => 'Google did not return a token. Add this redirect in Google Cloud: '.$this->redirectUri()];
        }

        $site->update([
            'gbp_connected' => true,
            'gbp_token' => json_encode($tokens),
            'gbp_last_error' => null,
        ]);

        try {
            $locations = $this->listLocations($site->fresh());
        } catch (RuntimeException $e) {
            $site->update(['gbp_last_error' => $e->getMessage()]);

            return ['ok' => false, 'message' => $e->getMessage()];
        }

        $site->update(['gbp_locations' => $locations]);

        if (count($locations) === 1) {
            $this->selectLocation($site->fresh(), $locations[0]['account_name'], $locations[0]['location_name']);
        }

        return [
            'ok' => true,
            'message' => count($locations) === 0
                ? 'Google account connected, but it has no Business listings.'
                : 'Google Business connected. '.count($locations).' listing'.(count($locations) === 1 ? '' : 's').' found.',
        ];
    }

    /**
     * @return list<array{account_name:string,location_name:string,title:string,address:string,phone:string,website:string}>
     */
    public function listLocations(SeoSite $site): array
    {
        $token = $this->accessToken($site);
        $accounts = $this->get($token, 'https://mybusinessaccountmanagement.googleapis.com/v1/accounts');
        $locations = [];

        foreach ($accounts['accounts'] ?? [] as $account) {
            $accountName = (string) ($account['name'] ?? '');
            if ($accountName === '') {
                continue;
            }
            $payload = $this->get($token, 'https://mybusinessbusinessinformation.googleapis.com/v1/'.$accountName.'/locations', [
                'readMask' => 'name,title,storefrontAddress,phoneNumbers,websiteUri',
                'pageSize' => 100,
            ]);
            foreach ($payload['locations'] ?? [] as $location) {
                $locations[] = [
                    'account_name' => $accountName,
                    'location_name' => (string) ($location['name'] ?? ''),
                    'title' => (string) ($location['title'] ?? 'Untitled listing'),
                    'address' => $this->formatAddress($location['storefrontAddress'] ?? []),
                    'phone' => (string) ($location['phoneNumbers']['primaryPhone'] ?? ''),
                    'website' => (string) ($location['websiteUri'] ?? ''),
                ];
            }
        }

        return $locations;
    }

    public function selectLocation(SeoSite $site, string $accountName, string $locationName): void
    {
        $match = collect($site->gbp_locations ?? [])->first(fn (array $row) => $row['location_name'] === $locationName && $row['account_name'] === $accountName);
        $site->update([
            'gbp_account_name' => $accountName,
            'gbp_location_name' => $locationName,
            'gbp_location_title' => $match['title'] ?? $site->gbp_location_title,
            'gbp_last_error' => null,
        ]);
        $this->refresh($site->fresh());
    }

    public function refresh(SeoSite $site): void
    {
        if (blank($site->gbp_location_name) || blank($site->gbp_account_name)) {
            $site->update(['gbp_locations' => $this->listLocations($site)]);

            return;
        }

        $token = $this->accessToken($site);
        $location = $this->get($token, 'https://mybusinessbusinessinformation.googleapis.com/v1/'.$site->gbp_location_name, [
            'readMask' => 'name,title,storeCode,phoneNumbers,categories,storefrontAddress,websiteUri,regularHours,specialHours,serviceArea,labels,openInfo,metadata,profile',
        ]);
        $reviews = $this->reviews($token, $site);
        $profile = $this->profileFromLocation($location, $this->verification($token, $location));

        $site->update([
            'gbp_location_title' => $profile['title'] !== '' ? $profile['title'] : $site->gbp_location_title,
            'gbp_profile' => $profile,
            'gbp_reviews' => $reviews['items'],
            'gbp_last_error' => $reviews['error'],
        ]);
    }

    public function updateListing(SeoSite $site, string $phone, string $website, string $description): void
    {
        $this->requireLocation($site);
        $mask = [];
        $body = [];
        if ($phone !== '') {
            $mask[] = 'phoneNumbers.primaryPhone';
            $body['phoneNumbers'] = ['primaryPhone' => $phone];
        }
        if ($website !== '') {
            $mask[] = 'websiteUri';
            $body['websiteUri'] = $website;
        }
        $mask[] = 'profile.description';
        $body['profile'] = ['description' => $description];

        $this->patch(
            $this->accessToken($site),
            'https://mybusinessbusinessinformation.googleapis.com/v1/'.$site->gbp_location_name,
            $body,
            ['updateMask' => implode(',', $mask)],
        );
        $this->refresh($site->fresh());
    }

    public function replyToReview(SeoSite $site, string $reviewName, string $comment): void
    {
        $this->requireLocation($site);
        $prefix = $site->gbp_account_name.'/'.$site->gbp_location_name.'/reviews/';
        if (! str_starts_with($reviewName, $prefix)) {
            throw new RuntimeException('That review is not on the connected listing.');
        }

        $this->put(
            $this->accessToken($site),
            'https://mybusiness.googleapis.com/v4/'.$reviewName.'/reply',
            ['comment' => $comment],
        );
        $this->refresh($site->fresh());
    }

    public function publishPost(SeoSite $site, string $summary): void
    {
        $this->requireLocation($site);
        $this->post(
            $this->accessToken($site),
            'https://mybusiness.googleapis.com/v4/'.$site->gbp_account_name.'/'.$site->gbp_location_name.'/localPosts',
            [
                'languageCode' => 'en',
                'summary' => $summary,
                'topicType' => 'STANDARD',
            ],
        );
    }

    private function requireLocation(SeoSite $site): void
    {
        if (blank($site->gbp_account_name) || blank($site->gbp_location_name)) {
            throw new RuntimeException('Choose a Google Business listing first.');
        }
    }

    private function accessToken(SeoSite $site): string
    {
        $tokens = json_decode((string) $site->gbp_token, true);
        if (! is_array($tokens) || blank($tokens['access_token'] ?? null)) {
            throw new RuntimeException('Connect Google Business again.');
        }

        $expiresAt = (int) ($tokens['expires_at'] ?? 0);
        if ($expiresAt === 0 || $expiresAt > now()->timestamp) {
            return (string) $tokens['access_token'];
        }
        if (blank($tokens['refresh_token'] ?? null)) {
            throw new RuntimeException('Google Business access expired. Connect again.');
        }

        $config = $this->integrations->googleGscConfig($site->workspace);
        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'refresh_token' => $tokens['refresh_token'],
            'grant_type' => 'refresh_token',
        ]);
        if (! $response->successful() || blank($response->json('access_token'))) {
            throw new RuntimeException('Google Business access expired. Connect again.');
        }

        $tokens['access_token'] = (string) $response->json('access_token');
        $tokens['expires_at'] = now()->addSeconds((int) ($response->json('expires_in') ?? 3600) - 60)->timestamp;
        $site->update(['gbp_token' => json_encode($tokens)]);

        return $tokens['access_token'];
    }

    /**
     * @return array{access_token:string,refresh_token?:string,expires_at:int}|null
     */
    private function exchangeCode(SeoSite $site, string $code): ?array
    {
        $config = $this->integrations->googleGscConfig($site->workspace);
        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'code' => $code,
            'client_id' => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'redirect_uri' => $this->redirectUri(),
            'grant_type' => 'authorization_code',
        ]);
        if (! $response->successful() || blank($response->json('access_token'))) {
            return null;
        }

        $payload = [
            'access_token' => (string) $response->json('access_token'),
            'expires_at' => now()->addSeconds((int) ($response->json('expires_in') ?? 3600) - 60)->timestamp,
        ];
        if (filled($response->json('refresh_token'))) {
            $payload['refresh_token'] = (string) $response->json('refresh_token');
        }

        return $payload;
    }

    private function redirectUri(): string
    {
        if (app()->runningInConsole()) {
            return route('seo.gbp.callback');
        }

        return url('/seo/gbp/callback');
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function get(string $token, string $url, array $query = []): array
    {
        $response = Http::withToken($token)->timeout(30)->get($url, $query);
        $this->ensureOk($response);

        return $response->json() ?? [];
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $query
     */
    private function patch(string $token, string $url, array $body, array $query): void
    {
        $response = Http::withToken($token)->timeout(30)->patch($url.'?'.http_build_query($query), $body);
        $this->ensureOk($response);
    }

    /** @param  array<string, mixed>  $body */
    private function put(string $token, string $url, array $body): void
    {
        $response = Http::withToken($token)->timeout(30)->put($url, $body);
        $this->ensureOk($response);
    }

    /** @param  array<string, mixed>  $body */
    private function post(string $token, string $url, array $body): void
    {
        $response = Http::withToken($token)->timeout(30)->post($url, $body);
        $this->ensureOk($response);
    }

    private function ensureOk(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $message = (string) ($response->json('error.message') ?: 'Google Business request failed.');
        if ($response->status() === 403) {
            $message = 'Google has not enabled Business Profile API access for this OAuth client. Enable Account Management, Business Information, and My Business APIs, then request access. '.$message;
        }

        throw new RuntimeException($message);
    }

    /**
     * @param  array<string, mixed>  $location
     * @param  array{status:string,label:string}  $verification
     * @return array<string, mixed>
     */
    private function profileFromLocation(array $location, array $verification): array
    {
        $primary = (string) ($location['categories']['primaryCategory']['displayName'] ?? '');
        $extraCategories = collect($location['categories']['additionalCategories'] ?? [])
            ->map(fn ($category) => (string) ($category['displayName'] ?? ''))
            ->filter()
            ->implode(', ');
        $serviceArea = collect($location['serviceArea']['places']['placeInfos'] ?? [])
            ->map(fn ($place) => (string) ($place['placeName'] ?? ''))
            ->filter()
            ->implode(', ');
        $labels = collect($location['labels'] ?? [])->map(fn ($label) => (string) $label)->filter()->implode(', ');

        return [
            'title' => (string) ($location['title'] ?? ''),
            'address' => $this->formatAddress($location['storefrontAddress'] ?? []),
            'phone' => (string) ($location['phoneNumbers']['primaryPhone'] ?? ''),
            'extra_phones' => collect($location['phoneNumbers']['additionalPhones'] ?? [])->map(fn ($phone) => (string) $phone)->filter()->implode(', '),
            'website' => (string) ($location['websiteUri'] ?? ''),
            'description' => (string) ($location['profile']['description'] ?? ''),
            'hours' => $this->formatHours($location['regularHours']['periods'] ?? []),
            'special_hours' => $this->formatSpecialHours($location['specialHours']['specialHourPeriods'] ?? []),
            'category' => $primary,
            'extra_categories' => $extraCategories,
            'open_status' => $this->openStatus((string) ($location['openInfo']['status'] ?? '')),
            'service_area' => $serviceArea,
            'store_code' => (string) ($location['storeCode'] ?? ''),
            'labels' => $labels,
            'place_id' => (string) ($location['metadata']['placeId'] ?? ''),
            'maps_uri' => (string) ($location['metadata']['mapsUri'] ?? ''),
            'verification' => $verification,
        ];
    }

    /**
     * @param  array<string, mixed>  $location
     * @return array{status:string,label:string}
     */
    private function verification(string $token, array $location): array
    {
        $metadata = $location['metadata']['hasVoiceOfMerchant'] ?? null;
        $fallback = is_bool($metadata)
            ? $this->verificationState($metadata ? 'verified' : 'unverified')
            : $this->verificationState('unknown');
        $name = (string) ($location['name'] ?? '');
        if ($name === '') {
            return $fallback;
        }

        try {
            $state = $this->get($token, 'https://mybusinessverifications.googleapis.com/v1/'.$name.'/VoiceOfMerchantState');
        } catch (RuntimeException) {
            return $fallback;
        }

        $reason = (string) ($state['complyWithGuidelines']['recommendationReason'] ?? '');
        if ($reason === 'BUSINESS_LOCATION_SUSPENDED') {
            return $this->verificationState('suspended');
        }
        if ($reason === 'BUSINESS_LOCATION_DISABLED') {
            return $this->verificationState('disabled');
        }
        if (($state['verify']['hasPendingVerification'] ?? false) || isset($state['waitForVoiceOfMerchant'])) {
            return $this->verificationState('pending');
        }
        if (($state['hasVoiceOfMerchant'] ?? null) === true) {
            return $this->verificationState('verified');
        }
        if (($state['hasVoiceOfMerchant'] ?? null) === false) {
            return $this->verificationState('unverified');
        }

        return $fallback;
    }

    /** @return array{status:string,label:string} */
    private function verificationState(string $status): array
    {
        $label = match ($status) {
            'verified' => 'Verified',
            'unverified' => 'Not verified',
            'pending' => 'Verification pending',
            'suspended' => 'Suspended',
            'disabled' => 'Disabled',
            default => 'Not available',
        };

        return ['status' => $status, 'label' => $label];
    }

    private function openStatus(string $status): string
    {
        return match ($status) {
            'OPEN' => 'Open',
            'CLOSED_TEMPORARILY' => 'Temporarily closed',
            'CLOSED_PERMANENTLY' => 'Permanently closed',
            default => '',
        };
    }

    /**
     * @return array{items:list<array<string,mixed>>,error:?string}
     */
    private function reviews(string $token, SeoSite $site): array
    {
        try {
            $payload = $this->get($token, 'https://mybusiness.googleapis.com/v4/'.$site->gbp_account_name.'/'.$site->gbp_location_name.'/reviews', [
                'pageSize' => 20,
            ]);
        } catch (RuntimeException $e) {
            return [
                'items' => $site->gbp_reviews ?? [],
                'error' => $e->getMessage(),
            ];
        }

        return [
            'items' => collect($payload['reviews'] ?? [])->map(fn (array $review) => [
                'name' => (string) ($review['name'] ?? ''),
                'reviewer' => (string) ($review['reviewer']['displayName'] ?? 'Google user'),
                'rating' => $this->stars($review['starRating'] ?? ''),
                'comment' => (string) ($review['comment'] ?? ''),
                'reply' => (string) ($review['reviewReply']['comment'] ?? ''),
                'created_at' => (string) ($review['createTime'] ?? ''),
            ])->filter(fn (array $review) => $review['name'] !== '')->values()->all(),
            'error' => null,
        ];
    }

    /** @param  array<string, mixed>  $address */
    private function formatAddress(array $address): string
    {
        $lines = array_filter([
            ...(array) ($address['addressLines'] ?? []),
            $address['locality'] ?? null,
            $address['administrativeArea'] ?? null,
            $address['postalCode'] ?? null,
        ]);

        return implode(', ', array_map('strval', $lines));
    }

    /** @param  list<array<string, mixed>>  $periods */
    private function formatHours(array $periods): string
    {
        return collect($periods)->map(function (array $period) {
            $open = $period['openDay'] ?? '';
            $close = ($period['openTime']['hours'] ?? 0).':'.str_pad((string) ($period['openTime']['minutes'] ?? 0), 2, '0', STR_PAD_LEFT);
            $end = ($period['closeTime']['hours'] ?? 0).':'.str_pad((string) ($period['closeTime']['minutes'] ?? 0), 2, '0', STR_PAD_LEFT);

            return trim($open.' '.$close.'–'.$end);
        })->filter()->implode(' · ');
    }

    /** @param  list<array<string, mixed>>  $periods */
    private function formatSpecialHours(array $periods): string
    {
        return collect($periods)->map(function (array $period) {
            $date = $period['startDate'] ?? [];
            $label = isset($date['year'])
                ? sprintf('%04d-%02d-%02d', $date['year'], $date['month'] ?? 1, $date['day'] ?? 1)
                : '';
            if (! empty($period['closed'])) {
                return trim($label.' Closed');
            }
            $close = ($period['openTime']['hours'] ?? 0).':'.str_pad((string) ($period['openTime']['minutes'] ?? 0), 2, '0', STR_PAD_LEFT);
            $end = ($period['closeTime']['hours'] ?? 0).':'.str_pad((string) ($period['closeTime']['minutes'] ?? 0), 2, '0', STR_PAD_LEFT);

            return trim($label.' '.$close.'–'.$end);
        })->filter()->implode(' · ');
    }

    private function stars(string $rating): int
    {
        return match ($rating) {
            'ONE' => 1,
            'TWO' => 2,
            'THREE' => 3,
            'FOUR' => 4,
            'FIVE' => 5,
            default => 0,
        };
    }
}
