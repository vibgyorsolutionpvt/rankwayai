import { Link } from '@inertiajs/react';

const TABS = [
    { id: 'cards', label: 'Business cards', route: 'studio.cards.index' },
    { id: 'brochures', label: 'Brochures', route: 'studio.brochures.index' },
    { id: 'itineraries', label: 'Itineraries', route: 'studio.itineraries.index' },
    { id: 'quotations', label: 'Quotations', route: 'studio.quotations.index' },
];

export default function StudioSubnav({ active = 'cards' }) {
    return (
        <nav
            aria-label="Studio sections"
            className="flex flex-wrap gap-2 rounded-lg border border-line/80 bg-white/90 p-1.5 shadow-panel"
        >
            {TABS.map((tab) => {
                const on = tab.id === active;
                return (
                    <Link
                        key={tab.id}
                        href={route(tab.route)}
                        className={
                            'rounded-md px-3.5 py-2 text-sm font-semibold transition ' +
                            (on
                                ? 'bg-emerald-200/90 text-emerald-950'
                                : 'text-ink-muted hover:bg-emerald-50 hover:text-emerald-900')
                        }
                    >
                        {tab.label}
                    </Link>
                );
            })}
        </nav>
    );
}
