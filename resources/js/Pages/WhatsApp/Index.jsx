import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import HelpGuide, { HELP } from '@/Components/HelpGuide';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectMenu from '@/Components/SelectMenu';
import TextInput from '@/Components/TextInput';
import { toast } from '@/Components/ToastProvider';
import { confirmAsk } from '@/Components/ConfirmProvider';
import Toggle from '@/Components/Toggle';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';

const TABS = [
    { id: 'setup', label: 'Setup' },
    { id: 'conversations', label: 'Conversations' },
    { id: 'templates', label: 'Templates' },
    { id: 'campaigns', label: 'Campaigns' },
];

const categoryOptions = [
    { value: 'utility', label: 'Utility' },
    { value: 'marketing', label: 'Marketing' },
    { value: 'authentication', label: 'Authentication' },
];

const statusOptions = [
    { value: 'draft', label: 'Draft' },
    { value: 'ready', label: 'Ready' },
];

function waQuery(view, extra = {}) {
    return route('whatsapp.index', { view, ...extra });
}

export default function Index({
    workspace,
    view = 'conversations',
    provider = 'sandbox',
    plan = null,
    meta_setup = null,
    conversations = [],
    activeConversation = null,
    messages = [],
    templates = [],
    campaigns = [],
    leads = [],
    placeholders = [],
    counts = {},
}) {
    const sendLocked = plan && !plan.features?.channel_send;
    const [composing, setComposing] = useState(false);
    const [editingTemplateId, setEditingTemplateId] = useState(null);

    const replyForm = useForm({ body: '', template_id: '', as_template: false });
    const startForm = useForm({
        phone: '',
        crm_lead_id: '',
        contact_name: '',
        body: '',
        template_id: '',
        as_template: false,
    });
    const templateForm = useForm({
        name: '',
        body: '',
        category: 'utility',
        language: 'en',
        wa_status: 'draft',
    });

    const leadOptions = useMemo(
        () => [
            { value: '', label: 'Manual phone…' },
            ...leads.map((l) => ({
                value: String(l.id),
                label: `${l.name} · ${l.phone}`,
            })),
        ],
        [leads],
    );

    const templateOptions = useMemo(
        () => [
            { value: '', label: 'Free-form reply' },
            ...templates.map((t) => ({
                value: String(t.id),
                label: `${t.name}${t.wa_status === 'ready' ? '' : ' (draft)'}`,
            })),
        ],
        [templates],
    );

    const setView = (next) => {
        router.get(waQuery(next), {}, { preserveState: true, preserveScroll: true });
    };

    const openConversation = (id) => {
        router.get(
            waQuery('conversations', { conversation: id }),
            {},
            { preserveState: true, preserveScroll: true },
        );
    };

    const applyTemplateToReply = (id) => {
        const tpl = templates.find((t) => String(t.id) === String(id));
        replyForm.setData({
            ...replyForm.data,
            template_id: id,
            body: tpl?.body || replyForm.data.body,
        });
    };

    const resetTemplateForm = () => {
        setEditingTemplateId(null);
        templateForm.setData({
            name: '',
            body: '',
            category: 'utility',
            language: 'en',
            wa_status: 'draft',
        });
        templateForm.clearErrors();
    };

    return (
        <AuthenticatedLayout
            header={
                <div>
                    <div className="text-xs font-semibold uppercase tracking-[0.16em] text-ink-muted">
                        {workspace.name}
                    </div>
                    <div className="flex items-center gap-1.5">
                        <h2 className="font-display text-2xl font-bold text-ink">WhatsApp</h2>
                        <HelpGuide help={HELP.whatsapp} />
                    </div>
                </div>
            }
        >
            <Head title="WhatsApp" />
            <div className="atlas-shell space-y-3">
                <section className="atlas-panel flex flex-wrap items-center justify-between gap-3 p-3">
                    <div>
                        <p className="text-sm text-ink-muted">
                            Provider:{' '}
                            <span className="font-semibold text-ink">
                                {provider === 'meta'
                                    ? 'Meta Cloud API'
                                    : provider === 'zavu'
                                      ? 'Zavu (fallback)'
                                      : 'test mode'}
                            </span>
                            {' · '}
                            Conversations, templates, and campaigns in one place.
                        </p>
                        <p className="mt-1 text-xs text-ink-muted">
                            Meta webhook:{' '}
                            <code className="rounded bg-mist px-1 py-0.5">
                                /webhooks/meta/whatsapp/{workspace.id}
                            </code>
                        </p>
                    </div>
                    <div className="flex gap-4 text-sm">
                        <Stat label="Unread" value={counts.unread || 0} />
                        <Stat label="Chats" value={counts.conversations || 0} />
                        <Stat label="Templates" value={counts.templates || 0} />
                        <Stat label="Campaigns" value={counts.campaigns || 0} />
                    </div>
                </section>

                {provider === 'sandbox' ? (
                    <section className="atlas-panel border border-amber-200 bg-amber-50/80 p-3">
                        <p className="text-sm text-amber-900">
                            WhatsApp is in <span className="font-semibold">test mode</span>. Open{' '}
                            <button
                                type="button"
                                className="font-semibold underline"
                                onClick={() => setView('setup')}
                            >
                                Setup
                            </button>{' '}
                            and submit your WhatsApp number + business details — RankwayAI will
                            connect Meta for you.
                        </p>
                    </section>
                ) : null}

                {sendLocked ? (
                    <section className="atlas-panel border border-amber-200 bg-amber-50/80 p-3">
                        <p className="text-sm text-amber-900">
                            Sending is locked on the free plan. You can still draft templates and
                            review conversations.{' '}
                            <Link href={route('billing.index')} className="font-semibold underline">
                                Upgrade
                            </Link>
                        </p>
                    </section>
                ) : null}

                <section className="flex flex-wrap gap-1 rounded-md border border-line bg-white p-1">
                    {TABS.map((tab) => {
                        const active = view === tab.id;
                        return (
                            <button
                                key={tab.id}
                                type="button"
                                onClick={() => setView(tab.id)}
                                className={
                                    'rounded px-3 py-2 text-sm font-semibold transition ' +
                                    (active
                                        ? 'bg-signal text-white'
                                        : 'text-ink-muted hover:bg-mist hover:text-ink')
                                }
                            >
                                {tab.label}
                            </button>
                        );
                    })}
                </section>

                {view === 'setup' ? (
                    <SetupView metaSetup={meta_setup} workspace={workspace} />
                ) : null}

                {view === 'conversations' ? (
                    <ConversationsView
                        conversations={conversations}
                        activeConversation={activeConversation}
                        messages={messages}
                        composing={composing}
                        setComposing={setComposing}
                        openConversation={openConversation}
                        replyForm={replyForm}
                        startForm={startForm}
                        leadOptions={leadOptions}
                        templateOptions={templateOptions}
                        templates={templates}
                        placeholders={placeholders}
                        sendLocked={sendLocked}
                        applyTemplateToReply={applyTemplateToReply}
                    />
                ) : null}

                {view === 'templates' ? (
                    <TemplatesView
                        templates={templates}
                        templateForm={templateForm}
                        editingTemplateId={editingTemplateId}
                        setEditingTemplateId={setEditingTemplateId}
                        resetTemplateForm={resetTemplateForm}
                        placeholders={placeholders}
                    />
                ) : null}

                {view === 'campaigns' ? (
                    <CampaignsView campaigns={campaigns} />
                ) : null}
            </div>
        </AuthenticatedLayout>
    );
}

function Stat({ label, value }) {
    return (
        <div>
            <span className="font-display text-xl font-bold text-ink">{value}</span>
            <span className="ms-1 text-ink-muted">{label}</span>
        </div>
    );
}

function ConversationsView({
    conversations,
    activeConversation,
    messages,
    composing,
    setComposing,
    openConversation,
    replyForm,
    startForm,
    leadOptions,
    templateOptions,
    templates,
    placeholders,
    sendLocked,
    applyTemplateToReply,
}) {
    return (
        <section className="grid gap-3 lg:grid-cols-[280px_1fr]">
            <div className="atlas-panel flex max-h-[70vh] flex-col overflow-hidden">
                <div className="flex items-center justify-between border-b border-line p-3">
                    <div className="font-display text-lg font-bold text-ink">Inbox</div>
                    <SecondaryButton type="button" onClick={() => setComposing(true)}>
                        New
                    </SecondaryButton>
                </div>
                <div className="flex-1 overflow-y-auto">
                    {conversations.length === 0 ? (
                        <p className="p-3 text-sm text-ink-muted">
                            No conversations yet. Start one or wait for inbound WhatsApp messages.
                        </p>
                    ) : (
                        conversations.map((c) => {
                            const active = activeConversation?.id === c.id;
                            return (
                                <button
                                    key={c.id}
                                    type="button"
                                    onClick={() => openConversation(c.id)}
                                    className={
                                        'block w-full border-b border-line px-3 py-2.5 text-left transition ' +
                                        (active ? 'bg-signal-soft/50' : 'hover:bg-mist')
                                    }
                                >
                                    <div className="flex items-center justify-between gap-2">
                                        <div className="truncate font-semibold text-ink">
                                            {c.contact_name || c.phone}
                                        </div>
                                        {c.unread_count > 0 ? (
                                            <span className="rounded bg-signal px-1.5 py-0.5 text-[10px] font-bold text-white">
                                                {c.unread_count}
                                            </span>
                                        ) : null}
                                    </div>
                                    <div className="truncate text-xs text-ink-muted">
                                        {c.last_message_preview || c.phone}
                                    </div>
                                    <div className="mt-0.5 text-[10px] text-ink-muted">
                                        {c.last_message_at || '—'}
                                        {c.window_open ? ' · window open' : ''}
                                    </div>
                                </button>
                            );
                        })
                    )}
                </div>
            </div>

            <div className="atlas-panel flex max-h-[70vh] flex-col overflow-hidden">
                {composing ? (
                    <form
                        className="space-y-3 p-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            if (sendLocked) {
                                toast.error('Upgrade to send WhatsApp messages.');
                                return;
                            }
                            startForm.post(route('whatsapp.conversations.start'), {
                                preserveScroll: true,
                                onSuccess: () => {
                                    setComposing(false);
                                    startForm.reset();
                                },
                            });
                        }}
                    >
                        <div className="font-display text-lg font-bold text-ink">New conversation</div>
                        <div>
                            <InputLabel value="Lead" />
                            <div className="mt-1">
                                <SelectMenu
                                    value={String(startForm.data.crm_lead_id || '')}
                                    onChange={(v) => startForm.setData('crm_lead_id', v)}
                                    options={leadOptions}
                                />
                            </div>
                        </div>
                        {!startForm.data.crm_lead_id ? (
                            <>
                                <div>
                                    <InputLabel value="Phone (E.164)" />
                                    <TextInput
                                        className="mt-1 w-full"
                                        value={startForm.data.phone}
                                        onChange={(e) => startForm.setData('phone', e.target.value)}
                                        placeholder="+9198…"
                                    />
                                </div>
                                <div>
                                    <InputLabel value="Contact name" />
                                    <TextInput
                                        className="mt-1 w-full"
                                        value={startForm.data.contact_name}
                                        onChange={(e) =>
                                            startForm.setData('contact_name', e.target.value)
                                        }
                                    />
                                </div>
                            </>
                        ) : null}
                        <div>
                            <InputLabel value="Template (optional)" />
                            <div className="mt-1">
                                <SelectMenu
                                    value={String(startForm.data.template_id || '')}
                                    onChange={(v) => {
                                        const tpl = templates.find((t) => String(t.id) === String(v));
                                        startForm.setData({
                                            ...startForm.data,
                                            template_id: v,
                                            body: tpl?.body || startForm.data.body,
                                        });
                                    }}
                                    options={templateOptions}
                                />
                            </div>
                        </div>
                        <div>
                            <InputLabel value="Message" />
                            <textarea
                                className="mt-1 w-full rounded-md border-line text-sm"
                                rows={5}
                                value={startForm.data.body}
                                onChange={(e) => startForm.setData('body', e.target.value)}
                            />
                            <PlaceholderRow
                                placeholders={placeholders}
                                onInsert={(token) =>
                                    startForm.setData(
                                        'body',
                                        `${startForm.data.body}${startForm.data.body ? ' ' : ''}${token}`,
                                    )
                                }
                            />
                        </div>
                        <Toggle
                            checked={!!startForm.data.as_template}
                            onChange={(v) => startForm.setData('as_template', v)}
                            label="Send as WhatsApp template (outside 24h window)"
                        />
                        <div className="flex gap-2">
                            <PrimaryButton processing={startForm.processing}>Send</PrimaryButton>
                            <SecondaryButton type="button" onClick={() => setComposing(false)}>
                                Cancel
                            </SecondaryButton>
                        </div>
                    </form>
                ) : !activeConversation ? (
                    <div className="flex flex-1 items-center justify-center p-6 text-sm text-ink-muted">
                        Select a conversation or start a new one.
                    </div>
                ) : (
                    <>
                        <div className="flex items-start justify-between gap-2 border-b border-line p-3">
                            <div>
                                <div className="font-display text-lg font-bold text-ink">
                                    {activeConversation.contact_name || activeConversation.phone}
                                </div>
                                <div className="text-xs text-ink-muted">
                                    {activeConversation.phone}
                                    {activeConversation.window_open
                                        ? ` · free-form until ${activeConversation.window_expires_at}`
                                        : ' · use a template if outside 24h window'}
                                </div>
                            </div>
                            <SecondaryButton
                                type="button"
                                onClick={() =>
                                    router.post(
                                        route(
                                            'whatsapp.conversations.close',
                                            activeConversation.id,
                                        ),
                                    )
                                }
                            >
                                Close
                            </SecondaryButton>
                        </div>
                        <div className="flex-1 space-y-2 overflow-y-auto p-3">
                            {messages.map((m) => (
                                <div
                                    key={m.id}
                                    className={
                                        'max-w-[85%] rounded-lg px-3 py-2 text-sm ' +
                                        (m.direction === 'outbound'
                                            ? 'ms-auto bg-signal text-white'
                                            : 'bg-mist text-ink')
                                    }
                                >
                                    <div className="whitespace-pre-wrap">{m.body}</div>
                                    <div
                                        className={
                                            'mt-1 text-[10px] ' +
                                            (m.direction === 'outbound'
                                                ? 'text-white/70'
                                                : 'text-ink-muted')
                                        }
                                    >
                                        {m.sent_at} · {m.status}
                                        {m.template_name ? ` · ${m.template_name}` : ''}
                                    </div>
                                    {m.error_message ? (
                                        <div className="mt-1 text-[10px] text-rose-200">
                                            {m.error_message}
                                        </div>
                                    ) : null}
                                </div>
                            ))}
                        </div>
                        <form
                            className="space-y-2 border-t border-line p-3"
                            onSubmit={(e) => {
                                e.preventDefault();
                                if (sendLocked) {
                                    toast.error('Upgrade to send WhatsApp messages.');
                                    return;
                                }
                                replyForm.post(
                                    route(
                                        'whatsapp.conversations.reply',
                                        activeConversation.id,
                                    ),
                                    {
                                        preserveScroll: true,
                                        onSuccess: () => replyForm.setData('body', ''),
                                    },
                                );
                            }}
                        >
                            <SelectMenu
                                value={String(replyForm.data.template_id || '')}
                                onChange={applyTemplateToReply}
                                options={templateOptions}
                            />
                            <textarea
                                className="w-full rounded-md border-line text-sm"
                                rows={3}
                                placeholder="Write a reply…"
                                value={replyForm.data.body}
                                onChange={(e) => replyForm.setData('body', e.target.value)}
                            />
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <Toggle
                                    checked={!!replyForm.data.as_template}
                                    onChange={(v) => replyForm.setData('as_template', v)}
                                    label="Send as template"
                                />
                                <PrimaryButton processing={replyForm.processing}>
                                    Send reply
                                </PrimaryButton>
                            </div>
                        </form>
                    </>
                )}
            </div>
        </section>
    );
}

function TemplatesView({
    templates,
    templateForm,
    editingTemplateId,
    setEditingTemplateId,
    resetTemplateForm,
    placeholders,
}) {
    return (
        <section className="grid gap-3 lg:grid-cols-2">
            <form
                className="atlas-panel space-y-3 p-4"
                onSubmit={(e) => {
                    e.preventDefault();
                    const opts = {
                        preserveScroll: true,
                        onSuccess: () => resetTemplateForm(),
                    };
                    if (editingTemplateId) {
                        templateForm.patch(
                            route('whatsapp.templates.update', editingTemplateId),
                            opts,
                        );
                    } else {
                        templateForm.post(route('whatsapp.templates.store'), opts);
                    }
                }}
            >
                <div className="font-display text-lg font-bold text-ink">
                    {editingTemplateId ? `Edit template #${editingTemplateId}` : 'WhatsApp template'}
                </div>
                <p className="text-sm text-ink-muted">
                    Save reusable WhatsApp copy. Mark Ready when approved for live sends. Use
                    templates to start chats outside the 24h window.
                </p>
                <div>
                    <InputLabel value="Name" />
                    <TextInput
                        className="mt-1 w-full"
                        value={templateForm.data.name}
                        onChange={(e) => templateForm.setData('name', e.target.value)}
                        placeholder="order_update"
                    />
                </div>
                <div className="grid gap-3 sm:grid-cols-3">
                    <div>
                        <InputLabel value="Category" />
                        <div className="mt-1">
                            <SelectMenu
                                value={templateForm.data.category}
                                onChange={(v) => templateForm.setData('category', v)}
                                options={categoryOptions}
                            />
                        </div>
                    </div>
                    <div>
                        <InputLabel value="Language" />
                        <TextInput
                            className="mt-1 w-full"
                            value={templateForm.data.language}
                            onChange={(e) => templateForm.setData('language', e.target.value)}
                            placeholder="en"
                        />
                    </div>
                    <div>
                        <InputLabel value="Status" />
                        <div className="mt-1">
                            <SelectMenu
                                value={templateForm.data.wa_status}
                                onChange={(v) => templateForm.setData('wa_status', v)}
                                options={statusOptions}
                            />
                        </div>
                    </div>
                </div>
                <div>
                    <InputLabel value="Body" />
                    <textarea
                        className="mt-1 w-full rounded-md border-line text-sm"
                        rows={6}
                        value={templateForm.data.body}
                        onChange={(e) => templateForm.setData('body', e.target.value)}
                    />
                    <PlaceholderRow
                        placeholders={placeholders}
                        onInsert={(token) =>
                            templateForm.setData(
                                'body',
                                `${templateForm.data.body}${templateForm.data.body ? ' ' : ''}${token}`,
                            )
                        }
                    />
                </div>
                <div className="flex flex-wrap gap-2">
                    <PrimaryButton processing={templateForm.processing}>
                        {editingTemplateId ? 'Update template' : 'Save template'}
                    </PrimaryButton>
                    {editingTemplateId ? (
                        <SecondaryButton type="button" onClick={resetTemplateForm}>
                            Cancel
                        </SecondaryButton>
                    ) : null}
                </div>
            </form>

            <div className="atlas-panel space-y-2 p-4">
                <div className="font-display text-lg font-bold text-ink">Saved templates</div>
                {templates.length === 0 ? (
                    <p className="text-sm text-ink-muted">No WhatsApp templates yet.</p>
                ) : (
                    templates.map((tpl) => (
                        <div
                            key={tpl.id}
                            className="rounded-md border border-line p-3"
                        >
                            <div className="flex items-start justify-between gap-2">
                                <div>
                                    <div className="font-semibold text-ink">{tpl.name}</div>
                                    <div className="text-xs text-ink-muted">
                                        {(tpl.category || 'utility').toUpperCase()} ·{' '}
                                        {tpl.language || 'en'} · {tpl.wa_status || 'draft'}
                                    </div>
                                </div>
                                <div className="flex gap-2">
                                    <button
                                        type="button"
                                        className="text-sm font-semibold text-signal"
                                        onClick={() => {
                                            setEditingTemplateId(tpl.id);
                                            templateForm.setData({
                                                name: tpl.name,
                                                body: tpl.body,
                                                category: tpl.category || 'utility',
                                                language: tpl.language || 'en',
                                                wa_status: tpl.wa_status || 'draft',
                                            });
                                        }}
                                    >
                                        Edit
                                    </button>
                                    <button
                                        type="button"
                                        className="text-sm font-semibold text-rose-600"
                                        onClick={async () => {
                                            const ok = await confirmAsk({
                                                title: 'Delete this template?',
                                                message: tpl.name
                                                    ? `“${tpl.name}” will be removed.`
                                                    : 'This WhatsApp template will be removed.',
                                                confirmLabel: 'Delete',
                                            });
                                            if (ok) {
                                                router.delete(
                                                    route('whatsapp.templates.destroy', tpl.id),
                                                );
                                            }
                                        }}
                                    >
                                        Delete
                                    </button>
                                </div>
                            </div>
                            <p className="mt-2 whitespace-pre-wrap text-sm text-ink-muted">
                                {tpl.body}
                            </p>
                        </div>
                    ))
                )}
            </div>
        </section>
    );
}

function CampaignsView({ campaigns }) {
    return (
        <section className="atlas-panel overflow-hidden">
            <div className="flex items-center justify-between border-b border-line p-3">
                <div className="font-display text-lg font-bold text-ink">WhatsApp campaigns</div>
                <Link
                    href={route('channels.index')}
                    className="text-sm font-semibold text-signal"
                >
                    Compose in Channels →
                </Link>
            </div>
            {campaigns.length === 0 ? (
                <p className="p-4 text-sm text-ink-muted">
                    No WhatsApp campaigns yet. Create one from Channels with channel = WhatsApp.
                </p>
            ) : (
                <ul className="divide-y divide-line">
                    {campaigns.map((c) => (
                        <li key={c.id} className="flex flex-wrap items-center justify-between gap-2 p-3">
                            <div>
                                <div className="font-semibold text-ink">{c.name}</div>
                                <div className="text-xs text-ink-muted">
                                    {c.status} · {c.provider} · {c.sent_count || 0} sent /{' '}
                                    {c.recipient_count || 0} recipients
                                </div>
                            </div>
                            <div className="max-w-md truncate text-sm text-ink-muted">{c.body}</div>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

function SetupView({ metaSetup, workspace }) {
    const fields = metaSetup?.fields || [];
    const secretsSet = metaSetup?.secrets_set || {};
    const brandProfile = metaSetup?.brand_profile || {};
    const canManageMeta = !!metaSetup?.can_manage_meta;
    const onboardingStatus = metaSetup?.onboarding_status || 'not_started';

    const initial = useMemo(() => {
        const credentials = { ...(metaSetup?.values || {}) };
        for (const field of fields) {
            if (field.secret) {
                credentials[field.key] = '';
            } else if (credentials[field.key] === undefined) {
                credentials[field.key] = field.type === 'select' ? field.options?.[0]?.value || '' : '';
            }
        }
        return {
            enabled: true,
            credentials,
        };
    }, [metaSetup, fields]);

    const form = useForm(initial);

    const businessKeys = [
        'business_display_name',
        'business_phone',
        'business_category',
        'business_email',
        'business_website',
        'business_address',
        'business_country',
        'business_about',
    ];
    const metaKeys = [
        'phone_number_id',
        'waba_id',
        'access_token',
        'app_secret',
        'verify_token',
        'api_version',
    ];

    const byKey = useMemo(() => {
        const map = {};
        for (const f of fields) map[f.key] = f;
        return map;
    }, [fields]);

    const categoryLabel = (value) => {
        const opts = byKey.business_category?.options || [];
        return opts.find((o) => o.value === value)?.label || value || '—';
    };

    const statusLabel = metaSetup?.connected
        ? 'Connected'
        : onboardingStatus === 'pending_platform' || onboardingStatus === 'pending'
          ? 'Pending RankwayAI'
          : 'Not started';

    const statusClass = metaSetup?.connected
        ? 'bg-emerald-100 text-emerald-800'
        : onboardingStatus === 'pending_platform' || onboardingStatus === 'pending'
          ? 'bg-sky-100 text-sky-900'
          : 'bg-amber-100 text-amber-900';

    const setCredential = (key, value) => {
        form.setData('credentials', {
            ...form.data.credentials,
            [key]: value,
        });
    };

    const copyBrandToWaba = (key) => {
        const brandVal = brandProfile[key];
        if (brandVal === undefined || brandVal === null || brandVal === '') return;
        setCredential(key, brandVal);
    };

    const copyAllBrandToWaba = () => {
        const next = { ...form.data.credentials };
        for (const key of businessKeys) {
            if (brandProfile[key]) next[key] = brandProfile[key];
        }
        form.setData('credentials', next);
    };

    const save = (e) => {
        e.preventDefault();
        const payload = {
            enabled: form.data.enabled,
            credentials: {},
        };
        for (const key of businessKeys) {
            payload.credentials[key] = form.data.credentials[key] ?? '';
        }
        if (canManageMeta) {
            for (const key of metaKeys) {
                payload.credentials[key] = form.data.credentials[key] ?? '';
            }
        }
        form.transform(() => payload).put(route('whatsapp.setup'), {
            preserveScroll: true,
            onSuccess: () =>
                toast.success(
                    canManageMeta
                        ? 'WhatsApp setup saved'
                        : 'Details submitted — RankwayAI will connect Meta'
                ),
        });
    };

    const renderMetaField = (key) => {
        const field = byKey[key];
        if (!field) return null;
        const err = form.errors[`credentials.${key}`];

        return (
            <div key={key}>
                <InputLabel
                    value={
                        field.label +
                        (field.secret && secretsSet[key] ? ' (saved — leave blank to keep)' : '')
                    }
                />
                <TextInput
                    className="mt-1 w-full"
                    type={field.secret ? 'password' : 'text'}
                    value={form.data.credentials[key] || ''}
                    placeholder={field.placeholder || ''}
                    autoComplete="off"
                    onChange={(e) => setCredential(key, e.target.value)}
                />
                {err ? <p className="mt-1 text-xs text-rose-600">{err}</p> : null}
            </div>
        );
    };

    const renderDualField = (key) => {
        const field = byKey[key];
        if (!field) return null;
        const err = form.errors[`credentials.${key}`];
        const brandVal = brandProfile[key] || '';
        const wabaVal = form.data.credentials[key] || '';
        const differs = brandVal !== '' && wabaVal !== '' && brandVal !== wabaVal;
        const brandDisplay =
            key === 'business_category' ? categoryLabel(brandVal) : brandVal || 'Not set on Brand';

        return (
            <div
                key={key}
                className={
                    'rounded-lg border px-3 py-3 ' +
                    (differs ? 'border-amber-200 bg-amber-50/40' : 'border-line bg-white')
                }
            >
                <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                    <div className="text-sm font-semibold text-ink">{field.label}</div>
                    {differs ? (
                        <span className="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-amber-900">
                            Differs from Brand
                        </span>
                    ) : null}
                </div>

                {/* One row: Brand | WhatsApp — labels + controls stay aligned */}
                <div className="grid grid-cols-1 items-end gap-3 sm:grid-cols-2">
                    <div className="min-w-0">
                        <div className="flex h-7 items-center justify-between gap-2">
                            <div className="text-[10px] font-bold uppercase tracking-wide text-ink-muted">
                                Brand (RankwayAI)
                            </div>
                            {brandVal ? (
                                <button
                                    type="button"
                                    className="shrink-0 text-[11px] font-semibold text-ink-muted underline hover:text-ink"
                                    onClick={() => copyBrandToWaba(key)}
                                >
                                    Use on WhatsApp
                                </button>
                            ) : null}
                        </div>
                        <div className="flex h-10 items-center rounded-md border border-dashed border-line bg-mist/40 px-3 text-sm text-ink">
                            <span className="truncate">{brandDisplay}</span>
                        </div>
                    </div>

                    <div className="min-w-0">
                        <div className="flex h-7 items-center gap-1">
                            <div className="text-[10px] font-bold uppercase tracking-wide text-ink-muted">
                                WhatsApp / WABA
                            </div>
                            {key === 'business_phone' ? (
                                <HelpGuide help={HELP.whatsapp_setup} />
                            ) : null}
                        </div>
                        {field.type === 'select' ? (
                            <SelectMenu
                                className="w-full"
                                value={wabaVal}
                                onChange={(value) => setCredential(key, value)}
                                options={(field.options || []).map((o) => ({
                                    value: o.value,
                                    label: o.label,
                                }))}
                            />
                        ) : (
                            <TextInput
                                className="h-10 w-full"
                                type="text"
                                value={wabaVal}
                                placeholder={field.placeholder || ''}
                                autoComplete="off"
                                onChange={(e) => setCredential(key, e.target.value)}
                            />
                        )}
                        {err ? <p className="mt-1 text-xs text-rose-600">{err}</p> : null}
                    </div>
                </div>
            </div>
        );
    };

    return (
        <form onSubmit={save} className="space-y-3">
            <section className="atlas-panel space-y-3 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div className="flex items-center gap-1.5">
                            <h3 className="font-display text-lg font-bold text-ink">
                                WhatsApp Business setup
                            </h3>
                        </div>
                        <p className="mt-1 text-sm text-ink-muted">
                            {canManageMeta
                                ? 'Platform: add Meta Cloud API credentials after the client submits their number and business profile.'
                                : 'Brand details stay on RankwayAI. WhatsApp / WABA can use the same or different values — Meta is created by RankwayAI.'}
                        </p>
                    </div>
                    <span
                        className={
                            'rounded-md px-2 py-1 text-[10px] font-bold uppercase tracking-wide ' +
                            statusClass
                        }
                    >
                        {statusLabel}
                    </span>
                </div>

                {!metaSetup?.connected &&
                (onboardingStatus === 'pending_platform' || onboardingStatus === 'pending') ? (
                    <div className="rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-sm text-sky-950">
                        Your details are with RankwayAI. We will connect Meta WhatsApp for{' '}
                        <strong>{metaSetup?.business_phone || 'this number'}</strong> and notify you
                        when messaging goes live.
                    </div>
                ) : null}
            </section>

            <section className="atlas-panel space-y-3 p-4">
                <div className="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <h4 className="text-sm font-bold text-ink">Brand vs WhatsApp profile</h4>
                        <p className="mt-1 text-xs text-ink-muted">
                            Left = already stored on Brand. Right = what goes on the WhatsApp /
                            WABA profile (can differ).
                        </p>
                    </div>
                    <button
                        type="button"
                        className="text-xs font-semibold text-ink-muted underline hover:text-ink"
                        onClick={copyAllBrandToWaba}
                    >
                        Copy all Brand → WhatsApp
                    </button>
                </div>
                <div className="space-y-3">{businessKeys.map(renderDualField)}</div>
            </section>

            {canManageMeta ? (
                <>
                    <section className="atlas-panel space-y-3 p-4">
                        <h4 className="text-sm font-bold text-ink">Meta Cloud API (platform)</h4>
                        <p className="text-xs text-ink-muted">
                            RankwayAI-only: Phone number ID, WABA, access token, and webhook verify
                            token after you create the Meta assets for this client.
                        </p>
                        <div className="rounded-lg border border-line bg-mist/50 px-3 py-2 text-sm">
                            <div className="text-[10px] font-bold uppercase tracking-wide text-ink-muted">
                                Meta webhook callback URL
                            </div>
                            <code className="mt-1 block break-all text-xs text-ink">
                                {metaSetup?.webhook_url ||
                                    `${window.location.origin}/webhooks/meta/whatsapp/${workspace.id}`}
                            </code>
                        </div>
                        <div className="grid gap-3 sm:grid-cols-2">{metaKeys.map(renderMetaField)}</div>
                    </section>

                    <section className="flex flex-wrap items-center justify-between gap-3">
                        <label className="inline-flex items-center gap-2 text-sm font-semibold text-ink">
                            <Toggle
                                checked={!!form.data.enabled}
                                onChange={(enabled) => form.setData('enabled', enabled)}
                            />
                            Enable WhatsApp for this workspace
                        </label>
                        <PrimaryButton type="submit" disabled={form.processing}>
                            {form.processing ? 'Saving…' : 'Save Meta connection'}
                        </PrimaryButton>
                    </section>
                </>
            ) : (
                <section className="flex justify-end">
                    <PrimaryButton type="submit" disabled={form.processing}>
                        {form.processing ? 'Submitting…' : 'Submit for Meta setup'}
                    </PrimaryButton>
                </section>
            )}
        </form>
    );
}

function PlaceholderRow({ placeholders, onInsert }) {
    if (!placeholders?.length) return null;
    return (
        <div className="mt-1 flex flex-wrap gap-1">
            {placeholders.map((p) => (
                <button
                    key={p.token}
                    type="button"
                    className="rounded bg-mist px-1.5 py-0.5 text-[10px] font-semibold text-ink-muted hover:text-ink"
                    onClick={() => onInsert(p.token)}
                >
                    {p.token}
                </button>
            ))}
        </div>
    );
}
