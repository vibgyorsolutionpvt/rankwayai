import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import HelpGuide, { HELP } from '@/Components/HelpGuide';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { confirmAsk } from '@/Components/ConfirmProvider';

const stages = ['new', 'contacted', 'qualified', 'won', 'lost'];
const stageLabel = {
    new: 'New',
    contacted: 'Contacted',
    qualified: 'Qualified',
    won: 'Won',
    lost: 'Lost',
};

export default function Index({ workspace, byStage, counts }) {
    const form = useForm({
        name: '',
        email: '',
        phone: '',
        company: '',
        stage: 'new',
        source: 'manual',
        value_cents: 0,
        notes: '',
    });

    return (
        <AuthenticatedLayout
            header={
                <div>
                    <div className="text-xs font-semibold uppercase tracking-[0.16em] text-ink-muted">
                        {workspace.name}
                    </div>
                    <div className="flex items-center gap-1.5">
                        <h2 className="font-display text-2xl font-bold text-ink">CRM</h2>
                        <HelpGuide help={HELP.crm} />
                    </div>
                </div>
            }
        >
            <Head title="CRM" />
            <div className="atlas-shell space-y-4">
<section className="grid gap-3 sm:grid-cols-3">
                        <div className="atlas-panel p-4">
                            <div className="text-[11px] font-semibold uppercase text-ink-muted">Leads</div>
                            <div className="mt-1 font-display text-3xl font-bold text-ink">{counts.total}</div>
                        </div>
                        <div className="atlas-panel p-4">
                            <div className="text-[11px] font-semibold uppercase text-ink-muted">Deal value</div>
                            <div className="mt-1 font-display text-3xl font-bold text-ink">
                                ${(counts.pipeline_value / 100).toFixed(0)}
                            </div>
                        </div>
                        <div className="atlas-panel p-4">
                            <div className="text-[11px] font-semibold uppercase text-ink-muted">Closed won</div>
                            <div className="mt-1 font-display text-3xl font-bold text-ink">{counts.won}</div>
                        </div>
                    </section>

                    <form
                        className="atlas-panel grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post(route('crm.store'), {
                                onSuccess: () => form.reset('name', 'email', 'phone', 'company', 'notes', 'value_cents'),
                            });
                        }}
                    >
                        <div className="sm:col-span-2 lg:col-span-4">
                            <div className="flex items-center gap-1.5">
                                <h3 className="font-display text-lg font-bold text-ink">Add lead</h3>
                                <HelpGuide help={HELP.crm} />
                            </div>
                        </div>
                        <div>
                            <InputLabel value="Name" />
                            <TextInput
                                className="mt-1.5 w-full"
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                                required
                            />
                        </div>
                        <div>
                            <InputLabel value="Email" />
                            <TextInput
                                type="email"
                                className="mt-1.5 w-full"
                                value={form.data.email}
                                onChange={(e) => form.setData('email', e.target.value)}
                            />
                        </div>
                        <div>
                            <InputLabel value="Phone (+91…)" />
                            <TextInput
                                className="mt-1.5 w-full"
                                placeholder="+9198…"
                                value={form.data.phone}
                                onChange={(e) => form.setData('phone', e.target.value)}
                            />
                        </div>
                        <div>
                            <InputLabel value="Deal value (USD)" />
                            <TextInput
                                type="number"
                                className="mt-1.5 w-full"
                                value={form.data.value_cents / 100}
                                onChange={(e) =>
                                    form.setData('value_cents', Math.round(Number(e.target.value || 0) * 100))
                                }
                            />
                        </div>
                        <div className="sm:col-span-2 lg:col-span-4">
                            <PrimaryButton processing={form.processing}>Save lead</PrimaryButton>
                        </div>
                    </form>

                    <div className="grid gap-3 lg:grid-cols-5">
                        {stages.map((stage) => (
                            <div key={stage} className="atlas-panel overflow-hidden">
                                <div className="border-b border-line px-3 py-2 text-xs font-semibold uppercase tracking-wide text-ink-muted">
                                    {stageLabel[stage] || stage}
                                </div>
                                <ul className="divide-y divide-line">
                                    {(byStage[stage] || []).length === 0 ? (
                                        <li className="px-3 py-6 text-xs text-ink-muted">Empty</li>
                                    ) : (
                                        (byStage[stage] || []).map((lead) => (
                                            <li key={lead.id} className="px-3 py-2.5">
                                                <Link href={route('crm.show', lead.id)} className="block group">
                                                    <div className="font-semibold text-ink group-hover:text-signal">
                                                        {lead.name}
                                                    </div>
                                                    <div className="text-xs text-ink-muted">
                                                        {lead.email || lead.phone || '—'}
                                                    </div>
                                                </Link>
                                                <div className="mt-2 flex flex-wrap gap-1">
                                                    {stages
                                                        .filter((s) => s !== stage)
                                                        .slice(0, 3)
                                                        .map((s) => (
                                                            <button
                                                                key={s}
                                                                type="button"
                                                                className="rounded border border-line px-1.5 py-0.5 text-[10px] font-semibold uppercase text-ink-muted hover:border-signal/40 hover:text-ink"
                                                                onClick={() =>
                                                                    router.patch(route('crm.update', lead.id), {
                                                                        stage: s,
                                                                    })
                                                                }
                                                            >
                                                                {stageLabel[s] || s}
                                                            </button>
                                                        ))}
                                                    <button
                                                        type="button"
                                                        className="rounded border border-rose-200 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-rose-600"
                                                        onClick={async () => {
                                                            const ok = await confirmAsk({
                                                                title: 'Delete this lead?',
                                                                message: `“${lead.name}” will be removed permanently.`,
                                                                confirmLabel: 'Delete',
                                                            });
                                                            if (ok) {
                                                                router.delete(route('crm.destroy', lead.id));
                                                            }
                                                        }}
                                                    >
                                                        del
                                                    </button>
                                                </div>
                                            </li>
                                        ))
                                    )}
                                </ul>
                            </div>
                        ))}
                    </div>
                </div>
        </AuthenticatedLayout>
    );
}
