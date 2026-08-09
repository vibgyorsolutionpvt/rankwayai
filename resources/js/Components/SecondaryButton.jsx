import { ButtonSpinner } from '@/Components/ToastProvider';
import { forwardRef } from 'react';

const SecondaryButton = forwardRef(function SecondaryButton(
    { type = 'button', className = '', disabled, processing = false, children, ...props },
    ref,
) {
    const busy = Boolean(disabled || processing);

    return (
        <button
            {...props}
            ref={ref}
            type={type}
            className={
                `inline-flex items-center justify-center gap-2 rounded-md border border-line bg-white px-3.5 py-2 text-sm font-semibold text-ink transition duration-200 ease-out hover:-translate-y-0.5 hover:border-signal/40 hover:bg-signal-soft/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-signal/30 focus-visible:ring-offset-2 active:translate-y-0 disabled:pointer-events-none disabled:opacity-50 ` +
                className
            }
            disabled={busy}
            aria-busy={busy || undefined}
        >
            {processing ? <ButtonSpinner className="h-4 w-4 text-signal" /> : null}
            {children}
        </button>
    );
});

export default SecondaryButton;
