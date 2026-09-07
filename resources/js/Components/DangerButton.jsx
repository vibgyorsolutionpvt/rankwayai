import { ButtonSpinner } from '@/Components/ToastProvider';

export default function DangerButton({
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
                `inline-flex items-center justify-center gap-2 rounded-md bg-danger px-3.5 py-2 text-sm font-semibold text-white transition duration-200 ease-out hover:-translate-y-0.5 hover:bg-danger/90 focus:outline-none focus-visible:ring-2 focus-visible:ring-danger/30 focus-visible:ring-offset-2 active:translate-y-0 disabled:pointer-events-none disabled:opacity-50 ` +
                className
            }
            disabled={busy}
            aria-busy={busy || undefined}
        >
            {processing ? <ButtonSpinner className="h-4 w-4 text-white" /> : null}
            {children}
        </button>
    );
}
