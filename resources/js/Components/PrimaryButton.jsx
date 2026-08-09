import { ButtonSpinner } from '@/Components/ToastProvider';

export default function PrimaryButton({
    className = '',
    disabled,
    processing = false,
    children,
    ...props
}) {
    const busy = Boolean(disabled || processing);

    return (
        <button
            {...props}
            className={
                `inline-flex items-center justify-center gap-2 rounded-md bg-signal px-4 py-2 text-sm font-semibold text-white shadow-sm transition duration-200 ease-out hover:-translate-y-0.5 hover:bg-signal-strong hover:shadow-lift focus:outline-none focus-visible:ring-2 focus-visible:ring-signal/40 focus-visible:ring-offset-2 active:translate-y-0 disabled:pointer-events-none disabled:opacity-50 ` +
                className
            }
            disabled={busy}
            aria-busy={busy || undefined}
        >
            {processing ? <ButtonSpinner /> : null}
            {children}
        </button>
    );
}
