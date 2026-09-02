import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import InputLabel from '@/Components/InputLabel';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import { Head, router } from '@inertiajs/react';

function Pagination({ links = [] }) {
    if (links.length <= 3) {
        return null;
    }

    return (
        <div className="flex flex-wrap gap-2 border-t border-line/70 px-6 py-4">
            {links.map((link, i) => (
                <button
                    key={i}
                    type="button"
                    disabled={!link.url}
                    onClick={() => link.url && router.get(link.url)}
                    className={
                        'rounded-md px-2.5 py-1 text-xs font-semibold ' +
                        (link.active
                            ? 'bg-ink text-white'
                            : 'border border-line text-ink-muted disabled:opacity-40')
                    }
                    dangerouslySetInnerHTML={{ __html: link.label }}
                />
            ))}
        </div>
    );
}

export default function Activity({ logs, loginLogs, filters = {} }) {
    const tab = filters.tab || 'actions';

    const search = (e) => {
        e.preventDefault();
        const q = new FormData(e.target).get('q') || '';
        router.get(
            route('admin.activity'),
            { q, tab, user_id: filters.user_id || undefined },
            { preserveState: true, replace: true },
        );
    };

    const switchTab = (nextTab) => {
        router.get(
            route('admin.activity'),
            { q: filters.q || undefined, tab: nextTab, user_id: filters.user_id || undefined },
            { preserveState: true, replace: true },
        );
    };

    return (
        <AuthenticatedLayout
            header={
                <div>
                    <div className="text-xs font-semibold uppercase tracking-[0.18em] text-ink-muted">
                        Super admin
                    </div>
                    <h2 className="font-display text-2xl font-bold tracking-tight text-ink">
                        Team activity
                    </h2>
                </div>
            }
        >
            <Head title="Admin · Team activity" />

            <div className="atlas-shell space-y-6">
                <div className="flex flex-wrap gap-2">
                    <button
                        type="button"
                        onClick={() => switchTab('actions')}
                        className={
                            'rounded-md px-3 py-1.5 text-sm font-semibold ' +
                            (tab === 'actions'
                                ? 'bg-ink text-white'
                                : 'border border-line text-ink-muted')
                        }
                    >
                        What they did
                    </button>
                    <button
                        type="button"
                        onClick={() => switchTab('logins')}
                        className={
                            'rounded-md px-3 py-1.5 text-sm font-semibold ' +
                            (tab === 'logins'
                                ? 'bg-ink text-white'
                                : 'border border-line text-ink-muted')
                        }
                    >
                        Login history
                    </button>
                </div>

                <form onSubmit={search} className="flex flex-wrap items-end gap-2">
                    <div>
                        <InputLabel htmlFor="q" value="Search" />
                        <TextInput
                            id="q"
                            name="q"
                            defaultValue={filters.q || ''}
                            placeholder={
                                tab === 'logins'
                                    ? 'Name, email, or IP'
                                    : 'Action, user, or workspace'
                            }
                            className="mt-1 w-72"
                        />
                    </div>
                    <SecondaryButton type="submit">Search</SecondaryButton>
                    {filters.user_id ? (
                        <SecondaryButton
                            type="button"
                            onClick={() =>
                                router.get(route('admin.activity'), { tab }, { replace: true })
                            }
                        >
                            Clear user filter
                        </SecondaryButton>
                    ) : null}
                </form>

                {tab === 'logins' ? (
                    <section className="atlas-panel overflow-hidden">
                        <div className="border-b border-line/70 px-6 py-4">
                            <h3 className="font-display text-lg font-bold text-ink">Login history</h3>
                            <p className="mt-1 text-sm text-ink-muted">
                                When team members signed in, from which IP, and when they signed out.
                            </p>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="min-w-full text-left text-sm">
                                <thead className="bg-mist/80 text-ink-muted">
                                    <tr>
                                        <th className="px-4 py-3 font-semibold">Signed in</th>
                                        <th className="px-4 py-3 font-semibold">User</th>
                                        <th className="px-4 py-3 font-semibold">IP</th>
                                        <th className="px-4 py-3 font-semibold">Signed out</th>
                                        <th className="px-4 py-3 font-semibold">Notes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {loginLogs.data.length === 0 ? (
                                        <tr>
                                            <td
                                                colSpan={5}
                                                className="px-4 py-8 text-center text-ink-muted"
                                            >
                                                No login records yet.
                                            </td>
                                        </tr>
                                    ) : (
                                        loginLogs.data.map((log) => (
                                            <tr key={log.id} className="border-t border-line/70">
                                                <td className="px-4 py-3 whitespace-nowrap text-ink-muted">
                                                    {log.logged_in_at}
                                                </td>
                                                <td className="px-4 py-3">
                                                    <div className="font-semibold text-ink">
                                                        {log.user || '—'}
                                                    </div>
                                                    <div className="text-xs text-ink-muted">
                                                        {log.email}
                                                    </div>
                                                </td>
                                                <td className="px-4 py-3 font-mono text-xs text-ink-muted">
                                                    {log.ip_address || '—'}
                                                </td>
                                                <td className="px-4 py-3 whitespace-nowrap text-ink-muted">
                                                    {log.logged_out_at || 'Active'}
                                                </td>
                                                <td className="px-4 py-3 text-xs text-ink-muted">
                                                    {log.simulated ? (
                                                        <span className="rounded-md bg-amber-50 px-2 py-0.5 font-semibold text-amber-800">
                                                            Simulated by {log.simulated_by || 'admin'}
                                                        </span>
                                                    ) : (
                                                        log.channel
                                                    )}
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </div>
                        <Pagination links={loginLogs.links} />
                    </section>
                ) : (
                    <section className="atlas-panel overflow-hidden">
                        <div className="border-b border-line/70 px-6 py-4">
                            <h3 className="font-display text-lg font-bold text-ink">Action history</h3>
                            <p className="mt-1 text-sm text-ink-muted">
                                Posts, settings, team changes, and other actions by clients and team
                                members.
                            </p>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="min-w-full text-left text-sm">
                                <thead className="bg-mist/80 text-ink-muted">
                                    <tr>
                                        <th className="px-4 py-3 font-semibold">When</th>
                                        <th className="px-4 py-3 font-semibold">Action</th>
                                        <th className="px-4 py-3 font-semibold">User</th>
                                        <th className="px-4 py-3 font-semibold">Workspace</th>
                                        <th className="px-4 py-3 font-semibold">Details</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {logs.data.length === 0 ? (
                                        <tr>
                                            <td
                                                colSpan={5}
                                                className="px-4 py-8 text-center text-ink-muted"
                                            >
                                                No activity recorded yet.
                                            </td>
                                        </tr>
                                    ) : (
                                        logs.data.map((log) => (
                                            <tr key={log.id} className="border-t border-line/70 align-top">
                                                <td className="px-4 py-3 whitespace-nowrap text-ink-muted">
                                                    {log.created_at}
                                                </td>
                                                <td className="px-4 py-3">
                                                    <div className="font-semibold text-ink">
                                                        {log.label || log.action}
                                                    </div>
                                                    <div className="font-mono text-[11px] text-ink-muted">
                                                        {log.action}
                                                    </div>
                                                </td>
                                                <td className="px-4 py-3 text-ink-muted">
                                                    <div>{log.user || '—'}</div>
                                                    <div className="text-xs">{log.email}</div>
                                                </td>
                                                <td className="px-4 py-3 text-ink-muted">
                                                    {log.workspace || '—'}
                                                </td>
                                                <td className="px-4 py-3 font-mono text-xs text-ink-muted">
                                                    {log.meta ? JSON.stringify(log.meta) : '—'}
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </div>
                        <Pagination links={logs.links} />
                    </section>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
