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
