export default function Toggle({
    checked = false,
    onChange,
    label,
    disabled = false,
    className = '',
    size = 'md',
}) {
    const compact = size === 'sm';

    return (
        <button
            type="button"
            role="switch"
            aria-checked={checked}
            disabled={disabled}
            onClick={() => {
                if (!disabled) {
                    onChange?.(!checked);
                }
            }}
            className={
                'flex w-full items-center gap-3 text-left focus:outline-none disabled:cursor-not-allowed disabled:opacity-60 ' +
                className
            }
        >
            <span
                className={
                    'relative shrink-0 rounded-full transition-colors duration-200 focus-visible:ring-2 focus-visible:ring-signal/40 ' +
                    (compact ? 'h-5 w-9' : 'h-6 w-11') +
                    ' ' +
                    (checked ? 'bg-signal' : 'bg-line')
                }
            >
                <span
                    aria-hidden="true"
                    className={
                        'pointer-events-none absolute left-0.5 rounded-full bg-white shadow-sm transition-transform duration-200 ' +
                        (compact ? 'top-0.5 h-4 w-4 ' : 'top-0.5 h-5 w-5 ') +
                        (checked ? (compact ? 'translate-x-4' : 'translate-x-5') : 'translate-x-0')
                    }
                />
            </span>
            {label ? <span className="min-w-0 flex-1 text-sm font-medium text-ink">{label}</span> : null}
        </button>
    );
}
