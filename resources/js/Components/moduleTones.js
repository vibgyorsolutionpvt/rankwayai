/** Shared colorful module card styles (sidebar-matched tones). */
export const MODULE_TONES = {
    amber: {
        card: 'border-amber-200 bg-gradient-to-br from-amber-50 to-white',
        chip: 'bg-amber-100 text-amber-800',
        off: 'border-amber-100 bg-amber-50/40 opacity-70',
    },
    rose: {
        card: 'border-rose-200 bg-gradient-to-br from-rose-50 to-white',
        chip: 'bg-rose-100 text-rose-800',
        off: 'border-rose-100 bg-rose-50/40 opacity-70',
    },
    sky: {
        card: 'border-sky-200 bg-gradient-to-br from-sky-50 to-white',
        chip: 'bg-sky-100 text-sky-800',
        off: 'border-sky-100 bg-sky-50/40 opacity-70',
    },
    blue: {
        card: 'border-blue-200 bg-gradient-to-br from-blue-50 to-white',
        chip: 'bg-blue-100 text-blue-800',
        off: 'border-blue-100 bg-blue-50/40 opacity-70',
    },
    zinc: {
        card: 'border-zinc-300 bg-gradient-to-br from-zinc-100 to-white',
        chip: 'bg-zinc-200 text-zinc-900',
        off: 'border-zinc-200 bg-zinc-50/60 opacity-70',
    },
    fuchsia: {
        card: 'border-fuchsia-200 bg-gradient-to-br from-fuchsia-50 to-white',
        chip: 'bg-fuchsia-100 text-fuchsia-800',
        off: 'border-fuchsia-100 bg-fuchsia-50/40 opacity-70',
    },
    emerald: {
        card: 'border-emerald-200 bg-gradient-to-br from-emerald-50 to-white',
        chip: 'bg-emerald-100 text-emerald-800',
        off: 'border-emerald-100 bg-emerald-50/40 opacity-70',
    },
    signal: {
        card: 'border-signal/30 bg-gradient-to-br from-signal-soft/80 to-white',
        chip: 'bg-signal-soft text-signal-strong',
        off: 'border-signal/15 bg-signal-soft/30 opacity-70',
    },
    ink: {
        card: 'border-line bg-gradient-to-br from-mist to-white',
        chip: 'bg-mist-deep text-ink',
        off: 'border-line bg-mist/50 opacity-70',
    },
};

export function moduleTone(keyOrTone) {
    return MODULE_TONES[keyOrTone] || MODULE_TONES.signal;
}

const KEY_TONES = {
    today: 'amber',
    brand: 'rose',
    media: 'sky',
    social: 'fuchsia',
    seo: 'emerald',
    ai: 'rose',
    channels: 'sky',
    whatsapp: 'emerald',
    crm: 'amber',
    funnels: 'fuchsia',
    billing: 'emerald',
    settings: 'signal',
};

export function toneForModule(item) {
    return item?.tone || KEY_TONES[item?.key] || 'signal';
}
