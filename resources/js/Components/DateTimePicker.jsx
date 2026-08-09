import { Popover, PopoverButton, PopoverPanel, Transition } from '@headlessui/react';
import { Fragment, useMemo, useState } from 'react';

const WEEKDAYS = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];
const MONTHS = [
    'January',
    'February',
    'March',
    'April',
    'May',
    'June',
    'July',
    'August',
    'September',
    'October',
    'November',
    'December',
];

function pad(n) {
    return String(n).padStart(2, '0');
}

/** Parse `YYYY-MM-DDTHH:mm` or `YYYY-MM-DD` or empty → Date | null */
function parseValue(value, dateOnly = false) {
    if (!value) return null;
    const s = String(value);
    if (dateOnly) {
        const m = s.match(/^(\d{4})-(\d{2})-(\d{2})/);
        if (!m) return null;
        const d = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]));
        return Number.isNaN(d.getTime()) ? null : d;
    }
    const m = s.match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/);
    if (!m) return null;
    const d = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]), Number(m[4]), Number(m[5]));
    return Number.isNaN(d.getTime()) ? null : d;
}

function toValue(date, dateOnly = false) {
    if (!date) return '';
    const day = `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
    if (dateOnly) return day;
    return `${day}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

function formatDisplay(date, dateOnly = false) {
    if (!date) return '';
    const day = date.getDate();
    const month = MONTHS[date.getMonth()].slice(0, 3);
    const year = date.getFullYear();
    if (dateOnly) {
        return `${day} ${month} ${year}`;
    }
    let h = date.getHours();
    const mins = pad(date.getMinutes());
    const ampm = h >= 12 ? 'PM' : 'AM';
    h = h % 12 || 12;
    return `${day} ${month} ${year} · ${h}:${mins} ${ampm}`;
}

function startOfMonth(d) {
    return new Date(d.getFullYear(), d.getMonth(), 1);
}

function daysInMonth(d) {
    return new Date(d.getFullYear(), d.getMonth() + 1, 0).getDate();
}

function sameDay(a, b) {
    return (
        a &&
        b &&
        a.getFullYear() === b.getFullYear() &&
        a.getMonth() === b.getMonth() &&
        a.getDate() === b.getDate()
    );
}

function isToday(d) {
    return sameDay(d, new Date());
}

/**
 * Atlas DateTimePicker — replaces native datetime-local / date inputs.
 * value/onChange: `YYYY-MM-DDTHH:mm` by default, or `YYYY-MM-DD` when dateOnly.
 */
export default function DateTimePicker({
    value = '',
    onChange,
    className = '',
    placeholder = 'Pick date & time',
    minuteStep = 5,
    dateOnly = false,
}) {
    const selected = parseValue(value, dateOnly);
    const [view, setView] = useState(() => startOfMonth(selected || new Date()));

    const hours = useMemo(() => Array.from({ length: 12 }, (_, i) => i + 1), []);
    const minutes = useMemo(() => {
        const step = Math.max(1, Math.min(30, minuteStep));
        return Array.from({ length: Math.ceil(60 / step) }, (_, i) => i * step);
    }, [minuteStep]);

    const cells = useMemo(() => {
        const firstDow = startOfMonth(view).getDay();
        const total = daysInMonth(view);
        const list = [];
        for (let i = 0; i < firstDow; i++) list.push(null);
        for (let day = 1; day <= total; day++) {
            list.push(new Date(view.getFullYear(), view.getMonth(), day));
        }
        return list;
    }, [view]);

    const hour12 = selected ? selected.getHours() % 12 || 12 : 10;
    const minute = selected ? selected.getMinutes() - (selected.getMinutes() % minuteStep) : 0;
    const ampm = selected ? (selected.getHours() >= 12 ? 'PM' : 'AM') : 'AM';

    const commit = (next) => {
        onChange?.(toValue(next, dateOnly));
    };

    const pickDay = (day) => {
        if (!day) return;
        if (dateOnly) {
            commit(new Date(day.getFullYear(), day.getMonth(), day.getDate()));
            return;
        }
        const base = selected || new Date();
        const next = new Date(
            day.getFullYear(),
            day.getMonth(),
            day.getDate(),
            base.getHours(),
            base.getMinutes(),
        );
        if (!selected) {
            next.setHours(10, 0, 0, 0);
        }
        commit(next);
    };

    const setTime = ({ h12 = hour12, min = minute, period = ampm }) => {
        const base = selected || new Date();
        let h = h12 % 12;
        if (period === 'PM') h += 12;
        const next = new Date(
            base.getFullYear(),
            base.getMonth(),
            base.getDate(),
            h,
            min,
            0,
            0,
        );
        commit(next);
    };

    const setNow = () => {
        const n = new Date();
        if (dateOnly) {
            n.setHours(0, 0, 0, 0);
            setView(startOfMonth(n));
            commit(n);
            return;
        }
        n.setSeconds(0, 0);
        n.setMinutes(n.getMinutes() - (n.getMinutes() % minuteStep));
        setView(startOfMonth(n));
        commit(n);
    };

    return (
        <Popover className={`relative ${className}`}>
            {({ close }) => (
                <>
                    <PopoverButton
                        type="button"
                        className={
                            'relative flex w-full items-center gap-2 rounded-md border border-line bg-white py-2.5 pl-3 pr-10 text-left text-sm shadow-sm transition ' +
                            'hover:border-signal/50 focus:outline-none focus-visible:ring-2 focus-visible:ring-signal/30 ' +
                            (selected ? 'font-semibold text-ink' : 'font-medium text-ink-muted')
                        }
                    >
                        <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-signal-soft text-signal-strong">
                            <svg
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                strokeWidth="1.8"
                                className="h-4 w-4"
                                aria-hidden
                            >
                                <rect x="3" y="5" width="18" height="16" rx="2" />
                                <path d="M8 3v4M16 3v4M3 11h18" strokeLinecap="round" />
                            </svg>
                        </span>
                        <span className="block min-w-0 truncate tracking-tight">
                            {selected
                                ? formatDisplay(selected, dateOnly)
                                : placeholder || (dateOnly ? 'Pick date' : 'Pick date & time')}
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
                    </PopoverButton>

                    <Transition
                        as={Fragment}
                        enter="transition ease-out duration-150"
                        enterFrom="opacity-0 translate-y-1"
                        enterTo="opacity-100 translate-y-0"
                        leave="transition ease-in duration-100"
                        leaveFrom="opacity-100 translate-y-0"
                        leaveTo="opacity-0 translate-y-1"
                    >
                        <PopoverPanel
                            portal
                            anchor="bottom start"
                            className="z-[100] mt-1.5 w-[min(100vw-2rem,22rem)] overflow-hidden rounded-lg border border-line bg-white shadow-panel [--anchor-gap:8px] focus:outline-none"
                        >
                            <div className="border-b border-line bg-gradient-to-br from-signal-soft/50 to-white px-3 py-2.5">
                                <div className="flex items-center justify-between gap-2">
                                    <button
                                        type="button"
                                        className="rounded-md p-1.5 text-ink-muted transition hover:bg-white hover:text-ink"
                                        onClick={() =>
                                            setView(
                                                new Date(view.getFullYear(), view.getMonth() - 1, 1),
                                            )
                                        }
                                        aria-label="Previous month"
                                    >
                                        <Chevron dir="left" />
                                    </button>
                                    <div className="font-display text-sm font-bold text-ink">
                                        {MONTHS[view.getMonth()]} {view.getFullYear()}
                                    </div>
                                    <button
                                        type="button"
                                        className="rounded-md p-1.5 text-ink-muted transition hover:bg-white hover:text-ink"
                                        onClick={() =>
                                            setView(
                                                new Date(view.getFullYear(), view.getMonth() + 1, 1),
                                            )
                                        }
                                        aria-label="Next month"
                                    >
                                        <Chevron dir="right" />
                                    </button>
                                </div>
                            </div>

                            <div className="grid grid-cols-7 gap-0.5 px-2.5 pb-1 pt-2">
                                {WEEKDAYS.map((d) => (
                                    <div
                                        key={d}
                                        className="py-1 text-center text-[10px] font-semibold uppercase tracking-wide text-ink-muted"
                                    >
                                        {d}
                                    </div>
                                ))}
                                {cells.map((day, idx) => {
                                    if (!day) {
                                        return <div key={`e-${idx}`} className="h-9" />;
                                    }
                                    const active = sameDay(day, selected);
                                    const today = isToday(day);
                                    return (
                                        <button
                                            key={day.toISOString()}
                                            type="button"
                                            onClick={() => {
                                                pickDay(day);
                                                if (dateOnly) close();
                                            }}
                                            className={
                                                'flex h-9 items-center justify-center rounded-md text-sm font-semibold transition ' +
                                                (active
                                                    ? 'bg-signal text-white shadow-sm'
                                                    : today
                                                      ? 'bg-signal-soft text-signal-strong hover:bg-signal/20'
                                                      : 'text-ink hover:bg-mist')
                                            }
                                        >
                                            {day.getDate()}
                                        </button>
                                    );
                                })}
                            </div>

                            {!dateOnly ? (
                                <>
                                    <div className="mx-2.5 my-2 border-t border-line" />
                                    <div className="px-3 pb-2">
                                        <div className="mb-1.5 text-[10px] font-semibold uppercase tracking-wide text-ink-muted">
                                            Time
                                        </div>
                                        <div className="grid grid-cols-3 gap-2">
                                            <TimeColumn
                                                label="Hr"
                                                items={hours}
                                                selected={hour12}
                                                onPick={(h) => setTime({ h12: h })}
                                            />
                                            <TimeColumn
                                                label="Min"
                                                items={minutes}
                                                selected={minute}
                                                format={(n) => pad(n)}
                                                onPick={(m) => setTime({ min: m })}
                                            />
                                            <TimeColumn
                                                label=" "
                                                items={['AM', 'PM']}
                                                selected={ampm}
                                                onPick={(p) => setTime({ period: p })}
                                            />
                                        </div>
                                    </div>
                                </>
                            ) : null}

                            <div className="flex items-center justify-between gap-2 border-t border-line bg-mist/60 px-3 py-2">
                                <button
                                    type="button"
                                    className="text-xs font-semibold text-ink-muted transition hover:text-rose-600"
                                    onClick={() => {
                                        onChange?.('');
                                        close();
                                    }}
                                >
                                    Clear
                                </button>
                                <div className="flex gap-2">
                                    <button
                                        type="button"
                                        className="rounded-md px-2.5 py-1.5 text-xs font-semibold text-signal-strong transition hover:bg-signal-soft"
                                        onClick={() => {
                                            setNow();
                                            if (dateOnly) close();
                                        }}
                                    >
                                        {dateOnly ? 'Today' : 'Now'}
                                    </button>
                                    {!dateOnly ? (
                                        <button
                                            type="button"
                                            className="rounded-md bg-signal px-2.5 py-1.5 text-xs font-semibold text-white transition hover:bg-signal-strong"
                                            onClick={() => close()}
                                        >
                                            Done
                                        </button>
                                    ) : null}
                                </div>
                            </div>
                        </PopoverPanel>
                    </Transition>
                </>
            )}
        </Popover>
    );
}

function TimeColumn({ label, items, selected, onPick, format = (n) => String(n) }) {
    return (
        <div>
            {label.trim() ? (
                <div className="mb-1 text-center text-[10px] font-semibold uppercase text-ink-muted">
                    {label}
                </div>
            ) : (
                <div className="mb-1 h-3" />
            )}
            <div className="max-h-28 overflow-y-auto rounded-md border border-line bg-white py-0.5">
                {items.map((item) => {
                    const active = item === selected;
                    return (
                        <button
                            key={String(item)}
                            type="button"
                            onClick={() => onPick(item)}
                            className={
                                'flex w-full items-center justify-center px-1 py-1.5 text-sm font-semibold transition ' +
                                (active
                                    ? 'bg-signal text-white'
                                    : 'text-ink hover:bg-signal-soft/70 hover:text-signal-strong')
                            }
                        >
                            {format(item)}
                        </button>
                    );
                })}
            </div>
        </div>
    );
}

function Chevron({ dir }) {
    return (
        <svg viewBox="0 0 20 20" fill="currentColor" className="h-4 w-4" aria-hidden>
            {dir === 'left' ? (
                <path
                    fillRule="evenodd"
                    d="M12.79 5.23a.75.75 0 0 1-.02 1.06L8.83 10l3.94 3.71a.75.75 0 1 1-1.04 1.08l-4.5-4.25a.75.75 0 0 1 0-1.08l4.5-4.25a.75.75 0 0 1 1.06.02Z"
                    clipRule="evenodd"
                />
            ) : (
                <path
                    fillRule="evenodd"
                    d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.17 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z"
                    clipRule="evenodd"
                />
            )}
        </svg>
    );
}
