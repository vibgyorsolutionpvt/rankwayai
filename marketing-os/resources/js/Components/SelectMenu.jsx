import { Listbox, ListboxButton, ListboxOption, ListboxOptions, Transition } from '@headlessui/react';
import { Fragment, useMemo, useRef, useState } from 'react';

/**
 * Custom select — replaces native <select>. Search is always on.
 * options: [{ value, label, meta? }]
 */
export default function SelectMenu({
    value,
    onChange,
    options = [],
    placeholder = 'Select…',
    searchPlaceholder = 'Search…',
    className = '',
    buttonClassName = '',
    disabled = false,
}) {
    const [query, setQuery] = useState('');
    const searchRef = useRef(null);
    const selected = options.find((opt) => String(opt.value) === String(value)) || null;

    const filtered = useMemo(() => {
        const needle = query.trim().toLowerCase();
        if (!needle) {
            return options;
        }
        return options.filter((opt) => {
            const haystack = [opt.label, opt.meta, String(opt.value ?? '')]
                .filter(Boolean)
                .join(' ')
                .toLowerCase();
            return haystack.includes(needle);
        });
    }, [options, query]);

    const resetQuery = () => setQuery('');

    return (
        <Listbox
            value={value}
            onChange={(next) => {
                resetQuery();
                onChange(next);
            }}
            disabled={disabled}
            onClose={resetQuery}
        >
            <div className={`relative ${className}`}>
                <ListboxButton
                    className={
                        'relative flex w-full items-center gap-2 rounded-md border border-line bg-white py-2.5 pl-3 pr-10 text-left text-sm font-medium text-ink shadow-sm transition ' +
                        'hover:border-signal/50 focus:outline-none focus-visible:ring-2 focus-visible:ring-signal/30 data-[open]:border-signal ' +
                        'disabled:cursor-not-allowed disabled:opacity-50 ' +
                        buttonClassName
                    }
                >
                    <span className="block truncate">{selected?.label || placeholder}</span>
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
                    afterEnter={() => requestAnimationFrame(() => searchRef.current?.focus())}
                >
                    <ListboxOptions
                        portal
                        anchor="bottom start"
                        className="z-[100] mt-1.5 max-h-72 w-[var(--button-width)] min-w-[240px] overflow-hidden rounded-md border border-line bg-white text-sm shadow-panel [--anchor-gap:6px] focus:outline-none"
                    >
                        <div className="sticky top-0 z-10 border-b border-line bg-gradient-to-r from-signal-soft/40 via-white to-white p-2">
                            <label className="relative block">
                                <span className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-2.5 text-ink-muted">
                                    <svg
                                        viewBox="0 0 20 20"
                                        fill="none"
                                        stroke="currentColor"
                                        strokeWidth="1.8"
                                        className="h-3.5 w-3.5"
                                        aria-hidden
                                    >
                                        <circle cx="8.5" cy="8.5" r="5.5" />
                                        <path strokeLinecap="round" d="M13 13l3.5 3.5" />
                                    </svg>
                                </span>
                                <input
                                    ref={searchRef}
                                    type="search"
                                    value={query}
                                    onChange={(e) => setQuery(e.target.value)}
                                    onClick={(e) => e.stopPropagation()}
                                    onKeyDown={(e) => {
                                        if (e.key !== 'Escape') {
                                            e.stopPropagation();
                                        }
                                    }}
                                    placeholder={searchPlaceholder}
                                    className="w-full rounded-md border border-line bg-white py-2 pl-8 pr-3 text-sm text-ink placeholder:text-ink-muted/70 outline-none transition focus:border-signal focus:ring-2 focus:ring-signal/20"
                                />
                            </label>
                        </div>

                        <div className="max-h-52 overflow-auto py-1">
                            {filtered.length === 0 ? (
                                <div className="px-3 py-6 text-center text-sm text-ink-muted">
                                    No matches
                                </div>
                            ) : (
                                filtered.map((opt) => (
                                    <ListboxOption
                                        key={String(opt.value)}
                                        value={opt.value}
                                        className={({ focus, selected: isSelected }) =>
                                            'relative cursor-pointer select-none px-3 py-2.5 font-medium text-ink transition ' +
                                            (focus ? 'bg-signal-soft/70 text-signal-strong' : '') +
                                            (isSelected ? ' bg-mist/60' : '')
                                        }
                                    >
                                        {({ selected: isSelected }) => (
                                            <div className="flex items-center justify-between gap-3">
                                                <div className="min-w-0">
                                                    <div className="truncate">{opt.label}</div>
                                                    {opt.meta ? (
                                                        <div className="mt-0.5 truncate text-xs font-normal text-ink-muted">
                                                            {opt.meta}
                                                        </div>
                                                    ) : null}
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
                                ))
                            )}
                        </div>
                    </ListboxOptions>
                </Transition>
            </div>
        </Listbox>
    );
}
