import ApplicationLogo from '@/Components/ApplicationLogo';
import BrandName from '@/Components/BrandName';
import ContactChannels from '@/Components/Marketing/ContactChannels';
import SeoHead from '@/Components/Marketing/SeoHead';
import { Link, usePage } from '@inertiajs/react';

const NAV = [
    { href: '/about', label: 'About', routeName: 'about' },
    { href: '/website-rank-checker', label: 'Rank check', routeName: 'website-rank-checker' },
    { href: '/pricing', label: 'Pricing', routeName: 'pricing' },
    { href: '/contact', label: 'Contact', routeName: 'contact' },
];

export default function MarketingLayout({
    children,
    seo,
    jsonLd = null,
    auth = null,
    canLogin = true,
    canRegister = true,
}) {
    const page = usePage();
    const current = page.url.split('?')[0];
    const user = auth?.user ?? page.props.auth?.user;

    const title = seo?.title || 'rankwayAI';
    const description = seo?.description || '';
    const keywords = seo?.keywords || '';
    const canonical =
        seo?.canonical || (typeof window !== 'undefined' ? window.location.href : '/');
    const image = seo?.image
        ? seo.image.startsWith('http')
            ? seo.image
            : `${String(canonical).replace(/\/$/, '')}${seo.image}`
        : null;

    return (
        <>
            <SeoHead
                title={title}
                description={description}
                keywords={keywords}
                canonical={canonical}
                image={image}
                jsonLd={jsonLd}
            />

            <div className="min-h-screen bg-mist text-ink">
                <header className="sticky top-0 z-50 border-b border-line/70 bg-mist/90 backdrop-blur-sm">
                    <div className="mx-auto flex max-w-6xl items-center justify-between gap-4 px-6 py-5">
                        <Link href="/" className="flex items-center gap-3" aria-label="RankwayAI home">
                            <ApplicationLogo className="h-11 w-11 sm:h-12 sm:w-12" />
                            <BrandName className="text-xl text-ink sm:text-2xl" />
                        </Link>

                        <nav
                            className="hidden items-center gap-1 md:flex"
                            aria-label="Marketing"
                        >
                            {NAV.map((item) => {
                                const active = current === item.href;
                                return (
                                    <Link
                                        key={item.href}
                                        href={route(item.routeName)}
                                        className={`rounded-md px-3 py-2 text-sm font-semibold transition ${
                                            active
                                                ? 'text-signal-strong'
                                                : 'text-ink-muted hover:text-ink'
                                        }`}
                                    >
                                        {item.label}
                                    </Link>
                                );
                            })}
                        </nav>

                        <div className="flex items-center gap-2 sm:gap-3">
                            {user ? (
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
                                            className="rounded-md px-3 py-2.5 text-sm font-semibold text-ink-muted transition hover:text-ink sm:px-4"
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
                        </div>
                    </div>

                    <nav
                        className="flex gap-1 overflow-x-auto border-t border-line/60 px-4 py-2 md:hidden"
                        aria-label="Marketing mobile"
                    >
                        {NAV.map((item) => {
                            const active = current === item.href;
                            return (
                                <Link
                                    key={item.href}
                                    href={route(item.routeName)}
                                    className={`whitespace-nowrap rounded-md px-3 py-2 text-sm font-semibold ${
                                        active
                                            ? 'text-signal-strong'
                                            : 'text-ink-muted'
                                    }`}
                                >
                                    {item.label}
                                </Link>
                            );
                        })}
                    </nav>
                </header>

                <main>{children}</main>

                <footer className="border-t border-line bg-white/80">
                    <div className="mx-auto flex max-w-6xl flex-col gap-6 px-6 py-10 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <div className="flex items-center gap-2.5">
                                <ApplicationLogo className="h-8 w-8" />
                                <BrandName className="text-sm text-ink" />
                            </div>
                            <p className="mt-3 max-w-xs text-xs leading-relaxed text-ink-muted">
                                Marketing OS for SEO, Search Console, social, WhatsApp, and CRM.
                            </p>
                            <ContactChannels
                                className="mt-4"
                                compact
                                email="contact@rankwayai.com"
                                phone="+91 9889995999"
                            />
                        </div>
                        <div className="flex flex-wrap gap-x-6 gap-y-2 text-sm font-semibold text-ink-muted">
                            {NAV.map((item) => (
                                <Link
                                    key={item.href}
                                    href={route(item.routeName)}
                                    className="transition hover:text-ink"
                                >
                                    {item.label}
                                </Link>
                            ))}
                        </div>
                        <p className="text-xs text-ink-muted sm:text-right">
                            © {new Date().getFullYear()} RankwayAI
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
