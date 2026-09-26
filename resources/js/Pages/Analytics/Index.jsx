import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';

const money = (n) =>
    `₹${Number(n || 0).toLocaleString('en-IN', { maximumFractionDigits: 0 })}`;

function Stat({ label, value, tone = 'ink' }) {
    const toneClass =
        tone === 'rose'
            ? 'text-rose-700'
            : tone === 'emerald'
              ? 'text-emerald-800'
              : tone === 'amber'
                ? 'text-amber-800'
                : 'text-ink';
    return (
        <div className="atlas-panel p-4">
            <div className="text-[11px] font-semibold uppercase tracking-wide text-ink-muted">{label}</div>
            <div className={`mt-1 font-display text-3xl font-bold ${toneClass}`}>{value}</div>
        </div>
    );
}

export default function Index({ workspace, snapshot }) {
    const leads = snapshot.leads || {};
    const quotations = snapshot.quotations || {};
    const assets = snapshot.assets || {};

    return (
        <AuthenticatedLayout
            header={
                <div>
                    <div className="text-xs font-semibold uppercase tracking-[0.16em] text-ink-muted">
                        {workspace.name}
                    </div>
                    <h2 className="font-display text-2xl font-bold text-ink">Analytics</h2>
                </div>
            }
        >
            <Head title="Analytics" />
            <div className="atlas-shell space-y-4">
                <section>
                    <h3 className="mb-2 font-display text-sm font-bold text-ink">Leads</h3>
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <Stat label="Total leads" value={leads.total || 0} />
                        <Stat label="Qualified" value={leads.qualified || 0} />
                        <Stat label="Hot" value={leads.hot || 0} tone="rose" />
                        <Stat label="Won" value={leads.won || 0} tone="emerald" />
                    </div>
                </section>

                <section>
                    <h3 className="mb-2 font-display text-sm font-bold text-ink">Sales</h3>
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <Stat label="Quotations" value={quotations.total || 0} />
                        <Stat label="Quote value" value={money(quotations.value)} />
                        <Stat label="Won value" value={money(leads.won_value)} tone="emerald" />
                        <Stat
                            label="Conversion"
                            value={`${leads.conversion_rate || 0}%`}
                            tone="amber"
                        />
                    </div>
                </section>

                <section>
                    <h3 className="mb-2 font-display text-sm font-bold text-ink">Assets</h3>
                    <div className="grid gap-3 sm:grid-cols-3">
                        <Stat label="Card views" value={assets.card_views || 0} />
                        <Stat label="Brochure views" value={assets.brochure_views || 0} />
                        <Stat label="Brochure downloads" value={assets.brochure_downloads || 0} />
                    </div>
                </section>

                <div className="grid gap-4 lg:grid-cols-2">
                    <section className="atlas-panel overflow-hidden">
                        <div className="border-b border-line px-4 py-3 font-display text-base font-bold text-ink">
                            Hot leads
                        </div>
                        {(snapshot.hot_leads || []).length === 0 ? (
                            <p className="px-4 py-6 text-sm text-ink-muted">
                                Score leads in CRM to surface hot opportunities.
                            </p>
                        ) : (
                            <ul className="divide-y divide-line">
                                {snapshot.hot_leads.map((lead) => (
                                    <li key={lead.id} className="px-4 py-3">
                                        <Link
                                            href={route('crm.show', lead.id)}
                                            className="font-semibold text-ink hover:text-signal"
                                        >
                                            {lead.name}
                                        </Link>
                                        <div className="text-xs text-ink-muted">
                                            Score {lead.score} · {lead.stage}
                                            {lead.next_action ? ` · ${lead.next_action}` : ''}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>

                    <section className="atlas-panel overflow-hidden">
                        <div className="border-b border-line px-4 py-3 font-display text-base font-bold text-ink">
                            Follow-ups due
                        </div>
                        {(snapshot.follow_ups_due || []).length === 0 ? (
                            <p className="px-4 py-6 text-sm text-ink-muted">
                                No follow-ups due in the next day.
                            </p>
                        ) : (
                            <ul className="divide-y divide-line">
                                {snapshot.follow_ups_due.map((lead) => (
                                    <li key={lead.id} className="px-4 py-3">
                                        <Link
                                            href={route('crm.show', lead.id)}
                                            className="font-semibold text-ink hover:text-signal"
                                        >
                                            {lead.name}
                                        </Link>
                                        <div className="text-xs text-ink-muted">
                                            {lead.follow_up_due_at}
                                            {lead.next_action ? ` · ${lead.next_action}` : ''}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
