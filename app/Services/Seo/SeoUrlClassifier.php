<?php

namespace App\Services\Seo;

/**
 * Classifies URLs for SEO crawl/audit scope.
 *
 * Ranking SEO is for public marketing pages. Auth utilities and post-login
 * app pages should not burn crawl budget or create fake "fix noindex" todos.
 */
class SeoUrlClassifier
{
    /**
     * Post-login / account / checkout style paths — skip crawl + audit.
     *
     * @var list<string>
     */
    private const PRIVATE_APP_SEGMENTS = [
        'cart',
        'basket',
        'bag',
        'checkout',
        'orders',
        'order',
        'account',
        'my-account',
        'myaccount',
        'profile',
        'dashboard',
        'wishlist',
        'favorites',
        'favourite',
        'billing',
        'invoices',
        'invoice',
        'wallet',
        'notifications',
        'messages',
        'inbox',
        'settings',
        'admin',
        'wp-admin',
        'wp-login',
        'member',
        'members',
        'customer',
        'customers',
        'user',
        'users',
        'my-orders',
        'my-profile',
        'portal',
    ];

    /**
     * Login/register/password flows — may appear in public nav, but noindex is intentional.
     *
     * @var list<string>
     */
    private const AUTH_UTILITY_SEGMENTS = [
        'login',
        'log-in',
        'signin',
        'sign-in',
        'register',
        'signup',
        'sign-up',
        'logout',
        'log-out',
        'auth',
        'password',
        'forgot-password',
        'reset-password',
        'verify-email',
        'email/verify',
        'two-factor',
        '2fa',
    ];

    public function pathOf(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);

        return $path === null || $path === '' ? '/' : '/'.trim($path, '/');
    }

    /**
     * Account, cart, orders, profile, dashboard, etc.
     */
    public function isPrivateAppUrl(string $url): bool
    {
        return $this->pathMatches($this->pathOf($url), self::PRIVATE_APP_SEGMENTS);
    }

    /**
     * Login, register, password reset, /auth/*, etc.
     */
    public function isAuthUtilityUrl(string $url): bool
    {
        return $this->pathMatches($this->pathOf($url), self::AUTH_UTILITY_SEGMENTS);
    }

    /**
     * Should this URL be crawled for SEO?
     */
    public function shouldCrawl(string $url): bool
    {
        return ! $this->isPrivateAppUrl($url) && ! $this->isAuthUtilityUrl($url);
    }

    /**
     * Should ranking issues (title/meta/h1/noindex/…) be raised?
     */
    public function shouldAuditForRanking(string $url): bool
    {
        return $this->shouldCrawl($url);
    }

    /**
     * noindex on auth utilities is expected — not a critical SEO bug.
     */
    public function expectsNoindex(string $url): bool
    {
        return $this->isAuthUtilityUrl($url) || $this->isPrivateAppUrl($url);
    }

    /**
     * @param  list<string>  $segments
     */
    private function pathMatches(string $path, array $segments): bool
    {
        $normalized = strtolower(rtrim($path, '/') ?: '/');
        if ($normalized === '/') {
            return false;
        }

        $parts = array_values(array_filter(explode('/', trim($normalized, '/'))));
        if ($parts === []) {
            return false;
        }

        foreach ($segments as $segment) {
            $segment = strtolower($segment);
            // Exact path or prefix: /cart, /cart/..., /auth/login, /vendor/auth/register
            if ($normalized === '/'.$segment || str_starts_with($normalized, '/'.$segment.'/')) {
                return true;
            }
            // Segment anywhere in path (e.g. /vendor/auth/register → auth)
            if (in_array($segment, $parts, true)) {
                return true;
            }
        }

        return false;
    }
}
