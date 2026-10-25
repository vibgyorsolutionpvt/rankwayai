import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Toggle from '@/Components/Toggle';
import { moduleTone, toneForModule } from '@/Components/moduleTones';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

export default function Dashboard({
    stats,
    menus = [],
    menuGroups = [],
    socialPlatforms = [],
    recentUsers = [],
    recentWorkspaces = [],
}) {
    const [expandedMenuGroup, setExpandedMenuGroup] = useState(null);

    return (
        <AuthenticatedLayout
            header={
                <div>
                    <div className="text-xs font-semibold uppercase tracking-[0.18em] text-ink-muted">
                        Super admin
                    </div>
                    <h2 className="font-display text-2xl font-bold tracking-tight text-ink">
                        Platform overview
                    </h2>
                </div>
            }
        >
            <Head title="Admin" />

            <div className="atlas-shell space-y-6 stagger">
                <section className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    {[
                        { label: 'Total users', value: stats.users, href: route('admin.users') },
                        { label: 'Clients', value: stats.clients, href: route('admin.users') },
                        {
                            label: 'Workspaces',
                            value: stats.workspaces,
                            href: route('admin.workspaces'),
                        },
                        { label: 'Admins', value: stats.superadmins, href: route('admin.users') },
                    ].map((stat) => (
                        <Link
                            key={stat.label}
                            href={stat.href}
                            className="atlas-panel block p-5 transition hover:-translate-y-0.5 hover:border-signal/40"
                        >
                            <div className="text-xs font-semibold uppercase tracking-[0.16em] text-ink-muted">
                                {stat.label}
                            </div>
                            <div className="mt-3 font-display text-4xl font-bold text-ink">
                                {stat.value}
                            </div>
                        </Link>
                    ))}
                </section>

                <section className="atlas-panel overflow-hidden">
                    <div className="border-b border-line/70 px-6 py-5">
                        <h3 className="font-display text-lg font-bold text-ink">Client menus</h3>
                        <p className="mt-1 text-sm text-ink-muted">
                            Turn a section on to manage its submenus. Disabling a submenu hides only
                            that feature for all workspaces.
                        </p>
                    </div>
                    <div className="grid gap-3 p-4 lg:grid-cols-2">
                        {menuGroups.map((group) => {
                            const tone = moduleTone(group.tone || toneForModule(group));
                            return (
                                <section
                                    key={group.key}
                                    className={
                                        'overflow-hidden rounded-lg border shadow-sm ' +
                                        (group.enabled ? tone.card : tone.off)
                                    }
                                >
                                    <div className="flex items-center gap-3 px-3 py-2.5">
                                        <button
                                            type="button"
                                            aria-expanded={expandedMenuGroup === group.key}
                                            disabled={!group.enabled}
                                            onClick={() =>
                                                setExpandedMenuGroup((current) =>
                                                    current === group.key ? null : group.key,
                                                )
                                            }
                                            className="flex min-w-0 flex-1 items-center gap-2 text-left disabled:cursor-default"
                                        >
                                            <span
                                                className={
                                                    'rounded px-2 py-1 text-xs font-bold uppercase tracking-wide ' +
                                                    tone.chip
                                                }
                                            >
                                                {group.label}
                                            </span>
                                            {group.enabled ? (
                                                <>
                                                    <span className="text-xs text-ink-muted">
                                                        {group.children.length} submenus
                                                    </span>
                                                </>
                                            ) : null}
                                        </button>
                                        <div className="flex shrink-0 flex-col items-center gap-0.5">
                                            <Toggle
                                                checked={group.enabled}
                                                onChange={(enabled) =>
                                                    router.patch(
                                                        route('admin.menus.update', group.key),
                                                        { enabled },
                                                        { preserveScroll: true },
                                                    )
                                                }
                                                size="sm"
                                            />
                                            <span className="text-[8px] font-semibold uppercase tracking-wide text-ink-muted">
                                                {group.enabled ? 'Show' : 'Hide'}
                                            </span>
                                        </div>
                                        {group.enabled ? (
                                            <button
                                                type="button"
                                                aria-label={`${expandedMenuGroup === group.key ? 'Collapse' : 'Expand'} ${group.label} submenus`}
                                                aria-expanded={expandedMenuGroup === group.key}
                                                onClick={() =>
                                                    setExpandedMenuGroup((current) =>
                                                        current === group.key ? null : group.key,
                                                    )
                                                }
                                                className="flex h-8 w-8 shrink-0 items-center justify-center rounded-md text-ink-muted transition hover:bg-black/5 hover:text-ink"
                                            >
                                                <svg
                                                    viewBox="0 0 20 20"
                                                    fill="none"
                                                    aria-hidden="true"
                                                    className={
                                                        'h-4 w-4 transition-transform ' +
                                                        (expandedMenuGroup === group.key
                                                            ? 'rotate-180'
                                                            : '')
                                                    }
                                                >
                                                    <path
                                                        d="m5 7.5 5 5 5-5"
                                                        stroke="currentColor"
                                                        strokeWidth="1.8"
                                                        strokeLinecap="round"
                                                        strokeLinejoin="round"
                                                    />
                                                </svg>
                                            </button>
                                        ) : null}
                                    </div>
                                    {group.enabled && expandedMenuGroup === group.key ? (
                                        <div className="grid gap-2 border-t border-current/10 p-3 sm:grid-cols-2">
                                            {group.children.map((menu) => {
                                                const childTone = moduleTone(
                                                    menu.tone || toneForModule(menu),
                                                );
                                                return (
                                                    <div
                                                        key={menu.key}
                                                        className={
                                                            'flex items-center justify-between gap-2 rounded-md border px-2.5 py-2 ' +
                                                            (menu.enabled ? childTone.card : childTone.off)
                                                        }
                                                    >
                                                        <span
                                                            className={
                                                                'truncate rounded px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide ' +
                                                                childTone.chip
                                                            }
                                                        >
                                                            {menu.label}
                                                        </span>
                                                        <div className="flex shrink-0 flex-col items-center gap-0.5">
                                                            <Toggle
                                                                checked={menu.enabled}
                                                                onChange={(enabled) =>
                                                                    router.patch(
                                                                        route('admin.menus.update', menu.key),
                                                                        { enabled },
                                                                        { preserveScroll: true },
                                                                    )
                                                                }
                                                            />
                                                            <span className="text-[9px] font-semibold uppercase tracking-wide text-ink-muted">
                                                                {menu.enabled ? 'Show' : 'Hide'}
                                                            </span>
                                                        </div>
                                                    </div>
                                                );
                                            })}
                                        </div>
                                    ) : null}
                                </section>
                            );
                        })}
                    </div>
                    <div className="grid gap-2 p-4 sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5">
                        {menus.map((menu) => {
                            const tone = moduleTone(toneForModule(menu));
                            const on = menu.enabled;
                            return (
                                <div
                                    key={menu.key}
                                    className={
                                        'flex items-center justify-between gap-2 rounded-lg border px-2.5 py-2 shadow-sm transition ' +
                                        (on ? tone.card : tone.off)
                                    }
                                >
                                    <span
                                        className={
                                            'truncate rounded px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide ' +
                                            tone.chip
                                        }
                                    >
                                        {menu.label}
                                    </span>
                                    <div className="flex shrink-0 flex-col items-center gap-0.5">
                                        <Toggle
                                            checked={on}
                                            onChange={(enabled) =>
                                                router.patch(
                                                    route('admin.menus.update', menu.key),
                                                    { enabled },
                                                    { preserveScroll: true },
                                                )
                                            }
                                        />
                                        <span className="text-[9px] font-semibold uppercase tracking-wide text-ink-muted">
                                            {on ? 'Show' : 'Hide'}
                                        </span>
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </section>

                <section className="atlas-panel overflow-hidden">
                    <div className="border-b border-line/70 px-6 py-5">
                        <h3 className="font-display text-lg font-bold text-ink">SMM platforms</h3>
                        <p className="mt-1 text-sm text-ink-muted">
                            Disable a network to hide it from every client SMM Connect / Compose.
                            Workspaces can only show platforms that are enabled here.
                        </p>
                    </div>
                    <div className="grid gap-2 p-4 sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-5">
                        {socialPlatforms.map((item) => {
                            const tone = moduleTone(item.tone || 'ink');
                            const on = item.enabled;
                            return (
                                <div
                                    key={item.key}
                                    className={
                                        'flex items-center justify-between gap-2 rounded-lg border px-2.5 py-2 shadow-sm transition ' +
                                        (on ? tone.card : tone.off)
                                    }
                                >
                                    <div className="min-w-0 shrink-0">
                                        <span
                                            className={
                                                'inline-flex rounded px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide ' +
                                                tone.chip
                                            }
                                        >
                                            {item.label}
                                        </span>
                                    </div>
                                    <div className="flex shrink-0 flex-col items-center gap-0.5">
                                        <Toggle
                                            checked={on}
                                            onChange={(enabled) =>
                                                router.patch(
                                                    route('admin.social-platforms.update', item.key),
                                                    { enabled },
                                                    { preserveScroll: true },
                                                )
                                            }
                                        />
                                        <span className="text-[9px] font-semibold uppercase tracking-wide text-ink-muted">
                                            {on ? 'Show' : 'Hide'}
                                        </span>
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </section>

                <div className="grid gap-6 lg:grid-cols-2">
                    <section className="atlas-panel overflow-hidden">
                        <div className="flex items-center justify-between border-b border-line/70 px-6 py-5">
                            <h3 className="font-display text-lg font-bold text-ink">Recent users</h3>
                            <Link
                                href={route('admin.users')}
                                className="text-sm font-semibold text-signal-strong hover:text-signal"
                            >
                                Manage
                            </Link>
                        </div>
                        <ul className="divide-y divide-line/70">
                            {recentUsers.map((user) => (
                                <li
                                    key={user.id}
                                    className="flex items-center justify-between gap-3 px-6 py-3"
                                >
                                    <div className="min-w-0">
                                        <div className="truncate text-sm font-semibold text-ink">
                                            {user.name}
                                        </div>
                                        <div className="truncate text-xs text-ink-muted">
                                            {user.email}
                                        </div>
                                    </div>
                                    <span
                                        className={
                                            'shrink-0 rounded-md px-2 py-0.5 text-[10px] font-semibold uppercase ' +
                                            (user.is_superadmin
                                                ? 'bg-ink text-white'
                                                : 'bg-signal-soft text-signal-strong')
                                        }
                                    >
                                        {user.is_superadmin ? 'admin' : 'client'}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </section>

                    <section className="atlas-panel overflow-hidden">
                        <div className="flex items-center justify-between border-b border-line/70 px-6 py-5">
                            <h3 className="font-display text-lg font-bold text-ink">
                                Recent workspaces
                            </h3>
                            <Link
                                href={route('admin.workspaces')}
                                className="text-sm font-semibold text-signal-strong hover:text-signal"
                            >
                                Manage
                            </Link>
                        </div>
                        <ul className="divide-y divide-line/70">
                            {recentWorkspaces.map((workspace) => (
                                <li
                                    key={workspace.id}
                                    className="flex items-center justify-between gap-3 px-6 py-3"
                                >
                                    <div className="min-w-0">
                                        <div className="truncate text-sm font-semibold text-ink">
                                            {workspace.name}
                                        </div>
                                        <div className="truncate text-xs text-ink-muted">
                                            /{workspace.slug} · {workspace.members_count} members
                                        </div>
                                    </div>
                                    <span className="shrink-0 rounded-md bg-mist px-2 py-0.5 text-[10px] font-semibold uppercase text-ink-muted">
                                        {workspace.plan}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </section>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
