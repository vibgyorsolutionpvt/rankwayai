export default function Toggle({ checked = false, onChange, label, disabled = false, className = '' }) {
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
                'inline-flex items-center gap-3 text-left focus:outline-none disabled:cursor-not-allowed disabled:opacity-60 ' +
                className
            }
        >
            <span
                className={
                    'relative h-6 w-11 shrink-0 rounded-full transition-colors duration-200 focus-visible:ring-2 focus-visible:ring-signal/40 ' +
                    (checked ? 'bg-signal' : 'bg-line')
                }
            >
                <span
                    aria-hidden="true"
                    className={
                        'pointer-events-none absolute top-0.5 left-0.5 h-5 w-5 rounded-full bg-white shadow-sm transition-transform duration-200 ' +
                        (checked ? 'translate-x-5' : 'translate-x-0')
                    }
                />
            </span>
            {label ? <span className="text-sm font-medium text-ink">{label}</span> : null}
        </button>
    );
}
