import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectMenu from '@/Components/SelectMenu';
import TextInput from '@/Components/TextInput';
import { Head, router } from '@inertiajs/react';

export default function Workspaces({
    stats,
    workspaces,
    filters = {},
    plans = [],
    intervals = [],
    markets = [],
}) {
    const search = (e) => {
        e.preventDefault();
        const q = new FormData(e.target).get('q') || '';
        router.get(route('admin.workspaces'), { q }, { preserveState: true, replace: true });
    };

    const patchWorkspace = (id, data) => {
        router.patch(route('admin.workspaces.update', id), data, { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout
            header={
                <div>
                    <div className="text-xs font-semibold uppercase tracking-[0.18em] text-ink-muted">
                        Super admin
                    </div>
                    <h2 className="font-display text-2xl font-bold tracking-tight text-ink">
                        Workspaces
                    </h2>
                </div>
            }
        >
            <Head title="Admin · Workspaces" />

            <div className="atlas-shell space-y-6">
                <section className="flex flex-wrap items-end justify-between gap-4">
                    <form onSubmit={search} className="flex flex-wrap items-end gap-2">
                        <div>
                            <InputLabel htmlFor="q" value="Search" />
                            <TextInput
                                id="q"
                                name="q"
                                defaultValue={filters.q || ''}
                                placeholder="Name or slug"
                                className="mt-1 w-64"
                            />
                        </div>
                        <SecondaryButton type="submit">Search</SecondaryButton>
                    </form>
                    <p className="text-sm text-ink-muted">{stats.workspaces} workspaces total</p>
                </section>

                <section className="atlas-panel overflow-hidden">
                    <div className="border-b border-line/70 px-6 py-5">
                        <h3 className="font-display text-lg font-bold text-ink">
                            All workspaces
                        </h3>
                        <p className="mt-1 text-sm text-ink-muted">
                            Change plan, billing market, and status for any tenant.
                        </p>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-left text-sm">
                            <thead className="bg-mist/80 text-ink-muted">
                                <tr>
                                    <th className="px-4 py-3 font-semibold">Workspace</th>
                                    <th className="px-4 py-3 font-semibold">Members</th>
                                    <th className="px-4 py-3 font-semibold">Plan</th>
                                    <th className="px-4 py-3 font-semibold">Status</th>
                                    <th className="px-4 py-3 font-semibold">Market</th>
                                    <th className="px-4 py-3 font-semibold">Interval</th>
                                    <th className="px-4 py-3 font-semibold">Period end</th>
                                    <th className="px-4 py-3 font-semibold">Open</th>
                                </tr>
                            </thead>
                            <tbody>
                                {workspaces.data.map((workspace) => (
                                    <tr key={workspace.id} className="border-t border-line/70 align-top">
                                        <td className="px-4 py-3">
                                            <div className="font-semibold text-ink">
                                                {workspace.name}
                                            </div>
                                            <div className="text-xs text-ink-muted">
                                                /{workspace.slug}
                                            </div>
                                        </td>
                                        <td className="px-4 py-3 text-ink">
                                            {workspace.members_count}
                                        </td>
                                        <td className="px-4 py-3 min-w-[8rem]">
                                            <SelectMenu
                                                value={workspace.plan}
                                                onChange={(plan) =>
                                                    patchWorkspace(workspace.id, { plan })
                                                }
                                                options={plans.map((plan) => ({
                                                    value: plan,
                                                    label: plan,
                                                }))}
                                            />
                                        </td>
                                        <td className="px-4 py-3 min-w-[8rem]">
                                            <SelectMenu
                                                value={workspace.status}
                                                onChange={(status) =>
                                                    patchWorkspace(workspace.id, { status })
                                                }
                                                options={[
                                                    'active',
                                                    'trialing',
                                                    'past_due',
                                                    'canceled',
                                                ].map((status) => ({
                                                    value: status,
                                                    label: status,
                                                }))}
                                            />
                                        </td>
                                        <td className="px-4 py-3 min-w-[7rem]">
                                            <SelectMenu
                                                value={workspace.billing_market}
                                                onChange={(billing_market) =>
                                                    patchWorkspace(workspace.id, {
                                                        billing_market,
                                                    })
                                                }
                                                options={markets.map((market) => ({
                                                    value: market,
                                                    label: market === 'in' ? 'India' : 'Global',
                                                }))}
                                            />
                                        </td>
                                        <td className="px-4 py-3 min-w-[7rem]">
                                            <SelectMenu
                                                value={workspace.billing_interval}
                                                onChange={(billing_interval) =>
                                                    patchWorkspace(workspace.id, {
                                                        billing_interval,
                                                    })
                                                }
                                                options={intervals.map((interval) => ({
                                                    value: interval,
                                                    label: interval,
                                                }))}
                                            />
                                        </td>
                                        <td className="px-4 py-3 text-ink-muted">
                                            {workspace.current_period_ends_at || '—'}
                                        </td>
                                        <td className="px-4 py-3">
                                            <PrimaryButton
                                                type="button"
                                                onClick={() =>
                                                    router.post(
                                                        route(
                                                            'admin.workspaces.enter',
                                                            workspace.id,
                                                        ),
                                                    )
                                                }
                                            >
                                                Open
                                            </PrimaryButton>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    {workspaces.links?.length > 3 ? (
                        <div className="flex flex-wrap gap-2 border-t border-line/70 px-6 py-4">
                            {workspaces.links.map((link, i) => (
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
