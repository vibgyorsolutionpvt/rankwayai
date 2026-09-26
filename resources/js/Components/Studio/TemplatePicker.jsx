import { useEffect, useState } from 'react';
import BusinessCardPreview from '@/Components/Studio/BusinessCardPreview';

const DEMO_CARD = {
    company_name: 'Your company',
    person_name: 'Alex Morgan',
    person_title: 'Founder',
    phone: '98765 43210',
    email: 'hello@company.com',
    website: 'company.com',
    whatsapp: '9876543210',
    address: 'City, India',
    tagline: 'Your short description here',
    social_links: {
        facebook: 'https://facebook.com',
        instagram: 'https://instagram.com',
        linkedin: 'https://linkedin.com',
        x: 'https://x.com',
    },
};

export default function TemplatePicker({
    templates = [],
    value,
    onChange,
    sampleCard = {},
    defaultOpen = false,
}) {
    const selected = templates.find((t) => t.value === value) || templates[0] || null;
    const [open, setOpen] = useState(defaultOpen || !value);

    useEffect(() => {
        if (!value) setOpen(true);
    }, [value]);

    const thumbCard = {
        ...DEMO_CARD,
        styles: sampleCard?.styles || {},
    };

    function pick(next) {
        onChange(next);
        setOpen(false);
    }

    return (
        <div className="overflow-hidden rounded-xl border border-line bg-white">
            <button
                type="button"
                onClick={() => setOpen((v) => !v)}
                className="flex w-full items-center gap-3 px-3.5 py-3 text-left transition hover:bg-mist/40"
                aria-expanded={open}
            >
                <div className="min-w-0 flex-1">
                    <div className="text-sm font-semibold text-ink">Choose template *</div>
                    <div className="mt-0.5 truncate text-xs text-ink-muted">
                        {selected
                            ? `${selected.label} · ${selected.description}`
                            : 'Pick a layout — Classic is the default.'}
                    </div>
                </div>
                {selected ? (
                    <span className="shrink-0 rounded-md bg-emerald-600 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-white">
                        {selected.label}
                    </span>
                ) : null}
                <svg
                    viewBox="0 0 20 20"
                    fill="currentColor"
                    className={
                        'h-4 w-4 shrink-0 text-ink-muted transition ' + (open ? 'rotate-180' : '')
                    }
                    aria-hidden
                >
                    <path
                        fillRule="evenodd"
                        d="M5.23 7.21a.75.75 0 011.06.02L10 10.94l3.71-3.71a.75.75 0 111.06 1.06l-4.24 4.24a.75.75 0 01-1.06 0L5.21 8.29a.75.75 0 01.02-1.08z"
                        clipRule="evenodd"
                    />
                </svg>
            </button>

            {open ? (
                <div className="border-t border-line px-3 pb-3 pt-2">
                    <p className="mb-2 text-[11px] text-ink-muted">
                        Select a layout. Live changes show in the preview panel on the right.
                    </p>
                    <div className="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                        {templates.map((tpl) => {
                            const isSelected = value === tpl.value;
                            return (
                                <button
                                    key={tpl.value}
                                    type="button"
                                    onClick={() => pick(tpl.value)}
                                    className={
                                        'flex flex-col overflow-hidden rounded-xl border text-left transition ' +
                                        (isSelected
                                            ? 'border-emerald-500 bg-emerald-50/60 ring-2 ring-emerald-200'
                                            : 'border-line bg-white hover:border-emerald-300 hover:bg-emerald-50/30')
                                    }
                                >
                                    <div className="flex min-h-[120px] items-center justify-center bg-mist/50 p-3">
                                        <BusinessCardPreview
                                            card={{ ...thumbCard, template_key: tpl.value }}
                                            size="sm"
                                            showCta={false}
                                        />
                                    </div>
                                    <div className="border-t border-line/70 px-3 py-2">
                                        <div className="flex items-center justify-between gap-2">
                                            <div className="text-sm font-semibold text-ink">
                                                {tpl.label}
                                            </div>
                                            {isSelected ? (
                                                <span className="rounded bg-emerald-600 px-1.5 py-0.5 text-[9px] font-bold uppercase text-white">
                                                    Selected
                                                </span>
                                            ) : null}
                                        </div>
                                        <div className="mt-0.5 text-[11px] leading-snug text-ink-muted">
                                            {tpl.description}
                                        </div>
                                    </div>
                                </button>
                            );
                        })}
                    </div>
                </div>
            ) : null}
        </div>
    );
}
