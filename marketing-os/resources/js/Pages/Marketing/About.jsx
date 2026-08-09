import BrandName from '@/Components/BrandName';
import PricingPlans from '@/Components/Marketing/PricingPlans';
import MarketingLayout from '@/Layouts/MarketingLayout';
import { Link } from '@inertiajs/react';

export default function About({ auth, canLogin, canRegister, seo, plans = [] }) {
    const primaryHref = auth?.user ? route('home') : route('register');

    return (
        <MarketingLayout
            seo={seo}
            auth={auth}
            canLogin={canLogin}
            canRegister={canRegister}
            jsonLd={{
                '@context': 'https://schema.org',
                '@type': 'AboutPage',
                name: seo?.title,
                description: seo?.description,
                url: seo?.canonical,
            }}
        >
            <section className="relative overflow-hidden border-b border-line">
                <div className="absolute inset-0 bg-[radial-gradient(ellipse_80%_60%_at_0%_0%,rgba(14,159,144,0.16),transparent_55%)]" />
                <div className="relative mx-auto max-w-6xl px-6 py-16 sm:py-24">
                    <p className="text-xs font-semibold uppercase tracking-[0.2em] text-signal-strong">
                        About us
                    </p>
                    <BrandName className="mt-4 text-4xl text-ink sm:text-5xl" />
                    <h1 className="mt-4 max-w-2xl font-display text-2xl font-semibold leading-snug text-ink-soft sm:text-3xl">
                        Built so marketing teams can rank, publish, and convert in one place.
                    </h1>
                    <p className="mt-5 max-w-xl text-base leading-relaxed text-ink-muted sm:text-lg">
                        RankwayAI is a marketing operating system for Indian businesses and agencies —
                        SEO audits, Google Search Console, PageSpeed fixes, social scheduling,
                        WhatsApp, and CRM without hopping between five tools.
                    </p>
                </div>
            </section>

            <section className="mx-auto max-w-6xl px-6 py-16 sm:py-24">
                <h2 className="font-display text-3xl font-bold tracking-tight text-ink">
                    What we believe
                </h2>
                <div className="mt-10 grid gap-10 border-t border-line pt-10 sm:grid-cols-3">
                    {[
                        {
                            title: 'Honest data',
                            body: 'Crawls and Search Console syncs show what the live site and Google actually report — not vanity dashboards.',
                        },
                        {
                            title: 'Daily shipping',
                            body: 'Today turns SEO issues, posts, and leads into one focus list your team can finish.',
                        },
                        {
                            title: 'India-first ops',
                            body: 'INR pricing, WhatsApp-ready workflows, and workspaces sized for local agencies and brands.',
                        },
                    ].map((item) => (
                        <div key={item.title}>
                            <h3 className="font-display text-lg font-bold text-ink">{item.title}</h3>
                            <p className="mt-2 text-sm leading-relaxed text-ink-muted">{item.body}</p>
                        </div>
                    ))}
                </div>
            </section>

            <section className="border-y border-line bg-white/70">
                <div className="mx-auto max-w-6xl px-6 py-16 sm:py-24">
                    <p className="text-xs font-semibold uppercase tracking-[0.2em] text-signal-strong">
                        Pricing
                    </p>
                    <h2 className="mt-3 font-display text-3xl font-bold tracking-tight text-ink">
                        Start free. Scale when you need more.
                    </h2>
                    <p className="mt-3 max-w-xl text-base text-ink-muted">
                        Monthly INR list prices. Switch to yearly on the{' '}
                        <Link href={route('pricing')} className="font-semibold text-signal-strong">
                            pricing page
                        </Link>{' '}
                        to save two months.
                    </p>
                    <PricingPlans
                        plans={plans}
                        interval="month"
                        interactive={false}
                        registerHref={primaryHref}
                    />
                </div>
            </section>
        </MarketingLayout>
    );
}
