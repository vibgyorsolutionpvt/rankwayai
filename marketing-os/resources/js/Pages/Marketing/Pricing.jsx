import BrandName from '@/Components/BrandName';
import PricingPlans from '@/Components/Marketing/PricingPlans';
import MarketingLayout from '@/Layouts/MarketingLayout';
import { Link } from '@inertiajs/react';

export default function Pricing({ auth, canLogin, canRegister, seo, plans = [], interval = 'month' }) {
    const registerHref = auth?.user ? route('home') : route('register');

    return (
        <MarketingLayout
            seo={seo}
            auth={auth}
            canLogin={canLogin}
            canRegister={canRegister}
            jsonLd={{
                '@context': 'https://schema.org',
                '@type': 'Product',
                name: 'rankwayAI',
                description: seo?.description,
                brand: { '@type': 'Brand', name: 'rankwayAI' },
                offers: plans.map((plan) => ({
                    '@type': 'Offer',
                    name: plan.name,
                    price: String(plan.price),
                    priceCurrency: plan.currency,
                    description: plan.blurb,
                    url: seo?.canonical,
                })),
            }}
        >
            <section className="relative overflow-hidden border-b border-line">
                <div className="absolute inset-0 bg-[radial-gradient(ellipse_80%_55%_at_50%_0%,rgba(14,159,144,0.15),transparent_55%)]" />
                <div className="relative mx-auto max-w-6xl px-6 py-16 sm:py-20">
                    <p className="text-xs font-semibold uppercase tracking-[0.2em] text-signal-strong">
                        Pricing
                    </p>
                    <h1 className="mt-4 font-display text-3xl font-bold tracking-tight text-ink sm:text-4xl">
                        Simple plans for <BrandName className="text-inherit" />
                    </h1>
                    <p className="mt-4 max-w-xl text-base leading-relaxed text-ink-muted">
                        Start free. Upgrade when you need AI budget, more workspaces, and higher
                        channel send limits. Prices shown in INR.
                    </p>
                </div>
            </section>

            <section className="mx-auto max-w-6xl px-6 py-14 sm:py-20">
                <PricingPlans
                    plans={plans}
                    interval={interval}
                    registerHref={registerHref}
                    interactive
                />

                <p className="mt-12 max-w-2xl text-sm leading-relaxed text-ink-muted">
                    Need a walkthrough or agency onboarding?{' '}
                    <Link href={route('contact')} className="font-semibold text-signal-strong">
                        Contact us
                    </Link>{' '}
                    — or learn more{' '}
                    <Link href={route('about')} className="font-semibold text-signal-strong">
                        about RankwayAI
                    </Link>
                    .
                </p>
            </section>
        </MarketingLayout>
    );
}
