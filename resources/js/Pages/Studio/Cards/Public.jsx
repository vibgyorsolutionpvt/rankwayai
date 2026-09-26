import BusinessCardPreview from '@/Components/Studio/BusinessCardPreview';
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

function telHref(phone) {
    return phone ? `tel:${String(phone).replace(/\s+/g, '')}` : null;
}

function waHref(phone) {
    if (!phone) return null;
    const digits = String(phone).replace(/\D+/g, '');
    return digits ? `https://wa.me/${digits}` : null;
}

function vcardHref(card) {
    const lines = [
        'BEGIN:VCARD',
        'VERSION:3.0',
        `FN:${card.person_name || card.company_name || 'Contact'}`,
        card.person_name ? `N:${card.person_name};;;;` : null,
        card.company_name ? `ORG:${card.company_name}` : null,
        card.person_title ? `TITLE:${card.person_title}` : null,
        card.phone ? `TEL;TYPE=CELL:${card.phone}` : null,
        card.email ? `EMAIL:${card.email}` : null,
        card.website ? `URL:${card.website}` : null,
        card.address ? `ADR;TYPE=WORK:;;${card.address};;;;` : null,
        'END:VCARD',
    ].filter(Boolean);
    return `data:text/vcard;charset=utf-8,${encodeURIComponent(lines.join('\n'))}`;
}

function mapsHref(address) {
    if (!address) return null;
    return `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(String(address).trim())}`;
}

function absoluteHref(url) {
    if (!url) return null;
    return String(url).startsWith('http') ? String(url) : `https://${url}`;
}

export default function Public({ card, track_url }) {
    const isDigital = card.template_key === 'digital';
    const isConnect = card.template_key === 'connect';
    const socials = [
        { key: 'facebook', label: 'Facebook', href: card.social_links?.facebook },
        { key: 'instagram', label: 'Instagram', href: card.social_links?.instagram },
        { key: 'linkedin', label: 'LinkedIn', href: card.social_links?.linkedin },
        { key: 'x', label: 'X', href: card.social_links?.x },
    ].filter((s) => s.href);

    const actionLinks = {
        phone: telHref(card.phone),
        email: card.email ? `mailto:${card.email}` : null,
        wa: waHref(card.whatsapp || card.phone),
        web: absoluteHref(card.website),
        map: mapsHref(card.address),
        facebook: absoluteHref(card.social_links?.facebook),
        instagram: absoluteHref(card.social_links?.instagram),
        linkedin: absoluteHref(card.social_links?.linkedin),
        x: absoluteHref(card.social_links?.x),
    };

    const actions = [
        { key: 'phone', label: 'Call', href: actionLinks.phone, show: !!card.phone },
        {
            key: 'whatsapp',
            label: 'WhatsApp',
            href: actionLinks.wa,
            show: !!(card.whatsapp || card.phone),
        },
        {
            key: 'email',
            label: 'Email',
            href: actionLinks.email,
            show: !!card.email,
        },
        {
            key: 'website',
            label: 'Website',
            href: actionLinks.web,
            show: !!card.website,
        },
        {
            key: 'direction',
            label: 'Direction',
            href: actionLinks.map,
            show: !!card.address,
        },
        {
            key: 'vcard',
            label: '+ Add to Contacts',
            href: vcardHref(card),
            show: !isDigital && !isConnect,
            download: `${(card.person_name || card.company_name || 'contact').replace(/\s+/g, '-')}.vcf`,
        },
    ].filter((a) => a.show && a.href);

    return (
        <div className="min-h-screen bg-gradient-to-b from-mist to-white px-4 py-8">
            <Head title={card.person_name || card.company_name || 'Business card'} />
            <div className="mx-auto flex max-w-md flex-col items-center gap-5">
                <BusinessCardPreview
                    card={card}
                    size="lg"
                    showCta={false}
                    actionLinks={isConnect ? actionLinks : undefined}
                    onActionClick={(key) =>
                        track(
                            track_url,
                            key === 'map'
                                ? 'website'
                                : key === 'wa'
                                  ? 'whatsapp'
                                  : key === 'web'
                                    ? 'website'
                                    : key,
                        )
                    }
                    className={
                        isDigital || isConnect ? 'max-w-[320px]' : 'w-full max-w-[360px]'
                    }
                />

                {isDigital && socials.length > 0 ? (
                    <div className="flex flex-wrap items-center justify-center gap-3">
                        {socials.map((s) => (
                            <a
                                key={s.key}
                                href={absoluteHref(s.href)}
                                target="_blank"
                                rel="noreferrer"
                                onClick={() => track(track_url, 'website')}
                                className="inline-flex h-11 w-11 items-center justify-center rounded-full bg-ink text-sm font-semibold text-white transition hover:bg-ink/85"
                                aria-label={s.label}
                                title={s.label}
                            >
                                {s.label.charAt(0)}
                            </a>
                        ))}
                    </div>
                ) : null}

                {!isConnect ? (
                    <div className="w-full space-y-2">
                        {actions.map((action) => (
                            <a
                                key={action.key}
                                href={action.href}
                                download={action.download || undefined}
                                target={
                                    action.key === 'website' ||
                                    action.key === 'whatsapp' ||
                                    action.key === 'direction'
                                        ? '_blank'
                                        : undefined
                                }
                                rel="noreferrer"
                                onClick={() =>
                                    track(
                                        track_url,
                                        action.key === 'vcard' || action.key === 'direction'
                                            ? 'website'
                                            : action.key,
                                    )
                                }
                                className={
                                    'block rounded-xl px-4 py-3 text-center text-sm font-semibold shadow-sm transition ' +
                                    (action.key === 'vcard'
                                        ? 'bg-ink text-white hover:bg-ink/90'
                                        : 'border border-line bg-white text-ink hover:border-signal hover:bg-signal-soft/30')
                                }
                            >
                                {action.label}
                                {action.key === 'direction' && card.address ? (
                                    <span className="mt-0.5 block text-xs font-normal text-ink-muted">
                                        {card.address}
                                    </span>
                                ) : null}
                            </a>
                        ))}
                    </div>
                ) : card.address ? (
                    <p className="text-center text-xs text-ink-muted">{card.address}</p>
                ) : null}

                {card.qr_url ? (
                    <div className="flex flex-col items-center gap-2 rounded-xl border border-line bg-white p-4">
                        <img src={card.qr_url} alt="QR" className="h-28 w-28" />
                        <div className="text-[10px] font-semibold uppercase tracking-wide text-ink-muted">
                            Scan to save
                        </div>
                    </div>
                ) : null}

                <div className="pt-2 text-center text-[11px] text-ink-muted">
                    Powered by <BrandName className="font-semibold text-ink" />
                </div>
            </div>
        </div>
    );
}
