<?php

namespace App\Services\Billing;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Free IP → country detection (no paid GeoIP DB).
 * Order: CDN header → session → cache → free APIs → default IN.
 */
class IpCountryResolver
{
    public function countryCode(Request $request): string
    {
        $fromHeader = $this->fromCdnHeader($request);
        if ($fromHeader !== null) {
            return $fromHeader;
        }

        $sessionKey = 'geo.country_code';
        $cachedSession = $request->session()->get($sessionKey);
        if (is_string($cachedSession) && strlen($cachedSession) === 2) {
            return strtoupper($cachedSession);
        }

        $ip = $request->ip();
        if (! $ip || $this->isPrivateIp($ip)) {
            $code = $this->fallbackFromLanguage($request) ?? 'IN';
            $request->session()->put($sessionKey, $code);

            return $code;
        }

        $code = Cache::remember(
            'geo:ip:'.$ip,
            now()->addDays(14),
            fn () => $this->lookupFreeApis($ip) ?? $this->fallbackFromLanguage($request) ?? 'IN'
        );

        $code = strtoupper((string) $code);
        if (strlen($code) !== 2) {
            $code = 'IN';
        }

        $request->session()->put($sessionKey, $code);

        return $code;
    }

    public function marketFor(Request $request): string
    {
        return $this->countryCode($request) === 'IN'
            ? PlanCatalog::MARKET_IN
            : PlanCatalog::MARKET_GLOBAL;
    }

    private function fromCdnHeader(Request $request): ?string
    {
        $raw = strtoupper((string) (
            $request->header('CF-IPCountry')
            ?: $request->header('X-Appengine-Country')
            ?: $request->header('CloudFront-Viewer-Country')
            ?: ''
        ));

        if ($raw === '' || $raw === 'XX' || $raw === 'T1') {
            return null;
        }

        return strlen($raw) === 2 ? $raw : null;
    }

    private function lookupFreeApis(string $ip): ?string
    {
        // 1) GeoJS — free, no key, HTTPS
        try {
            $response = Http::timeout(2)
                ->acceptJson()
                ->get('https://get.geojs.io/v1/ip/country/'.$ip.'.json');

            if ($response->successful()) {
                $code = strtoupper((string) ($response->json('country') ?? $response->json('country_code') ?? ''));
                // geojs country endpoint often returns {"country":"US","name":"...","ip":"..."}
                if (strlen($code) === 2) {
                    return $code;
                }
            }
        } catch (\Throwable $e) {
            Log::debug('GeoJS country lookup failed', ['ip' => $ip, 'error' => $e->getMessage()]);
        }

        // 2) ip-api.com — free non-HTTPS for free tier; fields limited
        try {
            $response = Http::timeout(2)
                ->acceptJson()
                ->get('http://ip-api.com/json/'.$ip, [
                    'fields' => 'status,countryCode',
                ]);

            if ($response->successful() && $response->json('status') === 'success') {
                $code = strtoupper((string) $response->json('countryCode'));
                if (strlen($code) === 2) {
                    return $code;
                }
            }
        } catch (\Throwable $e) {
            Log::debug('ip-api country lookup failed', ['ip' => $ip, 'error' => $e->getMessage()]);
        }

        // 3) ipwho.is — free, no key
        try {
            $response = Http::timeout(2)
                ->acceptJson()
                ->get('https://ipwho.is/'.$ip);

            if ($response->successful() && $response->json('success') !== false) {
                $code = strtoupper((string) $response->json('country_code'));
                if (strlen($code) === 2) {
                    return $code;
                }
            }
        } catch (\Throwable $e) {
            Log::debug('ipwho.is country lookup failed', ['ip' => $ip, 'error' => $e->getMessage()]);
        }

        return null;
    }

    private function fallbackFromLanguage(Request $request): ?string
    {
        $lang = strtolower((string) $request->header('Accept-Language', ''));
        if (str_contains($lang, 'en-in') || str_contains($lang, 'hi')) {
            return 'IN';
        }

        return null;
    }

    private function isPrivateIp(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return true;
        }

        return ! filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }
}
