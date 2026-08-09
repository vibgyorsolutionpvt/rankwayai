import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PrimaryButton from '@/Components/PrimaryButton';
import { Head, Link } from '@inertiajs/react';

export default function PlanGate({
    moduleLabel,
    message,
    freeHighlights = [
        'SEO site audit crawl',
        'Google Search Console',
        'PageSpeed Insights',
        'DataForSEO ranks & keyword metrics',
        'Billing & workspace settings',
    ],
}) {
    return (
        <AuthenticatedLayout
            header={
                <div>
                    <div className="text-xs font-semibold uppercase tracking-[0.16em] text-ink-muted">
                        {moduleLabel}
                    </div>
                    <h2 className="font-display text-2xl font-bold leading-tight text-ink">
                        Upgrade to unlock
                    </h2>
                </div>
            }
        >
            <Head title={`${moduleLabel} · Upgrade`} />

            <div className="atlas-shell">
                <section className="atlas-panel border border-amber-200 bg-amber-50/80 p-6 sm:p-8">
                    <p className="text-xs font-semibold uppercase tracking-[0.18em] text-amber-800">
                        Free plan
                    </p>
                    <h3 className="mt-3 font-display text-2xl font-bold text-ink">
                        {moduleLabel} needs credits or a paid plan
                    </h3>
                    <p className="mt-3 max-w-xl text-sm leading-relaxed text-amber-950/80">
                        {message}
                    </p>
                    <div className="mt-4 max-w-xl">
                        <div className="text-[11px] font-semibold uppercase tracking-wide text-signal-strong">
                            Free includes
                        </div>
                        <ul className="mt-2 space-y-1.5 text-sm text-ink">
                            {freeHighlights.map((item) => (
                                <li key={item} className="flex gap-2">
                                    <span className="mt-0.5 text-signal-strong" aria-hidden>
                                        ✓
                                    </span>
                                    <span>{item}</span>
                                </li>
                            ))}
                        </ul>
                        <p className="mt-3 text-sm text-ink-muted">
                            Buy AI credits to unlock {moduleLabel} and all paid modules, or upgrade
                            your plan.
                        </p>
                    </div>
                    <div className="mt-6 flex flex-wrap gap-3">
                        <Link href={route('billing.index')}>
                            <PrimaryButton type="button">View plans</PrimaryButton>
                        </Link>
                        <Link
                            href={route('seo.index')}
                            className="inline-flex items-center rounded-md border border-line bg-white px-4 py-2 text-sm font-semibold text-ink transition hover:border-signal/40"
                        >
                            Back to SEO
                        </Link>
                    </div>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
