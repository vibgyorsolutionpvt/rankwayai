function quotedFont(name) {
    if (!name) return undefined;
    return /[,\s]/.test(name) ? `"${name}"` : name;
}

function ContactIcon({ type }) {
    const common = {
        viewBox: '0 0 24 24',
        fill: 'none',
        stroke: 'currentColor',
        strokeWidth: 1.8,
        strokeLinecap: 'round',
        strokeLinejoin: 'round',
        className: 'h-3.5 w-3.5',
        'aria-hidden': true,
    };

    if (type === 'email') {
        return (
            <svg {...common}>
                <rect x="3" y="5" width="18" height="14" rx="2" />
                <path d="m3 7 9 7 9-7" />
            </svg>
        );
    }
    if (type === 'web') {
        return (
            <svg {...common}>
                <circle cx="12" cy="12" r="9" />
                <path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18" />
            </svg>
        );
    }
    return (
        <svg {...common}>
            <path d="M6.5 4.5h3l1.5 4-2 1.5a12 12 0 0 0 5 5l1.5-2 4 1.5v3a2 2 0 0 1-2 2A15 15 0 0 1 4.5 6.5a2 2 0 0 1 2-2z" />
        </svg>
    );
}

function ActionGlyph({ type }) {
    const common = {
        viewBox: '0 0 24 24',
        fill: 'none',
        stroke: 'currentColor',
        strokeWidth: 1.8,
        className: 'h-4 w-4',
        'aria-hidden': true,
    };
    switch (type) {
        case 'sms':
            return (
                <svg {...common}>
                    <path d="M4 6h16v10H8l-4 3V6z" strokeLinecap="round" strokeLinejoin="round" />
                </svg>
            );
        case 'email':
            return (
                <svg {...common}>
                    <rect x="3" y="5" width="18" height="14" rx="2" />
                    <path d="m3 7 9 7 9-7" />
                </svg>
            );
        case 'wa':
            return (
                <svg {...common}>
                    <path d="M7 18.5 4.5 20l.7-3.2A7.5 7.5 0 1 1 12 19.5a7.4 7.4 0 0 1-3.4-.9L7 18.5z" />
                </svg>
            );
        case 'web':
            return (
                <svg {...common}>
                    <circle cx="12" cy="12" r="9" />
                    <path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18" />
                </svg>
            );
        case 'map':
            return (
                <svg {...common}>
                    <path d="M12 21s7-5.2 7-11a7 7 0 1 0-14 0c0 5.8 7 11 7 11z" />
                    <circle cx="12" cy="10" r="2.5" />
                </svg>
            );
        case 'facebook':
            return (
                <svg {...common} fill="currentColor" stroke="none">
                    <path d="M14 9h2.5V6.2C16 4.6 17.1 4 18.4 4H20v3h-1.6c-.6 0-.9.3-.9.9V9H20l-.4 3H17.5v8H14v-8h-2V9h2z" />
                </svg>
            );
        case 'instagram':
            return (
                <svg {...common}>
                    <rect x="4" y="4" width="16" height="16" rx="4" />
                    <circle cx="12" cy="12" r="3.5" />
                    <circle cx="17" cy="7" r="1" fill="currentColor" stroke="none" />
                </svg>
            );
        case 'linkedin':
            return (
                <svg {...common} fill="currentColor" stroke="none">
                    <path d="M6.5 9.5H9v9H6.5v-9zM7.7 4.8a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3zM11 9.5h2.4v1.3h.1c.3-.6 1.2-1.5 2.6-1.5 2.8 0 3.3 1.8 3.3 4.2V18.5H17v-4c0-1 0-2.2-1.4-2.2s-1.6 1-1.6 2.1v4.1H11v-9z" />
                </svg>
            );
        case 'x':
            return (
                <svg {...common} fill="currentColor" stroke="none">
                    <path d="M5 5h3.2l3.4 4.6L15.5 5H19l-5.2 6.2L19.2 19h-3.2l-3.7-5-4.2 5H5l5.5-6.5L5 5z" />
                </svg>
            );
        default:
            return (
                <svg {...common}>
                    <path d="M6.5 4.5h3l1.5 4-2 1.5a12 12 0 0 0 5 5l1.5-2 4 1.5v3a2 2 0 0 1-2 2A15 15 0 0 1 4.5 6.5a2 2 0 0 1 2-2z" />
                </svg>
            );
    }
}

function CompanyLogo({ url, compact = false, className = '', size = 'md' }) {
    if (!url) return null;
    const sizes =
        size === 'lg'
            ? compact
                ? 'h-8 max-w-[88px]'
                : 'h-12 max-w-[160px]'
            : compact
              ? 'h-5 max-w-[64px]'
              : 'h-9 max-w-[128px]';
    return (
        <img
            src={url}
            alt=""
            className={'object-contain ' + sizes + (className ? ' ' + className : '')}
        />
    );
}

/** Logo if present, otherwise company name text. */
function CompanyBrand({
    logoUrl,
    company,
    compact = false,
    className = '',
    textClassName = '',
    textStyle,
    headingFont,
    logoSize = 'md',
}) {
    if (logoUrl) {
        return (
            <CompanyLogo
                url={logoUrl}
                compact={compact}
                className={className}
                size={logoSize}
            />
        );
    }
    if (!company) return null;
    return (
        <div
            className={
                (textClassName ||
                    'truncate font-bold leading-tight ' +
                        (compact ? 'text-[8px]' : 'text-xs')) +
                (className ? ' ' + className : '')
            }
            style={{ fontFamily: headingFont, ...textStyle }}
        >
            {company}
        </div>
    );
}

function contactLines(card) {
    const lines = [];
    if (card?.phone) lines.push({ key: 'phone', text: card.phone });
    if (card?.whatsapp && card.whatsapp !== card.phone) {
        lines.push({ key: 'wa', text: `WA ${card.whatsapp}` });
    }
    if (card?.email) lines.push({ key: 'email', text: card.email });
    if (card?.website) lines.push({ key: 'web', text: card.website });
    if (card?.address) lines.push({ key: 'map', text: card.address });
    return lines;
}

function socialItemsFromCard(card) {
    return [
        { key: 'facebook', type: 'facebook' },
        { key: 'instagram', type: 'instagram' },
        { key: 'linkedin', type: 'linkedin' },
        { key: 'x', type: 'x' },
    ].filter((s) => card?.social_links?.[s.key]);
}

function socialBrandColor(key) {
    switch (key) {
        case 'facebook':
            return '#1877F2';
        case 'instagram':
            return '#E4405F';
        case 'linkedin':
            return '#0A66C2';
        case 'x':
            return '#111827';
        default:
            return '#475569';
    }
}

/**
 * Each template has its own vibrant look — not green/black monoculture.
 * muted/contact colors tuned for readable contrast.
 */
function templateColors(template) {
    switch (template) {
        case 'classic':
            return {
                bg: '#FFF7ED',
                bar: '#EA580C',
                company: '#9A3412',
                ink: '#0C0A09',
                muted: '#57534E',
                contact: '#1C1917',
                social: '#EA580C',
                descBand: '#FFEDD5',
            };
        case 'digital':
            return {
                header: 'linear-gradient(165deg, #DBEAFE 0%, #F0F9FF 45%, #F8FAFC 100%)',
                company: '#1E40AF',
                icon: '#2563EB',
                photo: '#F59E0B',
                ink: '#0F172A',
                muted: '#475569',
                contact: '#0F172A',
                descBand: '#EFF6FF',
                useSocialBrands: true,
            };
        case 'connect':
            return {
                coverFrom: '#6366F1',
                coverTo: '#EC4899',
                action: '#6366F1',
                photo: '#F472B6',
                ink: '#1E1B4B',
                muted: '#4B5563',
                contact: '#1E1B4B',
                descBand: '#F5F3FF',
                useSocialBrands: true,
            };
        case 'modern':
            return {
                bg: 'linear-gradient(145deg, #312E81 0%, #6D28D9 55%, #DB2777 100%)',
                band: '#FBBF24',
                ink: '#FFFFFF',
                muted: 'rgba(255,255,255,0.88)',
                contact: 'rgba(255,255,255,0.95)',
                social: '#FBBF24',
            };
        case 'minimal':
            return {
                bg: '#FAF5FF',
                rule: '#A855F7',
                company: '#6B21A8',
                ink: '#1E1B4B',
                muted: '#5B21B6',
                contact: '#1E1B4B',
                social: '#A855F7',
                descBand: '#F3E8FF',
            };
        default:
            return {
                bg: '#FFF7ED',
                bar: '#EA580C',
                company: '#9A3412',
                ink: '#0C0A09',
                muted: '#57534E',
                contact: '#1C1917',
                social: '#EA580C',
                descBand: '#FFEDD5',
            };
    }
}

function PersonAvatar({ url, initial, color, compact = false, size = 'md', className = '' }) {
    const box =
        size === 'lg'
            ? compact
                ? 'h-12 w-12'
                : 'h-[88px] w-[88px]'
            : compact
              ? 'h-10 w-10'
              : 'h-16 w-16';
    return (
        <div
            className={
                'shrink-0 overflow-hidden rounded-full border-[3px] ' +
                box +
                (className ? ' ' + className : '')
            }
            style={{ borderColor: color, background: color }}
        >
            {url ? (
                <img src={url} alt="" className="h-full w-full object-cover" />
            ) : (
                <div className="flex h-full w-full items-center justify-center text-lg font-bold text-white">
                    {initial}
                </div>
            )}
        </div>
    );
}

function ContactLines({ lines, color, compact = false }) {
    if (!lines.length) return null;
    return (
        <div
            className={
                'mt-auto space-y-1 pt-3 font-medium ' +
                (compact ? 'text-[8px] leading-snug' : 'text-[13px] leading-snug')
            }
            style={{ color }}
        >
            {lines.map((l) => (
                <div key={l.key} className="truncate">
                    {l.text}
                </div>
            ))}
        </div>
    );
}

function MiniSocials({ items, color, compact = false, useBrands = false, ink }) {
    if (!items.length) return null;
    return (
        <div className={'flex items-center ' + (compact ? 'gap-0.5' : 'gap-1.5')}>
            {items.map((s) => {
                const bg = useBrands ? socialBrandColor(s.key) : color;
                const fg = ink || '#fff';
                return (
                    <span
                        key={s.key}
                        title={s.key}
                        className={
                            'inline-flex items-center justify-center rounded-full ' +
                            (compact ? 'h-3.5 w-3.5' : 'h-5 w-5')
                        }
                        style={{ background: bg, color: fg }}
                    >
                        <span className={compact ? 'scale-[0.5]' : 'scale-[0.62]'}>
                            <ActionGlyph type={s.type} />
                        </span>
                    </span>
                );
            })}
        </div>
    );
}

function connectActionsFromCard(card) {
    const social = card?.social_links || {};
    const contacts = [];
    const socials = [];
    if (card?.phone) contacts.push({ type: 'phone', label: 'Call' });
    if (card?.email) contacts.push({ type: 'email', label: 'Email' });
    if (card?.whatsapp || card?.phone) contacts.push({ type: 'wa', label: 'WhatsApp' });
    if (card?.website) contacts.push({ type: 'web', label: 'Website' });
    if (card?.address) contacts.push({ type: 'map', label: 'Direction' });
    if (social.facebook) socials.push({ type: 'facebook', label: 'Facebook' });
    if (social.instagram) socials.push({ type: 'instagram', label: 'Instagram' });
    if (social.linkedin) socials.push({ type: 'linkedin', label: 'LinkedIn' });
    if (social.x) socials.push({ type: 'x', label: 'X' });
    return { contacts, socials };
}

/** Formal, readable description block used across templates. */
function CardDescription({
    text,
    compact = false,
    tone = 'light',
    band = false,
    bandBg,
    className = '',
}) {
    const value = String(text || '').trim();
    if (!value) return null;

    const size = compact
        ? 'text-[8px] leading-[1.4]'
        : 'text-[13px] leading-[1.55]';
    const color = tone === 'dark' ? 'text-white' : 'text-[#0f172a]';
    const base =
        size +
        ' ' +
        color +
        ' font-medium text-left whitespace-pre-line break-words hyphens-none ' +
        className;

    if (band) {
        return (
            <div
                className={(compact ? 'px-2.5 py-2 ' : 'px-4 py-3.5 ') + base}
                style={{
                    background: bandBg || (tone === 'dark' ? 'rgba(255,255,255,0.12)' : '#f8fafc'),
                }}
            >
                {value}
            </div>
        );
    }

    return <div className={base}>{value}</div>;
}

function sizeBox(template, size) {
    if (template === 'digital') {
        if (size === 'lg') return 'h-[520px] w-[280px]';
        if (size === 'sm') return 'h-[210px] w-[118px]';
        return 'h-[440px] w-[220px]';
    }
    if (template === 'connect') {
        if (size === 'lg') return 'min-h-0 w-[280px]';
        if (size === 'sm') return 'min-h-0 w-[118px]';
        return 'min-h-0 w-[220px]';
    }
    if (size === 'lg') return 'h-[240px] w-[420px]';
    if (size === 'sm') return 'h-[112px] w-[200px]';
    return 'h-[168px] w-[300px]';
}

export default function BusinessCardPreview({
    card,
    className = '',
    size = 'md',
    showCta = true,
    actionLinks = null,
    onActionClick = null,
}) {
    const styles = card?.styles || {};
    const template =
        card?.template_key === 'bold' ? 'classic' : card?.template_key || 'classic';
    const company = card?.company_name || 'Your company';
    const name = (card?.person_name || '').trim();
    const role = (card?.person_title || '').trim();
    const displayInitial = (name || company || 'A').charAt(0).toUpperCase();
    const bodyFont = quotedFont(styles.body_font || 'Plus Jakarta Sans');
    const headingFont = quotedFont(styles.heading_font || styles.body_font || 'Outfit');
    const sizeClass = sizeBox(template, size);
    const tc = templateColors(template);
    const lines = contactLines(card);
    const socialItems = socialItemsFromCard(card);
    const base =
        `relative flex max-w-full flex-col overflow-hidden rounded-xl shadow-lift ${sizeClass} ` +
        className;

    if (template === 'digital') {
        const compact = size === 'sm';
        const digitalRows = [
            card?.phone ? { type: 'phone', value: card.phone, label: 'Work' } : null,
            card?.whatsapp && card.whatsapp !== card.phone
                ? { type: 'wa', value: card.whatsapp, label: 'WhatsApp' }
                : null,
            card?.email ? { type: 'email', value: card.email, label: 'Work' } : null,
            card?.website ? { type: 'web', value: card.website, label: 'Company' } : null,
            card?.address ? { type: 'map', value: card.address, label: 'Direction' } : null,
        ].filter(Boolean);
        const description = (card?.tagline || '').trim();

        const photo = (
            <div
                className={
                    'shrink-0 overflow-hidden rounded-full border-[3px] ' +
                    (compact ? 'h-11 w-11' : 'h-[72px] w-[72px]')
                }
                style={{ borderColor: tc.photo, background: tc.photo }}
            >
                {card?.person_photo_url ? (
                    <img
                        src={card.person_photo_url}
                        alt=""
                        className="h-full w-full object-cover"
                    />
                ) : (
                    <div className="flex h-full w-full items-center justify-center text-lg font-bold text-white">
                        {(displayInitial)}
                    </div>
                )}
            </div>
        );

        return (
            <div
                className={base + ' border border-line bg-white'}
                style={{ fontFamily: bodyFont, color: tc.ink }}
            >
                <div
                    className={compact ? 'px-2.5 pb-2 pt-2.5' : 'px-4 pb-3 pt-3.5'}
                    style={{ background: tc.header }}
                >
                    <div className="flex items-start justify-between gap-2">
                        <div className="min-w-0 flex-1">
                            <CompanyBrand
                                logoUrl={card?.logo_url}
                                company={company}
                                compact={compact}
                                logoSize="lg"
                                headingFont={headingFont}
                                textStyle={{ color: tc.company }}
                            />
                            {name ? (
                                <div
                                    className={
                                        'mt-2 font-bold leading-tight ' +
                                        (compact ? 'text-[11px]' : 'text-xl')
                                    }
                                    style={{ fontFamily: headingFont, color: tc.ink }}
                                >
                                    {name}
                                </div>
                            ) : null}
                            {role ? (
                                <div
                                    className={compact ? 'mt-0.5 text-[7px]' : 'mt-0.5 text-xs'}
                                    style={{ color: tc.muted }}
                                >
                                    {role}
                                </div>
                            ) : null}
                        </div>
                        <div className="flex shrink-0 flex-col items-end gap-1.5">
                            {socialItems.length > 0 ? (
                                <MiniSocials items={socialItems} compact={compact} useBrands />
                            ) : null}
                            {photo}
                        </div>
                    </div>
                </div>

                {description ? (
                    <div
                        className={compact ? 'px-2.5 py-2' : 'px-4 py-3'}
                        style={{ background: tc.descBand }}
                    >
                        <CardDescription text={description} compact={compact} />
                    </div>
                ) : null}

                <div
                    className={
                        'flex flex-1 flex-col gap-2 bg-white ' +
                        (compact ? 'px-2.5 py-2' : 'gap-2.5 px-4 pt-3 pb-2')
                    }
                >
                    {digitalRows.map((row) => (
                        <div key={row.type} className="flex items-center gap-2">
                            <span
                                className={
                                    'inline-flex shrink-0 items-center justify-center rounded-full text-white ' +
                                    (compact ? 'h-5 w-5' : 'h-8 w-8')
                                }
                                style={{ background: tc.icon }}
                            >
                                {row.type === 'map' || row.type === 'wa' ? (
                                    <ActionGlyph type={row.type} />
                                ) : (
                                    <ContactIcon type={row.type} />
                                )}
                            </span>
                            <div className="min-w-0">
                                <div
                                    className={
                                        'truncate font-semibold ' +
                                        (compact ? 'text-[8px]' : 'text-[13px]')
                                    }
                                    style={{ color: tc.contact }}
                                >
                                    {row.value}
                                </div>
                                {!compact ? (
                                    <div className="text-[11px] font-medium" style={{ color: tc.muted }}>
                                        {row.label}
                                    </div>
                                ) : null}
                            </div>
                        </div>
                    ))}
                </div>

                <div className={compact ? 'h-2' : 'h-4'} aria-hidden />
            </div>
        );
    }

    if (template === 'connect') {
        const compact = size === 'sm';
        const { contacts, socials } = connectActionsFromCard(card);
        const description = (card?.tagline || '').trim();
        const hasAny = contacts.length > 0 || socials.length > 0 || description;

        const renderActionGrid = (items, kind = 'contact') => {
            if (items.length === 0) return null;
            const cols = items.length;
            return (
                <div
                    className={
                        'grid ' +
                        (compact ? 'gap-0.5 px-1.5' : 'gap-1.5 px-2.5')
                    }
                    style={{ gridTemplateColumns: `repeat(${cols}, minmax(0, 1fr))` }}
                >
                    {items.map((a) => {
                        const href = actionLinks?.[a.type] || null;
                        const title =
                            a.type === 'map' && card?.address
                                ? card.address
                                : a.label;
                        const bg =
                            kind === 'social' ? socialBrandColor(a.type) : tc.action;
                        const inner = (
                            <>
                                <span
                                    className={
                                        'inline-flex items-center justify-center rounded-full text-white ' +
                                        (compact ? 'h-5 w-5' : 'h-9 w-9')
                                    }
                                    style={{ background: bg }}
                                >
                                    <ActionGlyph type={a.type} />
                                </span>
                                <span
                                    className={
                                        compact
                                            ? 'text-center text-[6px] font-medium'
                                            : 'text-center text-[10px] font-semibold'
                                    }
                                    style={{ color: tc.muted }}
                                >
                                    {a.label}
                                </span>
                            </>
                        );

                        if (href) {
                            return (
                                <a
                                    key={a.label}
                                    href={href}
                                    title={title}
                                    target={
                                        a.type === 'phone' || a.type === 'email'
                                            ? undefined
                                            : '_blank'
                                    }
                                    rel="noreferrer"
                                    onClick={() => onActionClick?.(a.type)}
                                    className="flex flex-col items-center gap-0.5 no-underline"
                                >
                                    {inner}
                                </a>
                            );
                        }

                        return (
                            <div
                                key={a.label}
                                className="flex flex-col items-center gap-0.5"
                                title={title}
                            >
                                {inner}
                            </div>
                        );
                    })}
                </div>
            );
        };

        return (
            <div
                className={base + ' bg-white'}
                style={{ fontFamily: bodyFont, color: tc.ink }}
            >
                <div
                    className={'relative shrink-0 ' + (compact ? 'h-16' : 'h-28')}
                    style={{
                        background: card?.cover_image_url
                            ? undefined
                            : `linear-gradient(135deg, ${tc.coverFrom}, ${tc.coverTo})`,
                    }}
                >
                    {card?.cover_image_url ? (
                        <img
                            src={card.cover_image_url}
                            alt=""
                            className="h-full w-full object-cover"
                        />
                    ) : null}
                    <div
                        className={
                            'absolute left-1/2 overflow-hidden rounded-full border-4 border-white bg-white shadow ' +
                            (compact
                                ? 'top-[40px] h-12 w-12 -translate-x-1/2'
                                : 'top-[72px] h-20 w-20 -translate-x-1/2')
                        }
                    >
                        {card?.person_photo_url ? (
                            <img
                                src={card.person_photo_url}
                                alt=""
                                className="h-full w-full object-cover"
                            />
                        ) : (
                            <div
                                className="flex h-full w-full items-center justify-center text-xl font-bold text-white"
                                style={{ background: tc.photo }}
                            >
                                {(displayInitial)}
                            </div>
                        )}
                    </div>
                </div>

                <div
                    className={
                        'text-center ' +
                        (compact ? 'px-2 pb-1.5 pt-8' : 'px-3 pb-2 pt-12')
                    }
                >
                    <div className="mb-2 flex justify-center">
                        <CompanyBrand
                            logoUrl={card?.logo_url}
                            company={company}
                            compact={compact}
                            logoSize="lg"
                            textClassName={
                                'font-medium ' +
                                (compact ? 'text-[6px]' : 'text-[11px]')
                            }
                            textStyle={{ color: tc.muted }}
                        />
                    </div>
                    {name ? (
                        <div
                            className={
                                'font-bold leading-tight ' + (compact ? 'text-[10px]' : 'text-lg')
                            }
                            style={{ fontFamily: headingFont }}
                        >
                            {name}
                        </div>
                    ) : null}
                    {role ? (
                        <div
                            className={compact ? 'text-[7px]' : 'text-xs'}
                            style={{ color: tc.muted }}
                        >
                            {role}
                        </div>
                    ) : null}
                </div>

                {hasAny ? (
                    <div className={compact ? 'space-y-1.5 pb-2.5 pt-0.5' : 'space-y-3 pb-4 pt-1'}>
                        {renderActionGrid(contacts, 'contact')}
                        {description ? (
                            <CardDescription
                                text={description}
                                compact={compact}
                                band
                                bandBg={tc.descBand}
                            />
                        ) : contacts.length > 0 && socials.length > 0 ? (
                            <div className={compact ? 'h-2' : 'h-3'} aria-hidden />
                        ) : null}
                        {renderActionGrid(socials, 'social')}
                    </div>
                ) : (
                    <div
                        className={
                            'px-3 text-center text-slate-500 ' +
                            (compact ? 'pb-2 text-[6px]' : 'pb-4 text-[10px]')
                        }
                    >
                        Add phone, email, website or socials
                    </div>
                )}
            </div>
        );
    }

    if (template === 'modern') {
        return (
            <div
                className={base}
                style={{ background: tc.bg, color: tc.ink, fontFamily: bodyFont }}
            >
                <div className="h-2.5 w-full" style={{ background: tc.band }} />
                <div className="flex flex-1 flex-col p-4">
                    <div className="flex items-start justify-between gap-2">
                        <CompanyBrand
                            logoUrl={card?.logo_url}
                            company={company}
                            logoSize="lg"
                            textClassName="truncate text-[10px] font-semibold uppercase tracking-[0.14em]"
                            textStyle={{ color: tc.muted }}
                        />
                        <MiniSocials items={socialItems} color={tc.social} ink="#312E81" />
                    </div>
                    <div className="mt-auto">
                        <div className="flex items-end justify-between gap-3">
                            <div className="min-w-0 flex-1">
                                {name ? (
                                    <div
                                        className="text-lg font-bold leading-tight"
                                        style={{ fontFamily: headingFont }}
                                    >
                                        {name}
                                    </div>
                                ) : null}
                                {role ? (
                                    <div className="mt-0.5 text-xs" style={{ color: tc.muted }}>
                                        {role}
                                    </div>
                                ) : null}
                                <CardDescription text={card?.tagline} tone="dark" className="mt-2" />
                                <ContactLines lines={lines} color={tc.contact} />
                            </div>
                            <PersonAvatar
                                url={card?.person_photo_url}
                                initial={displayInitial}
                                color={tc.band}
                                size="lg"
                            />
                        </div>
                    </div>
                </div>
            </div>
        );
    }

    if (template === 'minimal') {
        return (
            <div
                className={base + ' border border-violet-100 p-5'}
                style={{ background: tc.bg, color: tc.ink, fontFamily: bodyFont }}
            >
                <div className="mb-3 h-0.5 w-10" style={{ background: tc.rule }} />
                <div className="flex items-start justify-between gap-2">
                    <CompanyBrand
                        logoUrl={card?.logo_url}
                        company={company}
                        logoSize="lg"
                        textClassName="truncate text-xs font-semibold uppercase tracking-[0.14em]"
                        textStyle={{ color: tc.company }}
                    />
                    <MiniSocials items={socialItems} color={tc.social} />
                </div>
                <div className="flex flex-1 gap-3">
                    <div className="flex min-w-0 flex-1 flex-col">
                        {name ? (
                            <div
                                className="mt-3 text-xl font-bold leading-tight"
                                style={{ fontFamily: headingFont }}
                            >
                                {name}
                            </div>
                        ) : null}
                        {role ? (
                            <div className="mt-1 text-sm font-medium" style={{ color: tc.muted }}>
                                {role}
                            </div>
                        ) : null}
                        <CardDescription
                            text={card?.tagline}
                            band
                            bandBg={tc.descBand}
                            className="mt-2.5 rounded-lg"
                        />
                        <ContactLines lines={lines} color={tc.contact} />
                    </div>
                    <PersonAvatar
                        url={card?.person_photo_url}
                        initial={displayInitial}
                        color={tc.rule}
                        size="lg"
                        className="mt-3"
                    />
                </div>
            </div>
        );
    }

    // classic — warm orange / cream
    return (
        <div
            className={base}
            style={{
                background: tc.bg,
                borderLeft: `10px solid ${tc.bar}`,
                fontFamily: bodyFont,
                color: tc.ink,
            }}
        >
            <div className="flex flex-1 gap-3 p-4">
                <div className="flex min-w-0 flex-1 flex-col">
                    <div className="flex items-start justify-between gap-2">
                        <CompanyBrand
                            logoUrl={card?.logo_url}
                            company={company}
                            logoSize="lg"
                            textClassName="truncate text-xs font-semibold uppercase tracking-[0.14em]"
                            textStyle={{ color: tc.company }}
                        />
                        <MiniSocials items={socialItems} color={tc.social} />
                    </div>
                    {name ? (
                        <div
                            className="mt-3 text-lg font-bold leading-tight"
                            style={{ fontFamily: headingFont }}
                        >
                            {name}
                        </div>
                    ) : null}
                    {role ? (
                        <div className="mt-0.5 text-sm font-medium" style={{ color: tc.muted }}>
                            {role}
                        </div>
                    ) : null}
                    <CardDescription
                        text={card?.tagline}
                        band
                        bandBg={tc.descBand}
                        className="mt-2.5 rounded-md"
                    />
                    <ContactLines lines={lines} color={tc.contact} />
                </div>
                <PersonAvatar
                    url={card?.person_photo_url}
                    initial={displayInitial}
                    color={tc.bar}
                    size="lg"
                />
            </div>
        </div>
    );
}
