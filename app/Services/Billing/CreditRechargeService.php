<?php

namespace App\Services\Billing;

use App\Models\BillingAccount;
use App\Models\CreditRecharge;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Str;

class CreditRechargeService
{
    public function __construct(
        private BillingService $billing,
        private CreditWalletService $wallet,
        private RazorpayClient $razorpay,
        private BillingAccountService $accounts,
    ) {}

    /**
     * @return array{ok:bool, message:string, checkout_url?:string}
     */
    public function start(Workspace $workspace, User $user, string $packId, string $market): array
    {
        $pack = CreditPackCatalog::find($packId, $market);
        if (! $pack) {
            return ['ok' => false, 'message' => 'Invalid credit pack.'];
        }

        $account = $this->accounts->account($user);

        $recharge = CreditRecharge::query()->create([
            'workspace_id' => $workspace->id,
            'billing_account_id' => $account->id,
            'user_id' => $user->id,
            'pack_id' => $pack['id'],
            'credits' => $pack['credits'],
            'amount' => $pack['amount'],
            'currency' => $pack['currency'],
            'billing_market' => $market,
            'status' => 'pending',
        ]);

        return $this->razorpayCheckout($workspace, $user, $account, $recharge, $pack);
    }

    public function markPaid(CreditRecharge $recharge, string $provider, ?string $providerRef = null): void
    {
        if ($recharge->status === 'paid') {
            return;
        }

        $recharge->update([
            'status' => 'paid',
            'provider' => $provider,
            'provider_ref' => $providerRef ?: $recharge->provider_ref,
        ]);

        $account = $recharge->billingAccount
            ?? $this->accounts->accountForWorkspace($recharge->workspace);

        if ($account) {
            $this->accounts->recordTransaction(
                $account,
                'credit_recharge',
                (float) $recharge->amount,
                (string) $recharge->currency,
                'paid',
                $provider,
                $providerRef,
                $recharge->user,
                $recharge->workspace,
                null,
                $recharge->pack_id,
                (int) $recharge->credits,
            );
        }

        $this->wallet->addTopup($recharge->workspace, (int) $recharge->credits);
    }

    /**
     * When webhooks can't reach localhost, sync paid links on billing page load.
     *
     * @return list<CreditRecharge>
     */
    public function syncPendingRazorpay(
        Workspace $workspace,
        ?BillingAccount $account = null,
        ?string $linkId = null
    ): array {
        if (! $this->billing->razorpayConfigured()) {
            return [];
        }

        $account ??= $this->accounts->accountForWorkspace($workspace);
        if (! $account) {
            return [];
        }

        $query = CreditRecharge::query()
            ->where('billing_account_id', $account->id)
            ->where('status', 'pending')
            ->where('provider', 'razorpay')
            ->whereNotNull('provider_ref')
            ->latest()
            ->limit(10);

        if (filled($linkId)) {
            $query->where('provider_ref', $linkId);
        }

        $paid = [];
        foreach ($query->get() as $recharge) {
            $link = $this->razorpay->getPaymentLink((string) $recharge->provider_ref);
            if (! ($link['ok'] ?? false)) {
                continue;
            }

            if (($link['status'] ?? '') === 'paid' || (float) ($link['amount_paid'] ?? 0) > 0) {
                $this->markPaid($recharge, 'razorpay', $recharge->provider_ref);
                $paid[] = $recharge->fresh();
            }
        }

        return $paid;
    }

    /**
     * @param  array{id:string,credits:int,amount:float,currency:string}  $pack
     * @return array{ok:bool, message:string, checkout_url?:string}
     */
    private function razorpayCheckout(
        Workspace $workspace,
        User $user,
        BillingAccount $account,
        CreditRecharge $recharge,
        array $pack
    ): array {
        if (! $this->billing->razorpayConfigured()) {
            $this->markPaid($recharge, 'manual');

            return [
                'ok' => true,
                'message' => $pack['credits'].' credits added to your wallet.',
            ];
        }

        $linkId = 'cr_'.$recharge->id.'_'.Str::lower(Str::random(6));

        $result = $this->razorpay->createPaymentLink([
            'link_id' => $linkId,
            'amount' => (float) $pack['amount'],
            'currency' => (string) $pack['currency'],
            'purpose' => 'AI credits top-up: '.$pack['credits'].' credits',
            'customer_id' => 'acct_'.$account->id.'_u_'.$user->id,
            'customer_email' => $user->email,
            'customer_phone' => null,
            'customer_name' => $user->name,
            'return_url' => route('billing.index', ['recharge' => 'success']),
            'notes' => [
                'type' => 'credit_recharge',
                'recharge_id' => (string) $recharge->id,
                'billing_account_id' => (string) $account->id,
                'workspace_id' => (string) $workspace->id,
                'credits' => (string) $pack['credits'],
            ],
        ]);

        if (! $result['ok']) {
            $recharge->update(['status' => 'failed']);
            report(new \RuntimeException('Razorpay credit recharge link failed: '.($result['error'] ?? 'unknown')));

            return [
                'ok' => false,
                'message' => $this->paymentStartErrorMessage($result['error'] ?? null),
            ];
        }

        $recharge->update([
            'provider' => 'razorpay',
            'provider_ref' => $result['link_id'],
        ]);

        return [
            'ok' => true,
            'message' => 'Redirecting to payment…',
            'checkout_url' => $result['link_url'],
        ];
    }

    private function paymentStartErrorMessage(?string $razorpayError): string
    {
        if (blank($razorpayError)) {
            return 'Couldn’t start recharge payment. Please try again.';
        }

        $lower = strtolower($razorpayError);

        if (str_contains($lower, 'expired')) {
            return 'Razorpay API keys have expired. Generate new Test keys in Razorpay Dashboard → API Keys, then update `.env`.';
        }

        if (str_contains($lower, 'authentication')) {
            return 'Razorpay authentication failed. Check `RAZORPAY_KEY_ID` and `RAZORPAY_KEY_SECRET` in `.env`.';
        }

        return 'Couldn’t start recharge payment: '.$razorpayError;
    }
}
