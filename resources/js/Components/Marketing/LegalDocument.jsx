import MarketingLayout from '@/Layouts/MarketingLayout';
import { Link } from '@inertiajs/react';

export default function LegalDocument({
    auth,
    canLogin,
    canRegister,
    seo,
    email,
    title,
    updated,
    intro,
    sections,
    children,
}) {
    return (
        <MarketingLayout
            seo={seo}
            auth={auth}
            canLogin={canLogin}
            canRegister={canRegister}
            jsonLd={{
                '@context': 'https://schema.org',
                '@type': 'WebPage',
                name: seo?.title,
                description: seo?.description,
                url: seo?.canonical,
            }}
        >
            <section className="relative overflow-hidden border-b border-line">
                <div className="absolute inset-0 bg-[radial-gradient(ellipse_80%_60%_at_0%_0%,rgba(14,159,144,0.16),transparent_55%)]" />
                <div className="relative mx-auto max-w-3xl px-6 py-16 sm:py-20">
                    <p className="text-xs font-semibold uppercase tracking-[0.2em] text-signal-strong">
                        Legal
                    </p>
                    <h1 className="mt-4 font-display text-4xl font-bold tracking-tight text-ink sm:text-5xl">
                        {title}
                    </h1>
                    <p className="mt-3 text-sm text-ink-muted">Last updated: {updated}</p>
                    {intro ? (
                        <p className="mt-5 text-base leading-relaxed text-ink-muted">{intro}</p>
                    ) : null}
                </div>
            </section>

            <article className="mx-auto max-w-3xl space-y-10 px-6 py-14 sm:py-16">
                {children}

                {sections.map((section) => (
                    <section key={section.id} id={section.id}>
                        <h2 className="font-display text-xl font-bold text-ink">{section.title}</h2>
                        <div className="mt-3 space-y-3 text-sm leading-relaxed text-ink-muted">
                            {section.body.map((para) => (
                                <p key={para}>{para}</p>
                            ))}
                        </div>
                    </section>
                ))}

                <p className="border-t border-line pt-8 text-sm text-ink-muted">
                    Questions?{' '}
                    <a
                        href={`mailto:${email}`}
                        className="font-semibold text-signal-strong hover:text-signal"
                    >
                        {email}
                    </a>{' '}
                    or visit our{' '}
                    <Link href={route('contact')} className="font-semibold text-ink underline">
                        Contact
                    </Link>{' '}
                    page.
                </p>
            </article>
        </MarketingLayout>
    );
}
