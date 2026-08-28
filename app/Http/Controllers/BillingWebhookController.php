<?php

namespace App\Http\Controllers;

use App\Models\CreditRecharge;
use App\Models\Workspace;
use App\Services\Billing\BillingService;
use App\Services\Billing\CreditRechargeService;
use App\Services\Billing\PlanCatalog;
use App\Services\Billing\RazorpayClient;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class BillingWebhookController extends Controller
{
    public function stripe(Request $request, BillingService $billing, CreditRechargeService $recharges): Response
    {
        $payload = $request->getContent();
        $secret = config('services.stripe.webhook_secret');

        if (filled($secret)) {
            $sig = $request->header('Stripe-Signature', '');
            if (! $this->verifyStripeSignature($payload, $sig, $secret)) {
                return response('Invalid signature', 400);
            }
        }

        $event = json_decode($payload, true);
        $type = $event['type'] ?? '';
        $object = $event['data']['object'] ?? [];

        if ($type === 'checkout.session.completed') {
            $meta = $object['metadata'] ?? [];

            if (($meta['type'] ?? '') === 'credit_recharge') {
                $recharge = CreditRecharge::query()->find((int) ($meta['recharge_id'] ?? 0));
                if ($recharge) {
                    $recharges->markPaid($recharge, 'stripe', $object['id'] ?? null);
                }

                return response('ok', 200);
            }

            $workspaceId = (int) ($object['client_reference_id'] ?? $meta['workspace_id'] ?? 0);
            $plan = (string) ($meta['plan'] ?? 'starter');
            $interval = PlanCatalog::normalizeInterval($meta['interval'] ?? PlanCatalog::INTERVAL_MONTH);
            $workspace = Workspace::query()->find($workspaceId);
            if ($workspace) {
                $billing->applyCheckoutSuccess(
                    $workspace,
                    $plan,
                    PlanCatalog::MARKET_GLOBAL,
                    'stripe',
                    $object['customer'] ?? null,
                    $object['subscription'] ?? null,
                    $interval
                );
            }
        }

        if (in_array($type, ['customer.subscription.deleted', 'invoice.payment_failed'], true)) {
            $subId = $object['id'] ?? ($object['subscription'] ?? null);
            if ($subId) {
                $row = \App\Models\WorkspaceSubscription::query()
                    ->where('stripe_subscription_id', $subId)
                    ->first();
                if ($row) {
                    $billing->cancel($row->workspace);
                }
            }
        }

        return response('ok', 200);
    }

    public function razorpay(
        Request $request,
        BillingService $billing,
        CreditRechargeService $recharges,
        RazorpayClient $razorpay
    ): Response {
        $payload = $request->getContent();
        $sig = $request->header('X-Razorpay-Signature', '');

        if (! $razorpay->verifyWebhookSignature($payload, $sig)) {
            return response('Invalid signature', 400);
        }

        $event = json_decode($payload, true);
        $eventName = $event['event'] ?? '';

        if (in_array($eventName, ['payment_link.paid', 'payment.captured'], true)) {
            $entity = $event['payload']['payment_link']['entity']
                ?? $event['payload']['payment']['entity']
                ?? [];
            $notes = $entity['notes'] ?? [];

            if (($notes['type'] ?? '') === 'credit_recharge') {
                $recharge = CreditRecharge::query()->find((int) ($notes['recharge_id'] ?? 0));
                if ($recharge) {
                    $recharges->markPaid($recharge, 'razorpay', $entity['id'] ?? null);
                }

                return response('ok', 200);
            }

            if (($notes['type'] ?? '') === 'plan_checkout') {
                $billingAccountId = (int) ($notes['billing_account_id'] ?? 0);
                $workspaceId = (int) ($notes['workspace_id'] ?? 0);
                $plan = (string) ($notes['plan'] ?? 'starter');
                $market = ($notes['market'] ?? '') === PlanCatalog::MARKET_GLOBAL
                    ? PlanCatalog::MARKET_GLOBAL
                    : PlanCatalog::MARKET_IN;
                $interval = PlanCatalog::normalizeInterval($notes['interval'] ?? PlanCatalog::INTERVAL_MONTH);
                $workspace = $workspaceId ? Workspace::query()->find($workspaceId) : null;
                $account = $billingAccountId
                    ? \App\Models\BillingAccount::query()->find($billingAccountId)
                    : null;

                if ($account && $workspace) {
                    $billing->applyCheckoutSuccess(
                        $workspace,
                        $plan,
                        $market,
                        'razorpay',
                        $entity['customer_id'] ?? null,
                        $entity['id'] ?? null,
                        $interval,
                        $account,
                        $account->user,
                        (float) ($entity['amount_paid'] ?? 0),
                    );
                } elseif ($workspace) {
                    $billing->applyCheckoutSuccess(
                        $workspace,
                        $plan,
                        $market,
                        'razorpay',
                        $entity['customer_id'] ?? null,
                        $entity['id'] ?? null,
                        $interval
                    );
                } elseif ($account) {
                    $billing->applyAccountCheckoutSuccess(
                        $account,
                        $plan,
                        $market,
                        'razorpay',
                        $entity['customer_id'] ?? null,
                        $entity['id'] ?? null,
                        $interval,
                        null,
                        (float) ($entity['amount_paid'] ?? 0),
                    );
                }

                return response('ok', 200);
            }
        }

        $entity = $event['payload']['subscription']['entity']
            ?? $event['payload']['payment']['entity']
            ?? [];

        if (in_array($eventName, ['subscription.activated', 'subscription.charged'], true)) {
            $notes = $entity['notes'] ?? [];
            $workspaceId = (int) ($notes['workspace_id'] ?? 0);
            $plan = (string) ($notes['plan'] ?? 'starter');
            $interval = PlanCatalog::normalizeInterval($notes['interval'] ?? PlanCatalog::INTERVAL_MONTH);
            $workspace = Workspace::query()->find($workspaceId);
            if ($workspace) {
                $billing->applyCheckoutSuccess(
                    $workspace,
                    $plan,
                    PlanCatalog::MARKET_IN,
                    'razorpay',
                    $entity['customer_id'] ?? null,
                    $entity['id'] ?? null,
                    $interval
                );
            }
        }

        if (in_array($eventName, ['subscription.cancelled', 'subscription.completed', 'subscription.halted'], true)) {
            $subId = $entity['id'] ?? null;
            if ($subId) {
                $row = \App\Models\WorkspaceSubscription::query()
                    ->where('razorpay_subscription_id', $subId)
                    ->first();
                if ($row) {
                    $billing->cancel($row->workspace);
                }
            }
        }

        return response('ok', 200);
    }

    private function verifyStripeSignature(string $payload, string $header, string $secret): bool
    {
        // Stripe-Signature: t=timestamp,v1=signature
        $parts = [];
        foreach (explode(',', $header) as $item) {
            [$k, $v] = array_pad(explode('=', trim($item), 2), 2, null);
            if ($k && $v) {
                $parts[$k] = $v;
            }
        }

        if (empty($parts['t']) || empty($parts['v1'])) {
            return false;
        }

        $signed = $parts['t'].'.'.$payload;
        $expected = hash_hmac('sha256', $signed, $secret);

        return hash_equals($expected, $parts['v1']);
    }
}
