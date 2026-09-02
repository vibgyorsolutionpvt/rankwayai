import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import HelpGuide, { HELP } from '@/Components/HelpGuide';
import PrimaryButton from '@/Components/PrimaryButton';
import { Head, router } from '@inertiajs/react';

const MAIN_TABS = [
    { id: 'plan', label: 'Plan' },
    { id: 'history', label: 'History' },
];

const HISTORY_TABS = [
    { id: 'today', label: 'Today' },
    { id: '7d', label: '7 days' },
    { id: '30d', label: '30 days' },
];

function formatPrice(plan) {
    const amount = Number(plan.price);
    if (plan.currency === 'INR') {
        return `${plan.symbol}${amount.toLocaleString('en-IN')}`;
    }
    return `${plan.symbol}${amount}`;
}

function formatMoney(amount, currency) {
    const n = Number(amount || 0);
    if (currency === 'INR') {
        return `₹${n.toLocaleString('en-IN')}`;
    }
    return `$${n}`;
}

export default function Index({
    workspace,
    billing_account = null,
    subscription,
    plans = [],
    credit_packs = [],
    credit_history = [],
    payment_history = [],
    owned_workspaces = [],
    shared_workspaces = [],
    is_billing_owner = false,
    history_workspace_filter = null,
    markets = [],
    market = 'in',
    interval = 'month',
    tab = 'plan',
    history_period = '7d',
    can_switch_market = false,
    account_plan = null,
    note,
    admin_note = null,
    local_checkout_hint = null,
    is_platform_admin = false,
    credits_shared = false,
    usage = null,
    ai_history = null,
}) {
    const accountPlanId = billing_account?.plan || account_plan?.plan || subscription.plan || 'free';
    const coversHere = account_plan?.covers_this_workspace !== false;
    const displayPlan =
        billing_account?.plan && billing_account.plan !== 'free'
            ? billing_account.plan
            : subscription.plan !== 'free'
              ? subscription.plan
              : accountPlanId !== 'free' && coversHere
                ? accountPlanId
                : 'free';
    const sharedNames =
        shared_workspaces.length > 0
            ? shared_workspaces.map((w) => w.name)
            : owned_workspaces.length > 0
              ? owned_workspaces.map((w) => w.name)
              : [workspace.name];
    const isFree = displayPlan === 'free';
    const freeHighlights =
        plans.find((p) => p.id === 'free')?.highlights ?? [
            '1 workspace',
            'SEO site audit crawl',
            'Billing & workspace settings',
            'No external APIs (GSC, PageSpeed, social…)',
        ];
    const currency = subscription.billing_currency || (market === 'in' ? 'INR' : 'USD');
    const subInterval = subscription.billing_interval || 'month';
    const charged =
        subscription.mrr_amount != null
            ? Number(subscription.mrr_amount)
            : Number(subscription.mrr_usd || 0);

    const billingQuery = (extra = {}) => {
        const params = {
            tab,
            interval,
            history: history_period,
            ...extra,
        };
        if (history_workspace_filter) {
            params.workspace_filter = history_workspace_filter;
        }
        if (can_switch_market) {
            params.market = extra.market ?? market;
        } else {
            delete params.market;
        }
        return params;
    };

    const visitTab = (next) => {
        router.get(route('billing.index'), billingQuery({ tab: next }), {
            preserveState: true,
            replace: true,
            preserveScroll: true,
        });
    };

    const switchMarket = (id) => {
        router.get(route('billing.index'), billingQuery({ market: id, tab: 'plan' }), {
            preserveState: true,
            replace: true,
        });
    };

    const switchInterval = (next) => {
        router.get(route('billing.index'), billingQuery({ interval: next, tab: 'plan' }), {
            preserveState: true,
            replace: true,
        });
    };

    const switchHistory = (period) => {
        router.get(
            route('billing.index'),
            billingQuery({ history: period, tab: 'history' }),
            {
                preserveState: true,
                replace: true,
                preserveScroll: true,
            },
        );
    };

    const switchHistoryWorkspace = (workspaceId) => {
        router.get(
            route('billing.index'),
            billingQuery({
                tab: 'history',
                workspace_filter: workspaceId || undefined,
            }),
            {
                preserveState: true,
                replace: true,
                preserveScroll: true,
            },
        );
    };

    const selectPlan = (planId) => {
        router.post(route('billing.plan'), { plan: planId, market, interval });
    };

    const buyPack = (packId) => {
        router.post(route('billing.credits.recharge'), { pack: packId, market });
    };

    const formatPackPrice = (pack) => {
        const amount = Number(pack.amount);
        if (pack.currency === 'INR') {
            return `${pack.symbol}${amount.toLocaleString('en-IN')}`;
        }
        return `${pack.symbol}${amount}`;
    };

    const Meter = ({ title, used, limit, pct, locked, hint }) => (
        <div>
            <div className="flex items-baseline justify-between gap-2">
                <div className="text-[11px] font-semibold uppercase tracking-wide text-ink-muted">
                    {title}
                </div>
                <div className="text-sm font-semibold tabular-nums text-ink">
                    {locked ? 'Not on plan' : `${used.toLocaleString()} / ${limit.toLocaleString()}`}
                </div>
            </div>
            <div className="mt-1.5 h-1.5 overflow-hidden rounded-full bg-mist">
                <div
                    className="h-full rounded-full bg-signal"
                    style={{ width: `${locked ? 0 : pct}%` }}
                />
            </div>
            {hint ? <p className="mt-1 text-[11px] text-ink-muted">{hint}</p> : null}
        </div>
    );

    return (
        <AuthenticatedLayout
            header={
                <div>
                    <div className="text-xs font-semibold uppercase tracking-[0.16em] text-ink-muted">
                        Account billing
                    </div>
                    <div className="flex items-center gap-1.5">
                        <h2 className="font-display text-2xl font-bold text-ink">Billing</h2>
                        <HelpGuide help={HELP.billing} />
                    </div>
                    <p className="mt-1 text-sm text-ink-muted">
                        Account plan and credits are shared across{' '}
                        <span className="font-semibold text-ink">{sharedNames.join(' · ')}</span>
                        {is_billing_owner
                            ? '. Your whole team on these workspaces uses the same access.'
                            : '. Managed by the workspace owner’s account.'}
                    </p>
                </div>
            }
        >
            <Head title="Billing" />
            <div className="atlas-shell space-y-4">
                <section className="inline-flex flex-wrap gap-0.5 rounded-lg border border-line bg-mist/80 p-1">
                    {MAIN_TABS.map((t) => (
                        <button
                            key={t.id}
                            type="button"
                            onClick={() => visitTab(t.id)}
                            className={`rounded-md px-4 py-1.5 text-sm font-semibold transition ${
                                tab === t.id
                                    ? 'bg-white text-ink shadow-sm'
                                    : 'text-ink-muted hover:text-ink'
                            }`}
                        >
                            {t.label}
                        </button>
                    ))}
                </section>

                {tab === 'plan' ? (
                    <div className="space-y-4">
                        <section className="atlas-panel space-y-4 p-4">
                            <div>
                                <div className="text-[11px] font-semibold uppercase tracking-wide text-ink-muted">
                                    Current plan
                                </div>
                                <div className="mt-1 font-display text-3xl font-bold capitalize text-ink">
                                    {displayPlan}
                                </div>
                                <div className="mt-1 text-sm text-ink-muted">
                                    {shared_workspaces.length > 0
                                        ? `${shared_workspaces.length} workspace${shared_workspaces.length === 1 ? '' : 's'} on this plan`
                                        : account_plan
                                          ? `${account_plan.workspaces_used || 0}/${account_plan.workspace_limit || 1} workspaces`
                                          : `${subscription.seats} seats`}{' '}
                                    · {formatMoney(charged, currency)}/
                                    {subInterval === 'year' ? 'yr' : 'mo'}
                                    {market === 'in' ? ' · India' : ' · International'}
                                    {!isFree
                                        ? ` · billed ${
                                              subInterval === 'year' ? 'yearly' : 'monthly'
                                          }`
                                        : ''}
                                </div>
                                {isFree ? (
                                    <div className="mt-4 grid gap-4 sm:grid-cols-2">
                                        <div>
                                            <div className="text-[11px] font-semibold uppercase tracking-wide text-signal-strong">
                                                Included
                                            </div>
                                            <ul className="mt-2 space-y-1.5 text-sm text-ink">
                                                {freeHighlights.map((item) => (
                                                    <li key={item} className="flex gap-2">
                                                        <span
                                                            className="mt-0.5 text-signal-strong"
                                                            aria-hidden
                                                        >
                                                            ✓
                                                        </span>
                                                        <span>{item}</span>
                                                    </li>
                                                ))}
                                            </ul>
                                        </div>
                                        <div>
                                            <div className="text-[11px] font-semibold uppercase tracking-wide text-ink-muted">
                                                Paid plans unlock
                                            </div>
                                            <ul className="mt-2 space-y-1.5 text-sm text-ink-muted">
                                                {[
                                                    'AI studio & generations',
                                                    'Social publish / OAuth',
                                                    'WhatsApp, Email & RCS sends',
                                                    'CRM, channels, funnels & media',
                                                    'Backlinks, local pack, CMS & JS crawl',
                                                ].map((item) => (
                                                    <li key={item} className="flex gap-2">
                                                        <span className="mt-0.5" aria-hidden>
                                                            •
                                                        </span>
                                                        <span>{item}</span>
                                                    </li>
                                                ))}
                                            </ul>
                                        </div>
                                    </div>
                                ) : (
                                    <ul className="mt-4 space-y-1.5 text-sm text-ink">
                                        {[
                                            'Full SEO toolkit (audit, GSC, PageSpeed, metrics)',
                                            'AI studio with plan credits',
                                            'Social, WhatsApp / Email / RCS channel sends',
                                            'CRM, media, funnels & other modules',
                                        ].map((item) => (
                                            <li key={item} className="flex gap-2">
                                                <span
                                                    className="mt-0.5 text-signal-strong"
                                                    aria-hidden
                                                >
                                                    ✓
                                                </span>
                                                <span>{item}</span>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                                <p className="mt-4 text-sm text-ink-muted">{note}</p>
                                {local_checkout_hint ? (
                                    <p className="mt-2 rounded-md border border-amber-200 bg-amber-50 px-2.5 py-1.5 text-xs text-amber-900">
                                        {local_checkout_hint}
                                    </p>
                                ) : null}
                                {is_platform_admin && admin_note ? (
                                    <p className="mt-2 rounded-md border border-amber-200 bg-amber-50 px-2.5 py-1.5 text-xs text-amber-900">
                                        {admin_note}
                                    </p>
                                ) : null}
                                {!isFree ? (
                                    <button
                                        type="button"
                                        className="mt-3 text-sm font-semibold text-rose-600"
                                        onClick={() => router.post(route('billing.cancel'))}
                                    >
                                        Downgrade to Free
                                    </button>
                                ) : null}
                            </div>

                            {usage ? (
                                <div className="border-t border-line pt-4">
                                    <div className="text-[11px] font-semibold uppercase tracking-wide text-ink-muted">
                                        This month’s usage
                                    </div>
                                    <p className="mt-0.5 text-sm text-ink-muted">
                                        {usage.period} · included in your {usage.plan} plan
                                        {usage.ai?.topup > 0
                                            ? ` · ${usage.ai.topup.toLocaleString()} top-up credits`
                                            : ''}
                                    </p>
                                    <div className="mt-3 grid gap-4 sm:grid-cols-2">
                                        <Meter
                                            title={usage.ai.label}
                                            used={usage.ai.used}
                                            limit={usage.ai.limit}
                                            pct={usage.ai.pct}
                                            locked={!usage.ai.allowed}
                                            hint={
                                                usage.ai.allowed
                                                    ? `${(usage.ai.available ?? 0).toLocaleString()} available (plan + top-up). Recharge when empty — top-up also unlocks all paid modules.`
                                                    : 'Buy a credit pack or upgrade to unlock all paid modules.'
                                            }
                                        />
                                        <Meter
                                            title={usage.channel_sends.label}
                                            used={usage.channel_sends.used}
                                            limit={usage.channel_sends.limit}
                                            pct={usage.channel_sends.pct}
                                            locked={!usage.channel_sends.allowed}
                                            hint={
                                                usage.channel_sends.allowed
                                                    ? 'WhatsApp / Email / RCS messages sent this month.'
                                                    : 'Upgrade to unlock channel sending.'
                                            }
                                        />
                                    </div>
                                </div>
                            ) : null}
                        </section>

                        {credit_packs.length > 0 ? (
                            <section className="atlas-panel space-y-3 p-4">
                                <div>
                                    <div className="text-[11px] font-semibold uppercase tracking-wide text-ink-muted">
                                        Recharge AI credits
                                    </div>
                                    <p className="mt-0.5 text-sm text-ink-muted">
                                        {credits_shared
                                            ? 'Top-ups go to your account wallet — shared across all your workspaces.'
                                            : 'Top-ups stay in your wallet, don’t expire with the billing month, and unlock paid modules while balance remains.'}
                                    </p>
                                </div>
                                <div className="grid gap-3 sm:grid-cols-3">
                                    {credit_packs.map((pack) => (
                                        <div
                                            key={pack.id}
                                            className="flex flex-col rounded-md border border-line bg-white p-3"
                                        >
                                            <div className="font-display text-lg font-bold text-ink">
                                                {pack.label}
                                            </div>
                                            <div className="mt-1 text-2xl font-bold text-ink">
                                                {formatPackPrice(pack)}
                                            </div>
                                            <PrimaryButton
                                                className="mt-3"
                                                type="button"
                                                onClick={() => buyPack(pack.id)}
                                            >
                                                Buy pack
                                            </PrimaryButton>
                                        </div>
                                    ))}
                                </div>
                            </section>
                        ) : null}

                        {can_switch_market && markets.length > 0 ? (
                            <section className="flex flex-wrap gap-2">
                                {markets.map((m) => {
                                    const active = market === m.id;
                                    return (
                                        <button
                                            key={m.id}
                                            type="button"
                                            onClick={() => switchMarket(m.id)}
                                            className={
                                                'rounded-md border px-3 py-2 text-sm font-semibold transition ' +
                                                (active
                                                    ? 'border-signal bg-signal-soft/60 text-ink'
                                                    : 'border-line bg-white text-ink-muted hover:border-signal/40')
                                            }
                                        >
                                            {m.label}
                                        </button>
                                    );
                                })}
                            </section>
                        ) : null}

                        <section className="space-y-3">
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <div>
                                    <div className="text-[11px] font-semibold uppercase tracking-wide text-ink-muted">
                                        Change plan
                                    </div>
                                    <p className="text-sm text-ink-muted">
                                        Yearly saves ~2 months vs paying monthly.
                                    </p>
                                </div>
                                <div className="inline-flex rounded-md border border-line bg-white p-0.5">
                                    {[
                                        { id: 'month', label: 'Monthly' },
                                        { id: 'year', label: 'Yearly' },
                                    ].map((opt) => {
                                        const active = interval === opt.id;
                                        return (
                                            <button
                                                key={opt.id}
                                                type="button"
                                                onClick={() => switchInterval(opt.id)}
                                                className={
                                                    'rounded px-3 py-1.5 text-sm font-semibold transition ' +
                                                    (active
                                                        ? 'bg-signal text-white'
                                                        : 'text-ink-muted hover:text-ink')
                                                }
                                            >
                                                {opt.label}
                                            </button>
                                        );
                                    })}
                                </div>
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                                {plans.map((plan) => {
                                    const active =
                                        displayPlan === plan.id &&
                                        (plan.id === 'free' || subInterval === interval);
                                    const isYear = interval === 'year' && plan.id !== 'free';
                                    return (
                                        <div
                                            key={plan.id}
                                            className="atlas-panel flex flex-col p-4"
                                        >
                                            <div className="flex items-start justify-between gap-2">
                                                <div className="font-display text-xl font-bold text-ink">
                                                    {plan.name}
                                                </div>
                                                {plan.save_label ? (
                                                    <span className="shrink-0 text-[10px] font-semibold uppercase tracking-wide text-signal-strong">
                                                        {plan.save_label}
                                                    </span>
                                                ) : null}
                                            </div>
                                            <div className="mt-1 text-3xl font-bold text-ink">
                                                {formatPrice(plan)}
                                                <span className="text-base font-semibold text-ink-muted">
                                                    /{isYear ? 'yr' : 'mo'}
                                                </span>
                                            </div>
                                            {isYear ? (
                                                <div className="mt-0.5 text-xs text-ink-muted">
                                                    ≈{' '}
                                                    {formatMoney(
                                                        plan.price_monthly_equiv,
                                                        plan.currency,
                                                    )}
                                                    /mo
                                                </div>
                                            ) : null}
                                            <p className="mt-2 text-sm text-ink-muted">{plan.blurb}</p>
                                            {Array.isArray(plan.highlights) &&
                                            plan.highlights.length > 0 ? (
                                                <ul className="mt-3 flex-1 space-y-1.5 text-sm text-ink">
                                                    {plan.highlights.map((item) => (
                                                        <li key={item} className="flex gap-2">
                                                            <span
                                                                className="mt-0.5 text-signal-strong"
                                                                aria-hidden
                                                            >
                                                                ✓
                                                            </span>
                                                            <span>{item}</span>
                                                        </li>
                                                    ))}
                                                </ul>
                                            ) : (
                                                <div className="flex-1" />
                                            )}
                                            <PrimaryButton
                                                className="mt-4"
                                                type="button"
                                                disabled={active}
                                                onClick={() => selectPlan(plan.id)}
                                            >
                                                {active
                                                    ? 'Current plan'
                                                    : plan.id === 'free'
                                                      ? 'Switch to Free'
                                                      : interval === 'year'
                                                        ? 'Choose yearly'
                                                        : 'Choose monthly'}
                                            </PrimaryButton>
                                        </div>
                                    );
                                })}
                            </div>
                        </section>
                    </div>
                ) : null}

                {tab === 'history' ? (
                    <div className="space-y-4">
                        <section className="atlas-panel space-y-4 p-4">
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <div className="text-[11px] font-semibold uppercase tracking-wide text-ink-muted">
                                        AI usage history
                                    </div>
                                    <p className="mt-0.5 text-sm text-ink-muted">
                                        Account-wide credits and tokens by team member
                                        {ai_history?.from ? ` · since ${ai_history.from}` : ''}.
                                    </p>
                                </div>
                                <div className="flex flex-wrap items-center gap-2">
                                    {owned_workspaces.length > 1 ? (
                                        <select
                                            value={history_workspace_filter || ''}
                                            onChange={(e) =>
                                                switchHistoryWorkspace(
                                                    e.target.value
                                                        ? Number(e.target.value)
                                                        : null,
                                                )
                                            }
                                            className="rounded-md border border-line bg-white px-2.5 py-1.5 text-sm font-medium text-ink"
                                        >
                                            <option value="">All workspaces</option>
                                            {owned_workspaces.map((w) => (
                                                <option key={w.id} value={w.id}>
                                                    {w.name}
                                                </option>
                                            ))}
                                        </select>
                                    ) : null}
                                    <div className="inline-flex rounded-md border border-line bg-mist/80 p-0.5">
                                    {HISTORY_TABS.map((t) => {
                                        const active =
                                            (ai_history?.period || history_period) === t.id;
                                        return (
                                            <button
                                                key={t.id}
                                                type="button"
                                                onClick={() => switchHistory(t.id)}
                                                className={`rounded px-3 py-1.5 text-sm font-semibold transition ${
                                                    active
                                                        ? 'bg-white text-ink shadow-sm'
                                                        : 'text-ink-muted hover:text-ink'
                                                }`}
                                            >
                                                {t.label}
                                            </button>
                                        );
                                    })}
                                </div>
                                </div>
                            </div>

                            {ai_history ? (
                                <>
                                    <div className="grid gap-3 sm:grid-cols-3">
                                        <div className="rounded-md border border-line bg-mist/40 px-3 py-2.5">
                                            <div className="text-[10px] font-semibold uppercase tracking-wide text-ink-muted">
                                                Credits used
                                            </div>
                                            <div className="mt-0.5 font-display text-2xl font-bold tabular-nums text-ink">
                                                {ai_history.totals.credits.toLocaleString()}
                                            </div>
                                        </div>
                                        <div className="rounded-md border border-line bg-mist/40 px-3 py-2.5">
                                            <div className="text-[10px] font-semibold uppercase tracking-wide text-ink-muted">
                                                Tokens
                                            </div>
                                            <div className="mt-0.5 font-display text-2xl font-bold tabular-nums text-ink">
                                                {ai_history.totals.tokens.toLocaleString()}
                                            </div>
                                        </div>
                                        <div className="rounded-md border border-line bg-mist/40 px-3 py-2.5">
                                            <div className="text-[10px] font-semibold uppercase tracking-wide text-ink-muted">
                                                Events
                                            </div>
                                            <div className="mt-0.5 font-display text-2xl font-bold tabular-nums text-ink">
                                                {ai_history.totals.events.toLocaleString()}
                                            </div>
                                        </div>
                                    </div>

                                    <div>
                                        <div className="text-[11px] font-semibold uppercase tracking-wide text-ink-muted">
                                            By member
                                        </div>
                                        {ai_history.members.length === 0 ? (
                                            <p className="mt-2 text-sm text-ink-muted">
                                                No AI usage in this period.
                                            </p>
                                        ) : (
                                            <ul className="mt-2 divide-y divide-line rounded-md border border-line">
                                                {ai_history.members.map((m) => (
                                                    <li
                                                        key={m.user_id ?? `anon-${m.name}`}
                                                        className="flex flex-wrap items-center justify-between gap-2 px-3 py-2.5 text-sm"
                                                    >
                                                        <div className="min-w-0">
                                                            <div className="font-semibold text-ink">
                                                                {m.name}
                                                            </div>
                                                            {m.email ? (
                                                                <div className="truncate text-xs text-ink-muted">
                                                                    {m.email}
                                                                </div>
                                                            ) : null}
                                                        </div>
                                                        <div className="shrink-0 text-right tabular-nums">
                                                            <div className="font-semibold text-ink">
                                                                {m.credits.toLocaleString()} cr
                                                            </div>
                                                            <div className="text-xs text-ink-muted">
                                                                {m.tokens.toLocaleString()} tok ·{' '}
                                                                {m.events} runs
                                                            </div>
                                                        </div>
                                                    </li>
                                                ))}
                                            </ul>
                                        )}
                                    </div>

                                    <div>
                                        <div className="text-[11px] font-semibold uppercase tracking-wide text-ink-muted">
                                            Activity
                                        </div>
                                        {ai_history.activities.length === 0 ? (
                                            <p className="mt-2 text-sm text-ink-muted">
                                                No events yet.
                                            </p>
                                        ) : (
                                            <ul className="mt-2 divide-y divide-line rounded-md border border-line">
                                                <li className="grid grid-cols-[1fr_1fr_auto] gap-2 bg-mist/50 px-3 py-1.5 text-[10px] font-semibold uppercase tracking-wide text-ink-muted sm:grid-cols-[1.1fr_0.9fr_0.7fr_0.7fr_auto]">
                                                    <span>Action</span>
                                                    <span>Member</span>
                                                    <span className="hidden sm:inline">
                                                        Workspace
                                                    </span>
                                                    <span className="hidden sm:inline">
                                                        Tokens
                                                    </span>
                                                    <span className="text-right">Credits</span>
                                                </li>
                                                {ai_history.activities.map((row, i) => (
                                                    <li
                                                        key={`${row.action}-${row.at}-${i}`}
                                                        className="grid grid-cols-[1fr_1fr_auto] items-center gap-2 px-3 py-2 text-sm sm:grid-cols-[1.1fr_0.9fr_0.7fr_0.7fr_auto]"
                                                    >
                                                        <div className="min-w-0">
                                                            <div className="truncate font-medium text-ink">
                                                                {row.action}
                                                            </div>
                                                            <div className="text-[11px] text-ink-muted sm:hidden">
                                                                {row.at}
                                                            </div>
                                                        </div>
                                                        <div className="truncate text-ink-muted">
                                                            {row.member}
                                                        </div>
                                                        <div className="hidden truncate text-ink-muted sm:block">
                                                            {row.workspace || '—'}
                                                        </div>
                                                        <div className="hidden tabular-nums text-ink-muted sm:block">
                                                            {row.tokens.toLocaleString()}
                                                        </div>
                                                        <div className="text-right tabular-nums text-ink">
                                                            <div className="font-semibold">
                                                                {row.credits} cr
                                                            </div>
                                                            <div className="hidden text-[11px] text-ink-muted sm:block">
                                                                {row.at}
                                                            </div>
                                                        </div>
                                                    </li>
                                                ))}
                                            </ul>
                                        )}
                                    </div>
                                </>
                            ) : null}
                        </section>

                        <section className="atlas-panel space-y-3 p-4">
                            <div>
                                <div className="text-[11px] font-semibold uppercase tracking-wide text-ink-muted">
                                    Payment history
                                </div>
                                <p className="mt-0.5 text-sm text-ink-muted">
                                    Plan upgrades and credit pack purchases on your account.
                                </p>
                            </div>
                            {payment_history.length === 0 ? (
                                <p className="text-sm text-ink-muted">No payments yet.</p>
                            ) : (
                                <ul className="divide-y divide-line rounded-md border border-line">
                                    {payment_history.map((row) => (
                                        <li
                                            key={row.id}
                                            className="flex flex-wrap items-center justify-between gap-2 px-3 py-2.5 text-sm"
                                        >
                                            <div className="min-w-0">
                                                <div className="font-semibold text-ink">
                                                    {row.type === 'plan_checkout'
                                                        ? `${row.plan || 'Plan'} subscription`
                                                        : `+${Number(row.credits || 0).toLocaleString()} credits`}
                                                </div>
                                                <div className="text-xs text-ink-muted">
                                                    {row.at}
                                                    {row.workspace ? ` · ${row.workspace}` : ''}
                                                    {row.provider ? ` · ${row.provider}` : ''}
                                                    {row.pack_id ? ` · ${row.pack_id}` : ''}
                                                </div>
                                            </div>
                                            <div className="flex shrink-0 items-center gap-2">
                                                <span className="tabular-nums text-ink-muted">
                                                    {formatMoney(row.amount, row.currency)}
                                                </span>
                                                <span
                                                    className={
                                                        'rounded-md px-1.5 py-0.5 text-[10px] font-bold uppercase ' +
                                                        (row.status === 'paid'
                                                            ? 'bg-emerald-100 text-emerald-800'
                                                            : 'bg-amber-100 text-amber-800')
                                                    }
                                                >
                                                    {row.status}
                                                </span>
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </section>

                        <section className="atlas-panel space-y-3 p-4">
                            <div>
                                <div className="text-[11px] font-semibold uppercase tracking-wide text-ink-muted">
                                    Top-up history
                                </div>
                                <p className="mt-0.5 text-sm text-ink-muted">
                                    {credits_shared
                                        ? 'Credit pack purchases for your account (all workspaces).'
                                        : 'Credit pack purchases for this workspace.'}
                                </p>
                            </div>
                            {credit_history.length === 0 ? (
                                <p className="text-sm text-ink-muted">
                                    No top-ups yet. Buy a pack under Plan.
                                </p>
                            ) : (
                                <ul className="divide-y divide-line rounded-md border border-line">
                                    {credit_history.map((row) => (
                                        <li
                                            key={row.id}
                                            className="flex flex-wrap items-center justify-between gap-2 px-3 py-2.5 text-sm"
                                        >
                                            <div className="min-w-0">
                                                <div className="font-semibold text-ink">
                                                    +{Number(row.credits).toLocaleString()} credits
                                                </div>
                                                <div className="text-xs text-ink-muted">
                                                    {row.at}
                                                    {row.provider ? ` · ${row.provider}` : ''}
                                                    {row.pack_id ? ` · ${row.pack_id}` : ''}
                                                </div>
                                            </div>
                                            <div className="flex shrink-0 items-center gap-2">
                                                <span className="tabular-nums text-ink-muted">
                                                    {formatMoney(row.amount, row.currency)}
                                                </span>
                                                <span
                                                    className={
                                                        'rounded-md px-1.5 py-0.5 text-[10px] font-bold uppercase ' +
                                                        (row.status === 'paid'
                                                            ? 'bg-emerald-100 text-emerald-800'
                                                            : row.status === 'failed'
                                                              ? 'bg-rose-100 text-rose-700'
                                                              : 'bg-amber-100 text-amber-800')
                                                    }
                                                >
                                                    {row.status}
                                                </span>
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </section>
                    </div>
                ) : null}
            </div>
        </AuthenticatedLayout>
    );
}
