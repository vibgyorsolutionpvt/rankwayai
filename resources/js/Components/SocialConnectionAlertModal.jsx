import Modal from '@/Components/Modal';
import SecondaryButton from '@/Components/SecondaryButton';
import { router, usePage } from '@inertiajs/react';
import axios from 'axios';
import { useEffect, useRef, useState } from 'react';

const platformLabels = {
    facebook: 'Facebook',
    instagram: 'Instagram',
    threads: 'Threads',
    linkedin: 'LinkedIn',
    x: 'X (Twitter)',
};

const platformBadgeStyles = {
    facebook: 'bg-blue-100 text-blue-800 border-blue-200',
    instagram: 'bg-fuchsia-100 text-fuchsia-800 border-fuchsia-200',
    threads: 'bg-zinc-900 text-white border-zinc-800',
    linkedin: 'bg-sky-100 text-sky-800 border-sky-200',
    x: 'bg-zinc-200 text-zinc-800 border-zinc-300',
};

function PlatformIcon({ platform, className = 'h-4 w-4' }) {
    switch (platform) {
        case 'facebook':
            return (
                <svg className={className} viewBox="0 0 24 24" fill="currentColor">
                    <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z" />
                </svg>
            );
        case 'instagram':
            return (
                <svg className={className} viewBox="0 0 24 24" fill="currentColor">
                    <path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z" />
                </svg>
            );
        case 'threads':
            return (
                <svg className={className} viewBox="0 0 24 24" fill="currentColor">
                    <path d="M12.186 24h-.007c-3.581-.024-6.335-1.198-8.186-3.487C2.238 18.315 1.446 15.226 1.64 11.33 2.052 3.12 8.761 0 12.186 0c3.963 0 7.426 1.892 9.5 5.19 1.764 2.805 2.11 6.54.974 10.518-.174.606-.798.958-1.405.783-.605-.173-.956-.797-.783-1.404.975-3.414.673-6.602-.851-9.027C17.929 3.25 15.143 1.8 12.186 1.8 9.5 1.8 4.025 4.307 3.65 11.434c-.172 3.468.51 6.19 2.029 8.089 1.493 1.868 3.738 2.825 6.674 2.846 2.502.018 4.793-.728 6.626-2.158 1.954-1.524 3.023-3.67 3.007-6.046-.026-3.805-2.73-6.047-6.745-6.047-3.921 0-6.193 2.544-6.193 6.096 0 3.328 1.93 5.485 4.685 5.485 1.574 0 2.923-.74 3.69-2.03.355-.597 1.13-.794 1.727-.439.597.355.794 1.13.439 1.727-1.196 2.01-3.284 3.14-5.856 3.14-4.004 0-6.885-3.04-6.885-7.883 0-5.068 3.355-8.494 8.393-8.494 5.378 0 8.945 3.328 8.945 8.444.021 3.018-1.343 5.753-3.844 7.7-2.316 1.805-5.202 2.748-8.35 2.727z" />
                </svg>
            );
        case 'linkedin':
            return (
                <svg className={className} viewBox="0 0 24 24" fill="currentColor">
                    <path d="M19 0h-14c-2.761 0-5 2.239-5 5v14c0 2.761 2.239 5 5 5h14c2.762 0 5-2.239 5-5v-14c0-2.761-2.238-5-5-5zm-11 19h-3v-11h3v11zm-1.5-12.268c-.966 0-1.75-.79-1.75-1.764s.784-1.764 1.75-1.764 1.75.79 1.75 1.764-.783 1.764-1.75 1.764zm13.5 12.268h-3v-5.604c0-3.368-4-3.113-4 0v5.604h-3v-11h3v1.765c1.396-2.586 7-2.777 7 2.476v6.759z" />
                </svg>
            );
        case 'x':
            return (
                <svg className={className} viewBox="0 0 24 24" fill="currentColor">
                    <path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z" />
                </svg>
            );
        default:
            return (
                <svg className={className} viewBox="0 0 20 20" fill="currentColor">
                    <path fillRule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clipRule="evenodd" />
                </svg>
            );
    }
}

function safeRoute(name, params, fallback) {
    try {
        if (typeof route === 'function') {
            return params ? route(name, params) : route(name);
        }
    } catch {
        // fall through
    }
    return fallback;
}

function isDashboardPage() {
    if (typeof window === 'undefined') return false;
    const pathname = (window.location.pathname || '').replace(/\/+$/, '') || '/';
    if (pathname === '/today' || pathname === '/admin' || pathname === '/dashboard' || pathname === '/home') {
        return true;
    }
    try {
        if (typeof route === 'function') {
            if (
                route().current('today') ||
                route().current('admin.dashboard') ||
                route().current('dashboard') ||
                route().current('home')
            ) {
                return true;
            }
        }
    } catch {
        // fall through
    }
    return false;
}

export default function SocialConnectionAlertModal() {
    const {
        check_social_on_login,
        check_social_on_workspace_switch,
        activeWorkspace,
    } = usePage().props;
    const [isOpen, setIsOpen] = useState(false);
    const [failedAccounts, setFailedAccounts] = useState([]);
    const [isChecking, setIsChecking] = useState(false);
    const lastWorkspaceIdRef = useRef(null);
    const hasInitialFiredRef = useRef(false);

    const onDashboard = isDashboardPage();

    const performHealthCheck = async (quiet = false) => {
        setIsChecking(true);
        try {
            const url = safeRoute('social.accounts.health-check', null, '/social/accounts/health-check');
            const response = await axios.post(url);
            const data = response.data || {};

            if (data.has_issues && Array.isArray(data.failed_accounts) && data.failed_accounts.length > 0) {
                setFailedAccounts(data.failed_accounts);
                setIsOpen(true);
            } else {
                setFailedAccounts([]);
                setIsOpen(false);
            }
        } catch (err) {
            console.error('Failed to run social connection health check:', err);
        } finally {
            setIsChecking(false);
        }
    };

    useEffect(() => {
        const currentWorkspaceId = activeWorkspace?.id;
        if (!currentWorkspaceId) {
            return;
        }

        const params = new URLSearchParams(window.location.search);
        const forceCheck = params.has('check_social');

        const isWorkspaceSwitched =
            Boolean(check_social_on_workspace_switch) ||
            (lastWorkspaceIdRef.current !== null && lastWorkspaceIdRef.current !== currentWorkspaceId);

        const isInitialCheck =
            !hasInitialFiredRef.current && (Boolean(check_social_on_login) || onDashboard);

        if (isWorkspaceSwitched || isInitialCheck || forceCheck) {
            lastWorkspaceIdRef.current = currentWorkspaceId;
            hasInitialFiredRef.current = true;

            if (isWorkspaceSwitched) {
                setIsOpen(false);
                setFailedAccounts([]);
            }

            performHealthCheck();
        } else {
            lastWorkspaceIdRef.current = currentWorkspaceId;
        }
    }, [
        check_social_on_login,
        check_social_on_workspace_switch,
        activeWorkspace?.id,
        onDashboard,
    ]);

    if (!isOpen || failedAccounts.length === 0) {
        return null;
    }

    return (
        <Modal show={isOpen} onClose={() => setIsOpen(false)} maxWidth="lg">
            <div className="overflow-hidden rounded-2xl bg-white shadow-2xl">
                {/* Header Banner */}
                <div className="relative border-b border-rose-100 bg-gradient-to-br from-rose-50 via-white to-amber-50/50 p-5 sm:p-6">
                    <div className="flex items-start gap-4">
                        <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-rose-500/10 text-rose-600 ring-1 ring-rose-500/20">
                            <svg className="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                            </svg>
                        </div>
                        <div className="min-w-0 flex-1">
                            <div className="flex items-center gap-2">
                                <span className="inline-flex items-center rounded-full bg-rose-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-rose-700">
                                    Connection Alert
                                </span>
                                {activeWorkspace?.name ? (
                                    <span className="truncate text-xs font-medium text-ink-muted">
                                        {activeWorkspace.name}
                                    </span>
                                ) : null}
                            </div>
                            <h3 className="mt-1 font-display text-lg font-bold text-ink">
                                Social Media Account Reconnection Needed
                            </h3>
                            <p className="mt-1 text-xs text-ink-muted">
                                Connection test failed for {failedAccounts.length} account{failedAccounts.length > 1 ? 's' : ''}. Posts scheduled for these platforms cannot be published until reconnected.
                            </p>
                        </div>
                    </div>
                </div>

                {/* Problem Accounts List */}
                <div className="max-h-[60vh] space-y-3 overflow-y-auto p-5 sm:p-6">
                    {failedAccounts.map((account) => (
                        <div
                            key={account.id}
                            className="rounded-xl border border-rose-200/80 bg-rose-50/40 p-4 transition-all"
                        >
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <div className="flex items-center gap-2.5">
                                    <span
                                        className={`inline-flex h-7 w-7 items-center justify-center rounded-lg border shadow-xs ${
                                            platformBadgeStyles[account.platform] || 'bg-mist text-ink'
                                        }`}
                                    >
                                        <PlatformIcon platform={account.platform} className="h-3.5 w-3.5" />
                                    </span>
                                    <div>
                                        <div className="font-semibold text-ink">
                                            {account.account_name}
                                        </div>
                                        <div className="text-[11px] capitalize text-ink-muted">
                                            {platformLabels[account.platform] || account.platform} · {account.account_type || 'page'}
                                        </div>
                                    </div>
                                </div>
                                <span className="inline-flex items-center gap-1 rounded-md bg-rose-100 px-2 py-0.5 text-[10px] font-bold uppercase text-rose-700">
                                    <span className="h-1.5 w-1.5 rounded-full bg-rose-500 animate-pulse" />
                                    {account.status === 'disconnected' ? 'Disconnected' : 'Failed'}
                                </span>
                            </div>

                            <div className="mt-2.5 rounded-lg border border-rose-200/70 bg-white/90 p-2.5 text-xs text-rose-800 shadow-xs">
                                <div className="flex items-start gap-2">
                                    <svg className="mt-0.5 h-3.5 w-3.5 shrink-0 text-rose-500" viewBox="0 0 20 20" fill="currentColor">
                                        <path fillRule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clipRule="evenodd" />
                                    </svg>
                                    <span className="leading-relaxed">
                                        {account.error_message || 'Access token invalidated or expired.'}
                                    </span>
                                </div>
                            </div>

                            <div className="mt-3 flex items-center justify-end gap-2">
                                {account.reconnect_url ? (
                                    <a
                                        href={account.reconnect_url}
                                        className="inline-flex items-center gap-1.5 rounded-lg border border-signal bg-signal px-3.5 py-1.5 text-xs font-semibold text-white shadow-xs transition hover:bg-signal-strong active:scale-98"
                                    >
                                        <svg className="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor">
                                            <path fillRule="evenodd" d="M15.312 11.424a5.5 5.5 0 01-9.201 2.466l-.312-.311h2.433a.75.75 0 000-1.5H3.75a.75.75 0 00-.75.75v4.482a.75.75 0 001.5 0v-2.227l.385.385a7 7 0 0011.83-3.218.75.75 0 00-1.403-.427zM4.688 8.576a5.5 5.5 0 019.201-2.466l.312.311H11.77a.75.75 0 000 1.5h4.482a.75.75 0 00.75-.75V2.689a.75.75 0 00-1.5 0v2.227l-.385-.385a7 7 0 00-11.83 3.218.75.75 0 001.403.427z" clipRule="evenodd" />
                                        </svg>
                                        Reconnect {platformLabels[account.platform] || account.platform}
                                    </a>
                                ) : (
                                <button
                                    type="button"
                                    onClick={() => {
                                        setIsOpen(false);
                                        router.visit(safeRoute('social.index', { view: 'accounts' }, '/social?view=accounts'));
                                    }}
                                    className="inline-flex items-center gap-1.5 rounded-lg border border-line bg-white px-3 py-1.5 text-xs font-semibold text-ink shadow-xs hover:border-signal/50"
                                >
                                    Manage in Accounts tab
                                </button>
                                )}
                            </div>
                        </div>
                    ))}
                </div>

                {/* Footer Controls */}
                <div className="flex flex-wrap items-center justify-between gap-3 border-t border-line/70 bg-mist/30 px-5 py-3.5 sm:px-6">
                    <button
                        type="button"
                        disabled={isChecking}
                        onClick={() => performHealthCheck()}
                        className="inline-flex items-center gap-1.5 rounded-lg border border-line bg-white px-3 py-1.5 text-xs font-semibold text-ink shadow-xs hover:border-signal/50 hover:bg-mist disabled:opacity-50"
                    >
                        {isChecking ? (
                            <>
                                <span className="h-3 w-3 animate-spin rounded-full border-2 border-signal border-t-transparent" />
                                Testing…
                            </>
                        ) : (
                            <>
                                <svg className="h-3.5 w-3.5 text-signal" viewBox="0 0 20 20" fill="currentColor">
                                    <path fillRule="evenodd" d="M4 2a1 1 0 011 1v2.101a7.002 7.002 0 0111.601 2.566 1 1 0 11-1.885.666A5.002 5.002 0 005.999 7H9a1 1 0 010 2H4a1 1 0 01-1-1V3a1 1 0 011-1zm.008 9.057a1 1 0 011.276.61A5.002 5.002 0 0014.001 13H11a1 1 0 110-2h5a1 1 0 011 1v5a1 1 0 11-2 0v-2.101a7.002 7.002 0 01-11.601-2.566 1 1 0 01.61-1.276z" clipRule="evenodd" />
                                </svg>
                                Re-test connection
                            </>
                        )}
                    </button>

                    <div className="flex items-center gap-2">
                        <button
                            type="button"
                            onClick={() => {
                                setIsOpen(false);
                                router.visit(safeRoute('social.index', { view: 'accounts' }, '/social?view=accounts'));
                            }}
                            className="text-xs font-semibold text-signal hover:underline"
                        >
                            Open Social Accounts →
                        </button>
                        <SecondaryButton
                            type="button"
                            onClick={() => setIsOpen(false)}
                            className="!py-1.5 !text-xs"
                        >
                            Dismiss
                        </SecondaryButton>
                    </div>
                </div>
            </div>
        </Modal>
    );
}
