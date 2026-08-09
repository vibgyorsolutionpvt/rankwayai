import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Toggle from '@/Components/Toggle';
import { moduleTone, toneForModule } from '@/Components/moduleTones';
import { Head, router } from '@inertiajs/react';

export default function Dashboard({ stats, users, workspaces, menus = [] }) {
    return (
        <AuthenticatedLayout
            header={
                <div>
                    <div className="text-xs font-semibold uppercase tracking-[0.18em] text-ink-muted">
                        Admin
                    </div>
                    <h2 className="font-display text-2xl font-bold tracking-tight text-ink">
                        Overview
                    </h2>
                </div>
            }
        >
            <Head title="Admin" />

            <div className="atlas-shell space-y-6 stagger">
                <section className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    {[
                        { label: 'Total users', value: stats.users },
                        { label: 'Clients', value: stats.clients },
                        { label: 'Businesses', value: stats.workspaces },
                        { label: 'Admins', value: stats.superadmins },
                    ].map((stat) => (
                        <div key={stat.label} className="atlas-panel p-5">
                            <div className="text-xs font-semibold uppercase tracking-[0.16em] text-ink-muted">
                                {stat.label}
                            </div>
                            <div className="mt-3 font-display text-4xl font-bold text-ink">
                                {stat.value}
                            </div>
                        </div>
                    ))}
                </section>

                <section className="atlas-panel overflow-hidden">
                    <div className="border-b border-line/70 px-6 py-5">
                        <h3 className="font-display text-lg font-bold text-ink">Client menus</h3>
                        <p className="mt-1 text-sm text-ink-muted">
                            Disable a menu to hide it from every client sidebar and block the route.
                            Client admins can only grant modules that remain enabled here.
                        </p>
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
                        <h3 className="font-display text-lg font-bold text-ink">All accounts</h3>
                        <p className="mt-1 text-sm text-ink-muted">
                            All users across every account. Clients manage their own businesses.
                        </p>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-left text-sm">
                            <thead className="bg-mist/80 text-ink-muted">
                                <tr>
                                    <th className="px-6 py-3 font-semibold">Name</th>
                                    <th className="px-6 py-3 font-semibold">Email</th>
                                    <th className="px-6 py-3 font-semibold">Role</th>
                                    <th className="px-6 py-3 font-semibold">Workspaces</th>
                                    <th className="px-6 py-3 font-semibold">Joined</th>
                                </tr>
                            </thead>
                            <tbody>
                                {users.map((user) => (
                                    <tr key={user.id} className="border-t border-line/70">
                                        <td className="px-6 py-3 font-semibold text-ink">{user.name}</td>
                                        <td className="px-6 py-3 text-ink-muted">{user.email}</td>
                                        <td className="px-6 py-3">
                                            <span
                                                className={
                                                    'rounded-lg px-2.5 py-1 text-xs font-semibold ' +
                                                    (user.is_superadmin
                                                        ? 'bg-ink text-white'
                                                        : 'bg-signal-soft text-signal-strong')
                                                }
                                            >
                                                {user.is_superadmin ? 'superadmin' : 'client'}
                                            </span>
                                        </td>
                                        <td className="px-6 py-3 text-ink">{user.workspaces_count}</td>
                                        <td className="px-6 py-3 text-ink-muted">{user.created_at}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>

                <section className="atlas-panel overflow-hidden">
                    <div className="border-b border-line/70 px-6 py-5">
                        <h3 className="font-display text-lg font-bold text-ink">All workspaces</h3>
                        <p className="mt-1 text-sm text-ink-muted">
                            Tenant spaces on the platform. This is not a client workspace screen.
                        </p>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-left text-sm">
                            <thead className="bg-mist/80 text-ink-muted">
                                <tr>
                                    <th className="px-6 py-3 font-semibold">Workspace</th>
                                    <th className="px-6 py-3 font-semibold">Slug</th>
                                    <th className="px-6 py-3 font-semibold">Members</th>
                                    <th className="px-6 py-3 font-semibold">Created</th>
                                </tr>
                            </thead>
                            <tbody>
                                {workspaces.map((workspace) => (
                                    <tr key={workspace.id} className="border-t border-line/70">
                                        <td className="px-6 py-3 font-semibold text-ink">
                                            {workspace.name}
                                        </td>
                                        <td className="px-6 py-3 text-ink-muted">/{workspace.slug}</td>
                                        <td className="px-6 py-3 text-ink">{workspace.members_count}</td>
                                        <td className="px-6 py-3 text-ink-muted">
                                            {workspace.created_at}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
