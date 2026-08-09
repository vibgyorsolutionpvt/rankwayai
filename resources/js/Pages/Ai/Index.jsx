import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import HelpGuide, { HELP } from '@/Components/HelpGuide';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SelectMenu from '@/Components/SelectMenu';
import TextInput from '@/Components/TextInput';
import Toggle from '@/Components/Toggle';
import { Head, Link, router, useForm } from '@inertiajs/react';

export default function Index({
    workspace,
    settings,
    budget,
    credits = null,
    festivals = [],
    generations = [],
    usage = [],
    ai_providers = [],
    active_ai_provider = 'template',
    plan = null,
}) {
    const settingsForm = useForm({
        monthly_budget_usd: budget.monthly,
        template_first: !!settings.template_first,
        tone: settings.tone || 'mixed',
        industry: settings.industry || '',
        location: settings.location || '',
        auto_daily_posts: !!settings.auto_daily_posts,
    });
    const blogForm = useForm({ topic: '' });
    const metaForm = useForm({ page_title: '' });

    const planUsed = credits?.plan_used ?? Math.round(budget.spent * 100);
    const planLimit = credits?.plan_limit ?? Math.round(budget.monthly * 100);
    const topup = credits?.topup ?? 0;
    const available = credits?.available ?? Math.max(0, planLimit - planUsed) + topup;
    const spentPct = planLimit > 0 ? Math.min(100, (planUsed / planLimit) * 100) : 0;
    const aiLocked = plan && !plan.features?.ai;
    const lowCredits = !aiLocked && available < 50;

    return (
        <AuthenticatedLayout
            header={
                <div>
                    <div className="text-xs font-semibold uppercase tracking-[0.16em] text-ink-muted">
                        {workspace.name}
                    </div>
                    <div className="flex items-center gap-1.5">
                        <h2 className="font-display text-2xl font-bold text-ink">AI studio</h2>
                        <HelpGuide help={HELP.ai} />
                    </div>
                </div>
            }
        >
            <Head title="AI" />
            <div className="atlas-shell space-y-2.5">
                {aiLocked ? (
                    <section className="atlas-panel border border-amber-200 bg-amber-50/80 p-3.5">
                        <div className="font-semibold text-ink">AI needs credits</div>
                        <p className="mt-1 text-sm text-ink-muted">
                            Free plan has no included AI credits. Buy a top-up pack or upgrade to
                            Starter+ to generate posts, outlines, and SEO metas.
                        </p>
                        <Link
                            href={route('billing.index')}
                            className="mt-2 inline-block text-sm font-semibold text-signal-strong"
                        >
                            Recharge or upgrade →
                        </Link>
                    </section>
                ) : null}
                {lowCredits ? (
                    <section className="atlas-panel border border-amber-200 bg-amber-50/80 p-3.5">
                        <div className="font-semibold text-ink">Credits running low</div>
                        <p className="mt-1 text-sm text-ink-muted">
                            {available.toLocaleString()} credits left. Recharge to keep generating.
                        </p>
                        <Link
                            href={route('billing.index')}
                            className="mt-2 inline-block text-sm font-semibold text-signal-strong"
                        >
                            Recharge credits →
                        </Link>
                    </section>
                ) : null}
                <section className="atlas-panel flex flex-wrap items-center justify-between gap-2 p-3.5">
                    <div className="min-w-0 flex-1">
                        <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                            <div className="text-[10px] font-semibold uppercase tracking-wide text-ink-muted">
                                AI credits available
                            </div>
                            <div className="font-display text-xl font-bold tabular-nums text-ink">
                                {available.toLocaleString()}
                            </div>
                            <div className="text-[11px] text-ink-muted">
                                Plan {planUsed.toLocaleString()} / {planLimit.toLocaleString()}
                                {topup > 0 ? ` · +${topup.toLocaleString()} top-up` : ''}
                            </div>
                        </div>
                        <div className="mt-1.5 h-1 max-w-xs overflow-hidden rounded-full bg-mist">
                            <div
                                className="h-full rounded-full bg-signal"
                                style={{ width: `${spentPct}%` }}
                            />
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <PrimaryButton
                            type="button"
                            className="!bg-white !text-ink ring-1 ring-line"
                            onClick={() => router.visit(route('billing.index'))}
                        >
                            Recharge
                        </PrimaryButton>
                        <PrimaryButton
                            type="button"
                            disabled={aiLocked || available < 1}
                            onClick={() => router.post(route('ai.generate-today'))}
                        >
                            Write today’s posts
                        </PrimaryButton>
                    </div>
                </section>

                <div className="grid gap-2.5 lg:grid-cols-2">
                    <form
                        className="atlas-panel space-y-2.5 p-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            settingsForm.post(route('ai.settings'));
                        }}
                    >
                        <div className="flex items-center gap-1">
                            <h3 className="font-display text-base font-bold text-ink">Settings</h3>
                            <HelpGuide help={HELP.ai} className="!h-6 !w-6" />
                        </div>
                        <div className="grid gap-2 sm:grid-cols-2">
                            <div>
                                <InputLabel value="Monthly budget (USD)" />
                                <TextInput
                                    type="number"
                                    step="0.01"
                                    className="mt-1 w-full"
                                    value={settingsForm.data.monthly_budget_usd}
                                    onChange={(e) =>
                                        settingsForm.setData('monthly_budget_usd', e.target.value)
                                    }
                                />
                            </div>
                            <div>
                                <InputLabel value="Tone" />
                                <div className="mt-1">
                                    <SelectMenu
                                        value={settingsForm.data.tone}
                                        onChange={(v) => settingsForm.setData('tone', v)}
                                        buttonClassName="!py-2"
                                        options={[
                                            { value: 'mixed', label: 'Hindi / English mixed' },
                                            { value: 'hindi', label: 'Hindi' },
                                            { value: 'english', label: 'English' },
                                        ]}
                                    />
                                </div>
                            </div>
                            <div>
                                <InputLabel value="Industry" />
                                <TextInput
                                    className="mt-1 w-full"
                                    value={settingsForm.data.industry}
                                    onChange={(e) =>
                                        settingsForm.setData('industry', e.target.value)
                                    }
                                />
                            </div>
                            <div>
                                <InputLabel value="Location" />
                                <TextInput
                                    className="mt-1 w-full"
                                    value={settingsForm.data.location}
                                    onChange={(e) =>
                                        settingsForm.setData('location', e.target.value)
                                    }
                                />
                            </div>
                        </div>
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <Toggle
                                checked={!!settingsForm.data.template_first}
                                onChange={(v) => settingsForm.setData('template_first', v)}
                                label="Prefer free templates (cheaper)"
                            />
                            <PrimaryButton processing={settingsForm.processing}>
                                Save settings
                            </PrimaryButton>
                        </div>
                    </form>

                    <div className="space-y-2.5">
                        <form
                            className="atlas-panel space-y-2.5 p-4"
                            onSubmit={(e) => {
                                e.preventDefault();
                                blogForm.post(route('ai.blog-outline'), {
                                    onSuccess: () => blogForm.reset(),
                                });
                            }}
                        >
                            <h3 className="font-display text-base font-bold text-ink">Blog outline</h3>
                            <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                                <TextInput
                                    className="min-w-0 flex-1"
                                    placeholder="Topic"
                                    value={blogForm.data.topic}
                                    onChange={(e) => blogForm.setData('topic', e.target.value)}
                                    required
                                />
                                <PrimaryButton
                                    className="shrink-0"
                                    processing={blogForm.processing}
                                    disabled={aiLocked}
                                >
                                    Generate outline
                                </PrimaryButton>
                            </div>
                        </form>

                        <form
                            className="atlas-panel space-y-2.5 p-4"
                            onSubmit={(e) => {
                                e.preventDefault();
                                metaForm.post(route('ai.seo-metas'), {
                                    onSuccess: () => metaForm.reset(),
                                });
                            }}
                        >
                            <h3 className="font-display text-base font-bold text-ink">
                                Page title & meta help
                            </h3>
                            <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                                <TextInput
                                    className="min-w-0 flex-1"
                                    placeholder="Page title"
                                    value={metaForm.data.page_title}
                                    onChange={(e) => metaForm.setData('page_title', e.target.value)}
                                    required
                                />
                                <PrimaryButton
                                    className="shrink-0"
                                    processing={metaForm.processing}
                                    disabled={aiLocked}
                                >
                                    Suggest titles
                                </PrimaryButton>
                            </div>
                        </form>
                    </div>
                </div>

                <div className="grid gap-2.5 lg:grid-cols-2">
                    <div className="atlas-panel overflow-hidden">
                        <div className="border-b border-line px-3 py-2.5 font-display text-base font-bold text-ink">
                            Upcoming festivals
                        </div>
                        <ul className="max-h-[340px] divide-y divide-line overflow-y-auto">
                            {festivals.length === 0 ? (
                                <li className="px-3 py-6 text-sm text-ink-muted">
                                    No upcoming festivals.
                                </li>
                            ) : (
                                festivals.map((f) => (
                                    <li key={f.id} className="px-3 py-2.5 text-sm">
                                        <div className="font-semibold text-ink">{f.name}</div>
                                        <div className="text-[11px] text-ink-muted">
                                            {f.occurs_on} · {f.category || 'festival'}
                                        </div>
                                    </li>
                                ))
                            )}
                        </ul>
                    </div>

                    <div className="atlas-panel overflow-hidden">
                        <div className="flex items-center justify-between border-b border-line px-3 py-2.5">
                            <div className="font-display text-base font-bold text-ink">
                                Recent drafts
                            </div>
                            <Link
                                href={route('social.index')}
                                className="text-sm font-semibold text-signal-strong"
                            >
                                Open SMM
                            </Link>
                        </div>
                        <ul className="max-h-[340px] divide-y divide-line overflow-y-auto">
                            {generations.length === 0 ? (
                                <li className="px-3 py-6 text-sm text-ink-muted">
                                    Nothing generated yet.
                                </li>
                            ) : (
                                generations.map((g) => (
                                    <li
                                        key={g.id}
                                        className="flex items-center justify-between gap-2 px-3 py-2.5 text-sm"
                                    >
                                        <div className="min-w-0 truncate font-semibold text-ink">
                                            {g.title || g.type}
                                        </div>
                                        <div className="shrink-0 text-[10px] font-bold uppercase text-ink-muted">
                                            {g.type} · {g.status}
                                        </div>
                                    </li>
                                ))
                            )}
                        </ul>
                    </div>
                </div>

                <div className="atlas-panel overflow-hidden">
                    <div className="border-b border-line px-3 py-2.5 font-display text-base font-bold text-ink">
                        AI spend
                    </div>
                    <ul className="max-h-[220px] divide-y divide-line overflow-y-auto">
                        {usage.length === 0 ? (
                            <li className="px-3 py-6 text-sm text-ink-muted">No AI spend yet.</li>
                        ) : (
                            usage.map((row) => (
                                <li
                                    key={row.id}
                                    className="flex items-center justify-between px-3 py-2.5 text-sm"
                                >
                                    <span className="font-medium text-ink">
                                        {row.action} · {row.provider}
                                    </span>
                                    <span className="tabular-nums text-ink-muted">
                                        ${Number(row.cost_usd).toFixed(4)}
                                    </span>
                                </li>
                            ))
                        )}
                    </ul>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
