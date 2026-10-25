import BrandName from '@/Components/BrandName';

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

const ICONS = {
    phone: 'M3 5a2 2 0 012-2h2.3a1 1 0 01.95.68l1.2 3.6a1 1 0 01-.5 1.2l-1.5.75a11 11 0 005.5 5.5l.75-1.5a1 1 0 011.2-.5l3.6 1.2a1 1 0 01.68.95V19a2 2 0 01-2 2A16 16 0 013 5z',
    chat: 'M21 12a8 8 0 01-11.6 7.1L4 20l1-4.6A8 8 0 1121 12z',
    mail: 'M3 7a2 2 0 012-2h14a2 2 0 012 2v10a2 2 0 01-2 2H5a2 2 0 01-2-2V7zm0 0l9 6 9-6',
    globe: 'M12 21a9 9 0 100-18 9 9 0 000 18zm0 0c2.5-2.4 3.8-5.4 3.8-9S14.5 5.4 12 3m0 18c-2.500-2.400-3.800-5.400-3.800-9S9.5 5.4 12 3M3 12h18',
    pin: 'M12 21s7-5.8 7-11a7 7 0 10-14 0c0 5.2 7 11 7 11zm0-8.5a2.5 2.5 0 100-5 2.5 2.5 0 000 5z',
    clock: 'M12 21a9 9 0 100-18 9 9 0 000 18zm0-14v5l3 2',
    users: 'M17 20v-1.5a3.5 3.5 0 00-3.500-3.5h-5A3.5 3.5 0 005 18.5V20m11-11.5a3 3 0 11-6 0 3 3 0 016 0zm6 11.5v-1a3 3 0 00-2.500-3M16 5.2a3 3 0 010 5.6',
    building: 'M4 21V5a2 2 0 012-2h8a2 2 0 012 2v16M16 9h2a2 2 0 012 2v10M3 21h18M8 7h4M8 11h4M8 15h4',
    code: 'M8 8l-5 4 5 4m8-8l5 4-5 4m-3-11l-2 14',
    mobile: 'M8 3h8a1 1 0 011 1v16a1 1 0 01-1 1H8a1 1 0 01-1-1V4a1 1 0 011-1zm3 15h2',
    chart: 'M4 20V10m6 10V4m6 16v-7m4 7H2',
    briefcase: 'M4 8h16a1 1 0 011 1v10a1 1 0 01-1 1H4a1 1 0 01-1-1V9a1 1 0 011-1zm5 0V5a1 1 0 011-1h4a1 1 0 011 1v3M3 13h18',
    shield: 'M12 3l8 3v6c0 4.500-3.2 8-8 9-4.800-1-8-4.500-8-9V6l8-3zm-3 9l2 2 4-4',
    plane: 'M21 3L3 10.5l7 2.5 2.5 7L21 3zM10 13l11-10',
    utensils: 'M6 3v7a2 2 0 002 2v9m0-18v6M4 3v6m8 12V3c-2 1.500-3 4-3 7m10-7v18m0-18c-2 1-3 4-3 8h3',
    heart: 'M12 20s-8-4.800-8-11a4.5 4.5 0 018-2.8A4.5 4.5 0 0120 9c0 6.200-8 11-8 11z',
    book: 'M4 5a2 2 0 012-2h13v16H6a2 2 0 00-2 2V5zm2 14h13v2H6',
    home: 'M3 11l9-8 9 8M5 9.5V21h14V9.5M10 21v-6h4v6',
    star: 'M12 3l2.7 5.6 6.100.8-4.5 4.3 1.1 6.1L12 16.8 6.6 19.8l1.100-6.1L3.2 9.4l6.100-.8L12 3z',
    check: 'M5 12.5l4.5 4.5L19 7.5',
    arrow: 'M5 12h14m-6-6l6 6-6 6',
};

function Icon({ name, className = 'h-5 w-5', stroke = 1.8 }) {
    return (
        <svg
            viewBox="0 0 24 24"
            className={className}
            fill="none"
            stroke="currentColor"
            strokeWidth={stroke}
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <path d={ICONS[name] || ICONS.star} />
        </svg>
    );
}

function itemIcon(text) {
    const t = String(text).toLowerCase();
    const rules = [
        [/mobile|android|ios/, 'mobile'],
        [/web|app|software|develop|code|automation|api|cms/, 'code'],
        [/seo|market|digital|ads|growth|analytic|social/, 'chart'],
        [/consult|advis|strateg|audit|training/, 'briefcase'],
        [/support|maintenance|secure|security|warranty|insurance|legal|visa/, 'shield'],
        [/flight|hotel|tour|trip|travel|holiday|honeymoon|getaway|ticket/, 'plane'],
        [/food|dine|cater|menu|kitchen|delivery|takeaway/, 'utensils'],
        [/health|clinic|care|doctor|dental|therapy|wellness|diagnos/, 'heart'],
        [/course|class|learn|test|exam|school|tutor|coach|mentor/, 'book'],
        [/propert|home|rent|house|flat|real estate/, 'home'],
    ];
    return rules.find(([re]) => re.test(t))?.[1] || 'star';
}

function parseContact(item) {
    const m = String(item).match(/^([A-Za-z ]{2,14}):\s*(.+)$/);
    const label = m ? m[1].trim() : '';
    const value = m ? m[2].trim() : String(item);
    const key = label.toLowerCase();
    const map = {
        call: ['phone', (v) => `tel:${v.replace(/[^\d+]/g, '')}`],
        whatsapp: ['chat', (v) => `https://wa.me/${v.replace(/\D/g, '')}`],
        email: ['mail', (v) => `mailto:${v}`],
        web: ['globe', (v) => (/^https?:\/\//i.test(v) ? v : `https://${v}`)],
        visit: ['pin', () => null],
        hours: ['clock', () => null],
    };
    const [icon, href] = map[key] || ['globe', (v) => (/^https?:\/\//i.test(v) ? v : null)];
    return { label: label || 'Info', value, icon, href: href(value) };
}

function highlightIcon(text) {
    const t = String(text);
    if (t.startsWith('Industry')) return 'building';
    if (t.startsWith('Based')) return 'pin';
    if (t.startsWith('Built') || t.startsWith('Tailored')) return 'users';
    if (t.startsWith('Open')) return 'clock';
    return 'check';
}

function SectionLabel({ n, children, light, accent }) {
    return (
        <div className="mb-3 flex items-center gap-3">
            <span className="font-display text-sm font-extrabold tracking-widest" style={{ color: accent }}>
                {String(n).padStart(2, '0')}
            </span>
            <span className="h-px w-10" style={{ background: accent }} />
            <span
                className={`text-[11px] font-bold uppercase tracking-[0.22em] ${light ? 'text-white/70' : 'text-slate-500'}`}
            >
                {children}
            </span>
        </div>
    );
}

export default function BrochureView({ brochure, sections: allSections, track_url, pdf_url, compact = false }) {
    const primary = brochure.styles?.primary_color || '#0E9F90';
    const secondary = brochure.styles?.secondary_color || '#0B1220';
    const accent = brochure.styles?.accent_color || '#F59E0B';
    const ctaLabel = brochure.styles?.default_cta_label || 'Get in touch';

    const sections = (allSections || []).filter((s) => s.enabled !== false && s.key !== 'cover');
    const get = (key) => sections.find((s) => s.key === key);
    const about = get('about');
    const services = get('services');
    const products = get('products');
    const why = get('why_us');
    const contact = get('contact');
    const known = ['about', 'services', 'products', 'why_us', 'contact'];
    const extras = sections.filter((s) => !known.includes(s.key));

    const contactRows = (contact?.items || []).map(parseContact);
    const primaryContact = contactRows.find((c) => c.href);
    const ctaUrl = brochure.styles?.default_cta_url || primaryContact?.href || '#contact';
    const quick = contactRows.filter((c) => ['phone', 'mail', 'globe'].includes(c.icon)).slice(0, 3);
    const stats = [
        { label: 'Services', value: services?.items?.length },
        { label: 'Offerings', value: products?.items?.length },
        { label: 'Reasons to choose us', value: why?.items?.length },
    ].filter((x) => x.value > 0);

    const cols2 = compact ? 'grid-cols-1' : 'sm:grid-cols-2';
    const onClick = (event) => (e) => {
        if (compact) e.preventDefault();
        else track(track_url, event);
    };
    const pad = compact ? 'px-5 py-8' : 'px-6 py-12 sm:px-12';
    const bigTitle = compact ? 'text-3xl' : 'text-4xl sm:text-6xl';
    const sectionTitle = compact ? 'text-xl' : 'text-3xl';

    return (
        <div className={`${compact ? '' : 'min-h-screen'} bg-slate-100 font-sans`}>
            <div className="mx-auto max-w-4xl overflow-hidden bg-white shadow-2xl">
                {/* Cover */}
                <header
                    className={`relative overflow-hidden text-white ${compact ? 'px-5 pb-8 pt-8' : 'px-6 pb-16 pt-14 sm:px-12'}`}
                    style={{ background: `linear-gradient(150deg, ${secondary} 10%, ${primary} 130%)` }}
                >
                    <svg className="pointer-events-none absolute inset-0 h-full w-full opacity-20" aria-hidden="true">
                        <defs>
                            <pattern id="bv-dots" width="22" height="22" patternUnits="userSpaceOnUse">
                                <circle cx="2" cy="2" r="1.4" fill="#fff" />
                            </pattern>
                        </defs>
                        <rect width="100%" height="100%" fill="url(#bv-dots)" />
                    </svg>
                    <div
                        className="pointer-events-none absolute -right-24 -top-24 h-72 w-72 rounded-full opacity-30"
                        style={{ background: accent }}
                    />
                    <div
                        className="pointer-events-none absolute -bottom-32 -left-20 h-72 w-72 rounded-full border-[28px] opacity-20"
                        style={{ borderColor: accent }}
                    />
                    <div className="relative">
                        <div className="flex items-center gap-3">
                            {brochure.logo_url ? (
                                <img src={brochure.logo_url} alt="" className="h-12 w-auto rounded bg-white/95 p-1.5 object-contain" />
                            ) : (
                                <span
                                    className="flex h-11 w-11 items-center justify-center rounded-xl font-display text-xl font-extrabold"
                                    style={{ background: accent, color: secondary }}
                                >
                                    {String(brochure.company_name || 'B').charAt(0).toUpperCase()}
                                </span>
                            )}
                            <span className="text-xs font-bold uppercase tracking-[0.25em] text-white/80">
                                {brochure.company_name}
                            </span>
                        </div>

                        <h1 className={`mt-10 max-w-2xl font-display font-extrabold leading-[1.05] ${bigTitle}`}>
                            {brochure.headline || brochure.company_name}
                        </h1>
                        <div className="mt-4 h-1.5 w-20 rounded-full" style={{ background: accent }} />
                        {brochure.subheadline ? (
                            <p className={`mt-5 max-w-xl text-white/85 ${compact ? 'text-sm' : 'text-lg'}`}>
                                {brochure.subheadline}
                            </p>
                        ) : null}

                        <div className="mt-8 flex flex-wrap items-center gap-3">
                            <a
                                href={ctaUrl}
                                onClick={onClick('cta')}
                                className="inline-flex items-center gap-2 rounded-full px-6 py-3 text-sm font-extrabold shadow-lg"
                                style={{ background: accent, color: secondary }}
                            >
                                {ctaLabel}
                                <Icon name="arrow" className="h-4 w-4" stroke={2.4} />
                            </a>
                            <a
                                href={pdf_url || '#'}
                                onClick={onClick('download')}
                                className="rounded-full border border-white/40 px-6 py-3 text-sm font-semibold text-white hover:bg-white/10"
                            >
                                Download PDF
                            </a>
                        </div>

                        {quick.length > 0 ? (
                            <div className="mt-10 flex flex-wrap gap-2">
                                {quick.map((c) => (
                                    <span
                                        key={c.label}
                                        className="inline-flex items-center gap-2 rounded-full bg-white/10 px-3.5 py-1.5 text-xs font-semibold backdrop-blur"
                                    >
                                        <Icon name={c.icon} className="h-3.5 w-3.5" />
                                        {c.value.replace(/^https?:\/\//, '').replace(/\/$/, '')}
                                    </span>
                                ))}
                            </div>
                        ) : null}
                    </div>
                </header>

                {/* Stats band */}
                {stats.length > 0 ? (
                    <div className="flex" style={{ background: accent, color: secondary }}>
                        {stats.map((stat, i) => (
                            <div
                                key={stat.label}
                                className={`flex-1 px-3 py-4 text-center ${i > 0 ? 'border-l border-black/10' : ''}`}
                            >
                                <div className={`font-display font-extrabold ${compact ? 'text-2xl' : 'text-4xl'}`}>
                                    {String(stat.value).padStart(2, '0')}
                                </div>
                                <div className="text-[10px] font-bold uppercase tracking-widest opacity-75">
                                    {stat.label}
                                </div>
                            </div>
                        ))}
                    </div>
                ) : null}

                {/* About */}
                {about ? (
                    <section className={pad}>
                        <SectionLabel n={1} accent={primary}>
                            {about.title}
                        </SectionLabel>
                        {about.body ? (
                            <p
                                className={`font-display font-semibold leading-snug ${compact ? 'text-base' : 'text-2xl'}`}
                                style={{ color: secondary }}
                            >
                                {about.body}
                            </p>
                        ) : null}
                        {about.items?.length ? (
                            <div className={`mt-6 grid gap-3 ${cols2}`}>
                                {about.items.map((item, i) => (
                                    <div
                                        key={`${item}-${i}`}
                                        className="flex items-center gap-3 rounded-xl p-3"
                                        style={{ background: `${primary}12` }}
                                    >
                                        <span
                                            className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-white"
                                            style={{ background: primary }}
                                        >
                                            <Icon name={highlightIcon(item)} className="h-4 w-4" />
                                        </span>
                                        <span className="text-sm font-semibold text-slate-800">{item}</span>
                                    </div>
                                ))}
                            </div>
                        ) : null}
                    </section>
                ) : null}

                {/* Services */}
                {services ? (
                    <section className={`relative overflow-hidden text-white ${pad}`} style={{ background: secondary }}>
                        <div
                            className="pointer-events-none absolute -right-16 top-10 h-56 w-56 rounded-full opacity-20"
                            style={{ background: primary }}
                        />
                        <div className="relative">
                            <SectionLabel n={2} light accent={accent}>
                                {services.title}
                            </SectionLabel>
                            <h2 className={`font-display font-extrabold ${sectionTitle}`}>
                                {services.body || 'What we offer'}
                            </h2>
                            <div className={`mt-7 grid gap-4 ${cols2}`}>
                                {(services.items || []).map((item, i) => (
                                    <div
                                        key={`${item}-${i}`}
                                        className="flex items-center gap-4 rounded-2xl border border-white/10 bg-white/[0.06] p-4"
                                    >
                                        <span
                                            className="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl"
                                            style={{ background: accent, color: secondary }}
                                        >
                                            <Icon name={itemIcon(item)} className="h-6 w-6" />
                                        </span>
                                        <span className="text-sm font-bold leading-snug">{item}</span>
                                    </div>
                                ))}
                            </div>
                        </div>
                    </section>
                ) : null}

                {/* Products */}
                {products && products.items?.length ? (
                    <section className={pad}>
                        <SectionLabel n={3} accent={primary}>
                            {products.title}
                        </SectionLabel>
                        <h2 className={`font-display font-extrabold ${sectionTitle}`} style={{ color: secondary }}>
                            {products.body}
                        </h2>
                        <div className={`mt-6 grid gap-4 ${cols2}`}>
                            {products.items.map((item, i) => (
                                <div
                                    key={`${item}-${i}`}
                                    className="rounded-2xl border border-slate-200 p-5 shadow-sm"
                                    style={{ borderTop: `4px solid ${accent}` }}
                                >
                                    <Icon name={itemIcon(item)} className="h-6 w-6" />
                                    <div className="mt-2 font-display text-lg font-bold" style={{ color: secondary }}>
                                        {item}
                                    </div>
                                </div>
                            ))}
                        </div>
                    </section>
                ) : null}

                {/* Why us */}
                {why ? (
                    <section className={pad} style={{ background: `${primary}10` }}>
                        <SectionLabel n={4} accent={primary}>
                            {why.title}
                        </SectionLabel>
                        <div className={`mt-4 grid gap-x-8 gap-y-5 ${cols2}`}>
                            {(why.items || []).map((item, i) => (
                                <div key={`${item}-${i}`} className="flex items-start gap-4">
                                    <span
                                        className="font-display text-5xl font-extrabold leading-none"
                                        style={{ color: primary, opacity: 0.35 }}
                                    >
                                        {String(i + 1).padStart(2, '0')}
                                    </span>
                                    <span className="pt-1 text-sm font-bold leading-snug text-slate-800">{item}</span>
                                </div>
                            ))}
                        </div>
                    </section>
                ) : null}

                {extras.map((section, i) => (
                    <section key={`${section.key}-${i}`} className={pad}>
                        <SectionLabel n={5 + i} accent={primary}>
                            {section.title}
                        </SectionLabel>
                        {section.body ? <p className="text-sm leading-relaxed text-slate-600">{section.body}</p> : null}
                        {section.items?.length ? (
                            <ul className="mt-3 space-y-2">
                                {section.items.map((item, j) => (
                                    <li key={`${item}-${j}`} className="flex gap-2 text-sm font-medium text-slate-800">
                                        <span style={{ color: primary }}>
                                            <Icon name="check" className="mt-0.5 h-4 w-4" stroke={2.6} />
                                        </span>
                                        {item}
                                    </li>
                                ))}
                            </ul>
                        ) : null}
                    </section>
                ))}

                {/* Contact */}
                {contact ? (
                    <section
                        id="contact"
                        className={`relative overflow-hidden text-white ${pad}`}
                        style={{ background: `linear-gradient(150deg, ${primary} -20%, ${secondary} 90%)` }}
                    >
                        <div
                            className="pointer-events-none absolute -left-16 -top-16 h-52 w-52 rounded-full opacity-25"
                            style={{ background: accent }}
                        />
                        <div className={`relative flex gap-8 ${compact ? 'flex-col' : 'flex-col sm:flex-row sm:items-center'}`}>
                            <div className="flex-1">
                                <SectionLabel n={sections.filter((x) => x.key !== 'contact').length + 1} light accent={accent}>
                                    Contact
                                </SectionLabel>
                                <h2 className={`font-display font-extrabold ${sectionTitle}`}>{contact.title}</h2>
                                {contact.body ? <p className="mt-1 text-sm text-white/80">{contact.body}</p> : null}
                                <ul className="mt-6 space-y-3">
                                    {contactRows.map((c, i) => (
                                        <li key={`${c.value}-${i}`} className="flex items-center gap-3">
                                            <span
                                                className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full"
                                                style={{ background: accent, color: secondary }}
                                            >
                                                <Icon name={c.icon} className="h-4 w-4" />
                                            </span>
                                            <span className="min-w-0">
                                                <span className="block text-[10px] font-bold uppercase tracking-widest text-white/60">
                                                    {c.label}
                                                </span>
                                                {c.href ? (
                                                    <a
                                                        href={c.href}
                                                        onClick={onClick('cta')}
                                                        className="break-words text-sm font-bold hover:underline"
                                                    >
                                                        {c.value}
                                                    </a>
                                                ) : (
                                                    <span className="break-words text-sm font-bold">{c.value}</span>
                                                )}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                            {brochure.qr_url ? (
                                <div className="shrink-0 self-start text-center">
                                    <img
                                        src={brochure.qr_url}
                                        alt="QR code"
                                        className="h-36 w-36 rounded-2xl bg-white p-3 shadow-xl"
                                    />
                                    <div className="mt-2 text-[10px] font-bold uppercase tracking-widest text-white/70">
                                        Scan to share
                                    </div>
                                </div>
                            ) : null}
                        </div>
                    </section>
                ) : null}

                <footer className="bg-white px-6 py-5 text-center text-[11px] text-slate-500">
                    {brochure.styles?.default_footer ? <div className="mb-1">{brochure.styles.default_footer}</div> : null}
                    Powered by <BrandName className="font-semibold text-ink" />
                </footer>
            </div>
        </div>
    );
}
