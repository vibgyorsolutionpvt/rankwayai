import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import DateTimePicker from '@/Components/DateTimePicker';
import HelpGuide, { HELP } from '@/Components/HelpGuide';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SelectMenu from '@/Components/SelectMenu';
import TextInput from '@/Components/TextInput';
import { toast } from '@/Components/ToastProvider';
import { confirmAsk } from '@/Components/ConfirmProvider';
import { Head, router, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';

const deliveryOptions = [
    { id: 'now', label: 'Send now', hint: 'Deliver immediately' },
    { id: 'schedule', label: 'Schedule', hint: 'Pick date & time' },
    { id: 'draft', label: 'Draft', hint: 'Save without sending' },
];

const starters = {
    whatsapp: {
        name: 'WhatsApp intro',
        subject: '',
        body: 'Hi {{name}} 👋\n\nThis is {{brand}}.\n{{cta}}: {{cta_url}}\n\nCall us: {{phone}}',
    },
    email: {
        name: 'Email intro',
        subject: '{{brand}} — quick hello for {{name}}',
        body: 'Hi {{name}},\n\nThanks for connecting with {{brand}}.\n\n{{cta}}: {{cta_url}}\n\nWebsite: {{website}}\nEmail: {{email}}\nPhone: {{phone}}\n\n— {{brand}}',
    },
    rcs: {
        name: 'RCS intro',
        subject: '',
        body: 'Hi {{name}} 👋\n\n{{brand}} here with a quick update.\n\n{{cta}}: {{cta_url}}\n\nReply YES to talk to us, or call {{phone}}.',
    },
};

const channelOptions = [
    { value: 'whatsapp', label: 'WhatsApp' },
    { value: 'email', label: 'Email' },
    { value: 'rcs', label: 'RCS' },
];

export default function Index({
    workspace,
    provider,
    providers = {},
    rcs_providers = [],
    campaigns = [],
    templates = [],
    placeholders = [],
    brand_tokens = {},
    leads = [],
    counts,
    plan = null,
}) {
    const [editingTemplateId, setEditingTemplateId] = useState(null);
    const sendLocked = plan && !plan.features?.channel_send;
    const defaultRcsProvider =
        rcs_providers.find((p) => p.ready)?.id || rcs_providers[0]?.id || 'sandbox';

    const form = useForm({
        name: '',
        channel: 'whatsapp',
        rcs_provider: defaultRcsProvider,
        subject: '',
        body: '',
        scheduled_at: '',
        lead_ids: [],
        delivery: sendLocked ? 'draft' : 'now',
    });

    const templateForm = useForm({
        name: '',
        channel: 'whatsapp',
        subject: '',
        body: '',
    });

    const filteredTemplates = useMemo(
        () => templates.filter((t) => t.channel === form.data.channel),
        [templates, form.data.channel],
    );

    const toggleLead = (id) => {
        const set = new Set(form.data.lead_ids);
        if (set.has(id)) set.delete(id);
        else set.add(id);
        form.setData('lead_ids', [...set]);
    };

    const applyTemplate = (tpl) => {
        form.setData({
            ...form.data,
            name: tpl.name,
            channel: tpl.channel,
            subject: tpl.subject || '',
            body: tpl.body || '',
        });
        toast.success(`Loaded template: ${tpl.name}`);
        window.scrollTo({ top: 0, behavior: 'smooth' });
    };

    const beginEditTemplate = (tpl) => {
        setEditingTemplateId(tpl.id);
        templateForm.setData({
            name: tpl.name,
            channel: tpl.channel,
            subject: tpl.subject || '',
            body: tpl.body || '',
        });
        templateForm.clearErrors();
    };

    const cancelEditTemplate = () => {
        setEditingTemplateId(null);
        templateForm.setData({
            name: '',
            channel: 'whatsapp',
            subject: '',
            body: '',
        });
        templateForm.clearErrors();
    };

    const insertStarter = (channel) => {
        const s = starters[channel];
        templateForm.setData({
            name: s.name,
            channel,
            subject: s.subject,
            body: s.body,
        });
        setEditingTemplateId(null);
    };

    const submitLabel =
        form.data.delivery === 'now'
            ? 'Create + send now'
            : form.data.delivery === 'schedule'
              ? 'Schedule send'
              : 'Save draft';

    const previewBody = (form.data.body || '')
        .replaceAll('{{brand}}', brand_tokens.brand || workspace.name)
        .replaceAll('{{cta}}', brand_tokens.cta || 'Get started')
        .replaceAll('{{cta_url}}', brand_tokens.cta_url || '')
        .replaceAll('{{phone}}', brand_tokens.phone || '')
        .replaceAll('{{email}}', brand_tokens.email || '')
        .replaceAll('{{website}}', brand_tokens.website || '')
        .replaceAll('{{name}}', 'Ravi');

    return (
        <AuthenticatedLayout
            header={
                <div>
                    <div className="text-xs font-semibold uppercase tracking-[0.16em] text-ink-muted">
                        {workspace.name}
                    </div>
                    <div className="flex items-center gap-1.5">
                        <h2 className="font-display text-2xl font-bold text-ink">Channels</h2>
                        <HelpGuide help={HELP.channels} />
                    </div>
                </div>
            }
        >
            <Head title="Channels" />
            <div className="atlas-shell space-y-2.5">
                {sendLocked ? (
                    <section className="atlas-panel border border-amber-200 bg-amber-50/80 p-3.5">
                        <div className="font-semibold text-ink">Sending locked on Free</div>
                        <p className="mt-1 text-sm text-ink-muted">
                            You can save drafts and templates. WhatsApp / Email / RCS send needs a
                            paid plan (messaging API).
                        </p>
                        <a
                            href={route('billing.index')}
                            className="mt-2 inline-block text-sm font-semibold text-signal-strong"
                        >
                            View plans →
                        </a>
                    </section>
                ) : null}
                <section className="atlas-panel flex flex-wrap items-center justify-between gap-3 p-3">
                    <div>
                        <div className="text-[11px] font-semibold uppercase tracking-wide text-ink-muted">
                            WhatsApp · Email · RCS
                        </div>
                        <p className="mt-0.5 text-sm text-ink-muted">
                            WhatsApp:{' '}
                            <span className="font-semibold text-ink">
                                {providers.whatsapp === 'meta'
                                    ? 'Meta Cloud API'
                                    : providers.whatsapp === 'zavu'
                                      ? 'Zavu'
                                      : 'test mode'}
                            </span>
                            {' · '}
                            Email:{' '}
                            <span className="font-semibold text-ink">
                                {providers.email === 'smtp'
                                    ? 'Custom SMTP'
                                    : providers.email === 'zavu'
                                      ? 'Zavu'
                                      : 'test mode'}
                            </span>
                            {' · '}
                            RCS: Jio / Airtel / Vi / test
                            {provider === 'sandbox' && providers.email === 'sandbox'
                                ? ' — configure Integrations for live sends.'
                                : ''}
                        </p>
                    </div>
                    <div className="flex gap-4 text-sm">
                        <div>
                            <span className="font-display text-xl font-bold text-ink">
                                {counts.templates}
                            </span>
                            <span className="ms-1 text-ink-muted">Templates</span>
                        </div>
                        <div>
                            <span className="font-display text-xl font-bold text-ink">
                                {counts.whatsapp}
                            </span>
                            <span className="ms-1 text-ink-muted">WA</span>
                        </div>
                        <div>
                            <span className="font-display text-xl font-bold text-ink">
                                {counts.email}
                            </span>
                            <span className="ms-1 text-ink-muted">Email</span>
                        </div>
                        <div>
                            <span className="font-display text-xl font-bold text-ink">
                                {counts.rcs || 0}
                            </span>
                            <span className="ms-1 text-ink-muted">RCS</span>
                        </div>
                    </div>
                </section>

                <div className="grid gap-2.5 xl:grid-cols-[0.95fr_1.05fr]">
                    <section className="atlas-panel space-y-3 p-3">
                        <div className="flex flex-wrap items-start justify-between gap-2">
                            <div>
                                <div className="flex items-center gap-1">
                                    <h3 className="font-display text-base font-bold text-ink">
                                        {editingTemplateId
                                            ? `Edit template #${editingTemplateId}`
                                            : 'Message templates'}
                                    </h3>
                                    <HelpGuide help={HELP.channels} className="!h-6 !w-6" />
                                </div>
                                <p className="text-[11px] text-ink-muted">
                                    Save WhatsApp / Email / RCS copy. Brand kit fills{' '}
                                    <code className="text-ink">{'{{cta}}'}</code>,{' '}
                                    <code className="text-ink">{'{{phone}}'}</code>, etc. Lead name uses{' '}
                                    <code className="text-ink">{'{{name}}'}</code> on send.
                                </p>
                            </div>
                            <div className="flex flex-wrap gap-1.5">
                                <button
                                    type="button"
                                    className="rounded-md border border-line px-2 py-1 text-xs font-semibold text-ink hover:border-signal/40"
                                    onClick={() => insertStarter('whatsapp')}
                                >
                                    WA starter
                                </button>
                                <button
                                    type="button"
                                    className="rounded-md border border-line px-2 py-1 text-xs font-semibold text-ink hover:border-signal/40"
                                    onClick={() => insertStarter('email')}
                                >
                                    Email starter
                                </button>
                                <button
                                    type="button"
                                    className="rounded-md border border-line px-2 py-1 text-xs font-semibold text-ink hover:border-signal/40"
                                    onClick={() => insertStarter('rcs')}
                                >
                                    RCS starter
                                </button>
                            </div>
                        </div>

                        <form
                            className="space-y-2 rounded-md border border-line bg-mist/40 p-3"
                            onSubmit={(e) => {
                                e.preventDefault();
                                if (
                                    templateForm.data.channel === 'email' &&
                                    !templateForm.data.subject
                                ) {
                                    toast.error('Email templates need a subject.');
                                    return;
                                }
                                const opts = {
                                    preserveScroll: true,
                                    onSuccess: () => cancelEditTemplate(),
                                };
                                if (editingTemplateId) {
                                    templateForm.patch(
                                        route('channels.templates.update', editingTemplateId),
                                        opts,
                                    );
                                } else {
                                    templateForm.post(route('channels.templates.store'), opts);
                                }
                            }}
                        >
                            <div className="grid gap-2 sm:grid-cols-2">
                                <div>
                                    <InputLabel value="Template name" />
                                    <TextInput
                                        className="mt-1 w-full"
                                        value={templateForm.data.name}
                                        onChange={(e) =>
                                            templateForm.setData('name', e.target.value)
                                        }
                                        required
                                    />
                                </div>
                                <div>
                                    <InputLabel value="Channel" />
                                    <div className="mt-1">
                                        <SelectMenu
                                            value={templateForm.data.channel}
                                            onChange={(v) => templateForm.setData('channel', v)}
                                            buttonClassName="!py-2"
                                            options={channelOptions}
                                        />
                                    </div>
                                </div>
                            </div>
                            {templateForm.data.channel === 'email' ? (
                                <div>
                                    <InputLabel value="Subject" />
                                    <TextInput
                                        className="mt-1 w-full"
                                        value={templateForm.data.subject}
                                        onChange={(e) =>
                                            templateForm.setData('subject', e.target.value)
                                        }
                                        required
                                    />
                                </div>
                            ) : null}
                            <div>
                                <InputLabel value="Message body" />
                                <textarea
                                    className="mt-1 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink"
                                    rows={5}
                                    value={templateForm.data.body}
                                    onChange={(e) => templateForm.setData('body', e.target.value)}
                                    required
                                />
                                <div className="mt-1.5 flex flex-wrap gap-1">
                                    {placeholders.map((p) => (
                                        <button
                                            key={p.token}
                                            type="button"
                                            title={p.label}
                                            className="rounded border border-line bg-white px-1.5 py-0.5 text-[10px] font-semibold text-ink-muted hover:border-signal/40 hover:text-ink"
                                            onClick={() =>
                                                templateForm.setData(
                                                    'body',
                                                    `${templateForm.data.body}${templateForm.data.body ? ' ' : ''}${p.token}`,
                                                )
                                            }
                                        >
                                            {p.token}
                                        </button>
                                    ))}
                                </div>
                            </div>
                            <div className="flex flex-wrap gap-2">
                                <PrimaryButton processing={templateForm.processing}>
                                    {editingTemplateId ? 'Update template' : 'Save template'}
                                </PrimaryButton>
                                {editingTemplateId ? (
                                    <button
                                        type="button"
                                        className="text-sm font-semibold text-ink-muted"
                                        onClick={cancelEditTemplate}
                                    >
                                        Cancel
                                    </button>
                                ) : null}
                            </div>
                        </form>

                        <ul className="max-h-[280px] divide-y divide-line overflow-y-auto rounded-md border border-line">
                            {templates.length === 0 ? (
                                <li className="px-3 py-5 text-sm text-ink-muted">
                                    No templates yet. Use a starter or write your own.
                                </li>
                            ) : (
                                templates.map((tpl) => (
                                    <li
                                        key={tpl.id}
                                        className="flex items-start justify-between gap-2 px-3 py-2"
                                    >
                                        <div className="min-w-0">
                                            <div className="truncate text-sm font-semibold text-ink">
                                                {tpl.name}
                                            </div>
                                            <div className="text-[10px] font-bold uppercase text-ink-muted">
                                                {tpl.channel}
                                            </div>
                                            <p className="mt-0.5 line-clamp-2 text-[11px] text-ink-muted">
                                                {tpl.body}
                                            </p>
                                        </div>
                                        <div className="flex shrink-0 flex-col items-end gap-1">
                                            <button
                                                type="button"
                                                className="text-xs font-semibold text-signal-strong"
                                                onClick={() => applyTemplate(tpl)}
                                            >
                                                Use
                                            </button>
                                            <button
                                                type="button"
                                                className="text-xs font-semibold text-ink-muted"
                                                onClick={() => beginEditTemplate(tpl)}
                                            >
                                                Edit
                                            </button>
                                            <button
                                                type="button"
                                                className="text-xs font-semibold text-rose-600"
                                                onClick={async () => {
                                                    const ok = await confirmAsk({
                                                        title: 'Delete this template?',
                                                        message: tpl.name
                                                            ? `“${tpl.name}” will be removed.`
                                                            : 'This template will be removed.',
                                                        confirmLabel: 'Delete',
                                                    });
                                                    if (ok) {
                                                        router.delete(
                                                            route('channels.templates.destroy', tpl.id),
                                                            { preserveScroll: true },
                                                        );
                                                    }
                                                }}
                                            >
                                                Delete
                                            </button>
                                        </div>
                                    </li>
                                ))
                            )}
                        </ul>
                    </section>

                    <div className="space-y-2.5">
                        <form
                            className="atlas-panel space-y-3 p-3"
                            onSubmit={(e) => {
                                e.preventDefault();
                                if (form.data.delivery === 'schedule' && !form.data.scheduled_at) {
                                    toast.error('Pick a schedule date & time.');
                                    return;
                                }
                                form.transform((data) => ({
                                    ...data,
                                    scheduled_at:
                                        data.delivery === 'schedule'
                                            ? data.scheduled_at || null
                                            : null,
                                    lead_ids: data.lead_ids || [],
                                }));
                                form.post(route('channels.store'), {
                                    preserveScroll: true,
                                    onSuccess: () =>
                                        form.reset(
                                            'name',
                                            'body',
                                            'subject',
                                            'scheduled_at',
                                            'lead_ids',
                                        ),
                                });
                            }}
                        >
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <h3 className="font-display text-base font-bold text-ink">
                                    New campaign
                                </h3>
                                {filteredTemplates.length > 0 ? (
                                    <div className="min-w-[180px]">
                                        <SelectMenu
                                            value=""
                                            placeholder="Load template…"
                                            onChange={(id) => {
                                                const tpl = templates.find(
                                                    (t) => String(t.id) === String(id),
                                                );
                                                if (tpl) applyTemplate(tpl);
                                            }}
                                            buttonClassName="!py-2"
                                            options={filteredTemplates.map((t) => ({
                                                value: t.id,
                                                label: t.name,
                                            }))}
                                        />
                                    </div>
                                ) : null}
                            </div>
                            <div>
                                <InputLabel value="Name" />
                                <TextInput
                                    className="mt-1 w-full"
                                    value={form.data.name}
                                    onChange={(e) => form.setData('name', e.target.value)}
                                    required
                                />
                            </div>
                            <div className="grid gap-2 sm:grid-cols-2">
                                <div>
                                    <InputLabel value="Channel" />
                                    <div className="mt-1">
                                        <SelectMenu
                                            value={form.data.channel}
                                            onChange={(v) => form.setData('channel', v)}
                                            buttonClassName="!py-2"
                                            options={channelOptions}
                                        />
                                    </div>
                                </div>
                                {form.data.channel === 'rcs' ? (
                                    <div>
                                        <InputLabel value="RCS provider" />
                                        <div className="mt-1">
                                            <SelectMenu
                                                value={form.data.rcs_provider}
                                                onChange={(v) => form.setData('rcs_provider', v)}
                                                buttonClassName="!py-2"
                                                options={rcs_providers.map((p) => ({
                                                    value: p.id,
                                                    label: p.label,
                                                    meta: p.ready ? 'ready' : 'keys needed',
                                                }))}
                                            />
                                        </div>
                                        <p className="mt-1 text-[11px] text-ink-muted">
                                            Jio / Airtel / Vi — pick carrier. Without keys, send
                                            stays in test mode.
                                        </p>
                                    </div>
                                ) : form.data.channel === 'email' ? (
                                    <div>
                                        <InputLabel value="Subject" />
                                        <TextInput
                                            className="mt-1 w-full"
                                            value={form.data.subject}
                                            onChange={(e) =>
                                                form.setData('subject', e.target.value)
                                            }
                                            required
                                        />
                                    </div>
                                ) : (
                                    <div className="flex items-end">
                                        <p className="pb-2 text-xs text-ink-muted">
                                            WhatsApp uses message body only.
                                        </p>
                                    </div>
                                )}
                            </div>
                            {form.data.channel === 'rcs' ? (
                                <p className="text-xs text-ink-muted">
                                    RCS uses CRM phone numbers. Provider is stored on the campaign.
                                </p>
                            ) : null}
                            <div>
                                <InputLabel value="Message" />
                                <textarea
                                    className="mt-1 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink"
                                    rows={5}
                                    value={form.data.body}
                                    onChange={(e) => form.setData('body', e.target.value)}
                                    required
                                />
                                {form.data.body ? (
                                    <div className="mt-2 rounded-md border border-dashed border-line bg-mist/40 px-3 py-2">
                                        <div className="text-[10px] font-semibold uppercase tracking-wide text-ink-muted">
                                            Preview (sample lead “Ravi” + Brand kit)
                                        </div>
                                        <pre className="mt-1 whitespace-pre-wrap font-sans text-xs text-ink">
                                            {previewBody}
                                        </pre>
                                    </div>
                                ) : null}
                            </div>
                            <div>
                                <InputLabel value="Who gets this (optional — otherwise open leads)" />
                                <div className="mt-1.5 max-h-32 space-y-1 overflow-y-auto rounded-md border border-line p-2">
                                    {leads.length === 0 ? (
                                        <p className="text-xs text-ink-muted">
                                            Add leads in CRM first.
                                        </p>
                                    ) : (
                                        leads.map((lead) => (
                                            <label
                                                key={lead.id}
                                                className="flex items-center gap-2 text-sm text-ink"
                                            >
                                                <input
                                                    type="checkbox"
                                                    checked={form.data.lead_ids.includes(lead.id)}
                                                    onChange={() => toggleLead(lead.id)}
                                                />
                                                <span className="font-medium">{lead.name}</span>
                                                <span className="text-xs text-ink-muted">
                                                    {form.data.channel === 'email'
                                                        ? lead.email || 'no email'
                                                        : lead.phone || 'no phone'}
                                                </span>
                                            </label>
                                        ))
                                    )}
                                </div>
                            </div>

                            <div>
                                <InputLabel value="Delivery" />
                                <div className="mt-1.5 grid gap-2 sm:grid-cols-3">
                                    {deliveryOptions.map((opt) => {
                                        const active = form.data.delivery === opt.id;
                                        const locked =
                                            sendLocked && (opt.id === 'now' || opt.id === 'schedule');
                                        return (
                                            <button
                                                key={opt.id}
                                                type="button"
                                                disabled={locked}
                                                onClick={() => {
                                                    form.setData('delivery', opt.id);
                                                    if (opt.id !== 'schedule') {
                                                        form.clearErrors('scheduled_at');
                                                    }
                                                }}
                                                className={
                                                    'rounded-md border px-3 py-2 text-left transition ' +
                                                    (locked
                                                        ? 'cursor-not-allowed border-line bg-mist opacity-60'
                                                        : active
                                                          ? 'border-signal bg-signal-soft/60 shadow-sm'
                                                          : 'border-line bg-white hover:border-signal/40')
                                                }
                                            >
                                                <div className="text-sm font-semibold text-ink">
                                                    {opt.label}
                                                </div>
                                                <div className="mt-0.5 text-[11px] text-ink-muted">
                                                    {locked ? 'Paid plan required' : opt.hint}
                                                </div>
                                            </button>
                                        );
                                    })}
                                </div>
                            </div>

                            {form.data.delivery === 'schedule' ? (
                                <div>
                                    <InputLabel value="Schedule at" />
                                    <div className="mt-1">
                                        <DateTimePicker
                                            value={form.data.scheduled_at}
                                            onChange={(v) => form.setData('scheduled_at', v)}
                                            placeholder="Pick date & time"
                                        />
                                    </div>
                                    {form.errors.scheduled_at ? (
                                        <p className="mt-1 text-xs font-medium text-rose-600">
                                            {form.errors.scheduled_at}
                                        </p>
                                    ) : null}
                                </div>
                            ) : null}

                            <PrimaryButton processing={form.processing}>{submitLabel}</PrimaryButton>
                        </form>

                        <div className="atlas-panel overflow-hidden">
                            <div className="border-b border-line px-3 py-2 font-display text-base font-bold text-ink">
                                Campaigns
                            </div>
                            <ul className="max-h-[280px] divide-y divide-line overflow-y-auto">
                                {campaigns.length === 0 ? (
                                    <li className="px-3 py-5 text-sm text-ink-muted">
                                        No campaigns yet.
                                    </li>
                                ) : (
                                    campaigns.map((c) => (
                                        <li key={c.id} className="px-3 py-2">
                                            <div className="flex flex-wrap items-start justify-between gap-2">
                                                <div className="min-w-0">
                                                    <div className="font-semibold text-ink">
                                                        {c.name}
                                                    </div>
                                                    <div className="mt-0.5 text-[10px] font-bold uppercase tracking-wide text-ink-muted">
                                                        {c.channel} · {c.status} · {c.provider} ·{' '}
                                                        {c.sent_count}/{c.recipient_count}
                                                        {c.scheduled_at ? ` · ${c.scheduled_at}` : ''}
                                                    </div>
                                                    <p className="mt-0.5 line-clamp-2 text-[11px] text-ink-muted">
                                                        {c.body}
                                                    </p>
                                                </div>
                                                <div className="flex gap-2">
                                                    {['draft', 'scheduled', 'failed'].includes(
                                                        c.status,
                                                    ) && !sendLocked ? (
                                                        <button
                                                            type="button"
                                                            className="text-sm font-semibold text-signal-strong"
                                                            onClick={() =>
                                                                router.post(
                                                                    route('channels.send', c.id),
                                                                )
                                                            }
                                                        >
                                                            Send now
                                                        </button>
                                                    ) : null}
                                                    {[
                                                        'draft',
                                                        'scheduled',
                                                        'failed',
                                                        'cancelled',
                                                    ].includes(c.status) ? (
                                                        <button
                                                            type="button"
                                                            className="text-sm font-semibold text-rose-600"
                                                            onClick={async () => {
                                                                const ok = await confirmAsk({
                                                                    title: 'Delete this campaign?',
                                                                    message: c.name
                                                                        ? `“${c.name}” will be removed.`
                                                                        : 'This campaign will be removed.',
                                                                    confirmLabel: 'Delete',
                                                                });
                                                                if (ok) {
                                                                    router.delete(
                                                                        route('channels.destroy', c.id),
                                                                    );
                                                                }
                                                            }}
                                                        >
                                                            Delete
                                                        </button>
                                                    ) : null}
                                                </div>
                                            </div>
                                        </li>
                                    ))
                                )}
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
