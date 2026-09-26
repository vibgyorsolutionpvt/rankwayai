import BrandName from '@/Components/BrandName';
import { Head } from '@inertiajs/react';

function track(trackUrl, event) {
    if (!trackUrl || !event) return;
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    fetch(trackUrl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrf || '',
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ event }),
        credentials: 'same-origin',
    }).catch(() => {});
}

export default function Public({ brochure, track_url, pdf_url }) {
    const primary = brochure.styles?.primary_color || '#0E9F90';
    const secondary = brochure.styles?.secondary_color || '#0B1220';
    const accent = brochure.styles?.accent_color || '#F59E0B';
    const ctaLabel = brochure.styles?.default_cta_label || 'Get in touch';
    const ctaUrl = brochure.styles?.default_cta_url || (brochure.share_url ? '#' : '#');
    const sections = (brochure.enabled_sections || []).filter((s) => s.key !== 'cover');

    return (
        <div className="min-h-screen bg-white">
            <Head title={brochure.title || brochure.headline || 'Brochure'} />

            <header
                className="px-4 py-16 text-center text-white"
                style={{
                    background: `linear-gradient(145deg, ${secondary}, ${primary})`,
                }}
            >
                <div className="mx-auto max-w-2xl">
                    {brochure.logo_url ? (
                        <img
                            src={brochure.logo_url}
                            alt=""
                            className="mx-auto mb-4 h-12 w-auto object-contain"
                        />
                    ) : null}
                    <h1 className="font-display text-3xl font-bold sm:text-4xl">
                        {brochure.headline || brochure.company_name}
                    </h1>
                    {brochure.subheadline ? (
                        <p className="mt-2 text-base opacity-90">{brochure.subheadline}</p>
                    ) : null}
                    <div className="mt-6 flex flex-wrap items-center justify-center gap-2">
                        <a
                            href={ctaUrl}
                            onClick={() => track(track_url, 'cta')}
                            className="rounded-md px-4 py-2 text-sm font-bold text-ink"
                            style={{ background: accent }}
                        >
                            {ctaLabel}
                        </a>
                        <a
                            href={pdf_url}
                            onClick={() => track(track_url, 'download')}
                            className="rounded-md border border-white/40 px-4 py-2 text-sm font-semibold text-white hover:bg-white/10"
                        >
                            Download PDF
                        </a>
                    </div>
                </div>
            </header>

            <main className="mx-auto max-w-2xl space-y-8 px-4 py-10">
                {sections.map((section) => (
                    <section key={section.key}>
                        <h2 className="font-display text-xl font-bold" style={{ color: primary }}>
                            {section.title}
                        </h2>
                        {section.body ? (
                            <p className="mt-2 text-sm leading-relaxed text-ink-muted">{section.body}</p>
                        ) : null}
                        {Array.isArray(section.items) && section.items.length > 0 ? (
                            <ul className="mt-3 list-disc space-y-1 pl-5 text-sm text-ink">
                                {section.items.map((item) => (
                                    <li key={item}>{item}</li>
                                ))}
                            </ul>
                        ) : null}
                    </section>
                ))}
            </main>

            <footer className="border-t border-line px-4 py-6 text-center text-[11px] text-ink-muted">
                Powered by <BrandName className="font-semibold text-ink" />
            </footer>
        </div>
    );
}
