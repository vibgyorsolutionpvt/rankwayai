import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import InputLabel from '@/Components/InputLabel';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import { Head, router } from '@inertiajs/react';

export default function Activity({ logs, filters = {} }) {
    const search = (e) => {
        e.preventDefault();
        const q = new FormData(e.target).get('q') || '';
        router.get(route('admin.activity'), { q }, { preserveState: true, replace: true });
    };

    return (
        <AuthenticatedLayout
            header={
                <div>
                    <div className="text-xs font-semibold uppercase tracking-[0.18em] text-ink-muted">
                        Super admin
                    </div>
                    <h2 className="font-display text-2xl font-bold tracking-tight text-ink">
                        Activity
                    </h2>
                </div>
            }
        >
            <Head title="Admin · Activity" />

            <div className="atlas-shell space-y-6">
                <form onSubmit={search} className="flex flex-wrap items-end gap-2">
                    <div>
                        <InputLabel htmlFor="q" value="Search" />
                        <TextInput
                            id="q"
                            name="q"
                            defaultValue={filters.q || ''}
                            placeholder="Action, user, workspace"
                            className="mt-1 w-72"
                        />
                    </div>
                    <SecondaryButton type="submit">Search</SecondaryButton>
                </form>

                <section className="atlas-panel overflow-hidden">
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-left text-sm">
                            <thead className="bg-mist/80 text-ink-muted">
                                <tr>
                                    <th className="px-4 py-3 font-semibold">When</th>
                                    <th className="px-4 py-3 font-semibold">Action</th>
                                    <th className="px-4 py-3 font-semibold">User</th>
                                    <th className="px-4 py-3 font-semibold">Workspace</th>
                                    <th className="px-4 py-3 font-semibold">Meta</th>
                                </tr>
                            </thead>
                            <tbody>
                                {logs.data.map((log) => (
                                    <tr key={log.id} className="border-t border-line/70 align-top">
                                        <td className="px-4 py-3 whitespace-nowrap text-ink-muted">
                                            {log.created_at}
                                        </td>
                                        <td className="px-4 py-3 font-semibold text-ink">
                                            {log.action}
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
                                ))}
                            </tbody>
                        </table>
                    </div>
                    {logs.links?.length > 3 ? (
                        <div className="flex flex-wrap gap-2 border-t border-line/70 px-6 py-4">
                            {logs.links.map((link, i) => (
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
                    ) : null}
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
