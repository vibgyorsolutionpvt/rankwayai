import ApplicationLogo from '@/Components/ApplicationLogo';
import { router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

function visitPath(visit) {
    const raw = visit?.url;
    try {
        if (typeof raw === 'string') {
            return new URL(raw, window.location.origin).pathname + new URL(raw, window.location.origin).search;
        }
        if (raw?.pathname) {
            return `${raw.pathname}${raw.search || ''}`;
        }
    } catch {
        // fall through
    }
    return String(raw || '');
}

function moduleHintFromPath(path) {
    const pathname = path.split('?')[0] || path;
    const map = [
        ['/seo', 'SEO'],
        ['/social', 'SMM'],
        ['/blog', 'Blog'],
        ['/media', 'Media'],
        ['/brand', 'Brand'],
        ['/channels', 'Channels'],
        ['/whatsapp', 'WhatsApp'],
        ['/crm', 'CRM'],
        ['/funnels', 'Funnels'],
        ['/billing', 'Billing'],
        ['/settings', 'Settings'],
        ['/today', 'Today'],
        ['/workspaces', 'Workspaces'],
        ['/admin', 'Admin'],
    ];

    for (const [prefix, label] of map) {
        if (pathname === prefix || pathname.startsWith(`${prefix}/`)) {
            return label;
        }
    }

    return null;
}

function tabHintFromPath(path) {
    try {
        const q = path.includes('?') ? path.slice(path.indexOf('?')) : '';
        const tab = new URLSearchParams(q).get('tab');
        if (!tab) {
            return null;
        }
        return tab.replace(/[-_]/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
    } catch {
        return null;
    }
}

function switchWorkspaceId(path) {
    const match = path.match(/\/workspaces\/(\d+)\/switch/);
    return match ? Number(match[1]) : null;
}

/**
 * Custom overlay for navigation / switch / sync / heavy actions.
 * Compact "Saving…" pill only for real save mutations.
 */
function classifyVisit(visit, workspaces = []) {
    const method = (visit?.method || 'get').toLowerCase();
    const path = visitPath(visit);
    const moduleLabel = moduleHintFromPath(path);
    const tabLabel = tabHintFromPath(path);

    const switchId = switchWorkspaceId(path);
    if (switchId) {
        const target = workspaces.find((w) => Number(w.id) === switchId);
        return {
            mode: 'nav',
            eyebrow: 'Switching workspace',
            workspaceName: target?.name || null,
            subtitle: 'Loading shared brand data…',
        };
    }

    const isHeavyAction =
        /\/(sync|crawl|pagespeed|rankway|research|backlinks|oauth|compose|scan)/i.test(path) ||
        /gsc\/sync|analytics\.sync|sites\.crawl|sites\.pagespeed/i.test(path);

    if (method === 'get') {
        let eyebrow = 'Loading';
        if (moduleLabel && tabLabel) {
            eyebrow = `Opening ${moduleLabel} · ${tabLabel}`;
        } else if (moduleLabel) {
            eyebrow = `Opening ${moduleLabel}`;
        } else if (tabLabel) {
            eyebrow = `Opening ${tabLabel}`;
        }
        return {
            mode: 'nav',
            eyebrow,
            workspaceName: null,
            subtitle: 'Preparing your workspace…',
        };
    }

    if (isHeavyAction) {
        let eyebrow = 'Syncing';
        if (/pagespeed|speed/i.test(path)) {
            eyebrow = 'Running speed check';
        } else if (/crawl/i.test(path)) {
            eyebrow = 'Scanning site';
        } else if (/rankway/i.test(path)) {
            eyebrow = 'Checking rank';
        } else if (/research/i.test(path)) {
            eyebrow = 'Researching keywords';
        } else if (/gsc|analytics/i.test(path)) {
            eyebrow = 'Syncing data';
        }
        return {
            mode: 'nav',
            eyebrow,
            workspaceName: null,
            subtitle: moduleLabel ? `${moduleLabel} · please wait` : 'This may take a moment…',
        };
    }

    // Real saves: PUT / PATCH / DELETE, and ordinary POSTs (store/update/invite…).
    if (['put', 'patch', 'delete'].includes(method) || method === 'post') {
        return { mode: 'save' };
    }

    return {
        mode: 'nav',
        eyebrow: 'Loading',
        workspaceName: null,
        subtitle: 'Preparing your workspace…',
    };
}

export default function WorkspaceNavLoader() {
    const page = usePage().props;
    const activeWorkspace = page.activeWorkspace;
    const workspaces = page.workspaces || [];

    const [active, setActive] = useState(false);
    const [mode, setMode] = useState('nav');
    const [eyebrow, setEyebrow] = useState('Loading');
    const [subtitle, setSubtitle] = useState('Preparing your workspace…');
    const [displayName, setDisplayName] = useState(activeWorkspace?.name || 'Workspace');
    const timer = useRef(null);

    useEffect(() => {
        const onStart = (event) => {
            const visit = event?.detail?.visit;
            const classified = classifyVisit(visit, workspaces);
            clearTimeout(timer.current);

            const delay = classified.mode === 'save' ? 100 : 80;
            timer.current = setTimeout(() => {
                setMode(classified.mode);
                if (classified.mode === 'nav') {
                    setEyebrow(classified.eyebrow || 'Loading');
                    setSubtitle(classified.subtitle || 'Preparing your workspace…');
                    setDisplayName(
                        classified.workspaceName || activeWorkspace?.name || 'Workspace',
                    );
                }
                setActive(true);
            }, delay);
        };

        const stop = () => {
            clearTimeout(timer.current);
            setActive(false);
        };

        const offStart = router.on('start', onStart);
        const offFinish = router.on('finish', stop);
        const offError = router.on('error', stop);
        const offCancel = router.on('cancel', stop);

        return () => {
            clearTimeout(timer.current);
            offStart();
            offFinish();
            offError();
            offCancel();
        };
    }, [activeWorkspace?.name, workspaces]);

    if (!active) {
        return null;
    }

    if (mode === 'save') {
        return (
            <div
                className="pointer-events-none fixed inset-0 z-[200] flex items-start justify-center pt-20"
                aria-live="polite"
                aria-busy="true"
            >
                <div className="pointer-events-none absolute inset-0 bg-ink/10 backdrop-blur-[1px]" />
                <div className="relative flex items-center gap-2.5 rounded-full border border-line bg-white/95 px-4 py-2.5 text-sm font-semibold text-ink shadow-panel">
                    <span className="h-4 w-4 animate-spin rounded-full border-2 border-signal/25 border-t-signal" />
                    <span>Saving…</span>
                </div>
            </div>
        );
    }

    return (
        <div
            className="fixed inset-0 z-[200] flex items-center justify-center px-4"
            aria-live="polite"
            aria-busy="true"
            role="status"
        >
            <div className="absolute inset-0 bg-mist/80 backdrop-blur-md" />
            <div className="absolute inset-0 bg-[radial-gradient(ellipse_at_center,rgba(14,159,144,0.16),transparent_55%)]" />

            <div className="relative w-full max-w-sm animate-fade-up overflow-hidden rounded-2xl border border-line/80 bg-white/95 p-8 text-center shadow-panel">
                <div className="pointer-events-none absolute inset-x-0 top-0 h-1 overflow-hidden bg-mist-deep">
                    <div className="h-full w-1/2 animate-[nav-loader-bar_1.1s_ease-in-out_infinite] rounded-full bg-gradient-to-r from-signal/40 via-signal to-signal/40" />
                </div>

                <div className="mx-auto flex h-14 w-14 items-center justify-center">
                    <div className="absolute h-14 w-14 animate-[nav-loader-ring_1.4s_ease-in-out_infinite] rounded-full border-2 border-signal/20 border-t-signal" />
                    <ApplicationLogo className="relative h-9 w-9 rounded-lg shadow-sm" />
                </div>

                <p className="mt-6 text-[10px] font-semibold uppercase tracking-[0.22em] text-signal-strong">
                    {eyebrow}
                </p>
                <h2 className="mt-2 font-display text-2xl font-bold tracking-tight text-ink sm:text-3xl">
                    {displayName}
                </h2>
                <p className="mt-2 text-sm text-ink-muted">{subtitle}</p>

                <div className="mt-6 flex items-center justify-center gap-1.5">
                    {[0, 1, 2].map((i) => (
                        <span
                            key={i}
                            className="h-1.5 w-1.5 rounded-full bg-signal"
                            style={{
                                animation: 'nav-loader-dot 1s ease-in-out infinite',
                                animationDelay: `${i * 0.16}s`,
                            }}
                        />
                    ))}
                </div>
            </div>
        </div>
    );
}
