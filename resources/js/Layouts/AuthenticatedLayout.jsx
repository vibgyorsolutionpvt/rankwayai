import ApplicationLogo from '@/Components/ApplicationLogo';
import BrandName from '@/Components/BrandName';
import Dropdown from '@/Components/Dropdown';
import { AppFeedback } from '@/Components/ToastProvider';
import { Link, router, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';

function NavIcon({ name, className = 'h-4 w-4' }) {
    const common = {
        fill: 'none',
        stroke: 'currentColor',
        strokeWidth: 1.8,
        strokeLinecap: 'round',
        strokeLinejoin: 'round',
        viewBox: '0 0 24 24',
        className,
        'aria-hidden': true,
    };

    switch (name) {
        case 'today':
            return (
                <svg {...common}>
                    <rect x="3" y="5" width="18" height="16" rx="2" />
                    <path d="M8 3v4M16 3v4M3 11h18" />
                </svg>
            );
        case 'brand':
            return (
                <svg {...common}>
                    <circle cx="12" cy="12" r="8" />
                    <circle cx="12" cy="12" r="3" />
                </svg>
            );
        case 'media':
            return (
                <svg {...common}>
                    <rect x="3" y="5" width="18" height="14" rx="2" />
                    <path d="m3 15 5-4 4 3 3-2 6 4" />
                    <circle cx="9" cy="9" r="1.5" fill="currentColor" stroke="none" />
                </svg>
            );
        case 'social':
            return (
                <svg {...common}>
                    <circle cx="18" cy="6" r="2.5" />
                    <circle cx="6" cy="12" r="2.5" />
                    <circle cx="18" cy="18" r="2.5" />
                    <path d="m8.2 10.8 5.6-3.6M8.2 13.2l5.6 3.6" />
                </svg>
            );
        case 'seo':
            return (
                <svg {...common}>
                    <circle cx="11" cy="11" r="7" />
                    <path d="M20 20l-3.5-3.5" />
                </svg>
            );
        case 'blog':
            return (
                <svg {...common}>
                    <path d="M5 4h14v16H5z" />
                    <path d="M8 8h8M8 12h8M8 16h5" />
                </svg>
            );
        case 'workspace':
            return (
                <svg {...common}>
                    <path d="M4 7h16v12H4z" />
                    <path d="M9 7V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2" />
                </svg>
            );
        case 'platform':
            return (
                <svg {...common}>
                    <path d="M4 10h16v9H4z" />
                    <path d="M8 10V7a4 4 0 0 1 8 0v3" />
                </svg>
            );
        default:
            return (
                <svg {...common}>
                    <circle cx="12" cy="12" r="8" />
                </svg>
            );
    }
}

const toneStyles = {
    amber: {
        idle: 'text-amber-700 bg-amber-100',
        active: 'bg-amber-500 text-white',
        row: 'hover:bg-amber-50',
        activeRow: 'bg-amber-500 text-white shadow-sm shadow-amber-500/25',
    },
    rose: {
        idle: 'text-rose-700 bg-rose-100',
        active: 'bg-rose-500 text-white',
        row: 'hover:bg-rose-50',
        activeRow: 'bg-rose-500 text-white shadow-sm shadow-rose-500/25',
    },
    sky: {
        idle: 'text-sky-700 bg-sky-100',
        active: 'bg-sky-500 text-white',
        row: 'hover:bg-sky-50',
        activeRow: 'bg-sky-500 text-white shadow-sm shadow-sky-500/25',
    },
    fuchsia: {
        idle: 'text-fuchsia-700 bg-fuchsia-100',
        active: 'bg-fuchsia-500 text-white',
        row: 'hover:bg-fuchsia-50',
        activeRow: 'bg-fuchsia-500 text-white shadow-sm shadow-fuchsia-500/25',
    },
    emerald: {
        idle: 'text-emerald-700 bg-emerald-100',
        active: 'bg-emerald-500 text-white',
        row: 'hover:bg-emerald-50',
        activeRow: 'bg-emerald-500 text-white shadow-sm shadow-emerald-500/25',
    },
    signal: {
        idle: 'text-signal-strong bg-signal-soft',
        active: 'bg-signal text-white',
        row: 'hover:bg-signal-soft/70',
        activeRow: 'bg-signal text-white shadow-sm shadow-signal/30',
    },
    ink: {
        idle: 'text-ink bg-mist-deep',
        active: 'bg-ink text-white',
        row: 'hover:bg-mist',
        activeRow: 'bg-ink text-white shadow-sm shadow-ink/20',
    },
};

function NavLink({ item, onNavigate }) {
    const active = route().current(item.match);
    const tone = toneStyles[item.tone] || toneStyles.signal;
    const locked = Boolean(item.locked);

    return (
        <Link
            href={route(item.routeName)}
            onClick={onNavigate}
            title={locked ? 'Paid plan required' : undefined}
            className={
                'group flex items-center gap-2.5 rounded-md px-2 py-2 text-sm font-semibold transition duration-150 ' +
                (active
                    ? tone.activeRow
                    : locked
                      ? 'text-ink-muted/70 hover:bg-mist hover:text-ink-muted'
                      : `text-ink-muted ${tone.row} hover:text-ink`)
            }
        >
            <span
                className={
                    'inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-md transition ' +
                    (active ? 'bg-white/20 text-white' : locked ? 'bg-mist text-ink-muted' : tone.idle)
                }
            >
                <NavIcon name={item.icon} className="h-[15px] w-[15px]" />
            </span>
            <span className="min-w-0 flex-1 truncate">{item.label}</span>
            {locked ? (
                <span className="shrink-0 text-[10px] font-bold uppercase tracking-wide text-ink-muted">
                    Pro
                </span>
            ) : null}
        </Link>
    );
}

export default function AuthenticatedLayout({ header, children }) {
    const page = usePage().props;
    const user = page.auth.user;
    const workspaces = page.workspaces || [];
    const activeWorkspace = page.activeWorkspace || null;
    const plan = page.plan || null;
    const [mobileOpen, setMobileOpen] = useState(false);

    const navItems = useMemo(() => {
        const allowed = plan?.modules || null;
        const markLocked = (item) => {
            const unlocked = plan.unlocked ?? plan.paid;
            const locked =
                Boolean(plan) &&
                unlocked === false &&
                Array.isArray(allowed) &&
                !allowed.includes(item.key || item.route);
            return { ...item, locked };
        };

        const shared = page.navigation || [];
        if (shared.length > 0) {
            return shared.map((item) =>
                markLocked({
                    key: item.key,
                    label: item.label,
                    routeName: item.route,
                    match: item.match,
                    icon: item.icon,
                    tone: item.tone,
                }),
            );
        }

        return [];
    }, [page.navigation, plan, user?.is_superadmin]);

    const homeHref = navItems[0] ? route(navItems[0].routeName) : route('profile.edit');
    const impersonating = Boolean(page.impersonating);

    return (
        <div className="min-h-screen lg:grid lg:grid-cols-[220px_1fr]">
            <AppFeedback />
            <aside className="sticky top-0 z-30 hidden h-svh self-start overflow-y-auto border-r border-line bg-gradient-to-b from-white via-white to-signal-soft/30 lg:flex lg:flex-col">
                <div className="flex min-h-full flex-col px-2.5 py-4">
                    <Link href={homeHref} className="flex items-center gap-2 px-1.5">
                        <ApplicationLogo className="h-8 w-8 shrink-0" />
                        <div className="min-w-0">
                            <BrandName className="text-base leading-none text-ink" />
                            <div className="mt-1 truncate text-[10px] font-medium tracking-wide text-ink-muted">
                                Rank · Reach · Convert
                            </div>
                        </div>
                    </Link>

                    <nav className="mt-6 space-y-1">
                        {navItems.map((item) => (
                            <NavLink key={item.routeName} item={item} />
                        ))}
                    </nav>

                    <div className="mt-auto rounded-md border border-line bg-white/80 p-2.5">
                        <div className="text-[10px] font-semibold uppercase tracking-[0.14em] text-ink-muted">
                            {user?.is_superadmin ? (impersonating ? 'Viewing as' : 'Admin') : 'Signed in'}
                        </div>
                        <div className="mt-1 truncate text-sm font-semibold text-ink">{user.name}</div>
                        <div className="truncate text-xs text-ink-muted">{user.email}</div>
                        {impersonating ? (
                            <button
                                type="button"
                                onClick={() => router.post(route('admin.leave-workspace'))}
                                className="mt-2 w-full rounded-md bg-ink px-2 py-1.5 text-xs font-semibold text-white"
                            >
                                Exit workspace
                            </button>
                        ) : null}
                    </div>
                </div>
            </aside>

            <div className="flex min-h-screen flex-col">
                {impersonating ? (
                    <div className="flex items-center justify-between gap-3 bg-ink px-4 py-2 text-xs font-semibold text-white sm:px-6">
                        <span>
                            Super admin view · {activeWorkspace?.name || 'Workspace'}
                        </span>
                        <button
                            type="button"
                            onClick={() => router.post(route('admin.leave-workspace'))}
                            className="rounded-md bg-white/15 px-2.5 py-1 transition hover:bg-white/25"
                        >
                            Back to admin
                        </button>
                    </div>
                ) : null}
                <header className="sticky top-0 z-20 border-b border-line/70 bg-white/85 backdrop-blur-md">
                    <div className="flex h-14 items-center justify-between gap-3 px-4 sm:px-6">
                        <div className="flex min-w-0 items-center gap-2 lg:hidden">
                            <button
                                type="button"
                                onClick={() => setMobileOpen((v) => !v)}
                                className="shrink-0 rounded-md border border-line px-2.5 py-1.5 text-sm font-semibold text-ink"
                            >
                                Menu
                            </button>
                            <Link href={homeHref} className="flex min-w-0 items-center gap-2">
                                <ApplicationLogo className="h-7 w-7 shrink-0" />
                                <BrandName className="truncate text-base text-ink" />
                            </Link>
                        </div>

                        <div className="hidden min-w-0 flex-1 overflow-hidden lg:block">{header}</div>

                        <div className="ml-auto flex shrink-0 items-center gap-2">
                            {!user?.is_superadmin && workspaces.length > 0 ? (
                                <Dropdown>
                                    <Dropdown.Trigger>
                                        <button
                                            type="button"
                                            className="inline-flex max-w-[10rem] items-center gap-1.5 rounded-md border border-line bg-white px-2.5 py-1.5 text-sm font-semibold text-ink transition hover:border-signal/40 sm:max-w-[14rem]"
                                            title="Switch workspace"
                                        >
                                            <span className="truncate">
                                                {activeWorkspace?.name || 'Workspace'}
                                            </span>
                                            <svg
                                                className="h-3.5 w-3.5 shrink-0 text-ink-muted"
                                                viewBox="0 0 20 20"
                                                fill="currentColor"
                                                aria-hidden
                                            >
                                                <path
                                                    fillRule="evenodd"
                                                    d="M5.23 7.21a.75.75 0 011.06.02L10 11.17l3.71-3.94a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z"
                                                    clipRule="evenodd"
                                                />
                                            </svg>
                                        </button>
                                    </Dropdown.Trigger>
                                    <Dropdown.Content width="48" contentClasses="py-1 bg-white max-h-72 overflow-y-auto">
                                        <div className="border-b border-line px-3 py-1.5 text-[10px] font-semibold uppercase tracking-wide text-ink-muted">
                                            Workspaces
                                        </div>
                                        {workspaces.map((ws) => {
                                            const active = activeWorkspace?.id === ws.id;
                                            return (
                                                <button
                                                    key={ws.id}
                                                    type="button"
                                                    disabled={active}
                                                    onClick={() =>
                                                        router.post(
                                                            route('workspaces.switch', ws.id),
                                                            { redirect: 'back' },
                                                            { preserveScroll: true },
                                                        )
                                                    }
                                                    className={
                                                        'flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-sm transition ' +
                                                        (active
                                                            ? 'bg-signal-soft/60 font-semibold text-ink'
                                                            : 'text-ink hover:bg-mist')
                                                    }
                                                >
                                                    <span className="truncate">{ws.name}</span>
                                                    {active ? (
                                                        <span className="shrink-0 text-[10px] font-bold uppercase text-signal-strong">
                                                            Active
                                                        </span>
                                                    ) : null}
                                                </button>
                                            );
                                        })}
                                        <div className="border-t border-line px-1 py-1">
                                            <Dropdown.Link href={route('settings.index', { tab: 'workspace' })}>
                                                Manage workspaces…
                                            </Dropdown.Link>
                                        </div>
                                    </Dropdown.Content>
                                </Dropdown>
                            ) : null}

                            <Dropdown>
                                <Dropdown.Trigger>
                                    <button
                                        type="button"
                                        className="inline-flex items-center gap-2 rounded-md border border-line bg-white px-2.5 py-1.5 text-sm font-semibold text-ink transition hover:border-signal/40"
                                    >
                                        <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-signal-soft text-xs font-bold text-signal-strong">
                                            {(user?.name || '?').charAt(0).toUpperCase()}
                                        </span>
                                        <span className="hidden max-w-[9rem] truncate sm:inline">
                                            {user?.name || 'Account'}
                                        </span>
                                    </button>
                                </Dropdown.Trigger>
                                <Dropdown.Content>
                                    <Dropdown.Link href={route('profile.edit')}>Profile</Dropdown.Link>
                                    {!user?.is_superadmin ? (
                                        <Dropdown.Link href={route('settings.index', { tab: 'workspace' })}>
                                            Settings
                                        </Dropdown.Link>
                                    ) : null}
                                    <Dropdown.Link href={route('logout')} method="post" as="button">
                                        Log Out
                                    </Dropdown.Link>
                                </Dropdown.Content>
                            </Dropdown>
                        </div>
                    </div>

                    {mobileOpen ? (
                        <div className="space-y-1 border-t border-line bg-white px-2 py-2 lg:hidden">
                            {navItems.map((item) => (
                                <NavLink
                                    key={item.routeName}
                                    item={item}
                                    onNavigate={() => setMobileOpen(false)}
                                />
                            ))}
                        </div>
                    ) : null}
                </header>

                <div className="border-b border-line/60 bg-white/60 px-4 py-3 lg:hidden">{header}</div>
                <main className="flex-1 animate-fade-in">{children}</main>
            </div>
        </div>
    );
}
