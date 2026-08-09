import { Listbox, ListboxButton, ListboxOption, ListboxOptions, Transition } from '@headlessui/react';
import { Fragment, useEffect, useMemo } from 'react';

/** Curated brand fonts — Bunny Fonts (already used by Atlas). */
export const BRAND_FONTS = [
    { value: 'Plus Jakarta Sans', slug: 'plus-jakarta-sans', sample: 'Aa Bb Cc 123' },
    { value: 'Outfit', slug: 'outfit', sample: 'Aa Bb Cc 123' },
    { value: 'Inter', slug: 'inter', sample: 'Aa Bb Cc 123' },
    { value: 'DM Sans', slug: 'dm-sans', sample: 'Aa Bb Cc 123' },
    { value: 'Poppins', slug: 'poppins', sample: 'Aa Bb Cc 123' },
    { value: 'Manrope', slug: 'manrope', sample: 'Aa Bb Cc 123' },
    { value: 'Montserrat', slug: 'montserrat', sample: 'Aa Bb Cc 123' },
    { value: 'Space Grotesk', slug: 'space-grotesk', sample: 'Aa Bb Cc 123' },
    { value: 'Source Sans 3', slug: 'source-sans-3', sample: 'Aa Bb Cc 123' },
    { value: 'Roboto', slug: 'roboto', sample: 'Aa Bb Cc 123' },
    { value: 'Lora', slug: 'lora', sample: 'Aa Bb Cc 123' },
    { value: 'Libre Baskerville', slug: 'libre-baskerville', sample: 'Aa Bb Cc 123' },
    { value: 'Playfair Display', slug: 'playfair-display', sample: 'Aa Bb Cc 123' },
    { value: 'Fraunces', slug: 'fraunces', sample: 'Aa Bb Cc 123' },
    { value: 'Bebas Neue', slug: 'bebas-neue', sample: 'Aa Bb Cc 123' },
];

const UI_FONT = '"Plus Jakarta Sans", system-ui, sans-serif';
const loaded = new Set();

/** Already loaded by app shell — reloading them causes page-wide FOUC. */
const APP_UI_FONT_SLUGS = new Set(['plus-jakarta-sans', 'outfit']);

function ensureFontsLoaded(fonts = BRAND_FONTS) {
    const missing = fonts.filter(
        (f) => f.slug && !loaded.has(f.slug) && !APP_UI_FONT_SLUGS.has(f.slug),
    );
    if (missing.length === 0) return;

    const families = missing.map((f) => `${f.slug}:400,500,600,700`).join('|');
    const href = `https://fonts.bunny.net/css?family=${families}&display=swap`;
    const existing = document.querySelector(`link[data-atlas-fonts="${families}"]`);
    if (!existing) {
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = href;
        link.dataset.atlasFonts = families;
        document.head.appendChild(link);
    }
    missing.forEach((f) => loaded.add(f.slug));
    APP_UI_FONT_SLUGS.forEach((slug) => loaded.add(slug));
}

function quotedFont(name) {
    if (!name) return UI_FONT;
    return /[,\s]/.test(name) ? `"${name}"` : name;
}

/**
 * Font dropdown — brand typeface only on sample glyphs, never on Atlas UI chrome.
 */
export default function FontPicker({
    value,
    onChange,
    className = '',
    buttonClassName = '',
    disabled = false,
}) {
    const options = useMemo(() => {
        const list = [...BRAND_FONTS];
        if (value && !list.some((f) => f.value === value)) {
            list.unshift({ value, slug: null, sample: 'Aa Bb Cc 123' });
        }
        return list;
    }, [value]);

    useEffect(() => {
        ensureFontsLoaded(BRAND_FONTS);
    }, []);

    const selected = options.find((opt) => opt.value === value) || options[0];

    return (
        <div className={className} style={{ fontFamily: UI_FONT }}>
            <Listbox value={value} onChange={onChange} disabled={disabled}>
                <div className="relative">
                    <ListboxButton
                        className={
                            'relative flex w-full items-center gap-2 rounded-md border border-line bg-white py-2.5 pl-3 pr-10 text-left text-sm font-semibold text-ink shadow-sm transition ' +
                            'hover:border-signal/50 focus:outline-none focus-visible:ring-2 focus-visible:ring-signal/30 ' +
                            'disabled:cursor-not-allowed disabled:opacity-50 ' +
                            buttonClassName
                        }
                        style={{ fontFamily: UI_FONT }}
                    >
                        <span
                            className="flex h-8 w-8 shrink-0 items-center justify-center rounded border border-line bg-mist/60 text-base font-semibold text-ink"
                            style={{ fontFamily: quotedFont(selected?.value) }}
                            aria-hidden
                        >
                            Aa
                        </span>
                        <span className="block min-w-0 flex-1 truncate tracking-tight" style={{ fontFamily: UI_FONT }}>
                            {selected?.value || 'Select font…'}
                        </span>
                        <span className="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-2.5 text-ink-muted">
                            <svg viewBox="0 0 20 20" fill="currentColor" className="h-4 w-4" aria-hidden>
                                <path
                                    fillRule="evenodd"
                                    d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.17l3.71-3.94a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z"
                                    clipRule="evenodd"
                                />
                            </svg>
                        </span>
                    </ListboxButton>

                    <Transition
                        as={Fragment}
                        leave="transition ease-in duration-100"
                        leaveFrom="opacity-100"
                        leaveTo="opacity-0"
                    >
                        <ListboxOptions
                            portal
                            anchor="bottom start"
                            className="z-[100] mt-1.5 max-h-72 w-[var(--button-width)] min-w-[260px] overflow-auto rounded-md border border-line bg-white py-1 text-sm shadow-panel [--anchor-gap:6px] focus:outline-none"
                            style={{ fontFamily: UI_FONT }}
                        >
                            {options.map((opt) => (
                                <ListboxOption
                                    key={opt.value}
                                    value={opt.value}
                                    className={({ focus, selected: isSelected }) =>
                                        'relative cursor-pointer select-none px-3 py-2.5 transition ' +
                                        (focus ? 'bg-signal-soft/70 text-signal-strong' : 'text-ink') +
                                        (isSelected ? ' font-bold' : ' font-medium')
                                    }
                                >
                                    {({ selected: isSelected }) => (
                                        <div className="flex items-center justify-between gap-3">
                                            <div className="min-w-0">
                                                <div
                                                    className="truncate text-[15px] tracking-tight"
                                                    style={{ fontFamily: UI_FONT }}
                                                >
                                                    {opt.value}
                                                </div>
                                                <div
                                                    className="mt-0.5 text-xs text-ink-muted"
                                                    style={{ fontFamily: quotedFont(opt.value) }}
                                                >
                                                    {opt.sample}
                                                </div>
                                            </div>
                                            {isSelected ? (
                                                <svg
                                                    viewBox="0 0 20 20"
                                                    fill="currentColor"
                                                    className="h-4 w-4 shrink-0 text-signal"
                                                    aria-hidden
                                                >
                                                    <path
                                                        fillRule="evenodd"
                                                        d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z"
                                                        clipRule="evenodd"
                                                    />
                                                </svg>
                                            ) : null}
                                        </div>
                                    )}
                                </ListboxOption>
                            ))}
                        </ListboxOptions>
                    </Transition>
                </div>
            </Listbox>
        </div>
    );
}
