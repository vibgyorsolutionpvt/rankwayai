import ApplicationLogo from '@/Components/ApplicationLogo';
import BrandName from '@/Components/BrandName';
import { AppFeedback } from '@/Components/ToastProvider';
import { Link } from '@inertiajs/react';

export default function GuestLayout({ children, title, subtitle }) {
    return (
        <div className="relative flex min-h-screen items-center justify-center overflow-hidden px-4 py-12">
            <AppFeedback />
            <div className="pointer-events-none absolute inset-0 atlas-grid opacity-70" />
            <div className="pointer-events-none absolute -left-24 top-16 h-72 w-72 animate-float rounded-full bg-signal/15 blur-3xl" />
            <div className="pointer-events-none absolute -right-16 bottom-10 h-80 w-80 animate-float rounded-full bg-ink/10 blur-3xl [animation-delay:1.2s]" />

            <div className="relative w-full max-w-md animate-fade-up">
                <div className="mb-8 text-center">
                    <Link href="/" className="inline-flex items-center gap-3 transition hover:opacity-90">
                        <ApplicationLogo className="h-11 w-11" />
                        <BrandName className="text-2xl text-ink" />
                    </Link>
                    {title ? (
                        <h1 className="mt-6 font-display text-3xl font-bold tracking-tight text-ink">
                            {title}
                        </h1>
                    ) : null}
                    {subtitle ? (
                        <p className="mt-2 text-sm text-ink-muted">{subtitle}</p>
                    ) : null}
                </div>

                <div className="atlas-panel px-6 py-7 sm:px-8">{children}</div>
            </div>
        </div>
    );
}
