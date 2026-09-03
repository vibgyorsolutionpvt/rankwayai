import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import { useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';

function GlobeIcon({ className = 'h-5 w-5' }) {
    return (
        <svg
            className={className}
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.8"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden
        >
            <circle cx="12" cy="12" r="9" />
            <path d="M3 12h18" />
            <path d="M12 3a14 14 0 0 1 0 18" />
            <path d="M12 3a14 14 0 0 0 0 18" />
        </svg>
    );
}

function Step({ n, label, active, done }) {
    return (
        <div className="flex min-w-0 items-center gap-2">
            <span
                className={
                    'flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-[11px] font-bold ' +
                    (done
                        ? 'bg-signal text-white'
                        : active
                          ? 'bg-ink text-white'
                          : 'bg-white/70 text-ink-muted ring-1 ring-line')
                }
            >
                {done ? (
                    <svg className="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden>
                        <path
                            fillRule="evenodd"
                            d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z"
                            clipRule="evenodd"
                        />
                    </svg>
                ) : (
                    n
                )}
            </span>
            <span
                className={
                    'truncate text-xs font-semibold ' +
                    (active || done ? 'text-ink' : 'text-ink-muted')
                }
            >
                {label}
            </span>
        </div>
    );
}

export default function CreateWorkspaceModal({
    open,
    onClose,
    buttonLabel = 'Create workspace',
    showTrigger = true,
    triggerClassName = '',
}) {
    const [show, setShow] = useState(Boolean(open));
    const form = useForm({ domain: '' });

    useEffect(() => {
        if (typeof open === 'boolean') {
            setShow(open);
        }
    }, [open]);

    const close = () => {
        if (form.processing) {
            return;
        }
        setShow(false);
        form.reset();
        form.clearErrors();
        onClose?.();
    };

    const openModal = () => {
        setShow(true);
        form.reset();
        form.clearErrors();
    };

    const submit = (e) => {
        e.preventDefault();
        form.post(route('workspaces.store'), {
            preserveScroll: true,
            onSuccess: () => {
                setShow(false);
                form.reset();
                form.clearErrors();
            },
        });
    };

    return (
        <>
            {showTrigger ? (
                <PrimaryButton
                    type="button"
                    onClick={openModal}
                    className={triggerClassName || undefined}
                >
                    {buttonLabel}
                </PrimaryButton>
            ) : null}

            <Modal show={show} onClose={close} maxWidth="md" closeable={!form.processing}>
                <form onSubmit={submit}>
                    <div className="relative overflow-hidden border-b border-line/70 bg-gradient-to-br from-signal-soft/80 via-white to-mist px-5 pb-5 pt-5 sm:px-6">
                        <div
                            className="pointer-events-none absolute -right-8 -top-10 h-32 w-32 rounded-full bg-signal/10 blur-2xl"
                            aria-hidden
                        />
                        <div
                            className="pointer-events-none absolute -bottom-10 left-8 h-24 w-24 rounded-full bg-ink/5 blur-2xl"
                            aria-hidden
                        />

                        <div className="relative flex items-start gap-3">
                            <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-white shadow-sm ring-1 ring-line">
                                {form.processing ? (
                                    <span className="inline-flex h-5 w-5 animate-spin rounded-full border-2 border-signal border-t-transparent" />
                                ) : (
                                    <GlobeIcon className="h-5 w-5 text-signal-strong" />
                                )}
                            </div>
                            <div className="min-w-0 flex-1">
                                <div className="text-[10px] font-bold uppercase tracking-[0.18em] text-signal-strong">
                                    New workspace
                                </div>
                                <h3 className="mt-1 font-display text-xl font-bold tracking-tight text-ink sm:text-2xl">
                                    {form.processing
                                        ? 'Auditing your site…'
                                        : 'Launch from your domain'}
                                </h3>
                                <p className="mt-1.5 text-sm leading-relaxed text-ink-muted">
                                    {form.processing
                                        ? `Crawling ${form.data.domain || 'your site'} and checking SEO issues. Keep this open.`
                                        : 'One domain, one workspace. We create it and run the first SEO audit right away.'}
                                </p>
                            </div>
                            {!form.processing ? (
                                <button
                                    type="button"
                                    onClick={close}
                                    className="rounded-md p-1.5 text-ink-muted transition hover:bg-white/80 hover:text-ink"
                                    aria-label="Close"
                                >
                                    <svg className="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden>
                                        <path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z" />
                                    </svg>
                                </button>
                            ) : null}
                        </div>

                        <div className="relative mt-5 flex flex-wrap items-center gap-x-4 gap-y-2 rounded-lg border border-white/70 bg-white/60 px-3 py-2.5 backdrop-blur-sm">
                            <Step n={1} label="Domain" active={!form.processing} done={form.processing} />
                            <span className="hidden h-px w-6 bg-line sm:block" aria-hidden />
                            <Step n={2} label="SEO audit" active={form.processing} done={false} />
                            <span className="hidden h-px w-6 bg-line sm:block" aria-hidden />
                            <Step n={3} label="Ready" active={false} done={false} />
                        </div>
                    </div>

                    <div className="px-5 py-5 sm:px-6">
                        {form.processing ? (
                            <div className="rounded-lg border border-signal/25 bg-gradient-to-r from-signal-soft/50 to-white px-4 py-5">
                                <div className="flex items-start gap-3">
                                    <span className="mt-0.5 inline-flex h-5 w-5 animate-spin rounded-full border-2 border-signal border-t-transparent" />
                                    <div className="min-w-0">
                                        <div className="text-sm font-semibold text-ink">
                                            Live crawl in progress
                                        </div>
                                        <div className="mt-1 text-xs leading-relaxed text-ink-muted">
                                            Fetching pages, titles, and technical checks for{' '}
                                            <span className="font-semibold text-ink">
                                                {form.data.domain || 'your domain'}
                                            </span>
                                            . Larger sites take longer.
                                        </div>
                                        <div className="mt-3 h-1.5 overflow-hidden rounded-full bg-mist">
                                            <div className="h-full w-2/3 animate-pulse rounded-full bg-signal" />
                                        </div>
                                    </div>
                                </div>
                            </div>
                        ) : (
                            <div>
                                <InputLabel htmlFor="create-workspace-domain" value="Website domain" />
                                <div className="relative mt-1.5">
                                    <span className="pointer-events-none absolute inset-y-0 left-3 flex items-center text-ink-muted">
                                        <GlobeIcon className="h-4 w-4" />
                                    </span>
                                    <TextInput
                                        id="create-workspace-domain"
                                        className="block w-full !pl-10"
                                        value={form.data.domain}
                                        onChange={(e) => form.setData('domain', e.target.value)}
                                        placeholder="example.com"
                                        required
                                        autoComplete="url"
                                        isFocused={show}
                                    />
                                </div>
                                <p className="mt-2 text-xs text-ink-muted">
                                    Paste a full URL or just the domain — we normalize it.
                                </p>
                                <InputError className="mt-2" message={form.errors.domain} />
                            </div>
                        )}

                        <div className="mt-5 flex flex-wrap items-center justify-end gap-2">
                            {!form.processing ? (
                                <button
                                    type="button"
                                    onClick={close}
                                    className="rounded-md border border-line bg-white px-3 py-2 text-sm font-semibold text-ink transition hover:border-signal/40"
                                >
                                    Cancel
                                </button>
                            ) : null}
                            <PrimaryButton processing={form.processing} disabled={form.processing}>
                                {form.processing ? 'Auditing…' : 'Create & audit'}
                            </PrimaryButton>
                        </div>
                    </div>
                </form>
            </Modal>
        </>
    );
}
