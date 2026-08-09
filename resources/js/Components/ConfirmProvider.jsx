import DangerButton from '@/Components/DangerButton';
import Modal from '@/Components/Modal';
import SecondaryButton from '@/Components/SecondaryButton';
import {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useMemo,
    useRef,
    useState,
} from 'react';

const ConfirmContext = createContext(null);

let confirmApi = {
    ask: async () => false,
};

/**
 * Imperative confirm API — never use window.confirm().
 * @param {string | { title?: string, message?: string, confirmLabel?: string, cancelLabel?: string, tone?: 'danger' | 'default' }} options
 * @returns {Promise<boolean>}
 */
export function confirmAsk(options) {
    return confirmApi.ask(options);
}

function normalizeOptions(options) {
    if (typeof options === 'string') {
        return {
            title: 'Please confirm',
            message: options,
            confirmLabel: 'Confirm',
            cancelLabel: 'Cancel',
            tone: 'danger',
        };
    }

    return {
        title: options?.title || 'Please confirm',
        message: options?.message || '',
        confirmLabel: options?.confirmLabel || 'Confirm',
        cancelLabel: options?.cancelLabel || 'Cancel',
        tone: options?.tone === 'default' ? 'default' : 'danger',
    };
}

export function useConfirm() {
    const ctx = useContext(ConfirmContext);
    if (!ctx) {
        throw new Error('useConfirm must be used within ConfirmProvider');
    }
    return ctx;
}

export default function ConfirmProvider({ children }) {
    const [open, setOpen] = useState(false);
    const [options, setOptions] = useState(() => normalizeOptions(''));
    const [armed, setArmed] = useState(false);
    const resolverRef = useRef(null);
    const cancelRef = useRef(null);

    const close = useCallback((result) => {
        setOpen(false);
        setArmed(false);
        const resolve = resolverRef.current;
        resolverRef.current = null;
        resolve?.(Boolean(result));
    }, []);

    const ask = useCallback((raw) => {
        const next = normalizeOptions(raw);

        return new Promise((resolve) => {
            if (resolverRef.current) {
                resolverRef.current(false);
            }
            resolverRef.current = resolve;
            setArmed(false);
            setOptions(next);
            setOpen(true);
        });
    }, []);

    useEffect(() => {
        if (!open) {
            return undefined;
        }

        // Prevent the opening click from also activating Confirm (mouse-up click-through).
        const timer = window.setTimeout(() => setArmed(true), 280);
        const focusTimer = window.setTimeout(() => cancelRef.current?.focus?.(), 0);

        return () => {
            window.clearTimeout(timer);
            window.clearTimeout(focusTimer);
        };
    }, [open]);

    confirmApi = { ask };

    const value = useMemo(() => ({ ask }), [ask]);

    return (
        <ConfirmContext.Provider value={value}>
            {children}
            <Modal show={open} onClose={() => close(false)} maxWidth="sm" initialFocus={cancelRef}>
                <div className="p-5 sm:p-6">
                    <h2 className="font-display text-lg font-bold text-ink">{options.title}</h2>
                    {options.message ? (
                        <p className="mt-2 text-sm leading-relaxed text-ink-muted">{options.message}</p>
                    ) : null}
                    <div className="mt-5 flex flex-wrap justify-end gap-2">
                        <SecondaryButton
                            ref={cancelRef}
                            type="button"
                            onClick={() => close(false)}
                        >
                            {options.cancelLabel}
                        </SecondaryButton>
                        {options.tone === 'danger' ? (
                            <DangerButton
                                type="button"
                                disabled={!armed}
                                onClick={() => close(true)}
                            >
                                {options.confirmLabel}
                            </DangerButton>
                        ) : (
                            <button
                                type="button"
                                disabled={!armed}
                                className="inline-flex items-center justify-center rounded-md bg-signal px-3.5 py-2 text-sm font-semibold text-white hover:bg-signal-strong disabled:pointer-events-none disabled:opacity-50"
                                onClick={() => close(true)}
                            >
                                {options.confirmLabel}
                            </button>
                        )}
                    </div>
                </div>
            </Modal>
        </ConfirmContext.Provider>
    );
}
