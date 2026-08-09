import { Head, Link } from '@inertiajs/react';
import ApplicationLogo from '@/Components/ApplicationLogo';
import BrandName from '@/Components/BrandName';
import ContactChannels from '@/Components/Marketing/ContactChannels';

function SeoHead({ title, description, keywords, canonical, image }) {
    return (
        <Head title={title}>
            <meta head-key="description" name="description" content={description} />
            <meta head-key="keywords" name="keywords" content={keywords} />
            <meta head-key="robots" name="robots" content="index, follow" />
            <link head-key="canonical" rel="canonical" href={canonical} />
            <meta head-key="og:type" property="og:type" content="website" />
            <meta head-key="og:site_name" property="og:site_name" content="rankwayAI" />
            <meta head-key="og:title" property="og:title" content={title} />
            <meta head-key="og:description" property="og:description" content={description} />
            <meta head-key="og:url" property="og:url" content={canonical} />
            {image ? <meta head-key="og:image" property="og:image" content={image} /> : null}
            <meta head-key="twitter:card" name="twitter:card" content="summary_large_image" />
            <meta head-key="twitter:title" name="twitter:title" content={title} />
            <meta head-key="twitter:description" name="twitter:description" content={description} />
            <script type="application/ld+json">
                {JSON.stringify({
                    '@context': 'https://schema.org',
                    '@type': 'SoftwareApplication',
                    name: 'rankwayAI',
                    applicationCategory: 'BusinessApplication',
                    operatingSystem: 'Web',
                    description,
                    url: canonical,
                    offers: {
                        '@type': 'Offer',
                        price: '0',
                        priceCurrency: 'INR',
                        description: 'Start free',
                    },
                })}
            </script>
        </Head>
    );
}

function HeroVisual() {
    return (
        <div className="pointer-events-none absolute inset-0 overflow-hidden" aria-hidden="true">
            <div className="absolute inset-0 bg-[#f4f7f8]" />
            <div className="absolute inset-0 bg-[radial-gradient(ellipse_90%_70%_at_0%_0%,rgba(14,159,144,0.18),transparent_55%)]" />
            <div className="absolute inset-0 opacity-[0.35] [background-image:linear-gradient(rgba(11,18,32,0.05)_1px,transparent_1px),linear-gradient(90deg,rgba(11,18,32,0.05)_1px,transparent_1px)] [background-size:44px_44px] [mask-image:linear-gradient(90deg,black_40%,transparent_75%)]" />
        </div>
    );
}

function ProductPlane() {
    return (
        <div
            className="relative hidden h-full min-h-[calc(100svh-4.5rem)] w-full lg:flex"
            aria-hidden="true"
        >
            {/* Full-height ink plane aligned to this column */}
            <div className="absolute inset-y-0 -right-[max(1.5rem,calc((100vw-72rem)/2+1.5rem))] left-0 bg-gradient-to-br from-[#0b1f2a] via-[#0d2a33] to-[#0e9f90]/45" />
            <div className="absolute inset-y-0 -right-[max(1.5rem,calc((100vw-72rem)/2+1.5rem))] left-0 opacity-20 [background-image:linear-gradient(rgba(255,255,255,0.07)_1px,transparent_1px),linear-gradient(90deg,rgba(255,255,255,0.07)_1px,transparent_1px)] [background-size:40px_40px]" />

            <div className="relative z-10 flex w-full items-center justify-center px-10 xl:px-14">
                <div className="w-full max-w-sm animate-fade-up [animation-delay:0.15s]">
                    <div className="space-y-6 text-white">
                        <div>
                            <div className="text-[11px] font-semibold uppercase tracking-[0.2em] text-white/45">
                                SEO health
                            </div>
                            <div className="mt-2 font-display text-7xl font-extrabold tabular-nums tracking-tight text-white">
                                76
                                <span className="text-4xl text-signal">%</span>
                            </div>
                            <div className="mt-1 text-sm text-white/55">
                                vibgyorsolution.com · live crawl
                            </div>
                        </div>

                        <div className="grid grid-cols-3 gap-6 border-t border-white/15 pt-6">
                            {[
                                ['12', 'Queries'],
                                ['95', 'Speed'],
                                ['0', 'Critical'],
                            ].map(([value, label]) => (
                                <div key={label}>
                                    <div className="font-display text-2xl font-bold tabular-nums text-white">
                                        {value}
                                    </div>
                                    <div className="mt-0.5 text-[11px] uppercase tracking-wide text-white/45">
                                        {label}
                                    </div>
                                </div>
                            ))}
                        </div>

                        <div className="flex items-end gap-2.5 pt-2">
                            {[28, 44, 38, 62, 55, 78, 70, 92].map((h, i) => (
                                <div
                                    key={`${h}-${i}`}
                                    className="flex-1 origin-bottom rounded-sm bg-signal/85 animate-fade-up"
                                    style={{
                                        height: `${h}px`,
                                        animationDelay: `${0.25 + i * 0.06}s`,
                                    }}
                                />
                            ))}
                        </div>

                        <svg
                            className="h-16 w-full opacity-80"
                            viewBox="0 0 320 64"
                            fill="none"
                            preserveAspectRatio="none"
                        >
                            <path
                                d="M0 48 C40 46 60 40 90 34 C130 24 150 28 190 18 C230 8 260 14 320 6"
                                stroke="#F3F5F8"
                                strokeWidth="2"
                                strokeLinecap="round"
                            />
                        </svg>
                    </div>
                </div>
            </div>
        </div>
    );
}

export default function Welcome({ auth, canLogin, canRegister, seo }) {
    const title = seo?.title || 'rankwayAI — SEO & Digital Marketing Platform';
    const description =
        seo?.description ||
        'rankwayAI helps businesses grow with SEO audits, Search Console insights, social scheduling, CRM, and marketing automation in one workspace.';
    const keywords =
        seo?.keywords ||
        'rankwayAI, SEO software, Google Search Console, digital marketing platform, social media scheduler, marketing OS India';
    const canonical =
        seo?.canonical || (typeof window !== 'undefined' ? `${window.location.origin}/` : '/');
    const image = seo?.image
        ? seo.image.startsWith('http')
            ? seo.image
            : `${canonical.replace(/\/$/, '')}${seo.image}`
        : null;

    const primaryHref = auth?.user ? route('home') : route('register');
    const primaryLabel = auth?.user ? 'Open workspace' : 'Start free';

    return (
        <>
            <SeoHead
                title={title}
                description={description}
                keywords={keywords}
                canonical={canonical}
                image={image}
            />

            <div className="bg-mist text-ink">
                <header className="sticky top-0 z-50 border-b border-line/70 bg-mist/90 backdrop-blur-sm">
                    <div className="mx-auto flex max-w-6xl items-center justify-between gap-4 px-6 py-5">
                        <Link href="/" className="flex items-center gap-3" aria-label="RankwayAI home">
                            <ApplicationLogo className="h-11 w-11 sm:h-12 sm:w-12" />
                            <BrandName className="text-xl text-ink sm:text-2xl" />
                        </Link>
                        <nav className="hidden items-center gap-1 md:flex" aria-label="Marketing">
                            <Link
                                href={route('about')}
                                className="rounded-md px-3 py-2 text-sm font-semibold text-ink-muted transition hover:text-ink"
                            >
                                About
                            </Link>
                            <Link
                                href={route('pricing')}
                                className="rounded-md px-3 py-2 text-sm font-semibold text-ink-muted transition hover:text-ink"
                            >
                                Pricing
                            </Link>
                            <Link
                                href={route('contact')}
                                className="rounded-md px-3 py-2 text-sm font-semibold text-ink-muted transition hover:text-ink"
                            >
                                Contact
                            </Link>
                        </nav>
                        <nav className="flex items-center gap-2 sm:gap-3" aria-label="Primary">
                            {auth?.user ? (
                                <Link
                                    href={route('home')}
                                    className="rounded-md bg-signal px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-signal-strong"
                                >
                                    Open app
                                </Link>
                            ) : (
                                <>
                                    {canLogin ? (
                                        <Link
                                            href={route('login')}
                                            className="rounded-md px-4 py-2.5 text-sm font-semibold text-ink-muted transition hover:text-ink"
                                        >
                                            Log in
                                        </Link>
                                    ) : null}
                                    {canRegister ? (
                                        <Link
                                            href={route('register')}
                                            className="rounded-md bg-ink px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-ink-soft"
                                        >
                                            Create account
                                        </Link>
                                    ) : null}
                                </>
                            )}
                        </nav>
                    </div>
                </header>

                {/* ── Hero: one composition ── */}
                <section className="relative min-h-[calc(100svh-4.5rem)] overflow-hidden">
                    <HeroVisual />

                    <div className="relative z-20 mx-auto grid min-h-[calc(100svh-4.5rem)] max-w-6xl items-stretch gap-0 px-6 pb-0 pt-0 lg:grid-cols-2 lg:pb-0">
                        <div className="stagger flex max-w-xl flex-col justify-center py-12 lg:pr-10 lg:py-16">
                            <BrandName className="text-5xl text-ink sm:text-6xl lg:text-7xl" />
                            <h1 className="mt-5 font-display text-2xl font-semibold leading-snug text-ink-soft sm:text-3xl">
                                Marketing OS that ranks and converts.
                            </h1>
                            <p className="mt-4 max-w-md text-base leading-relaxed text-ink-muted sm:text-lg">
                                SEO, Search Console, social, WhatsApp, and CRM in one workspace —
                                built for Indian businesses and agencies.
                            </p>
                            <div className="mt-8 flex flex-wrap gap-3">
                                <Link
                                    href={primaryHref}
                                    className="rounded-md bg-signal px-6 py-3.5 text-sm font-semibold text-white transition hover:-translate-y-0.5 hover:bg-signal-strong"
                                >
                                    {primaryLabel}
                                </Link>
                                <a
                                    href="#seo-engine"
                                    className="rounded-md border border-line bg-white/80 px-6 py-3.5 text-sm font-semibold text-ink transition hover:border-signal/40"
                                >
                                    See product
                                </a>
                            </div>
                        </div>

                        <ProductPlane />
                    </div>
                </section>

                {/* ── SEO ── */}
                <section id="seo-engine" className="mx-auto max-w-6xl px-6 py-20 sm:py-28">
                    <p className="text-xs font-semibold uppercase tracking-[0.2em] text-signal-strong">
                        SEO engine
                    </p>
                    <h2 className="mt-3 max-w-2xl font-display text-3xl font-bold tracking-tight text-ink sm:text-4xl">
                        Find what holds rankings back — then fix it.
                    </h2>
                    <p className="mt-4 max-w-xl text-base leading-relaxed text-ink-muted sm:text-lg">
                        Crawl sites, pull Google Search Console queries, run PageSpeed checks, and
                        turn issues into a clear to-do list your team can ship.
                    </p>
                    <div className="mt-10 grid gap-8 border-t border-line pt-10 sm:grid-cols-3">
                        {[
                            {
                                title: 'Live crawl',
                                body: 'Titles, metas, ALT, noindex, and structure from pages you actually fetch.',
                            },
                            {
                                title: 'GSC queries',
                                body: 'Clicks, impressions, CTR, and position synced from Search Console.',
                            },
                            {
                                title: 'Speed fixes',
                                body: 'Core Web Vitals plus actionable opportunities to apply on the site.',
                            },
                        ].map((item) => (
                            <div key={item.title}>
                                <h3 className="font-display text-lg font-bold text-ink">
                                    {item.title}
                                </h3>
                                <p className="mt-2 text-sm leading-relaxed text-ink-muted">
                                    {item.body}
                                </p>
                            </div>
                        ))}
                    </div>
                </section>

                {/* ── Reach ── */}
                <section className="border-y border-line bg-white/70">
                    <div className="mx-auto max-w-6xl px-6 py-20 sm:py-28">
                        <p className="text-xs font-semibold uppercase tracking-[0.2em] text-signal-strong">
                            Reach
                        </p>
                        <h2 className="mt-3 max-w-2xl font-display text-3xl font-bold tracking-tight text-ink sm:text-4xl">
                            Publish where your customers already are.
                        </h2>
                        <p className="mt-4 max-w-xl text-base leading-relaxed text-ink-muted sm:text-lg">
                            Plan social posts, run WhatsApp conversations, and keep CRM leads in the
                            same place you manage SEO.
                        </p>
                        <ul className="mt-10 space-y-6 border-t border-line pt-10 sm:max-w-2xl">
                            {[
                                ['Social calendar', 'Schedule and publish without hopping tools.'],
                                ['WhatsApp hub', 'Conversations linked to the people in your CRM.'],
                                ['Funnels & leads', 'Capture interest and follow up in one flow.'],
                            ].map(([label, body]) => (
                                <li key={label} className="grid gap-1 sm:grid-cols-[11rem_1fr] sm:gap-6">
                                    <span className="font-display text-base font-bold text-ink">
                                        {label}
                                    </span>
                                    <span className="text-sm leading-relaxed text-ink-muted">
                                        {body}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </div>
                </section>

                {/* ── How it works ── */}
                <section className="mx-auto max-w-6xl px-6 py-20 sm:py-28">
                    <p className="text-xs font-semibold uppercase tracking-[0.2em] text-signal-strong">
                        How it works
                    </p>
                    <h2 className="mt-3 max-w-2xl font-display text-3xl font-bold tracking-tight text-ink sm:text-4xl">
                        From signup to daily shipping.
                    </h2>
                    <ol className="mt-12 space-y-10 border-t border-line pt-10">
                        {[
                            {
                                n: '01',
                                title: 'Create a workspace',
                                body: 'Invite your team and turn on the modules you need.',
                            },
                            {
                                n: '02',
                                title: 'Connect your site & channels',
                                body: 'Add domains, Search Console, and messaging providers.',
                            },
                            {
                                n: '03',
                                title: 'Work from Today',
                                body: 'SEO to-dos, posts, and leads — one daily focus.',
                            },
                        ].map((step) => (
                            <li
                                key={step.n}
                                className="grid gap-3 sm:grid-cols-[5rem_1fr] sm:items-baseline sm:gap-8"
                            >
                                <span className="font-display text-2xl font-bold tabular-nums text-signal">
                                    {step.n}
                                </span>
                                <div>
                                    <h3 className="font-display text-xl font-bold text-ink">
                                        {step.title}
                                    </h3>
                                    <p className="mt-1 max-w-xl text-sm leading-relaxed text-ink-muted sm:text-base">
                                        {step.body}
                                    </p>
                                </div>
                            </li>
                        ))}
                    </ol>
                </section>

                {/* ── Closing CTA ── */}
                <section className="relative overflow-hidden border-t border-line">
                    <div className="absolute inset-0 bg-gradient-to-br from-ink via-ink-soft to-[#0b3d38]" />
                    <div className="absolute -right-20 top-0 h-72 w-72 rounded-full bg-signal/25 blur-3xl" />
                    <div className="relative mx-auto max-w-6xl px-6 py-20 text-white sm:py-24">
                        <h2 className="max-w-xl font-display text-3xl font-bold tracking-tight sm:text-4xl">
                            Start ranking with <BrandName className="text-inherit" accentClassName="text-signal" />.
                        </h2>
                        <p className="mt-4 max-w-lg text-base text-white/70">
                            Free to begin. Connect a site, run a scan, and see what to fix first.
                        </p>
                        <div className="mt-8">
                            <Link
                                href={primaryHref}
                                className="inline-flex rounded-md bg-signal px-6 py-3.5 text-sm font-semibold text-white transition hover:-translate-y-0.5 hover:bg-signal-strong"
                            >
                                {primaryLabel}
                            </Link>
                        </div>
                    </div>
                </section>

                <footer className="border-t border-line bg-white/80">
                    <div className="mx-auto flex max-w-6xl flex-col gap-6 px-6 py-8 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
                        <div>
                            <div className="flex items-center gap-2.5">
                                <ApplicationLogo className="h-8 w-8" />
                                <BrandName className="text-sm text-ink" />
                            </div>
                            <ContactChannels
                                className="mt-4"
                                compact
                                email="contact@rankwayai.com"
                                phone="+91 9889995999"
                            />
                        </div>
                        <div className="flex flex-wrap gap-x-6 gap-y-2 text-sm font-semibold text-ink-muted">
                            <Link href={route('about')} className="transition hover:text-ink">
                                About
                            </Link>
                            <Link href={route('pricing')} className="transition hover:text-ink">
                                Pricing
                            </Link>
                            <Link href={route('contact')} className="transition hover:text-ink">
                                Contact
                            </Link>
                        </div>
                        <p className="text-xs text-ink-muted">
                            © {new Date().getFullYear()} RankwayAI. Marketing OS for growth teams.
                            <span className="mt-1 block">
                                A product of{' '}
                                <a
                                    href="https://vibgyorsolution.com"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="font-semibold transition hover:text-ink"
                                >
                                    Vibgyor Solution
                                </a>
                            </span>
                        </p>
                    </div>
                </footer>
            </div>
        </>
    );
}
