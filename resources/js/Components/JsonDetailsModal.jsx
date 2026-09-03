import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import { useEffect, useState } from 'react';

export function prettyJson(value) {
    if (value == null || value === '') {
        return '';
    }
    if (typeof value === 'string') {
        try {
            return JSON.stringify(JSON.parse(value), null, 2);
        } catch {
            return value;
        }
    }
    try {
        return JSON.stringify(value, null, 2);
    } catch {
        return String(value);
    }
}

export function previewJson(value, max = 36) {
    const raw =
        value == null || value === ''
            ? ''
            : typeof value === 'string'
              ? value
              : prettyJson(value).replace(/\s+/g, ' ');
    if (!raw) {
        return '';
    }
    return raw.length > max ? `${raw.slice(0, max).trim()}…` : raw;
}

function EyeIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            className="h-3.5 w-3.5"
            aria-hidden
        >
            <path
                strokeLinecap="round"
                strokeLinejoin="round"
                d="M2.25 12s3.75-6.75 9.75-6.75S21.75 12 21.75 12s-3.75 6.75-9.75 6.75S2.25 12 2.25 12z"
            />
            <circle cx="12" cy="12" r="2.5" />
        </svg>
    );
}

export function DetailTrigger({ value, onOpen }) {
    if (!value || (typeof value === 'object' && Object.keys(value).length === 0)) {
        return <span className="text-ink-muted">—</span>;
    }

    return (
        <div className="flex min-w-0 items-center gap-2">
            <span className="min-w-0 flex-1 truncate rounded-md border border-line/80 bg-white px-2 py-1.5 font-mono text-[11px] text-ink-muted">
                {previewJson(value)}
            </span>
            <button
                type="button"
                onClick={onOpen}
                className="inline-flex shrink-0 items-center gap-1.5 rounded-md bg-signal px-2.5 py-1.5 text-xs font-semibold text-white shadow-sm transition duration-200 hover:-translate-y-0.5 hover:bg-signal-strong hover:shadow-lift"
            >
                <EyeIcon />
                View
            </button>
        </div>
    );
}

export default function JsonDetailsModal({ show, title = 'Details', value, onClose }) {
    const pretty = prettyJson(value);
    const [copied, setCopied] = useState(false);

    useEffect(() => {
        if (!show) {
            setCopied(false);
        }
    }, [show]);

    const copy = async () => {
        if (!pretty) {
            return;
        }
        try {
            await navigator.clipboard.writeText(pretty);
            setCopied(true);
            window.setTimeout(() => setCopied(false), 1600);
        } catch {
            setCopied(false);
        }
    };

    return (
        <Modal show={show} onClose={onClose} maxWidth="3xl">
            <div className="flex items-start justify-between gap-3 border-b border-line bg-gradient-to-r from-signal-soft/50 via-white to-transparent px-4 py-4 sm:px-5">
                <div className="min-w-0">
                    <div className="text-[11px] font-semibold uppercase tracking-[0.14em] text-signal-strong">
                        Full record
                    </div>
                    <h3 className="mt-1 break-words font-display text-lg font-bold text-ink">
                        {title}
                    </h3>
                </div>
                <button
                    type="button"
                    onClick={onClose}
                    className="shrink-0 rounded-md p-1.5 text-ink-muted transition hover:bg-white hover:text-ink"
                    aria-label="Close"
                >
                    <svg className="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden>
                        <path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z" />
                    </svg>
                </button>
            </div>
            <div className="max-h-[min(70vh,32rem)] overflow-auto px-4 py-4 sm:px-5">
                <pre className="whitespace-pre-wrap break-all rounded-lg border border-signal/20 border-l-4 border-l-signal bg-gradient-to-br from-signal-soft/35 to-mist/70 p-4 font-mono text-xs leading-relaxed text-ink">
                    {pretty || '—'}
                </pre>
            </div>
            <div className="flex flex-wrap justify-end gap-2 border-t border-line bg-mist/40 px-4 py-3 sm:px-5">
                <SecondaryButton type="button" onClick={onClose}>
                    Close
                </SecondaryButton>
                {pretty ? (
                    <PrimaryButton type="button" onClick={copy}>
                        {copied ? 'Copied' : 'Copy JSON'}
                    </PrimaryButton>
                ) : null}
            </div>
        </Modal>
    );
}
