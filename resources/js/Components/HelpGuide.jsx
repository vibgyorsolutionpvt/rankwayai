import Modal from '@/Components/Modal';
import { useState } from 'react';

function InfoIcon({ className = 'h-3.5 w-3.5' }) {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            className={className}
            aria-hidden
        >
            <circle cx="12" cy="12" r="9" />
            <path d="M12 10.5V16" strokeLinecap="round" />
            <circle cx="12" cy="7.5" r="0.9" fill="currentColor" stroke="none" />
        </svg>
    );
}

/**
 * Info button → modal with Hindi / English help.
 *
 * Pass either `help={{ title, hindi, english }}` or separate title/hindi/english props.
 */
export default function HelpGuide({ help, title, hindi, english, className = '' }) {
    const [open, setOpen] = useState(false);
    const [lang, setLang] = useState('en');

    const resolvedTitle = help?.title ?? title;
    const body = lang === 'hi' ? (help?.hindi ?? hindi) : (help?.english ?? english);

    if (!resolvedTitle || !body?.what || !Array.isArray(body?.how)) {
        return null;
    }

    return (
        <>
            <button
                type="button"
                onClick={() => setOpen(true)}
                title="Help"
                aria-label={`Help: ${resolvedTitle}`}
                className={`inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-ink-muted transition hover:bg-mist hover:text-signal-strong focus:outline-none focus-visible:ring-2 focus-visible:ring-signal/30 ${className}`}
            >
                <InfoIcon />
            </button>

            <Modal show={open} onClose={() => setOpen(false)} maxWidth="md">
                <div className="border-b border-line px-5 py-4 sm:px-6">
                    <div className="flex items-center justify-between gap-3">
                        <h3 className="font-display text-lg font-bold text-ink">{resolvedTitle}</h3>
                        <button
                            type="button"
                            onClick={() => setOpen(false)}
                            className="rounded-md p-1.5 text-ink-muted hover:bg-mist hover:text-ink"
                            aria-label="Close"
                        >
                            <svg
                                viewBox="0 0 24 24"
                                className="h-4 w-4"
                                fill="none"
                                stroke="currentColor"
                                strokeWidth="2"
                            >
                                <path d="M6 6l12 12M18 6L6 18" strokeLinecap="round" />
                            </svg>
                        </button>
                    </div>
                    <div className="mt-3 flex w-fit gap-0.5 rounded-lg bg-mist p-0.5">
                        <button
                            type="button"
                            onClick={() => setLang('en')}
                            className={`rounded-md px-3 py-1.5 text-xs font-semibold ${
                                lang === 'en' ? 'bg-white text-ink shadow-sm' : 'text-ink-muted'
                            }`}
                        >
                            English
                        </button>
                        <button
                            type="button"
                            onClick={() => setLang('hi')}
                            className={`rounded-md px-3 py-1.5 text-xs font-semibold ${
                                lang === 'hi' ? 'bg-white text-ink shadow-sm' : 'text-ink-muted'
                            }`}
                        >
                            हिन्दी
                        </button>
                    </div>
                </div>

                <div className="space-y-5 px-5 py-5 text-sm leading-relaxed text-ink sm:px-6">
                    <section>
                        <h4 className="text-xs font-semibold uppercase tracking-wide text-ink-muted">
                            {lang === 'hi' ? 'ये क्या है' : 'What this is'}
                        </h4>
                        <p className="mt-1.5 text-ink/90">{body.what}</p>
                    </section>
                    <section>
                        <h4 className="text-xs font-semibold uppercase tracking-wide text-ink-muted">
                            {lang === 'hi' ? 'कैसे इस्तेमाल करें' : 'How to use'}
                        </h4>
                        <ol className="mt-1.5 list-decimal space-y-1.5 pl-4 text-ink/90">
                            {body.how.map((step) => (
                                <li key={step}>{step}</li>
                            ))}
                        </ol>
                    </section>
                    {body.note ? (
                        <p className="rounded-lg border border-line bg-mist/70 px-3 py-2.5 text-xs text-ink-muted">
                            {body.note}
                        </p>
                    ) : null}
                </div>
            </Modal>
        </>
    );
}

export { HELP, SEO_HELP, PROVIDER_HELP } from '@/help/content';
