import { usePage } from '@inertiajs/react';
import WorkspaceNavLoader from '@/Components/WorkspaceNavLoader';
import {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useMemo,
    useRef,
    useState,
} from 'react';

const ToastContext = createContext(null);

let toastApi = {
    push: () => {},
    success: () => {},
    error: () => {},
    info: () => {},
};

/** Imperative toast API — usable outside React components */
export const toast = {
    push: (message, type = 'info', duration = 4200) => toastApi.push(message, type, duration),
    success: (message, duration) => toastApi.success(message, duration),
    error: (message, duration) => toastApi.error(message, duration),
    info: (message, duration) => toastApi.info(message, duration),
};

function ToastIcon({ type }) {
    if (type === 'success') {
        return (
            <svg viewBox="0 0 20 20" fill="currentColor" className="h-4 w-4" aria-hidden>
                <path
                    fillRule="evenodd"
                    d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z"
                    clipRule="evenodd"
                />
            </svg>
        );
    }
    if (type === 'error') {
        return (
            <svg viewBox="0 0 20 20" fill="currentColor" className="h-4 w-4" aria-hidden>
                <path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z" />
            </svg>
        );
    }
    return (
        <svg viewBox="0 0 20 20" fill="currentColor" className="h-4 w-4" aria-hidden>
            <path
                fillRule="evenodd"
                d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-8-5a.75.75 0 01.75.75v4.5a.75.75 0 01-1.5 0v-4.5A.75.75 0 0110 5zm0 10a1 1 0 100-2 1 1 0 000 2z"
                clipRule="evenodd"
            />
        </svg>
    );
}

const toneClass = {
    success: 'border-emerald-200 bg-white text-ink',
    error: 'border-rose-200 bg-white text-ink',
    info: 'border-line bg-white text-ink',
};

const iconWrap = {
    success: 'bg-emerald-100 text-emerald-700',
    error: 'bg-rose-100 text-rose-700',
    info: 'bg-signal-soft text-signal-strong',
};

/** Mount inside Inertia page tree (layouts) so usePage works */
export function AppFeedback() {
    const { flash, errors } = usePage().props;
    const { push } = useToast();
    const seenFlash = useRef({ success: null, error: null });
    const seenErrors = useRef('');

    useEffect(() => {
        if (flash?.success && flash.success !== seenFlash.current.success) {
            seenFlash.current.success = flash.success;
            push(flash.success, 'success');
        }
        if (flash?.error && flash.error !== seenFlash.current.error) {
            seenFlash.current.error = flash.error;
            push(flash.error, 'error');
        }
    }, [flash?.success, flash?.error, push]);

    useEffect(() => {
        const values = errors ? Object.values(errors).flat().filter(Boolean) : [];
        if (!values.length) {
            seenErrors.current = '';
            return;
        }
        const key = values.join('|');
        if (key === seenErrors.current) return;
        seenErrors.current = key;
        push(String(values[0]), 'error');
    }, [errors, push]);

    return <WorkspaceNavLoader />;
}

export function useToast() {
    const ctx = useContext(ToastContext);
    if (!ctx) return toast;
    return ctx;
}

export default function ToastProvider({ children }) {
    const [items, setItems] = useState([]);

    const dismiss = useCallback((id) => {
        setItems((prev) => prev.filter((t) => t.id !== id));
    }, []);

    const push = useCallback(
        (message, type = 'info', duration = 4200) => {
            if (!message) return;
            const id = `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
            setItems((prev) => [...prev.slice(-4), { id, message: String(message), type }]);
            if (duration > 0) {
                window.setTimeout(() => dismiss(id), duration);
            }
        },
        [dismiss],
    );

    const api = useMemo(
        () => ({
            push,
            success: (message, duration) => push(message, 'success', duration ?? 4200),
            error: (message, duration) => push(message, 'error', duration ?? 5200),
            info: (message, duration) => push(message, 'info', duration ?? 4200),
            dismiss,
        }),
        [push, dismiss],
    );

    useEffect(() => {
        toastApi = api;
    }, [api]);

    return (
        <ToastContext.Provider value={api}>
            {children}
            <div
                className="pointer-events-none fixed inset-x-0 top-3 z-[210] flex flex-col items-center gap-2 px-3 sm:items-end sm:px-4"
                aria-live="polite"
            >
                {items.map((item) => (
                    <div
                        key={item.id}
                        className={
                            'pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-lg border px-3.5 py-3 shadow-panel animate-toast-in ' +
                            (toneClass[item.type] || toneClass.info)
                        }
                        role="status"
                    >
                        <span
                            className={
                                'mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-md ' +
                                (iconWrap[item.type] || iconWrap.info)
                            }
                        >
                            <ToastIcon type={item.type} />
                        </span>
                        <div className="min-w-0 flex-1 text-sm font-medium leading-snug">
                            {item.message}
                        </div>
                        <button
                            type="button"
                            className="rounded-md p-1 text-ink-muted transition hover:bg-mist hover:text-ink"
                            onClick={() => dismiss(item.id)}
                            aria-label="Dismiss"
                        >
                            <svg viewBox="0 0 20 20" fill="currentColor" className="h-4 w-4">
                                <path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z" />
                            </svg>
                        </button>
                    </div>
                ))}
            </div>
        </ToastContext.Provider>
    );
}

export function ButtonSpinner({ className = 'h-4 w-4' }) {
    return <Spinner className={className} />;
}
