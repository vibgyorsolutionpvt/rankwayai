import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';

export default function Billing({ stats, subscriptions = [], recharges = [] }) {
    return (
        <AuthenticatedLayout
            header={
                <div>
                    <div className="text-xs font-semibold uppercase tracking-[0.18em] text-ink-muted">
                        Super admin
                    </div>
                    <h2 className="font-display text-2xl font-bold tracking-tight text-ink">
                        Billing
                    </h2>
                </div>
            }
        >
            <Head title="Admin · Billing" />

            <div className="atlas-shell space-y-6">
                <section className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    {[
                        { label: 'Paid workspaces', value: stats.paid_workspaces },
                        { label: 'Pending recharges', value: stats.pending_recharges },
                        { label: 'Total workspaces', value: stats.workspaces },
                        {
                            label: 'Plan mix',
                            value: Object.entries(stats.plan_counts || {})
                                .map(([plan, n]) => `${plan}:${n}`)
                                .join(' · ') || '—',
                        },
                    ].map((stat) => (
                        <div key={stat.label} className="atlas-panel p-5">
                            <div className="text-xs font-semibold uppercase tracking-[0.16em] text-ink-muted">
                                {stat.label}
                            </div>
                            <div className="mt-3 font-display text-2xl font-bold text-ink">
                                {stat.value}
                            </div>
                        </div>
                    ))}
                </section>

                <section className="atlas-panel overflow-hidden">
                    <div className="border-b border-line/70 px-6 py-5">
                        <h3 className="font-display text-lg font-bold text-ink">Subscriptions</h3>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-left text-sm">
                            <thead className="bg-mist/80 text-ink-muted">
                                <tr>
                                    <th className="px-4 py-3 font-semibold">Workspace</th>
                                    <th className="px-4 py-3 font-semibold">Plan</th>
                                    <th className="px-4 py-3 font-semibold">Status</th>
                                    <th className="px-4 py-3 font-semibold">Market</th>
                                    <th className="px-4 py-3 font-semibold">Provider</th>
                                    <th className="px-4 py-3 font-semibold">MRR</th>
                                    <th className="px-4 py-3 font-semibold">Period end</th>
                                </tr>
                            </thead>
                            <tbody>
                                {subscriptions.map((row) => (
                                    <tr key={row.id} className="border-t border-line/70">
                                        <td className="px-4 py-3 font-semibold text-ink">
                                            {row.workspace || '—'}
                                        </td>
                                        <td className="px-4 py-3 text-ink">{row.plan}</td>
                                        <td className="px-4 py-3 text-ink-muted">{row.status}</td>
                                        <td className="px-4 py-3 text-ink-muted">
                                            {row.billing_market}
                                        </td>
                                        <td className="px-4 py-3 text-ink-muted">
                                            {row.billing_provider}
                                        </td>
                                        <td className="px-4 py-3 text-ink">
                                            {row.mrr_amount
                                                ? `${row.billing_currency || ''} ${row.mrr_amount}`
                                                : '—'}
                                        </td>
                                        <td className="px-4 py-3 text-ink-muted">
                                            {row.current_period_ends_at || '—'}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>

                <section className="atlas-panel overflow-hidden">
                    <div className="border-b border-line/70 px-6 py-5">
                        <h3 className="font-display text-lg font-bold text-ink">Credit recharges</h3>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-left text-sm">
                            <thead className="bg-mist/80 text-ink-muted">
                                <tr>
                                    <th className="px-4 py-3 font-semibold">Workspace</th>
                                    <th className="px-4 py-3 font-semibold">Credits</th>
                                    <th className="px-4 py-3 font-semibold">Amount</th>
                                    <th className="px-4 py-3 font-semibold">Status</th>
                                    <th className="px-4 py-3 font-semibold">Provider</th>
                                    <th className="px-4 py-3 font-semibold">When</th>
                                </tr>
                            </thead>
                            <tbody>
                                {recharges.length === 0 ? (
                                    <tr>
                                        <td
                                            colSpan={6}
                                            className="px-4 py-8 text-center text-sm text-ink-muted"
                                        >
                                            No recharges yet
                                        </td>
                                    </tr>
                                ) : (
                                    recharges.map((row) => (
                                        <tr key={row.id} className="border-t border-line/70">
                                            <td className="px-4 py-3 font-semibold text-ink">
                                                {row.workspace || '—'}
                                            </td>
                                            <td className="px-4 py-3 text-ink">{row.credits}</td>
                                            <td className="px-4 py-3 text-ink">
                                                {row.currency} {row.amount}
                                            </td>
                                            <td className="px-4 py-3 text-ink-muted">{row.status}</td>
                                            <td className="px-4 py-3 text-ink-muted">{row.provider}</td>
                                            <td className="px-4 py-3 text-ink-muted">
                                                {row.created_at}
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
