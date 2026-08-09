<?php

namespace App\Services\Billing;

use App\Models\Workspace;
use App\Models\WorkspaceSubscription;
use App\Support\NavModules;

class PlanAccess
{
    /** Modules available without a paid plan or credit top-up. */
    public const FREE_MODULES = ['seo', 'billing', 'settings'];

    public function __construct(
        private BillingService $billing,
        private CreditWalletService $wallet,
    ) {}

    /**
     * @return array{
     *   plan:string,
     *   status:string,
     *   paid:bool,
     *   unlocked:bool,
     *   topup:int,
     *   features:array<string,bool>,
     *   modules:list<string>
     * }
     */
    public function summary(Workspace $workspace): array
    {
        $sub = $this->billing->subscription($workspace);
        $paid = $this->isPaid($sub);
        $topupCredits = $this->wallet->snapshot($workspace, $sub)['topup'];
        $unlocked = $paid || $topupCredits > 0;

        return [
            'plan' => $sub->plan,
            'status' => $sub->status,
            'paid' => $paid,
            'unlocked' => $unlocked,
            'topup' => $topupCredits,
            'features' => $this->featuresFor($unlocked),
            'modules' => $this->modulesFor($workspace),
        ];
    }

    /**
     * @return array<string, bool>
     */
    public function features(Workspace $workspace): array
    {
        return $this->summary($workspace)['features'];
    }

    /**
     * Sidebar / route modules allowed for this workspace.
     *
     * @return list<string>
     */
    public function modulesFor(Workspace $workspace): array
    {
        if ($this->hasUnlockedAccess($workspace)) {
            return NavModules::keys();
        }

        return self::FREE_MODULES;
    }

    public function allows(Workspace $workspace, string $feature): bool
    {
        $features = $this->features($workspace);

        return (bool) ($features[$feature] ?? false);
    }

    /**
     * Paid subscription or purchased top-up credits unlock the full product.
     */
    public function hasUnlockedAccess(Workspace $workspace): bool
    {
        $sub = $this->billing->subscription($workspace);

        if ($this->isPaid($sub)) {
            return true;
        }

        return $this->wallet->snapshot($workspace, $sub)['topup'] > 0;
    }

    public function denyMessage(string $feature): string
    {
        return match ($feature) {
            'ai' => 'AI needs a paid plan or credit top-up. Buy credits or upgrade to continue.',
            'channel_send' => 'Sending WhatsApp / Email / RCS needs a paid plan or credit top-up.',
            'seo_apis' => 'Google Search Console & PageSpeed are temporarily unavailable.',
            'seo_metrics' => 'Keyword volume, difficulty, and live SERP ranks are temporarily unavailable.',
            'seo_local' => 'Local pack / Maps rank tracking needs a paid plan or credit top-up.',
            'seo_backlinks' => 'Backlink data needs a paid plan or credit top-up.',
            'seo_cms' => 'CMS autopublish needs a paid plan or credit top-up.',
            'seo_js_crawl' => 'JavaScript / advanced crawl needs a paid plan or credit top-up.',
            'social_oauth' => 'Live social connect & publish needs a paid plan or credit top-up.',
            'social_publish' => 'Publishing to social networks needs a paid plan or credit top-up.',
            'api' => 'This action needs a paid plan or credit top-up. Buy credits or upgrade to continue.',
            default => 'Free includes SEO audit, GSC, PageSpeed, and keyword metrics. Buy credits or upgrade for other modules.',
        };
    }

    public function isPaid(?WorkspaceSubscription $sub): bool
    {
        if (! $sub) {
            return false;
        }

        if ($sub->plan === 'free') {
            return false;
        }

        return ! in_array($sub->status, ['cancelled', 'canceled', 'expired'], true);
    }

    /**
     * Free = SEO toolkit only. Paid plan OR any top-up credits unlock all paid features.
     *
     * @return array<string, bool>
     */
    private function featuresFor(bool $unlocked): array
    {
        return [
            'ai' => $unlocked,
            'api' => $unlocked,
            'channel_send' => $unlocked,
            'seo_apis' => true,
            'seo_metrics' => true,
            'seo_local' => $unlocked,
            'seo_backlinks' => $unlocked,
            'seo_cms' => $unlocked,
            'seo_js_crawl' => $unlocked,
            'social_oauth' => $unlocked,
            'social_publish' => $unlocked,
            'seo_audit' => true,
        ];
    }
}
