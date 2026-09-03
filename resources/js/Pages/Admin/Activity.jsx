import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import InputLabel from '@/Components/InputLabel';
import JsonDetailsModal, { DetailTrigger } from '@/Components/JsonDetailsModal';
import PanelTitle from '@/Components/PanelTitle';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectMenu from '@/Components/SelectMenu';
import TextInput from '@/Components/TextInput';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

const PERIODS = [
    { id: 'all', label: 'Any time' },
    { id: 'today', label: 'Today' },
    { id: '7d', label: '7 days' },
    { id: '30d', label: '30 days' },
];

const ACTION_GROUPS = [
    { id: 'all', label: 'All' },
    { id: 'seo', label: 'SEO' },
    { id: 'social', label: 'Social' },
    { id: 'workspace', label: 'Workspace' },
    { id: 'blog', label: 'Blog' },
    { id: 'media', label: 'Media' },
    { id: 'billing', label: 'Billing' },
    { id: 'settings', label: 'Settings' },
    { id: 'admin', label: 'Admin' },
    { id: 'other', label: 'Other' },
];

const LOGIN_KINDS = [
    { id: 'all', label: 'All logins' },
    { id: 'live', label: 'Live' },
    { id: 'simulated', label: 'Simulated' },
];

const GROUP_CHIP = {
    seo: 'bg-emerald-100 text-emerald-800',
    social: 'bg-fuchsia-100 text-fuchsia-800',
    workspace: 'bg-sky-100 text-sky-800',
    blog: 'bg-amber-100 text-amber-800',
    media: 'bg-sky-100 text-sky-800',
    brand: 'bg-rose-100 text-rose-800',
    billing: 'bg-emerald-100 text-emerald-800',
    settings: 'bg-signal-soft text-signal-strong',
    admin: 'bg-ink text-white',
    other: 'bg-mist-deep text-ink',
};

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

function formatStamp(value) {
    if (!value) {
        return { date: '—', time: '' };
    }
    const [date, time] = String(value).split(' ');
    const parts = (date || '').split('-');
    if (parts.length !== 3) {
        return { date: value, time: time || '' };
    }
    const month = MONTHS[Number(parts[1]) - 1] || parts[1];
    return {
        date: `${Number(parts[2])} ${month} ${parts[0]}`,
        time: (time || '').slice(0, 5),
    };
}

function Avatar({ name }) {
    const letter = (name || '?').charAt(0).toUpperCase();
    return (
        <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-signal-soft text-xs font-bold text-signal-strong">
            {letter}
        </span>
    );
}

function GroupChip({ group }) {
    if (!group || group === 'other') {
        return null;
    }
    const label = ACTION_GROUPS.find((item) => item.id === group)?.label || group;
    return (
        <span
            className={
                'inline-flex rounded-md px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide ' +
                (GROUP_CHIP[group] || GROUP_CHIP.other)
            }
        >
            {label}
        </span>
    );
}

function ChipRow({ items, value, onChange }) {
    return (
        <div className="flex flex-wrap gap-1.5">
            {items.map((item) => {
                const active = value === item.id;
                return (
                    <button
                        key={item.id}
                        type="button"
                        onClick={() => onChange(item.id)}
                        className={
                            'rounded-md border px-2.5 py-1.5 text-xs font-semibold transition ' +
                            (active
                                ? 'border-signal bg-signal text-white shadow-sm'
                                : 'border-line bg-white text-ink-muted hover:border-signal/50 hover:text-ink')
                        }
                    >
                        {item.label}
                    </button>
                );
            })}
        </div>
    );
}

function Pagination({ links = [] }) {
    if (links.length <= 3) {
        return null;
    }

    return (
        <div className="flex flex-wrap gap-2 border-t border-line/70 bg-mist/30 px-4 py-4 sm:px-6">
            {links.map((link, i) => (
                <button
                    key={i}
                    type="button"
                    disabled={!link.url}
                    onClick={() => link.url && router.get(link.url)}
                    className={
                        'rounded-md px-2.5 py-1 text-xs font-semibold ' +
                        (link.active
                            ? 'bg-signal text-white shadow-sm'
                            : 'border border-line bg-white text-ink-muted disabled:opacity-40')
                    }
                    dangerouslySetInnerHTML={{ __html: link.label }}
                />
            ))}
        </div>
    );
}

function cleanParams(params) {
    const next = {};
    Object.entries(params).forEach(([key, value]) => {
        if (value === undefined || value === null || value === '' || value === 'all') {
            return;
        }
        next[key] = value;
    });
    return next;
}

export default function Activity({ logs, loginLogs, filters = {}, filterOptions = {} }) {
    const tab = filters.tab || 'actions';
    const period = filters.period || 'all';
    const group = filters.group || 'all';
    const kind = filters.kind || 'all';
    const [detail, setDetail] = useState(null);
    const [q, setQ] = useState(filters.q || '');

    const visit = (patch = {}) => {
        const nextTab = patch.tab ?? tab;
        router.get(
            route('admin.activity'),
            cleanParams({
                tab: nextTab === 'actions' ? undefined : nextTab,
                q: patch.q !== undefined ? patch.q : q || filters.q,
                user_id: patch.user_id !== undefined ? patch.user_id : filters.user_id,
                workspace_id:
                    patch.workspace_id !== undefined ? patch.workspace_id : filters.workspace_id,
                group: nextTab === 'logins' ? undefined : patch.group !== undefined ? patch.group : group,
                period: patch.period !== undefined ? patch.period : period,
                kind: nextTab === 'actions' ? undefined : patch.kind !== undefined ? patch.kind : kind,
            }),
            { preserveState: true, replace: true },
        );
    };

    const search = (e) => {
        e.preventDefault();
        visit({ q });
    };

    const hasFilters = Boolean(
        (filters.q && filters.q.length) ||
            filters.user_id ||
            filters.workspace_id ||
            (group && group !== 'all') ||
            (period && period !== 'all') ||
            (kind && kind !== 'all'),
    );

    const users = filterOptions.users || [];
    const workspaces = filterOptions.workspaces || [];

    return (
        <AuthenticatedLayout
            header={
                <div>
                    <div className="text-[11px] font-semibold uppercase tracking-[0.16em] text-ink-muted">
                        Super admin
                    </div>
                    <h2 className="mt-1 font-display text-2xl font-bold tracking-tight text-ink">
                        Team activity
                    </h2>
                </div>
            }
        >
            <Head title="Admin · Team activity" />

            <div className="atlas-shell min-w-0 space-y-5">
                <div className="flex rounded-lg border border-line bg-white p-1 shadow-sm">
                    {[
                        { id: 'actions', label: 'What they did', hint: logs.total },
                        { id: 'logins', label: 'Login history', hint: loginLogs.total },
                    ].map((item) => {
                        const active = tab === item.id;
                        return (
                            <button
                                key={item.id}
                                type="button"
                                onClick={() => visit({ tab: item.id })}
                                className={
                                    'flex flex-1 items-center justify-center gap-2 rounded-md px-3 py-2.5 text-sm font-semibold transition ' +
                                    (active
                                        ? 'bg-signal text-white shadow-sm'
                                        : 'text-ink-muted hover:bg-mist hover:text-ink')
                                }
                            >
                                {item.label}
                                <span
                                    className={
                                        'rounded-md px-1.5 py-0.5 text-[11px] tabular-nums ' +
                                        (active ? 'bg-white/20' : 'bg-mist-deep text-ink-muted')
                                    }
                                >
                                    {item.hint ?? 0}
                                </span>
                            </button>
                        );
                    })}
                </div>

                <section className="atlas-panel overflow-hidden">
                    <PanelTitle
                        title="Search & filters"
                        subtitle="Find an action, person, workspace, or time window."
                    />
                    <form onSubmit={search} className="space-y-4 p-4 sm:p-5">
                        <div className="grid gap-3 lg:grid-cols-[1fr_14rem_14rem_auto] lg:items-end">
                            <div className="min-w-0">
                                <InputLabel htmlFor="q" value="Search" />
                                <TextInput
                                    id="q"
                                    name="q"
                                    value={q}
                                    onChange={(e) => setQ(e.target.value)}
                                    placeholder={
                                        tab === 'logins'
                                            ? 'Name, email, or IP'
                                            : 'Action, user, or workspace'
                                    }
                                    className="mt-1 w-full"
                                />
                            </div>
                            <div className="min-w-0">
                                <InputLabel value="Person" />
                                <SelectMenu
                                    className="mt-1"
                                    value={filters.user_id ? String(filters.user_id) : ''}
                                    onChange={(value) =>
                                        visit({ user_id: value ? Number(value) : null })
                                    }
                                    placeholder="All people"
                                    searchPlaceholder="Search people…"
                                    options={[
                                        { value: '', label: 'All people' },
                                        ...users.map((user) => ({
                                            value: String(user.id),
                                            label: user.name || user.email,
                                            meta: user.email,
                                        })),
                                    ]}
                                />
                            </div>
                            {tab === 'actions' ? (
                                <div className="min-w-0">
                                    <InputLabel value="Workspace" />
                                    <SelectMenu
                                        className="mt-1"
                                        value={
                                            filters.workspace_id ? String(filters.workspace_id) : ''
                                        }
                                        onChange={(value) =>
                                            visit({ workspace_id: value ? Number(value) : null })
                                        }
                                        placeholder="All workspaces"
                                        searchPlaceholder="Search workspaces…"
                                        options={[
                                            { value: '', label: 'All workspaces' },
                                            ...workspaces.map((workspace) => ({
                                                value: String(workspace.id),
                                                label: workspace.name,
                                            })),
                                        ]}
                                    />
                                </div>
                            ) : (
                                <div />
                            )}
                            <div className="flex flex-wrap gap-2">
                                <PrimaryButton type="submit">Search</PrimaryButton>
                                {hasFilters ? (
                                    <SecondaryButton
                                        type="button"
                                        onClick={() => {
                                            setQ('');
                                            router.get(
                                                route('admin.activity'),
                                                { tab: tab === 'actions' ? undefined : tab },
                                                { replace: true },
                                            );
                                        }}
                                    >
                                        Clear
                                    </SecondaryButton>
                                ) : null}
                            </div>
                        </div>

                        <div className="space-y-2">
                            <div className="text-[11px] font-semibold uppercase tracking-[0.14em] text-ink-muted">
                                Time
                            </div>
                            <ChipRow
                                items={PERIODS}
                                value={period}
                                onChange={(next) => visit({ period: next })}
                            />
                        </div>

                        {tab === 'actions' ? (
                            <div className="space-y-2">
                                <div className="text-[11px] font-semibold uppercase tracking-[0.14em] text-ink-muted">
                                    Action type
                                </div>
                                <ChipRow
                                    items={ACTION_GROUPS}
                                    value={group}
                                    onChange={(next) => visit({ group: next })}
                                />
                            </div>
                        ) : (
                            <div className="space-y-2">
                                <div className="text-[11px] font-semibold uppercase tracking-[0.14em] text-ink-muted">
                                    Login type
                                </div>
                                <ChipRow
                                    items={LOGIN_KINDS}
                                    value={kind}
                                    onChange={(next) => visit({ kind: next })}
                                />
                            </div>
                        )}
                    </form>
                </section>

                {tab === 'logins' ? (
                    <section className="atlas-panel overflow-hidden">
                        <PanelTitle
                            title="Login history"
                            subtitle="When people signed in, from which IP, and when they signed out."
                            action={
                                <span className="rounded-md bg-signal-soft px-2.5 py-1 text-xs font-semibold text-signal-strong">
                                    {loginLogs.total} records
                                </span>
                            }
                        />

                        <div className="divide-y divide-line lg:hidden">
                            {loginLogs.data.length === 0 ? (
                                <p className="px-4 py-10 text-center text-sm text-ink-muted">
                                    No login records match these filters.
                                </p>
                            ) : (
                                loginLogs.data.map((log) => {
                                    const when = formatStamp(log.logged_in_at);
                                    return (
                                        <article key={log.id} className="space-y-2 px-4 py-4">
                                            <div className="flex items-start gap-3">
                                                <Avatar name={log.user} />
                                                <div className="min-w-0">
                                                    <div className="font-semibold text-ink">
                                                        {log.user || '—'}
                                                    </div>
                                                    <div className="truncate text-xs text-ink-muted">
                                                        {log.email}
                                                    </div>
                                                </div>
                                            </div>
                                            <div className="text-xs text-ink-muted">
                                                {when.date} · {when.time}
                                            </div>
                                            <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-ink-muted">
                                                <span className="font-mono">{log.ip_address || '—'}</span>
                                                <span>Out: {log.logged_out_at || 'Active'}</span>
                                            </div>
                                            {log.simulated ? (
                                                <span className="inline-flex rounded-md bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800">
                                                    Simulated by {log.simulated_by || 'admin'}
                                                </span>
                                            ) : (
                                                <span className="inline-flex rounded-md bg-mist-deep px-2 py-0.5 text-xs font-semibold text-ink">
                                                    {log.channel || 'web'}
                                                </span>
                                            )}
                                        </article>
                                    );
                                })
                            )}
                        </div>

                        <div className="hidden overflow-x-auto lg:block">
                            <table className="w-full table-fixed text-left text-sm">
                                <thead className="bg-mist/80 text-ink-muted">
                                    <tr>
                                        <th className="w-[9.5rem] px-4 py-3 font-semibold">Signed in</th>
                                        <th className="w-[28%] px-4 py-3 font-semibold">User</th>
                                        <th className="w-[8rem] px-4 py-3 font-semibold">IP</th>
                                        <th className="w-[9.5rem] px-4 py-3 font-semibold">Signed out</th>
                                        <th className="px-4 py-3 font-semibold">Notes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {loginLogs.data.length === 0 ? (
                                        <tr>
                                            <td
                                                colSpan={5}
                                                className="px-4 py-10 text-center text-ink-muted"
                                            >
                                                No login records match these filters.
                                            </td>
                                        </tr>
                                    ) : (
                                        loginLogs.data.map((log) => {
                                            const when = formatStamp(log.logged_in_at);
                                            return (
                                                <tr
                                                    key={log.id}
                                                    className="border-t border-line/70 transition hover:bg-signal-soft/20"
                                                >
                                                    <td className="whitespace-nowrap px-4 py-3">
                                                        <div className="font-medium text-ink">
                                                            {when.date}
                                                        </div>
                                                        <div className="text-xs text-ink-muted">
                                                            {when.time}
                                                        </div>
                                                    </td>
                                                    <td className="min-w-0 px-4 py-3">
                                                        <div className="flex min-w-0 items-center gap-2.5">
                                                            <Avatar name={log.user} />
                                                            <div className="min-w-0">
                                                                <div className="truncate font-semibold text-ink">
                                                                    {log.user || '—'}
                                                                </div>
                                                                <div className="truncate text-xs text-ink-muted">
                                                                    {log.email}
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td className="px-4 py-3 font-mono text-xs text-ink-muted">
                                                        {log.ip_address || '—'}
                                                    </td>
                                                    <td className="whitespace-nowrap px-4 py-3 text-ink-muted">
                                                        {log.logged_out_at || 'Active'}
                                                    </td>
                                                    <td className="min-w-0 px-4 py-3 text-xs">
                                                        {log.simulated ? (
                                                            <span className="rounded-md bg-amber-100 px-2 py-0.5 font-semibold text-amber-800">
                                                                Simulated by {log.simulated_by || 'admin'}
                                                            </span>
                                                        ) : (
                                                            <span className="rounded-md bg-mist-deep px-2 py-0.5 font-semibold text-ink">
                                                                {log.channel || 'web'}
                                                            </span>
                                                        )}
                                                    </td>
                                                </tr>
                                            );
                                        })
                                    )}
                                </tbody>
                            </table>
                        </div>
                        <Pagination links={loginLogs.links} />
                    </section>
                ) : (
                    <section className="atlas-panel overflow-hidden">
                        <PanelTitle
                            title="Action history"
                            subtitle="Posts, settings, team changes, crawls, and other client actions."
                            action={
                                <span className="rounded-md bg-signal-soft px-2.5 py-1 text-xs font-semibold text-signal-strong">
                                    {logs.total} records
                                </span>
                            }
                        />

                        <div className="divide-y divide-line lg:hidden">
                            {logs.data.length === 0 ? (
                                <p className="px-4 py-10 text-center text-sm text-ink-muted">
                                    No activity matches these filters.
                                </p>
                            ) : (
                                logs.data.map((log) => {
                                    const when = formatStamp(log.created_at);
                                    return (
                                        <article key={log.id} className="space-y-2.5 px-4 py-4">
                                            <div className="flex items-start justify-between gap-3">
                                                <div className="min-w-0">
                                                    <div className="flex flex-wrap items-center gap-1.5">
                                                        <GroupChip group={log.group} />
                                                        <span className="text-xs text-ink-muted">
                                                            {when.date} · {when.time}
                                                        </span>
                                                    </div>
                                                    <div className="mt-1 font-semibold text-ink">
                                                        {log.label || log.action}
                                                    </div>
                                                </div>
                                            </div>
                                            <div className="flex items-center gap-2.5">
                                                <Avatar name={log.user} />
                                                <div className="min-w-0 text-sm">
                                                    <div className="truncate text-ink">
                                                        {log.user || '—'}
                                                    </div>
                                                    <div className="truncate text-xs text-ink-muted">
                                                        {log.workspace || 'No workspace'}
                                                    </div>
                                                </div>
                                            </div>
                                            <DetailTrigger
                                                value={log.meta}
                                                onOpen={() =>
                                                    setDetail({
                                                        title: log.label || log.action,
                                                        value: log.meta,
                                                    })
                                                }
                                            />
                                        </article>
                                    );
                                })
                            )}
                        </div>

                        <div className="hidden overflow-x-auto lg:block">
                            <table className="w-full table-fixed text-left text-sm">
                                <thead className="bg-mist/80 text-ink-muted">
                                    <tr>
                                        <th className="w-[8.5rem] px-4 py-3 font-semibold">When</th>
                                        <th className="w-[24%] px-4 py-3 font-semibold">Action</th>
                                        <th className="w-[20%] px-4 py-3 font-semibold">User</th>
                                        <th className="w-[16%] px-4 py-3 font-semibold">Workspace</th>
                                        <th className="px-4 py-3 font-semibold">Details</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {logs.data.length === 0 ? (
                                        <tr>
                                            <td
                                                colSpan={5}
                                                className="px-4 py-10 text-center text-ink-muted"
                                            >
                                                No activity matches these filters.
                                            </td>
                                        </tr>
                                    ) : (
                                        logs.data.map((log) => {
                                            const when = formatStamp(log.created_at);
                                            return (
                                                <tr
                                                    key={log.id}
                                                    className="border-t border-line/70 align-middle transition hover:bg-signal-soft/20"
                                                >
                                                    <td className="whitespace-nowrap px-4 py-3">
                                                        <div className="font-medium text-ink">
                                                            {when.date}
                                                        </div>
                                                        <div className="text-xs text-ink-muted">
                                                            {when.time}
                                                        </div>
                                                    </td>
                                                    <td className="min-w-0 px-4 py-3">
                                                        <div className="mb-1">
                                                            <GroupChip group={log.group} />
                                                        </div>
                                                        <div className="truncate font-semibold text-ink">
                                                            {log.label || log.action}
                                                        </div>
                                                    </td>
                                                    <td className="min-w-0 px-4 py-3">
                                                        <div className="flex min-w-0 items-center gap-2.5">
                                                            <Avatar name={log.user} />
                                                            <div className="min-w-0">
                                                                <div className="truncate font-semibold text-ink">
                                                                    {log.user || '—'}
                                                                </div>
                                                                <div className="truncate text-xs text-ink-muted">
                                                                    {log.email}
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td className="min-w-0 truncate px-4 py-3 text-ink-muted">
                                                        {log.workspace || '—'}
                                                    </td>
                                                    <td className="min-w-0 px-4 py-3">
                                                        <DetailTrigger
                                                            value={log.meta}
                                                            onOpen={() =>
                                                                setDetail({
                                                                    title: log.label || log.action,
                                                                    value: log.meta,
                                                                })
                                                            }
                                                        />
                                                    </td>
                                                </tr>
                                            );
                                        })
                                    )}
                                </tbody>
                            </table>
                        </div>
                        <Pagination links={logs.links} />
                    </section>
                )}
            </div>

            <JsonDetailsModal
                show={Boolean(detail)}
                title={detail?.title}
                value={detail?.value}
                onClose={() => setDetail(null)}
            />
        </AuthenticatedLayout>
    );
}
