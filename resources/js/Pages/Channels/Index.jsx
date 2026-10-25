import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import DateTimePicker from '@/Components/DateTimePicker';
import HelpGuide, { HELP } from '@/Components/HelpGuide';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import RichTextEditor from '@/Components/RichTextEditor';
import SelectMenu from '@/Components/SelectMenu';
import TextInput from '@/Components/TextInput';
import { toast } from '@/Components/ToastProvider';
import { confirmAsk } from '@/Components/ConfirmProvider';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';

const stripMarkup = (value) =>
    String(value ?? '')
        .replace(/<[^>]*>/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();

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
    email_only = false,
    initial_channel = 'whatsapp',
    provider,
    providers = {},
    email_providers = [],
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
    const [activeEmailTab, setActiveEmailTab] = useState('campaigns');
    const [activeEmailCampaignView, setActiveEmailCampaignView] = useState('list');
    const [activeEmailTemplateView, setActiveEmailTemplateView] = useState('list');
    const sendLocked = plan && !plan.features?.channel_send;
    const defaultRcsProvider =
        rcs_providers.find((p) => p.ready)?.id || rcs_providers[0]?.id || 'sandbox';

    const form = useForm({
        name: '',
        channel: initial_channel,
        email_provider:
            providers.email && providers.email !== 'none' ? providers.email : 'auto',
        rcs_provider: defaultRcsProvider,
        subject: '',
        body: '',
        scheduled_at: '',
        lead_ids: [],
        delivery: sendLocked ? 'draft' : 'now',
    });

    const templateForm = useForm({
        name: '',
        channel: initial_channel,
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
        if (email_only) {
            setActiveEmailTab('campaigns');
            setActiveEmailCampaignView('create');
        }
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
            channel: initial_channel,
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
                        <h2 className="font-display text-2xl font-bold text-ink">
                            {email_only ? 'Email Campaigns' : 'Channels'}
                        </h2>
                        <HelpGuide help={HELP.channels} />
                    </div>
                </div>
            }
        >
            <Head title={email_only ? 'Email Campaigns' : 'Channels'} />
            <div className="atlas-shell space-y-2.5">
                {sendLocked ? (
                    <section className="atlas-panel border border-amber-200 bg-amber-50/80 p-3.5">
                        <div className="font-semibold text-ink">Sending locked on Free</div>
                        <p className="mt-1 text-sm text-ink-muted">
                            You can save drafts and templates. Sending needs a paid plan (messaging API).
                        </p>
                        <a
                            href={route('billing.index')}
                            className="mt-2 inline-block text-sm font-semibold text-signal-strong"
                        >
                            View plans →
                        </a>
                    </section>
                ) : null}
                {!email_only ? <section className="atlas-panel flex flex-wrap items-center justify-between gap-3 p-3">
                    <div>
                        <div className="text-[11px] font-semibold uppercase tracking-wide text-ink-muted">
                            WhatsApp · Email · RCS
                        </div>
                        <p className="mt-0.5 text-sm text-ink-muted">
                            WhatsApp:{' '}
                            <span className="font-semibold text-ink">
                                {providers.whatsapp === 'meta' ? (
                                    'Meta Cloud API'
                                ) : providers.whatsapp === 'zavu' ? (
                                    'Zavu'
                                ) : (
                                    <Link
                                        href={route('whatsapp.index', { view: 'setup' })}
                                        className="text-rose-700 underline"
                                    >
                                        Not connected — set up
                                    </Link>
                                )}
                            </span>
                            {' · '}
                            Email:{' '}
                            <span className="font-semibold text-ink">
                                {providers.email === 'auto'
                                    ? 'Automatic free-provider rotation'
                                    : providers.email === 'none'
                                      ? 'No provider connected'
                                      : providers.email === 'smtp'
                                      ? 'Custom SMTP'
                                      : providers.email === 'zavu'
                                        ? 'Zavu'
                                        : providers.email}
                            </span>
                            {' · '}
                            RCS: Jio / Airtel / Vi / test
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
                </section> : (
                    <section className="atlas-panel flex flex-wrap items-center justify-between gap-3 p-3">
                        <div>
                            <div className="text-[11px] font-semibold uppercase tracking-wide text-ink-muted">
                                Email campaigns
                            </div>
                            <p className="mt-0.5 text-sm text-ink-muted">
                                This page sends email only. Select CRM contacts with email addresses.
                            </p>
                        </div>
                        <span className="text-sm font-semibold text-ink">
                            Provider:{' '}
                            {providers.email === 'auto'
                                ? 'Automatic free-provider rotation'
                                : providers.email === 'none'
                                  ? 'No provider connected'
                                  : providers.email === 'smtp'
                                  ? 'Custom SMTP'
                                  : providers.email === 'zavu'
                                    ? 'Zavu'
                                    : providers.email}
                        </span>
                    </section>
                )}

                {email_only ? (
                    <nav
                        className="flex flex-wrap gap-1 rounded-lg border border-line bg-white p-1 shadow-sm"
                        aria-label="Email sections"
                    >
                        {[
                            { id: 'campaigns', label: 'Campaigns', count: campaigns.length },
                            { id: 'templates', label: 'Templates', count: templates.length },
                        ].map((tab) => (
                            <button
                                key={tab.id}
                                type="button"
                                onClick={() => setActiveEmailTab(tab.id)}
                                className={
                                    'rounded-md border px-3 py-1.5 text-xs font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-signal/30 ' +
                                    (activeEmailTab === tab.id
                                        ? 'border-signal bg-signal text-white shadow-sm'
                                        : 'border-line bg-mist/60 text-ink-soft hover:border-signal/40 hover:bg-signal-soft/50 hover:text-signal-strong')
                                }
                                aria-pressed={activeEmailTab === tab.id}
                            >
                                {tab.label}
                                <span className="ms-2 rounded-full bg-white px-2 py-0.5 text-xs font-semibold text-ink">
                                    {tab.count}
                                </span>
                            </button>
                        ))}
                    </nav>
                ) : null}

                <div
                    className={
                        email_only
                            ? 'space-y-2.5'
                            : 'grid gap-2.5 xl:grid-cols-[0.95fr_1.05fr]'
                    }
                >
                    {(!email_only || activeEmailTab === 'templates') ? (
                    <section className="space-y-4">
                        {email_only ? (
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <div className="flex flex-wrap gap-1 rounded-lg border border-line bg-white p-1 shadow-sm">
                                    {[
                                        { id: 'list', label: `List${templates.length ? ` · ${templates.length}` : ''}` },
                                        { id: 'create', label: editingTemplateId ? 'Edit template' : 'Create template' },
                                    ].map((tab) => (
                                        <button
                                            key={tab.id}
                                            type="button"
                                            aria-pressed={activeEmailTemplateView === tab.id}
                                            onClick={() => setActiveEmailTemplateView(tab.id)}
                                            className={
                                                'rounded-md border px-3 py-1.5 text-xs font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-signal/30 ' +
                                                (activeEmailTemplateView === tab.id
                                                    ? 'border-signal bg-signal text-white shadow-sm'
                                                    : 'border-line bg-mist/60 text-ink-soft hover:border-signal/40 hover:bg-signal-soft/50 hover:text-signal-strong')
                                            }
                                        >
                                            {tab.label}
                                        </button>
                                    ))}
                                </div>
                                {activeEmailTemplateView === 'list' ? (
                                    <PrimaryButton
                                        type="button"
                                        className="!px-3 !py-1.5 !text-xs"
                                        onClick={() => {
                                            cancelEditTemplate();
                                            setActiveEmailTemplateView('create');
                                        }}
                                    >
                                        + New email template
                                    </PrimaryButton>
                                ) : null}
                            </div>
                        ) : null}
                        {(!email_only || activeEmailTemplateView === 'create') ? (
                    <section className="atlas-panel space-y-3 overflow-hidden rounded-2xl border border-violet-100 p-0 shadow-sm">
                        {email_only ? <div className="h-1 bg-gradient-to-r from-violet-400 via-fuchsia-400 to-pink-400" /> : null}
                        <div className="flex flex-wrap items-start justify-between gap-2">
                            <div className="p-4 pb-0">
                                <div className="flex items-center gap-1">
                                    <h3 className="font-display text-base font-bold text-ink">
                                        {editingTemplateId
                                            ? `Edit template #${editingTemplateId}`
                                            : email_only
                                              ? 'Email templates'
                                              : 'Message templates'}
                                    </h3>
                                    <HelpGuide help={HELP.channels} className="!h-6 !w-6" />
                                </div>
                                <p className="text-[11px] text-ink-muted">
                                    {email_only
                                        ? 'Save email copy. Brand kit fills '
                                        : 'Save WhatsApp / Email / RCS copy. Brand kit fills '}
                                    <code className="text-ink">{'{{cta}}'}</code>,{' '}
                                    <code className="text-ink">{'{{phone}}'}</code>, etc. Lead name uses{' '}
                                    <code className="text-ink">{'{{name}}'}</code> on send.
                                </p>
                            </div>
                            {!email_only ? <div className="flex flex-wrap gap-1.5">
                                {!email_only ? (
                                    <button
                                        type="button"
                                        className="rounded-md border border-line px-2 py-1 text-xs font-semibold text-ink hover:border-signal/40"
                                        onClick={() => insertStarter('whatsapp')}
                                    >
                                        WA starter
                                    </button>
                                ) : null}
                                <button
                                    type="button"
                                    className="rounded-md border border-line px-2 py-1 text-xs font-semibold text-ink hover:border-signal/40"
                                    onClick={() => insertStarter('email')}
                                >
                                    Email starter
                                </button>
                                {!email_only ? (
                                    <button
                                        type="button"
                                        className="rounded-md border border-line px-2 py-1 text-xs font-semibold text-ink hover:border-signal/40"
                                        onClick={() => insertStarter('rcs')}
                                    >
                                        RCS starter
                                    </button>
                                ) : null}
                            </div> : null}
                        </div>

                        <form
                            className="space-y-4 p-4 pt-2"
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
                                    onSuccess: () => {
                                        cancelEditTemplate();
                                        if (email_only) setActiveEmailTemplateView('list');
                                    },
                                };
                                if (editingTemplateId) {
                                    templateForm.patch(
                                        route(email_only ? 'email.templates.update' : 'channels.templates.update', editingTemplateId),
                                        opts,
                                    );
                                } else {
                                    templateForm.post(
                                        route(email_only ? 'email.templates.store' : 'channels.templates.store'),
                                        opts,
                                    );
                                }
                            }}
                        >
                            <div className="grid gap-3 sm:grid-cols-2">
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
                                {!email_only ? (
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
                                ) : null}
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
                                <InputLabel value={email_only ? 'Email content' : 'Message body'} />
                                {email_only ? (
                                    <div className="mt-1 grid gap-4 xl:grid-cols-[minmax(0,1.3fr)_minmax(280px,0.7fr)]">
                                        <div className="overflow-hidden rounded-lg border border-line bg-white">
                                            <RichTextEditor
                                                id="email-template-body"
                                                value={templateForm.data.body}
                                                onChange={(html) => templateForm.setData('body', html)}
                                                placeholder="Design your email. Add headings, links, lists, and formatting."
                                                compact
                                            />
                                        </div>
                                        <div className="rounded-xl border border-line bg-mist/50 p-3">
                                            <div className="mb-2 flex items-center justify-between">
                                                <span className="text-xs font-bold uppercase tracking-wide text-ink-muted">Live preview</span>
                                                <span className="text-[10px] text-ink-muted">Desktop email</span>
                                            </div>
                                            <div className="overflow-hidden rounded-lg border border-line bg-white shadow-sm">
                                                <div className="border-b border-line bg-mist/40 px-4 py-3">
                                                    <div className="text-[10px] uppercase tracking-wide text-ink-muted">Subject</div>
                                                    <div className="mt-0.5 truncate text-sm font-semibold text-ink">{templateForm.data.subject || 'Your email subject'}</div>
                                                </div>
                                                <iframe
                                                    title="Email template preview"
                                                    sandbox=""
                                                    className="h-64 w-full bg-white"
                                                    srcDoc={`<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{font-family:Arial,sans-serif;color:#293244;font-size:14px;line-height:1.65;padding:20px;margin:0}img{max-width:100%;height:auto}a{color:#5145cd}h2,h3{line-height:1.25}</style></head><body>${templateForm.data.body || '<p style="color:#98a2b3">Your formatted email content will appear here.</p>'}</body></html>`}
                                                />
                                            </div>
                                        </div>
                                    </div>
                                ) : (
                                    <textarea
                                        className="mt-1 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink"
                                        rows={5}
                                        value={templateForm.data.body}
                                        onChange={(e) => templateForm.setData('body', e.target.value)}
                                        required
                                    />
                                )}
                                <div className="mt-2 flex flex-wrap gap-1">
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
                                        onClick={() => {
                                            cancelEditTemplate();
                                            if (email_only) setActiveEmailTemplateView('list');
                                        }}
                                    >
                                        Cancel
                                    </button>
                                ) : null}
                            </div>
                        </form>
                    </section>
                        ) : null}

                        {(!email_only || activeEmailTemplateView === 'list') ? (
                        <section className="atlas-panel overflow-hidden rounded-2xl border border-line shadow-sm">
                            <div className="flex items-center justify-between border-b border-line px-4 py-3">
                                <div>
                                    <h3 className="font-display text-lg font-bold text-ink">{email_only ? 'Saved email templates' : 'Saved message templates'}</h3>
                                    <p className="mt-0.5 text-xs text-ink-muted">{email_only ? 'Reuse a design in a campaign or keep editing it.' : 'Reuse a saved message in your next campaign.'}</p>
                                </div>
                                {email_only ? (
                                    <PrimaryButton
                                        type="button"
                                        className="!px-3 !py-1.5 !text-xs"
                                        onClick={() => {
                                            cancelEditTemplate();
                                            setActiveEmailTemplateView('create');
                                        }}
                                    >
                                        + Create template
                                    </PrimaryButton>
                                ) : null}
                            </div>
                        <ul className="divide-y divide-line">
                            {templates.length === 0 ? (
                                <li className="p-8 text-center">
                                    <div className="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-2xl bg-violet-50 text-xl text-violet-700">✉</div>
                                    <p className="font-semibold text-ink">No email templates yet</p>
                                    <p className="mt-1 text-sm text-ink-muted">Create a branded email once, then reuse it in future campaigns.</p>
                                    {email_only ? (
                                        <button
                                            type="button"
                                            className="mt-3 text-sm font-semibold text-signal-strong hover:underline"
                                            onClick={() => setActiveEmailTemplateView('create')}
                                        >
                                            Create your first template →
                                        </button>
                                    ) : null}
                                </li>
                            ) : (
                                templates.map((tpl) => (
                                    <li
                                        key={tpl.id}
                                        className="flex flex-col gap-3 p-4 sm:flex-row sm:items-start sm:justify-between"
                                    >
                                        <div className="min-w-0 flex-1">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <h4 className="font-display font-bold text-ink">{tpl.name}</h4>
                                                <span className="rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-bold uppercase text-emerald-700">{email_only ? 'Email' : tpl.channel}</span>
                                            </div>
                                            {tpl.subject ? <p className="mt-1 text-sm font-semibold text-ink-soft">{tpl.subject}</p> : null}
                                            <div className="mt-2 max-w-3xl rounded-xl border border-line bg-mist/40 p-4">
                                                <div className="mx-auto max-w-xl rounded-lg border border-line bg-white p-5 shadow-sm">
                                                    <div className="mb-3 border-b border-line pb-3 text-xs font-semibold uppercase tracking-wide text-ink-muted">{workspace.name}</div>
                                                    <div className="line-clamp-5 whitespace-pre-wrap text-sm leading-6 text-ink-soft">{stripMarkup(tpl.body)}</div>
                                                </div>
                                            </div>
                                        </div>
                                        <div className="flex shrink-0 flex-wrap items-center gap-2">
                                            <button
                                                type="button"
                                                className="rounded-md border border-signal/20 bg-signal-soft px-3 py-1.5 text-xs font-semibold text-signal-strong"
                                                onClick={() => applyTemplate(tpl)}
                                            >
                                                Use in campaign
                                            </button>
                                            <button
                                                type="button"
                                                className="rounded-md border border-line px-3 py-1.5 text-xs font-semibold text-ink-muted"
                                                onClick={() => {
                                                    beginEditTemplate(tpl);
                                                    if (email_only) setActiveEmailTemplateView('create');
                                                }}
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
                        ) : null}
                    </section>
                    ) : null}

                    {(!email_only || activeEmailTab === 'campaigns') ? (
                    <div className="space-y-2.5">
                        {email_only ? (
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <div className="flex flex-wrap gap-1 rounded-lg border border-line bg-white p-1 shadow-sm">
                                    {[
                                        { id: 'list', label: `Campaigns${campaigns.length ? ` · ${campaigns.length}` : ''}` },
                                        { id: 'create', label: 'Create campaign' },
                                    ].map((tab) => (
                                        <button
                                            key={tab.id}
                                            type="button"
                                            aria-pressed={activeEmailCampaignView === tab.id}
                                            onClick={() => setActiveEmailCampaignView(tab.id)}
                                            className={
                                                'rounded-md border px-3 py-1.5 text-xs font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-signal/30 ' +
                                                (activeEmailCampaignView === tab.id
                                                    ? 'border-signal bg-signal text-white shadow-sm'
                                                    : 'border-line bg-mist/60 text-ink-soft hover:border-signal/40 hover:bg-signal-soft/50 hover:text-signal-strong')
                                            }
                                        >
                                            {tab.label}
                                        </button>
                                    ))}
                                </div>
                                {activeEmailCampaignView === 'list' ? (
                                    <PrimaryButton
                                        type="button"
                                        className="!px-3 !py-1.5 !text-xs"
                                        onClick={() => setActiveEmailCampaignView('create')}
                                    >
                                        + New campaign
                                    </PrimaryButton>
                                ) : null}
                            </div>
                        ) : null}
                        {(!email_only || activeEmailCampaignView === 'create') ? (
                        <form
                            className="atlas-panel space-y-4 overflow-hidden rounded-2xl border border-line p-0"
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
                                form.post(route(email_only ? 'email.store' : 'channels.store'), {
                                    preserveScroll: true,
                                    onSuccess: () => {
                                        form.reset(
                                            'name',
                                            'body',
                                            'subject',
                                            'scheduled_at',
                                            'lead_ids',
                                        );
                                        if (email_only) setActiveEmailCampaignView('list');
                                    },
                                });
                            }}
                        >
                            {email_only ? <div className="h-1 bg-gradient-to-r from-sky-400 via-violet-400 to-fuchsia-400" /> : null}
                            <div className="space-y-4 p-4">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <div>
                                    <h3 className="font-display text-lg font-bold text-ink">{email_only ? 'Create email campaign' : 'New campaign'}</h3>
                                    <p className="mt-0.5 text-xs text-ink-muted">Design the email, select contacts with email addresses, and choose when to send.</p>
                                </div>
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
                                {!email_only ? (
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
                                ) : null}
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
                                    <>
                                        <div>
                                            <InputLabel value="Email provider" />
                                            {email_providers.length > 0 ? <div className="mt-1">
                                                <SelectMenu
                                                    value={form.data.email_provider}
                                                    onChange={(v) => form.setData('email_provider', v)}
                                                    buttonClassName="!py-2"
                                                    options={email_providers.map((p) => ({
                                                        value: p.id,
                                                        label: p.label,
                                                    }))}
                                                />
                                            </div> : (
                                                <p className="mt-1 text-sm text-rose-700">
                                                    No live email provider is connected.{' '}
                                                    <Link href={route('settings.index', { tab: 'providers' })} className="font-semibold underline">
                                                        Connect one in Settings → Providers
                                                    </Link>.
                                                </p>
                                            )}
                                            {form.data.email_provider === 'auto' ? (
                                                <p className="mt-1 text-[11px] text-ink-muted">
                                                    Automatically switches to another connected free provider when one reaches its documented cap.
                                                </p>
                                            ) : null}
                                        </div>
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
                                    </>
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
                                <InputLabel value={email_only ? 'Email body' : 'Message'} />
                                {email_only ? (
                                    <div className="mt-1 grid gap-4 xl:grid-cols-[minmax(0,1.3fr)_minmax(280px,0.7fr)]">
                                        <div className="overflow-hidden rounded-lg border border-line bg-white">
                                            <RichTextEditor
                                                id="email-campaign-body"
                                                value={form.data.body}
                                                onChange={(html) => form.setData('body', html)}
                                                placeholder="Create a polished email for your audience."
                                                compact
                                            />
                                        </div>
                                        <div className="rounded-xl border border-line bg-mist/50 p-3">
                                            <div className="mb-2 flex items-center justify-between">
                                                <span className="text-xs font-bold uppercase tracking-wide text-ink-muted">Email preview</span>
                                                <span className="text-[10px] text-ink-muted">Sample lead · Ravi</span>
                                            </div>
                                            <div className="overflow-hidden rounded-lg border border-line bg-white shadow-sm">
                                                <div className="border-b border-line bg-mist/40 px-4 py-3">
                                                    <div className="text-[10px] uppercase tracking-wide text-ink-muted">Subject</div>
                                                    <div className="mt-0.5 truncate text-sm font-semibold text-ink">{form.data.subject || 'Your email subject'}</div>
                                                </div>
                                                <iframe
                                                    title="Email campaign preview"
                                                    sandbox=""
                                                    className="h-64 w-full bg-white"
                                                    srcDoc={`<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{font-family:Arial,sans-serif;color:#293244;font-size:14px;line-height:1.65;padding:20px;margin:0}img{max-width:100%;height:auto}a{color:#5145cd}h2,h3{line-height:1.25}</style></head><body>${previewBody || '<p style="color:#98a2b3">Your formatted email content will appear here.</p>'}</body></html>`}
                                                />
                                            </div>
                                        </div>
                                    </div>
                                ) : (
                                    <textarea
                                        className="mt-1 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink"
                                        rows={5}
                                        value={form.data.body}
                                        onChange={(e) => form.setData('body', e.target.value)}
                                        required
                                    />
                                )}
                                {!email_only && form.data.body ? (
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
                            </div>
                        </form>
                        ) : null}

                        {(!email_only || activeEmailCampaignView === 'list') ? (
                        <div className="atlas-panel overflow-hidden rounded-2xl border border-line">
                            <div className="border-b border-line px-4 py-3">
                                <h3 className="font-display text-lg font-bold text-ink">{email_only ? 'Email campaigns' : 'Campaigns'}</h3>
                                <p className="mt-0.5 text-xs text-ink-muted">{email_only ? 'Review delivery status and manage your email sends.' : 'Review campaign status and manage your sends.'}</p>
                            </div>
                            <ul className="divide-y divide-line">
                                {campaigns.length === 0 ? (
                                    <li className="p-8 text-center">
                                        <div className="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-2xl bg-sky-50 text-xl text-sky-700">✉</div>
                                        <p className="font-semibold text-ink">{email_only ? 'No email campaigns yet' : 'No campaigns yet'}</p>
                                        <p className="mt-1 text-sm text-ink-muted">Your drafts and delivery history will appear here.</p>
                                        {email_only ? (
                                            <button
                                                type="button"
                                                className="mt-3 text-sm font-semibold text-signal-strong hover:underline"
                                                onClick={() => setActiveEmailCampaignView('create')}
                                            >
                                                Create your first campaign →
                                            </button>
                                        ) : null}
                                    </li>
                                ) : (
                                    campaigns.map((c) => (
                                        <li key={c.id} className="p-4">
                                            <div className="flex flex-wrap items-start justify-between gap-2">
                                                <div className="min-w-0">
                                                    <div className="font-semibold text-ink">
                                                        {c.name}
                                                    </div>
                                                    <div className="mt-1 text-[10px] font-bold uppercase tracking-wide text-ink-muted">
                                                        {c.channel} · {c.status} · {c.provider} ·{' '}
                                                        {c.sent_count}/{c.recipient_count}
                                                        {c.scheduled_at ? ` · ${c.scheduled_at}` : ''}
                                                    </div>
                                                    <p className="mt-2 line-clamp-2 text-xs text-ink-muted">
                                                        {stripMarkup(c.body)}
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
                        ) : null}
                    </div>
                    ) : null}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
