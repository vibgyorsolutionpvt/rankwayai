import { Link, router } from '@inertiajs/react';

function formatPrice(plan) {
    const amount = Number(plan.price);
    if (plan.currency === 'INR') {
        return `${plan.symbol}${amount.toLocaleString('en-IN')}`;
    }
    return `${plan.symbol}${amount}`;
}

export default function PricingPlans({
    plans = [],
    interval = 'month',
    registerHref,
    interactive = true,
    compact = false,
}) {
    const switchInterval = (next) => {
        if (!interactive) return;
        router.get(
            route('pricing'),
            { interval: next },
            { preserveState: true, replace: true, preserveScroll: true },
        );
    };

    return (
        <div>
            {interactive ? (
                <div className="flex flex-wrap items-center gap-2">
                    {[
                        { id: 'month', label: 'Monthly' },
                        { id: 'year', label: 'Yearly' },
                    ].map((opt) => (
                        <button
                            key={opt.id}
                            type="button"
                            onClick={() => switchInterval(opt.id)}
                            className={`rounded-md px-4 py-2 text-sm font-semibold transition ${
                                interval === opt.id
                                    ? 'bg-ink text-white'
                                    : 'border border-line bg-white/80 text-ink-muted hover:text-ink'
                            }`}
                        >
                            {opt.label}
                            {opt.id === 'year' ? (
                                <span className="ml-1.5 text-signal">· save 2 mo</span>
                            ) : null}
                        </button>
                    ))}
                </div>
            ) : null}

            <div
                className={`mt-8 grid gap-8 border-t border-line pt-10 ${
                    compact ? 'sm:grid-cols-2 lg:grid-cols-4' : 'sm:grid-cols-2 lg:grid-cols-4'
                }`}
            >
                {plans.map((plan) => (
                    <div key={plan.id} className="flex flex-col">
                        <div className="text-xs font-semibold uppercase tracking-[0.18em] text-signal-strong">
                            {plan.name}
                        </div>
                        <div className="mt-3 font-display text-3xl font-extrabold tracking-tight text-ink">
                            {Number(plan.price) === 0 ? 'Free' : formatPrice(plan)}
                            {Number(plan.price) > 0 ? (
                                <span className="ml-1 text-sm font-semibold text-ink-muted">
                                    /{interval === 'year' ? 'yr' : 'mo'}
                                </span>
                            ) : null}
                        </div>
                        {plan.save_label ? (
                            <div className="mt-1 text-xs font-semibold text-signal-strong">
                                {plan.save_label}
                            </div>
                        ) : null}
                        <p className="mt-3 text-sm leading-relaxed text-ink-muted">{plan.blurb}</p>
                        {Array.isArray(plan.highlights) && plan.highlights.length > 0 ? (
                            <ul className="mt-4 flex-1 space-y-1.5 text-sm text-ink">
                                {plan.highlights.map((item) => (
                                    <li key={item} className="flex gap-2">
                                        <span className="mt-0.5 text-signal-strong" aria-hidden>
                                            ✓
                                        </span>
                                        <span>{item}</span>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <div className="flex-1" />
                        )}
                        {registerHref ? (
                            <Link
                                href={registerHref}
                                className={`mt-5 inline-flex rounded-md px-4 py-2.5 text-center text-sm font-semibold transition ${
                                    plan.id === 'growth'
                                        ? 'bg-signal text-white hover:bg-signal-strong'
                                        : 'border border-line bg-white/80 text-ink hover:border-signal/40'
                                }`}
                            >
                                {plan.id === 'free' ? 'Start free' : 'Get started'}
                            </Link>
                        ) : null}
                    </div>
                ))}
            </div>
        </div>
    );
}
