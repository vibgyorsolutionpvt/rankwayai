import { Listbox, ListboxButton, ListboxOption, ListboxOptions, Transition } from '@headlessui/react';
import { Fragment } from 'react';

/**
 * Custom select — replaces native <select>.
 * options: [{ value, label, meta? }]
 */
export default function SelectMenu({
    value,
    onChange,
    options = [],
    placeholder = 'Select…',
    className = '',
    buttonClassName = '',
    disabled = false,
}) {
    const selected = options.find((opt) => String(opt.value) === String(value)) || null;

    return (
        <Listbox value={value} onChange={onChange} disabled={disabled}>
            <div className={`relative ${className}`}>
                <ListboxButton
                    className={
                        'relative flex w-full items-center gap-2 rounded-md border border-line bg-white py-2.5 pl-3 pr-10 text-left text-sm font-semibold text-ink shadow-sm transition ' +
                        'hover:border-signal/50 focus:outline-none focus-visible:ring-2 focus-visible:ring-signal/30 ' +
                        'disabled:cursor-not-allowed disabled:opacity-50 ' +
                        buttonClassName
                    }
                >
                    <span className="block truncate font-sans tracking-tight">
                        {selected?.label || placeholder}
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
                        className="z-[100] mt-1.5 max-h-60 w-[var(--button-width)] min-w-[220px] overflow-auto rounded-md border border-line bg-white py-1 text-sm shadow-panel [--anchor-gap:6px] focus:outline-none"
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
                                            <div className="truncate font-sans tracking-tight">
                                                {opt.label}
                                            </div>
                                            {opt.meta ? (
                                                <div className="mt-0.5 truncate text-xs font-medium text-ink-muted">
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
                        ))}
                    </ListboxOptions>
                </Transition>
            </div>
        </Listbox>
    );
}
