import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import DateTimePicker from '@/Components/DateTimePicker';
import HelpGuide, { HELP } from '@/Components/HelpGuide';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectMenu from '@/Components/SelectMenu';
import TextInput from '@/Components/TextInput';
import { toast } from '@/Components/ToastProvider';
import { confirmAsk } from '@/Components/ConfirmProvider';
import Toggle from '@/Components/Toggle';
import EmbeddedSignupButton from '@/Components/WhatsApp/EmbeddedSignupButton';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';

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

const headerOptions = [
    { value: 'NONE', label: 'No header' },
    { value: 'TEXT', label: 'Text header' },
    { value: 'IMAGE', label: 'Image header' },
    { value: 'DOCUMENT', label: 'Document header (PDF)' },
];

const buttonTypeOptions = [
    { value: 'QUICK_REPLY', label: 'Quick reply' },
    { value: 'URL', label: 'Open link / download attachment' },
    { value: 'PHONE_NUMBER', label: 'Call phone number' },
];

function waQuery(view, extra = {}) {
    return route('whatsapp.index', { view, ...extra });
}

function insertAtCursor(textarea, value, token, onChange) {
    const start = textarea?.selectionStart ?? value.length;
    const end = textarea?.selectionEnd ?? start;
    const nextValue = `${value.slice(0, start)}${token}${value.slice(end)}`;
    onChange(nextValue);

    requestAnimationFrame(() => {
        textarea?.focus();
        textarea?.setSelectionRange(start + token.length, start + token.length);
    });
}

export default function Index({
    workspace,
    view = 'conversations',
    provider = 'none',
    plan = null,
    meta_setup = null,
    conversations = [],
    inbox = 'all',
    inboxFilters = {},
    inboxTotal = 0,
    currentUserId = null,
    seesAllConversations = false,
    teamMembers = [],
    activeConversation = null,
    messages = [],
    templates = [],
    campaigns = [],
    leads = [],
    placeholders = [],
    leadGroups = [],
    importedGroupId = 0,
    leadImportFields = [],
    audienceOptions = null,
    counts = {},
}) {
    const sendLocked = plan && !plan.features?.channel_send;
    const notConnected = provider === 'none';
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
        language: 'en_US',
        wa_status: 'draft',
        submit_to_meta: provider === 'meta',
        header_format: 'NONE',
        header_text: '',
        header_media: null,
        footer: 'Reply STOP to opt out',
        buttons: [],
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
            ...templates.map((t) => {
                const status = String(t.wa_status || 'draft').toLowerCase();
                const suffix =
                    status === 'approved' || status === 'ready'
                        ? ''
                        : status === 'pending'
                          ? ' (pending Meta)'
                          : status === 'rejected'
                            ? ' (rejected)'
                            : ' (draft)';
                return {
                    value: String(t.id),
                    label: `${t.name}${suffix}`,
                };
            }),
        ],
        [templates],
    );

    const setView = (next) => {
        router.get(waQuery(next), {}, { preserveState: true, preserveScroll: true });
    };

    const inboxParams = (overrides = {}) => {
        const merged = {
            inbox,
            member: inboxFilters.member || '',
            campaign: inboxFilters.campaign || '',
            q: inboxFilters.q || '',
            ...overrides,
        };
        if (merged.inbox === 'all') merged.inbox = '';
        return Object.fromEntries(Object.entries(merged).filter(([, value]) => value !== '' && value != null));
    };

    const openConversation = (id) => {
        router.get(
            waQuery('conversations', { ...inboxParams(), conversation: id }),
            {},
            { preserveState: true, preserveScroll: true },
        );
    };

    const updateInbox = (overrides) => {
        router.get(
            waQuery('conversations', {
                ...inboxParams(overrides),
                ...(activeConversation ? { conversation: activeConversation.id } : {}),
            }),
            {},
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const setInbox = (next) => updateInbox({ inbox: next });

    const applyTemplateToReply = (id) => {
        const tpl = templates.find((t) => String(t.id) === String(id));
        replyForm.setData({
            ...replyForm.data,
            template_id: id,
            as_template: !!tpl,
            body: tpl?.body || replyForm.data.body,
        });
    };

    const resetTemplateForm = () => {
        setEditingTemplateId(null);
        templateForm.setData({
            name: '',
            body: '',
            category: 'utility',
            language: 'en_US',
            wa_status: 'draft',
            submit_to_meta: provider === 'meta',
            header_format: 'NONE',
            header_text: '',
            header_media: null,
            footer: 'Reply STOP to opt out',
            buttons: [],
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
            <div className="atlas-shell space-y-4">
                <section className="atlas-panel relative overflow-hidden border border-emerald-100 bg-gradient-to-br from-white via-white to-emerald-50/80 shadow-sm">
                    <div className="h-1 bg-gradient-to-r from-emerald-400 via-teal-400 to-sky-400" />
                    <div className="flex flex-col justify-between gap-5 p-5 sm:p-6 xl:flex-row xl:items-center">
                        <div className="min-w-0">
                            <div className="mb-3 flex flex-wrap items-center gap-2.5">
                                <span
                                    className={
                                        'inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-xs font-bold ' +
                                        (notConnected
                                            ? 'bg-amber-100 text-amber-900 ring-1 ring-inset ring-amber-200'
                                            : 'bg-emerald-100 text-emerald-900 ring-1 ring-inset ring-emerald-200')
                                    }
                                >
                                    <span
                                        className={
                                            'h-2 w-2 rounded-full ' +
                                            (notConnected ? 'bg-amber-500' : 'bg-emerald-500')
                                        }
                                    />
                                    {notConnected ? 'Not connected' : 'Connected'}
                                </span>
                                <span className="text-xs font-semibold text-slate-500">
                                    {provider === 'meta'
                                        ? 'Meta Cloud API'
                                        : provider === 'zavu'
                                          ? 'Zavu'
                                          : 'WhatsApp Business'}
                                </span>
                            </div>
                            <h1 className="font-display text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">
                                WhatsApp inbox
                            </h1>
                            <p className="mt-1 max-w-xl text-sm leading-6 text-slate-600">
                                Your conversations, templates, and campaigns in one place.
                            </p>
                            {meta_setup?.verified_phone || meta_setup?.verified_name ? (
                                <p className="mt-4 inline-flex max-w-full items-center gap-2 rounded-xl border border-emerald-100 bg-white px-3 py-2 text-xs text-slate-500 shadow-sm">
                                    <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" className="h-4 w-4 shrink-0 text-emerald-600">
                                        <path d="M5 4h3l2 5-2 1.5a14 14 0 0 0 5.5 5.5L15 14l5 2v3a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2Z" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round" />
                                    </svg>
                                    <span className="font-semibold text-slate-800">
                                        {[meta_setup.verified_name, meta_setup.verified_phone]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </span>
                                </p>
                            ) : null}
                        </div>
                        <div className="grid grid-cols-2 gap-2 sm:grid-cols-4 xl:min-w-[448px]">
                            <Stat label="Unread" value={counts.unread || 0} />
                            <Stat label="Chats" value={counts.conversations || 0} />
                            <Stat label="Templates" value={counts.templates || 0} />
                            <Stat label="Campaigns" value={counts.campaigns || 0} />
                        </div>
                    </div>
                </section>

                {notConnected ? (
                    <section className="flex flex-col gap-4 rounded-xl border border-amber-200 bg-amber-50/80 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5">
                        <div className="flex gap-3">
                            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-amber-100 text-amber-700">
                                <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" className="h-5 w-5">
                                    <path d="M12 8v4m0 4h.01M10.3 3.9 2.8 17a2 2 0 0 0 1.7 3h15a2 2 0 0 0 1.7-3l-7.5-13.1a2 2 0 0 0-3.4 0Z" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" />
                                </svg>
                            </div>
                            <div>
                                <p className="text-sm font-semibold text-amber-950">
                                    Connect your WhatsApp Business number
                                </p>
                                <p className="mt-1 max-w-3xl text-sm leading-5 text-amber-900/80">
                                    Messages won’t be sent until setup is complete. Connect your
                                    number to start replying to customers and sending templates.
                                </p>
                            </div>
                        </div>
                        <button
                            type="button"
                            className="inline-flex shrink-0 items-center justify-center rounded-lg bg-amber-900 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-amber-800 focus:outline-none focus:ring-2 focus:ring-amber-700 focus:ring-offset-2"
                            onClick={() => setView('setup')}
                        >
                            Connect WhatsApp
                        </button>
                    </section>
                ) : null}

                {sendLocked ? (
                    <section className="atlas-panel border border-amber-200 bg-amber-50/80 p-4">
                        <p className="text-sm leading-6 text-amber-900">
                            Sending is locked on the free plan. You can still draft templates and
                            review conversations.{' '}
                            <Link href={route('billing.index')} className="font-semibold underline">
                                Upgrade
                            </Link>
                        </p>
                    </section>
                ) : null}

                <section className="flex flex-wrap gap-1 rounded-lg border border-line bg-white p-1 shadow-sm">
                    {TABS.map((tab) => {
                        const active = view === tab.id;
                        return (
                            <button
                                key={tab.id}
                                type="button"
                                onClick={() => setView(tab.id)}
                                className={
                                    'rounded-md border px-3 py-1.5 text-xs font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-signal/30 ' +
                                    (active
                                        ? 'border-signal bg-signal text-white shadow-sm'
                                        : 'border-line bg-mist/60 text-ink-soft hover:border-signal/40 hover:bg-signal-soft/50 hover:text-signal-strong')
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
                        provider={provider}
                        inbox={inbox}
                        setInbox={setInbox}
                        updateInbox={updateInbox}
                        inboxFilters={inboxFilters}
                        inboxTotal={inboxTotal}
                        campaigns={campaigns}
                        teamMembers={teamMembers}
                        currentUserId={currentUserId}
                        seesAllConversations={seesAllConversations}
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
                        provider={provider}
                    />
                ) : null}

                {view === 'campaigns' ? (
                    <CampaignsView
                        campaigns={campaigns}
                        templates={templates.filter((template) => template.wa_status === 'approved')}
                        leads={leads.filter((lead) =>
                            ['new', 'contacted', 'qualified'].includes(lead.stage),
                        )}
                        provider={provider}
                        sendLocked={sendLocked}
                        leadGroups={leadGroups}
                        importedGroupId={importedGroupId}
                        leadImportFields={leadImportFields}
                        audienceOptions={audienceOptions}
                    />
                ) : null}
            </div>
        </AuthenticatedLayout>
    );
}

function Stat({ label, value }) {
    const tones = {
        Unread: 'border-sky-100 bg-sky-50/80 text-sky-700',
        Chats: 'border-emerald-100 bg-emerald-50/80 text-emerald-700',
        Templates: 'border-violet-100 bg-violet-50/80 text-violet-700',
        Campaigns: 'border-orange-100 bg-orange-50/80 text-orange-700',
    };

    return (
        <div
            className={
                'rounded-xl border px-3 py-3 shadow-sm transition-transform duration-200 hover:-translate-y-0.5 ' +
                (tones[label] || 'border-line bg-white text-ink-muted')
            }
        >
            <div className="font-display text-2xl font-bold leading-7 text-slate-900">{value}</div>
            <div className="mt-1.5 flex items-center gap-1.5 text-xs font-semibold">
                <span className="h-1.5 w-1.5 rounded-full bg-current opacity-70" />
                {label}
            </div>
        </div>
    );
}

const AVATAR_TONES = [
    'bg-sky-100 text-sky-700',
    'bg-violet-100 text-violet-700',
    'bg-amber-100 text-amber-700',
    'bg-rose-100 text-rose-700',
    'bg-emerald-100 text-emerald-700',
    'bg-indigo-100 text-indigo-700',
];

function initials(name) {
    const parts = String(name || '')
        .replace(/[^\p{L}\p{N}\s]/gu, ' ')
        .trim()
        .split(/\s+/)
        .filter(Boolean);
    if (parts.length === 0) return '#';
    return (parts[0][0] + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toUpperCase();
}

function Avatar({ name, seed = 0, size = 'h-10 w-10 text-sm' }) {
    return (
        <span
            className={`flex shrink-0 items-center justify-center rounded-full font-semibold ${size} ${
                AVATAR_TONES[Math.abs(Number(seed) || 0) % AVATAR_TONES.length]
            }`}
        >
            {initials(name)}
        </span>
    );
}

const todayLabel = () =>
    new Date().toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });

function splitStamp(stamp) {
    if (!stamp) return { date: '', time: '' };
    const [date, time = ''] = String(stamp).split(', ');
    return { date, time };
}

function shortStamp(stamp) {
    const { date, time } = splitStamp(stamp);
    if (!date) return '';
    return date === todayLabel() ? time : date.replace(/ \d{4}$/, '');
}

function StatusTicks({ status }) {
    if (status === 'failed') {
        return <span className="font-semibold text-danger">Failed</span>;
    }
    if (status === 'received') return null;
    const double = status === 'delivered' || status === 'read';
    return (
        <span
            title={status}
            className={'inline-flex items-center ' + (status === 'read' ? 'text-sky-500' : 'text-ink-muted/70')}
        >
            <svg viewBox="0 0 16 11" className="h-3 w-4" fill="none" stroke="currentColor" strokeWidth="1.6" aria-hidden>
                <path d="M1 6l3 3 6-7" strokeLinecap="round" strokeLinejoin="round" />
                {double ? <path d="M6 9l1 0.5L14 2" strokeLinecap="round" strokeLinejoin="round" /> : null}
            </svg>
        </span>
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
    provider = 'none',
    inbox = 'all',
    setInbox,
    updateInbox,
    inboxFilters = {},
    inboxTotal = 0,
    campaigns = [],
    teamMembers = [],
    currentUserId = null,
    seesAllConversations = false,
}) {
    const startBodyRef = useRef(null);
    const threadRef = useRef(null);
    const [search, setSearch] = useState(inboxFilters.q || '');
    const searchReady = useRef(false);

    useEffect(() => {
        if (!searchReady.current) {
            searchReady.current = true;
            return undefined;
        }
        const timer = setTimeout(() => updateInbox({ q: search.trim() }), 350);
        return () => clearTimeout(timer);
    }, [search]);
    const blockIfNotConnected = () => {
        if (provider !== 'none') return false;
        toast.error('Connect your WhatsApp number in Setup first. No message is sent until then.');
        router.get(waQuery('setup'), {}, { preserveScroll: true });
        return true;
    };

    useEffect(() => {
        if (threadRef.current) {
            threadRef.current.scrollTop = threadRef.current.scrollHeight;
        }
    }, [messages, activeConversation?.id]);

    const visibleConversations = conversations;
    const memberFilterOptions = useMemo(
        () => [
            { value: '', label: 'All members' },
            ...teamMembers.map((member) => ({
                value: String(member.id),
                label: member.id === currentUserId ? `${member.name} (me)` : member.name,
            })),
        ],
        [teamMembers, currentUserId],
    );
    const campaignFilterOptions = useMemo(
        () => [
            { value: '', label: 'All campaigns' },
            ...campaigns.map((campaign) => ({ value: String(campaign.id), label: campaign.name })),
        ],
        [campaigns],
    );

    const assigneeOptions = useMemo(
        () => [
            { value: '', label: 'Unassigned' },
            ...teamMembers.map((member) => ({
                value: String(member.id),
                label: member.id === currentUserId ? `${member.name} (me)` : member.name,
            })),
        ],
        [teamMembers, currentUserId],
    );

    const submitReply = () => {
        if (sendLocked) {
            toast.error('Upgrade to send WhatsApp messages.');
            return;
        }
        if (blockIfNotConnected()) return;
        replyForm.post(route('whatsapp.conversations.reply', activeConversation.id), {
            preserveScroll: true,
            onSuccess: () => replyForm.setData('body', ''),
        });
    };

    let lastDate = null;

    return (
        <section className="grid gap-4 lg:grid-cols-[340px_minmax(0,1fr)]">
            <div className="atlas-panel flex h-[calc(100vh-300px)] min-h-[480px] flex-col overflow-hidden rounded-xl shadow-sm">
                <div className="space-y-3 border-b border-line bg-white p-3">
                    <div className="flex items-center justify-between">
                        <div className="flex items-baseline gap-2">
                            <span className="font-display text-base font-bold text-ink">Inbox</span>
                            <span className="text-xs text-ink-muted">{(inboxTotal || conversations.length).toLocaleString()}</span>
                        </div>
                        <PrimaryButton type="button" className="!px-2.5 !py-1 !text-xs" onClick={() => setComposing(true)}>
                            ＋ New chat
                        </PrimaryButton>
                    </div>
                    <div className="grid grid-cols-3 gap-1 rounded-lg bg-mist p-0.5">
                        {[
                            { id: 'all', label: 'All' },
                            { id: 'mine', label: 'My chats' },
                            { id: 'unassigned', label: 'Unassigned' },
                        ].map((tab) => (
                            <button
                                key={tab.id}
                                type="button"
                                onClick={() => setInbox(tab.id)}
                                className={
                                    'rounded-md px-2 py-1 text-xs font-semibold transition ' +
                                    (inbox === tab.id ? 'bg-white text-ink shadow-sm' : 'text-ink-muted hover:text-ink')
                                }
                            >
                                {tab.label}
                            </button>
                        ))}
                    </div>
                    {seesAllConversations ? (
                        <div className="grid grid-cols-2 gap-1.5">
                            <SelectMenu
                                buttonClassName="!py-1 !pl-2.5 !text-xs"
                                value={String(inboxFilters.member || '')}
                                onChange={(value) => updateInbox({ member: value })}
                                options={memberFilterOptions}
                            />
                            <SelectMenu
                                buttonClassName="!py-1 !pl-2.5 !text-xs"
                                value={String(inboxFilters.campaign || '')}
                                onChange={(value) => updateInbox({ campaign: value })}
                                options={campaignFilterOptions}
                            />
                        </div>
                    ) : null}
                    <label className="relative block">
                        <span className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-2.5 text-ink-muted">
                            <svg viewBox="0 0 20 20" fill="currentColor" className="h-3.5 w-3.5" aria-hidden>
                                <path fillRule="evenodd" d="M9 3.5a5.5 5.5 0 100 11 5.5 5.5 0 000-11zM2 9a7 7 0 1112.45 4.39l3.08 3.08a.75.75 0 11-1.06 1.06l-3.08-3.08A7 7 0 012 9z" clipRule="evenodd" />
                            </svg>
                        </span>
                        <input
                            type="search"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Search name, number or message"
                            className="w-full rounded-md border border-line bg-white py-1.5 pl-8 pr-3 text-xs text-ink placeholder:text-ink-muted/70 focus:border-signal focus:ring-2 focus:ring-signal/20"
                        />
                    </label>
                </div>
                <div className="flex-1 overflow-y-auto">
                    {visibleConversations.length === 0 ? (
                        <div className="flex h-full flex-col items-center justify-center px-6 py-10 text-center">
                            <p className="text-sm font-semibold text-ink">
                                {search
                                    ? 'No matching chats'
                                    : inbox === 'mine'
                                      ? 'No chats assigned to you'
                                      : inbox === 'unassigned'
                                        ? 'Every chat has an owner'
                                        : 'Your inbox is ready'}
                            </p>
                            <p className="mt-1 text-xs text-ink-muted">
                                {inbox === 'all' && !search
                                    ? 'Start a chat or send a campaign — replies land here.'
                                    : 'Try another filter.'}
                            </p>
                        </div>
                    ) : (
                        visibleConversations.map((c) => {
                            const active = activeConversation?.id === c.id;
                            const name = c.contact_name || c.phone;
                            return (
                                <button
                                    key={c.id}
                                    type="button"
                                    onClick={() => openConversation(c.id)}
                                    className={
                                        'flex w-full items-center gap-2.5 border-b border-line/70 border-l-2 px-3 py-2 text-left transition ' +
                                        (active
                                            ? 'border-l-signal bg-signal-soft/40'
                                            : 'border-l-transparent hover:bg-mist/70')
                                    }
                                >
                                    <Avatar name={name} seed={c.id} size="h-8 w-8 text-xs" />
                                    <div className="min-w-0 flex-1">
                                        <div className="flex items-center gap-1.5">
                                            <span className={`truncate text-sm text-ink ${c.unread_count > 0 ? 'font-bold' : 'font-semibold'}`}>
                                                {name}
                                            </span>
                                            {c.opted_out ? (
                                                <span className="shrink-0 rounded bg-danger-soft px-1 text-[10px] font-medium text-danger">Opted out</span>
                                            ) : c.window_open ? (
                                                <span className="h-1.5 w-1.5 shrink-0 rounded-full bg-emerald-500" title="24h window open" />
                                            ) : null}
                                            {c.assigned_user_name ? (
                                                <span
                                                    className="max-w-[90px] shrink-0 truncate rounded bg-mist px-1 text-[10px] font-medium text-ink-muted"
                                                    title={`Assigned to ${c.assigned_user_name}`}
                                                >
                                                    {c.assigned_user_id === currentUserId ? 'You' : c.assigned_user_name}
                                                </span>
                                            ) : null}
                                            <span className={`ml-auto shrink-0 text-[11px] ${c.unread_count > 0 ? 'font-semibold text-signal' : 'text-ink-muted'}`}>
                                                {shortStamp(c.last_message_at)}
                                            </span>
                                        </div>
                                        <div className="flex items-center justify-between gap-2">
                                            <span className="truncate text-xs text-ink-muted">
                                                {c.last_message_preview || c.phone}
                                            </span>
                                            {c.unread_count > 0 ? (
                                                <span className="flex h-4 min-w-4 shrink-0 items-center justify-center rounded-full bg-signal px-1 text-[10px] font-bold text-white">
                                                    {c.unread_count}
                                                </span>
                                            ) : null}
                                        </div>
                                    </div>
                                </button>
                            );
                        })
                    )}
                    {inboxTotal > visibleConversations.length ? (
                        <p className="px-3 py-3 text-center text-[11px] text-ink-muted">
                            Showing latest {visibleConversations.length} of {inboxTotal.toLocaleString()} — search or filter to find others.
                        </p>
                    ) : null}
                </div>
            </div>

            <div className="atlas-panel flex h-[calc(100vh-300px)] min-h-[480px] flex-col overflow-hidden rounded-xl shadow-sm">
                {composing ? (
                    <form
                        className="flex flex-1 flex-col overflow-y-auto"
                        onSubmit={(e) => {
                            e.preventDefault();
                            if (sendLocked) {
                                toast.error('Upgrade to send WhatsApp messages.');
                                return;
                            }
                            if (blockIfNotConnected()) return;
                            startForm.post(route('whatsapp.conversations.start'), {
                                preserveScroll: true,
                                onSuccess: () => {
                                    setComposing(false);
                                    startForm.reset();
                                },
                            });
                        }}
                    >
                        <div className="flex items-center justify-between border-b border-line px-4 py-3">
                            <span className="font-display text-base font-bold text-ink">New chat</span>
                            <button
                                type="button"
                                aria-label="Cancel"
                                onClick={() => setComposing(false)}
                                className="rounded-md px-2 py-1 text-lg leading-none text-ink-muted hover:bg-mist"
                            >
                                ×
                            </button>
                        </div>
                        <div className="space-y-3 p-4">
                            <div className="grid gap-3 sm:grid-cols-2">
                                <div>
                                    <InputLabel value="Lead" />
                                    <div className="mt-1">
                                        <SelectMenu
                                            value={String(startForm.data.crm_lead_id || '')}
                                            onChange={(v) => startForm.setData('crm_lead_id', v)}
                                            options={leadOptions}
                                            buttonClassName="!py-1.5"
                                        />
                                    </div>
                                </div>
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
                                                    as_template: !!tpl,
                                                    body: tpl?.body || startForm.data.body,
                                                });
                                            }}
                                            options={templateOptions}
                                            buttonClassName="!py-1.5"
                                        />
                                    </div>
                                </div>
                            </div>
                            {!startForm.data.crm_lead_id ? (
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <div>
                                        <InputLabel value="Phone (with country code)" />
                                        <TextInput
                                            className="mt-1 w-full !py-1.5 text-sm"
                                            value={startForm.data.phone}
                                            onChange={(e) => startForm.setData('phone', e.target.value)}
                                            placeholder="+9198…"
                                        />
                                    </div>
                                    <div>
                                        <InputLabel value="Contact name" />
                                        <TextInput
                                            className="mt-1 w-full !py-1.5 text-sm"
                                            value={startForm.data.contact_name}
                                            onChange={(e) => startForm.setData('contact_name', e.target.value)}
                                        />
                                    </div>
                                </div>
                            ) : null}
                            <div>
                                <InputLabel value="Message" />
                                <textarea
                                    ref={startBodyRef}
                                    className="mt-1 w-full rounded-lg border-line text-sm"
                                    rows={5}
                                    value={startForm.data.body}
                                    onChange={(e) => startForm.setData('body', e.target.value)}
                                />
                                <PlaceholderRow
                                    placeholders={placeholders}
                                    onMouseDown={(event) => event.preventDefault()}
                                    onInsert={(token) =>
                                        insertAtCursor(
                                            startBodyRef.current,
                                            startForm.data.body,
                                            token,
                                            (value) => startForm.setData('body', value),
                                        )
                                    }
                                />
                            </div>
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <Toggle
                                    checked={!!startForm.data.as_template}
                                    onChange={(v) => startForm.setData('as_template', v)}
                                    label="Send as WhatsApp template"
                                />
                                <PrimaryButton className="!px-4 !py-1.5 !text-xs" processing={startForm.processing}>
                                    Send
                                </PrimaryButton>
                            </div>
                        </div>
                    </form>
                ) : !activeConversation ? (
                    <div className="flex flex-1 flex-col items-center justify-center bg-gradient-to-b from-white to-mist/50 p-8 text-center">
                        <div className="mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-signal-soft text-signal shadow-sm">
                            <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" className="h-8 w-8">
                                <path d="M20 11.5a8.5 8.5 0 0 1-12.6 7.4L3 20l1.1-4.1A8.5 8.5 0 1 1 20 11.5Z" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" />
                                <path d="M8.5 9.5c.4 2 2 3.6 4 4" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" />
                            </svg>
                        </div>
                        <h3 className="font-display text-base font-bold text-ink">
                            {conversations.length ? 'Pick a chat' : 'No chats yet'}
                        </h3>
                        <p className="mt-1 max-w-xs text-xs leading-5 text-ink-muted">
                            {conversations.length
                                ? 'Choose a conversation on the left to read and reply.'
                                : 'Start a chat or send a campaign — every recipient gets a thread here.'}
                        </p>
                        <PrimaryButton type="button" className="mt-4 !px-3 !py-1.5 !text-xs" onClick={() => setComposing(true)}>
                            ＋ New chat
                        </PrimaryButton>
                    </div>
                ) : (
                    <>
                        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-line bg-white px-4 py-2.5">
                            <div className="flex min-w-0 items-center gap-3">
                                <Avatar
                                    name={activeConversation.contact_name || activeConversation.phone}
                                    seed={activeConversation.id}
                                />
                                <div className="min-w-0">
                                    <div className="truncate text-sm font-bold text-ink">
                                        {activeConversation.contact_name || activeConversation.phone}
                                    </div>
                                    <div className="flex flex-wrap items-center gap-1.5 text-xs text-ink-muted">
                                        <span>{activeConversation.phone}</span>
                                        {activeConversation.opted_out ? (
                                            <span className="rounded bg-danger-soft px-1.5 py-0.5 text-[10px] font-semibold text-danger">
                                                Opted out
                                            </span>
                                        ) : activeConversation.window_open ? (
                                            <span
                                                className="rounded bg-emerald-50 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-700"
                                                title={`Free-form replies until ${activeConversation.window_expires_at}`}
                                            >
                                                24h window open
                                            </span>
                                        ) : (
                                            <span className="rounded bg-amber-50 px-1.5 py-0.5 text-[10px] font-semibold text-amber-700">
                                                Template only
                                            </span>
                                        )}
                                    </div>
                                </div>
                            </div>
                            <div className="flex items-center gap-2">
                                <span className="text-[11px] font-semibold uppercase tracking-wide text-ink-muted">Assigned</span>
                                <SelectMenu
                                    className="w-44"
                                    buttonClassName="!py-1 !text-xs"
                                    value={String(activeConversation.assigned_user_id || '')}
                                    onChange={async (value) => {
                                        const handingOff =
                                            !seesAllConversations && value && Number(value) !== currentUserId;
                                        if (handingOff) {
                                            const member = teamMembers.find((m) => String(m.id) === String(value));
                                            const confirmed = await confirmAsk({
                                                title: `Assign to ${member?.name || 'this member'}?`,
                                                message: 'The chat and its full history move to them. It will disappear from your inbox.',
                                                confirmLabel: 'Assign',
                                            });
                                            if (!confirmed) return;
                                        }
                                        router.post(
                                            route('whatsapp.conversations.assign', activeConversation.id),
                                            { user_id: value || null },
                                            { preserveScroll: true, preserveState: true },
                                        );
                                    }}
                                    options={assigneeOptions}
                                />
                                <SecondaryButton
                                    type="button"
                                    className="!px-2.5 !py-1 !text-xs"
                                    onClick={() =>
                                        router.post(route('whatsapp.conversations.close', activeConversation.id))
                                    }
                                >
                                    Close
                                </SecondaryButton>
                            </div>
                        </div>
                        <div
                            ref={threadRef}
                            className="flex-1 space-y-1.5 overflow-y-auto bg-mist/60 px-4 py-3"
                            style={{
                                backgroundImage: 'radial-gradient(rgba(11,18,32,0.05) 1px, transparent 1px)',
                                backgroundSize: '18px 18px',
                            }}
                        >
                            {messages.length === 0 ? (
                                <p className="py-10 text-center text-xs text-ink-muted">No messages yet.</p>
                            ) : null}
                            {messages.map((m) => {
                                const { date, time } = splitStamp(m.sent_at);
                                const showDate = date && date !== lastDate;
                                lastDate = date;
                                const outbound = m.direction === 'outbound';
                                const failed = m.status === 'failed';
                                const meta = (
                                    <>
                                        {outbound && m.user_name ? (
                                            <span className="font-semibold text-signal-strong">{m.user_name}</span>
                                        ) : null}
                                        {m.campaign_name ? <span>· {m.campaign_name}</span> : null}
                                        <span>{time}</span>
                                        {outbound ? <StatusTicks status={m.status} /> : null}
                                    </>
                                );
                                return (
                                    <div key={m.id}>
                                        {showDate ? (
                                            <div className="my-3 flex justify-center">
                                                <span className="rounded-full bg-white px-2.5 py-0.5 text-[10px] font-semibold text-ink-muted shadow-sm">
                                                    {date === todayLabel() ? 'Today' : date}
                                                </span>
                                            </div>
                                        ) : null}
                                        <div className={`flex ${outbound ? 'justify-end' : 'justify-start'}`}>
                                            <div
                                                title={m.template_name ? `Template: ${m.template_name}` : undefined}
                                                className={
                                                    'max-w-[78%] rounded-xl px-2.5 py-1.5 text-sm shadow-sm ' +
                                                    (outbound
                                                        ? failed
                                                            ? 'rounded-br-sm border border-danger/30 bg-danger-soft text-ink'
                                                            : 'rounded-br-sm border border-signal/15 bg-signal-soft text-ink'
                                                        : 'rounded-bl-sm border border-line bg-white text-ink')
                                                }
                                            >
                                                <div className="relative whitespace-pre-wrap break-words leading-snug">
                                                    {m.body}
                                                    <span aria-hidden className="invisible ml-3 inline-flex gap-1 text-[10px]">
                                                        {meta}
                                                    </span>
                                                    <span className="absolute -bottom-0.5 right-0 inline-flex items-center gap-1 whitespace-nowrap text-[10px] text-ink-muted">
                                                        {meta}
                                                    </span>
                                                </div>
                                                {m.error_message ? (
                                                    <div className="mt-1 text-xs text-danger">{m.error_message}</div>
                                                ) : null}
                                            </div>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                        <form
                            className="space-y-2 border-t border-line bg-white px-3 py-2.5"
                            onSubmit={(e) => {
                                e.preventDefault();
                                submitReply();
                            }}
                        >
                            {activeConversation.opted_out ? (
                                <p className="text-xs font-semibold text-danger">
                                    This contact asked to stop messages. Sending is paused until{' '}
                                    {activeConversation.opted_out_until}.
                                </p>
                            ) : !activeConversation.window_open ? (
                                <p className="text-[11px] text-amber-700">
                                    24h window is closed — pick an approved template to message this contact.
                                </p>
                            ) : null}
                            <div className="flex flex-wrap items-center gap-3">
                                <SelectMenu
                                    className="w-56"
                                    buttonClassName="!py-1 !text-xs"
                                    disabled={activeConversation.opted_out}
                                    value={String(replyForm.data.template_id || '')}
                                    onChange={applyTemplateToReply}
                                    options={templateOptions}
                                />
                                <Toggle
                                    disabled={activeConversation.opted_out}
                                    checked={!!replyForm.data.as_template}
                                    onChange={(v) => replyForm.setData('as_template', v)}
                                    label="Send as template"
                                />
                            </div>
                            <div className="flex items-end gap-2">
                                <textarea
                                    disabled={activeConversation.opted_out}
                                    className="max-h-40 min-h-[40px] flex-1 resize-y rounded-xl border-line text-sm focus:border-signal focus:ring-signal/20"
                                    rows={2}
                                    placeholder="Type a message…  (Ctrl/⌘ + Enter to send)"
                                    value={replyForm.data.body}
                                    onChange={(e) => replyForm.setData('body', e.target.value)}
                                    onKeyDown={(e) => {
                                        if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) {
                                            e.preventDefault();
                                            submitReply();
                                        }
                                    }}
                                />
                                <PrimaryButton
                                    aria-label="Send reply"
                                    className="!h-10 !w-10 !rounded-full !p-0"
                                    processing={replyForm.processing}
                                    disabled={activeConversation.opted_out}
                                >
                                    {replyForm.processing ? null : (
                                        <svg viewBox="0 0 20 20" fill="currentColor" className="h-4 w-4" aria-hidden>
                                            <path d="M3.105 2.29a.75.75 0 00-.826.95l1.414 4.925A1.5 1.5 0 005.135 9.25h6.115a.75.75 0 010 1.5H5.135a1.5 1.5 0 00-1.442 1.086l-1.414 4.926a.75.75 0 00.826.95 28.9 28.9 0 0015.293-7.155.75.75 0 000-1.114A28.9 28.9 0 003.105 2.29z" />
                                        </svg>
                                    )}
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
    provider = 'none',
}) {
    const bodyRef = useRef(null);
    const [templateQuery, setTemplateQuery] = useState('');
    const [statusFilter, setStatusFilter] = useState('');
    const [activeTab, setActiveTab] = useState('list');
    const headerMediaPreview = useMemo(() => {
        const media = templateForm.data.header_media;
        return typeof File !== 'undefined' && media instanceof File
            ? URL.createObjectURL(media)
            : null;
    }, [templateForm.data.header_media]);
    useEffect(
        () => () => {
            if (headerMediaPreview) URL.revokeObjectURL(headerMediaPreview);
        },
        [headerMediaPreview],
    );
    const previewValues = {
        name: 'Aarav',
        brand: 'Your business',
        phone: '+91 98765 43210',
        cta: 'View details',
        cta_url: 'example.com',
        ...Object.fromEntries(
            placeholders
                .filter((placeholder) => placeholder.custom)
                .map((placeholder) => [
                    placeholder.token.replace(/[{}]/g, ''),
                    `Sample ${placeholder.label}`,
                ]),
        ),
    };
    const previewBody = (templateForm.data.body || '')
        .replace(/\{\{\s*name\s*\}\}/gi, 'Aarav')
        .replace(/\{\{\s*brand\s*\}\}/gi, 'Your business')
        .replace(/\{\{\s*phone\s*\}\}/gi, '+91 98765 43210')
        .replace(/\{\{\s*cta\s*\}\}/gi, 'View details')
        .replace(/\{\{\s*cta_url\s*\}\}/gi, 'example.com')
        .replace(/\{\{\s*([a-z0-9_]+)\s*\}\}/gi, (placeholder, key) =>
            previewValues[key.toLowerCase()] ?? placeholder,
        );
    const filteredTemplates = templates.filter((tpl) => {
        const query = templateQuery.trim().toLowerCase();
        const matchesQuery =
            !query ||
            [tpl.name, tpl.body, tpl.category, tpl.language]
                .filter(Boolean)
                .some((value) => String(value).toLowerCase().includes(query));
        const status = String(tpl.wa_status || 'draft').toLowerCase();
        const matchesStatus =
            !statusFilter ||
            (statusFilter === 'draft'
                ? !['approved', 'pending', 'rejected', 'ready'].includes(status)
                : status === statusFilter);
        return matchesQuery && matchesStatus;
    });
    const templateStatusOptions = [
        { value: '', label: 'All statuses' },
        { value: 'approved', label: 'Approved' },
        { value: 'pending', label: 'Pending' },
        { value: 'draft', label: 'Drafts' },
        { value: 'rejected', label: 'Rejected' },
        { value: 'ready', label: 'Local ready' },
    ];

    return (
        <section className="space-y-4">
            <div className="flex flex-wrap gap-1 rounded-lg border border-line bg-white p-1 shadow-sm">
                {[
                    { id: 'list', label: 'List' },
                    { id: 'create', label: 'Create template' },
                ].map((tab) => (
                    <button
                        key={tab.id}
                        type="button"
                        aria-pressed={activeTab === tab.id}
                        onClick={() => setActiveTab(tab.id)}
                        className={
                            'rounded-md border px-3 py-1.5 text-xs font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-signal/30 ' +
                            (activeTab === tab.id
                                ? 'border-signal bg-signal text-white shadow-sm'
                                : 'border-line bg-mist/60 text-ink-soft hover:border-signal/40 hover:bg-signal-soft/50 hover:text-signal-strong')
                        }
                    >
                        {tab.label}
                    </button>
                ))}
            </div>

            {activeTab === 'create' ? (
                <form
                    className="atlas-panel overflow-hidden rounded-2xl border border-violet-100 shadow-sm"
                    onSubmit={(e) => {
                        e.preventDefault();
                        const opts = {
                            preserveScroll: true,
                            onSuccess: () => {
                                resetTemplateForm();
                                setActiveTab('list');
                            },
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
                <div className="h-1 bg-gradient-to-r from-violet-400 via-fuchsia-400 to-pink-400" />
                <div className="grid items-start gap-5 p-4 sm:p-5 lg:grid-cols-[minmax(0,1.2fr)_minmax(240px,0.8fr)]">
                    <div className="space-y-4">
                    <div className="flex items-start justify-between gap-3">
                        <div>
                            <div className="mb-1 text-[10px] font-bold uppercase tracking-[0.16em] text-violet-700">
                                {editingTemplateId ? 'Edit template' : 'Template studio'}
                            </div>
                            <h2 className="font-display text-lg font-bold text-ink">
                                {editingTemplateId ? 'Update your template' : 'Create a template'}
                            </h2>
                            <p className="mt-1 text-xs leading-5 text-ink-muted">
                                {provider === 'meta'
                                    ? 'Create reusable messages and submit them to Meta for review.'
                                    : 'Save reusable messages. Connect Meta to submit them to WhatsApp.'}
                            </p>
                        </div>
                        <span className="rounded-full bg-violet-50 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide text-violet-700">
                            {templateForm.data.category || 'utility'}
                        </span>
                    </div>
                    <div>
                        <InputLabel value="Template name" />
                        <TextInput
                            className="mt-1 w-full"
                            value={templateForm.data.name}
                            onChange={(e) => templateForm.setData('name', e.target.value)}
                            placeholder="order_update"
                        />
                        <p className="mt-1 text-[11px] text-ink-muted">
                            Use lowercase letters and underscores, for example order_update.
                        </p>
                    </div>
                    <div className="rounded-xl border border-violet-100 bg-violet-50/40 p-3">
                        <InputLabel value="Header type" />
                        <div className="mt-1">
                            <SelectMenu
                                value={templateForm.data.header_format}
                                onChange={(value) =>
                                    templateForm.setData({
                                        ...templateForm.data,
                                        header_format: value,
                                        header_media: null,
                                    })
                                }
                                options={
                                    provider === 'meta' || editingTemplateId
                                        ? headerOptions
                                        : headerOptions.slice(0, 2)
                                }
                            />
                        </div>
                        {templateForm.data.header_format === 'TEXT' ? (
                            <div className="mt-3">
                                <InputLabel value="Header text" />
                                <TextInput
                                    className="mt-1 w-full"
                                    maxLength={60}
                                    value={templateForm.data.header_text}
                                    onChange={(e) => templateForm.setData('header_text', e.target.value)}
                                    placeholder="Order update"
                                />
                                <p className="mt-1 text-[11px] text-ink-muted">
                                    Up to 60 characters. Optional body placeholders are also supported.
                                </p>
                            </div>
                        ) : null}
                        {['IMAGE', 'DOCUMENT'].includes(templateForm.data.header_format) ? (
                            <div className="mt-3">
                                <InputLabel
                                    value={
                                        templateForm.data.header_format === 'IMAGE'
                                            ? 'Header image'
                                            : 'Header document'
                                    }
                                />
                                <input
                                    className="mt-1 block w-full cursor-pointer rounded-lg border border-violet-100 bg-white text-sm text-slate-600 file:mr-3 file:cursor-pointer file:border-0 file:bg-violet-100 file:px-3 file:py-2 file:text-xs file:font-semibold file:text-violet-800 hover:file:bg-violet-200"
                                    type="file"
                                    accept={
                                        templateForm.data.header_format === 'IMAGE'
                                            ? 'image/jpeg,image/png'
                                            : 'application/pdf'
                                    }
                                    onChange={(e) =>
                                        templateForm.setData(
                                            'header_media',
                                            e.target.files?.[0] || null,
                                        )
                                    }
                                />
                                <p className="mt-1 text-[11px] text-ink-muted">
                                    {templateForm.data.header_format === 'IMAGE'
                                        ? 'JPG or PNG · up to 5 MB'
                                        : 'PDF · up to 100 MB'}
                                    {templateForm.data.components?.header?.filename &&
                                    !templateForm.data.header_media
                                        ? ` · Current: ${templateForm.data.components.header.filename}`
                                        : ''}
                                </p>
                                {templateForm.errors.header_media ? (
                                    <p className="mt-1 text-xs text-rose-600">
                                        {templateForm.errors.header_media}
                                    </p>
                                ) : null}
                            </div>
                        ) : null}
                        <p className="mt-2 text-[11px] leading-4 text-slate-500">
                            WhatsApp controls message colors and fonts. Headers, media, footers,
                            and buttons are rendered using WhatsApp’s native style.
                        </p>
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
                                placeholder="en_US"
                            />
                        </div>
                        <div>
                            <InputLabel value="Local status" />
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
                        <div className="mb-1 flex items-center justify-between gap-2">
                            <InputLabel value="Message body" />
                            <span className="text-[10px] font-medium tabular-nums text-ink-muted">
                                {(templateForm.data.body || '').length} characters
                            </span>
                        </div>
                        <textarea
                            ref={bodyRef}
                            className="mt-1 w-full rounded-xl border-line text-sm shadow-sm focus:border-violet-400 focus:ring-violet-400"
                            rows={5}
                            value={templateForm.data.body}
                            onChange={(e) => templateForm.setData('body', e.target.value)}
                            placeholder="Hi {{name}}, thanks for contacting {{brand}}."
                        />
                        <PlaceholderRow
                            placeholders={placeholders}
                            onMouseDown={(event) => event.preventDefault()}
                            onInsert={(token) =>
                                insertAtCursor(
                                    bodyRef.current,
                                    templateForm.data.body,
                                    token,
                                    (value) => templateForm.setData('body', value),
                                )
                            }
                        />
                    </div>
                    <div className="grid gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]">
                        <div>
                            <InputLabel value="Footer" />
                            <TextInput
                                className="mt-1 w-full"
                                maxLength={60}
                                value={templateForm.data.footer}
                                onChange={(e) => templateForm.setData('footer', e.target.value)}
                                placeholder="Reply STOP to opt out"
                            />
                            <p className="mt-1 text-[11px] text-ink-muted">
                                Included by default. Replying STOP pauses WhatsApp messages for
                                one month. Up to 60 characters.
                            </p>
                        </div>
                        <div className="rounded-xl border border-slate-200 bg-slate-50/70 p-3">
                            <div className="flex items-center justify-between gap-2">
                                <div>
                                    <div className="text-xs font-semibold text-ink">Buttons</div>
                                    <p className="mt-0.5 text-[11px] text-ink-muted">
                                        Up to 2 call-to-actions and 3 quick replies.
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    disabled={templateForm.data.buttons.length >= 5}
                                    className="rounded-lg bg-white px-2.5 py-1.5 text-xs font-semibold text-sky-700 shadow-sm ring-1 ring-slate-200 transition hover:bg-sky-50 disabled:cursor-not-allowed disabled:opacity-50"
                                    onClick={() =>
                                        templateForm.setData('buttons', [
                                            ...templateForm.data.buttons,
                                            { type: 'QUICK_REPLY', text: 'Quick reply' },
                                        ])
                                    }
                                >
                                    + Add button
                                </button>
                            </div>
                            {templateForm.data.buttons.map((button, index) => (
                                <div
                                    key={`${index}-${button.type}`}
                                    className="mt-3 rounded-lg border border-slate-200 bg-white p-2.5"
                                >
                                    <div className="flex items-center justify-between gap-2">
                                        <span className="text-[10px] font-bold uppercase tracking-wide text-slate-500">
                                            Button {index + 1}
                                        </span>
                                        <button
                                            type="button"
                                            className="text-[11px] font-semibold text-rose-600 hover:underline"
                                            onClick={() =>
                                                templateForm.setData(
                                                    'buttons',
                                                    templateForm.data.buttons.filter(
                                                        (_, itemIndex) => itemIndex !== index,
                                                    ),
                                                )
                                            }
                                        >
                                            Remove
                                        </button>
                                    </div>
                                    <div className="mt-2 grid gap-2 sm:grid-cols-2">
                                        <SelectMenu
                                            value={button.type}
                                            onChange={(type) =>
                                                templateForm.setData(
                                                    'buttons',
                                                    templateForm.data.buttons.map((item, itemIndex) =>
                                                        itemIndex === index
                                                            ? {
                                                                  type,
                                                                  text:
                                                                      type === 'URL'
                                                                          ? 'Visit website'
                                                                          : type === 'PHONE_NUMBER'
                                                                            ? 'Call us'
                                                                            : 'Quick reply',
                                                              }
                                                            : item,
                                                    ),
                                                )
                                            }
                                            options={buttonTypeOptions}
                                        />
                                        <TextInput
                                            className="w-full"
                                            maxLength={25}
                                            value={button.text}
                                            onChange={(e) =>
                                                templateForm.setData(
                                                    'buttons',
                                                    templateForm.data.buttons.map((item, itemIndex) =>
                                                        itemIndex === index
                                                            ? { ...item, text: e.target.value }
                                                            : item,
                                                    ),
                                                )
                                            }
                                            placeholder="Button label"
                                        />
                                    </div>
                                    {button.type === 'URL' ? (
                                        <div className="mt-2">
                                            <TextInput
                                                className="w-full"
                                                type="url"
                                                value={button.url || ''}
                                                onChange={(e) =>
                                                    templateForm.setData(
                                                        'buttons',
                                                        templateForm.data.buttons.map(
                                                            (item, itemIndex) =>
                                                                itemIndex === index
                                                                    ? { ...item, url: e.target.value }
                                                                    : item,
                                                        ),
                                                    )
                                                }
                                                placeholder="https://example.com/file.pdf"
                                                aria-label="Website or attachment URL"
                                            />
                                            <p className="mt-1 text-[11px] text-ink-muted">
                                                For downloads, set the button label to “Download
                                                file” and paste a public direct file URL. No upload
                                                is needed here; the link host must allow downloads.
                                            </p>
                                        </div>
                                    ) : null}
                                    {button.type === 'PHONE_NUMBER' ? (
                                        <TextInput
                                            className="mt-2 w-full"
                                            type="tel"
                                            value={button.phone_number || ''}
                                            onChange={(e) =>
                                                templateForm.setData(
                                                    'buttons',
                                                    templateForm.data.buttons.map((item, itemIndex) =>
                                                        itemIndex === index
                                                            ? { ...item, phone_number: e.target.value }
                                                            : item,
                                                    ),
                                                )
                                            }
                                            placeholder="+919876543210"
                                        />
                                    ) : null}
                                </div>
                            ))}
                        </div>
                    </div>
                    {!editingTemplateId && provider === 'meta' ? (
                        <div className="rounded-xl border border-sky-100 bg-sky-50/70 px-3 py-2.5">
                            <Toggle
                                checked={!!templateForm.data.submit_to_meta}
                                onChange={(v) => templateForm.setData('submit_to_meta', v)}
                                label="Submit to Meta (WABA) now"
                            />
                        </div>
                    ) : null}
                    <div className="flex flex-wrap items-center gap-2 border-t border-line pt-4">
                        <PrimaryButton processing={templateForm.processing}>
                            {editingTemplateId
                                ? 'Update template'
                                : templateForm.data.submit_to_meta && provider === 'meta'
                                  ? 'Save & submit to Meta'
                                  : 'Save template'}
                        </PrimaryButton>
                        {editingTemplateId ? (
                            <SecondaryButton
                                type="button"
                                onClick={() => {
                                    resetTemplateForm();
                                    setActiveTab('list');
                                }}
                            >
                                Cancel
                            </SecondaryButton>
                        ) : null}
                    </div>
                    </div>
                    <div className="rounded-xl border border-emerald-100 bg-emerald-50/60 p-3 lg:sticky lg:top-4">
                        <div className="mb-2 flex items-center justify-between">
                            <div className="text-[10px] font-bold uppercase tracking-wide text-emerald-800">
                                Live preview
                            </div>
                            <div className="text-[10px] font-medium text-emerald-700">
                                WhatsApp message
                            </div>
                        </div>
                        <div className="ml-auto max-w-[90%] rounded-2xl rounded-br-sm bg-white px-3 py-2.5 text-sm leading-5 text-slate-700 shadow-sm">
                            {templateForm.data.header_format !== 'NONE' ? (
                                templateForm.data.header_format === 'TEXT' ? (
                                    <div className="mb-2 text-sm font-semibold text-slate-800">
                                        {templateForm.data.header_text || 'Header text'}
                                    </div>
                                ) : templateForm.data.header_format === 'IMAGE' &&
                                  headerMediaPreview ? (
                                    <img
                                        src={headerMediaPreview}
                                        alt="WhatsApp template image header preview"
                                        className="mb-2 max-h-56 w-full rounded-lg object-cover"
                                    />
                                ) : templateForm.data.header_format === 'DOCUMENT' &&
                                  headerMediaPreview ? (
                                    <iframe
                                        src={headerMediaPreview}
                                        title="WhatsApp template document header preview"
                                        className="mb-2 h-56 w-full rounded-lg border border-slate-200"
                                    />
                                ) : (
                                    <div className="mb-2 flex min-h-24 items-center justify-center rounded-lg bg-gradient-to-br from-emerald-100 to-teal-50 px-3 py-4 text-center text-xs font-semibold text-emerald-800">
                                        {templateForm.data.components?.header?.filename ||
                                            (templateForm.data.header_format === 'IMAGE'
                                                ? 'Image header preview'
                                                : 'Document header preview')}
                                    </div>
                                )
                            ) : null}
                            {previewBody || (
                                <span className="text-slate-400">
                                    Your message preview will appear here.
                                </span>
                            )}
                            {templateForm.data.footer ? (
                                <div className="mt-2 border-t border-slate-100 pt-1.5 text-[11px] text-slate-500">
                                    {templateForm.data.footer}
                                </div>
                            ) : null}
                            <div className="mt-1 text-right text-[10px] text-slate-400">
                                now
                            </div>
                        </div>
                        {templateForm.data.buttons.length ? (
                            <div className="ml-auto mt-1 flex max-w-[90%] flex-col gap-1">
                                {templateForm.data.buttons.map((button, index) => (
                                    <div
                                        key={`${index}-${button.type}`}
                                        className="rounded-lg bg-white px-3 py-2 text-center text-xs font-semibold text-emerald-700 shadow-sm"
                                    >
                                        {button.text || 'Button'}
                                        {button.type === 'URL'
                                            ? ' ↗'
                                            : button.type === 'PHONE_NUMBER'
                                              ? ' ☎'
                                              : ''}
                                    </div>
                                ))}
                            </div>
                        ) : null}
                    </div>
                </div>
            </form>
            ) : (
                <div className="atlas-panel overflow-hidden rounded-2xl border border-sky-100 shadow-sm">
                <div className="border-b border-sky-100 bg-gradient-to-r from-sky-50 to-indigo-50/70 p-4 sm:p-5">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <div className="text-[10px] font-bold uppercase tracking-[0.16em] text-sky-700">
                                Library
                            </div>
                            <h2 className="mt-0.5 font-display text-lg font-bold text-ink">
                                Saved templates
                            </h2>
                            <p className="mt-0.5 text-xs text-ink-muted">
                                {templates.length} template{templates.length === 1 ? '' : 's'} in your library
                            </p>
                        </div>
                    </div>
                    <div className="mt-4 grid gap-2 sm:grid-cols-[minmax(0,1fr)_160px]">
                        <TextInput
                            className="w-full bg-white"
                            value={templateQuery}
                            onChange={(e) => setTemplateQuery(e.target.value)}
                            placeholder="Search name or message…"
                            aria-label="Search saved templates"
                        />
                        <SelectMenu
                            value={statusFilter}
                            onChange={setStatusFilter}
                            options={templateStatusOptions}
                        />
                    </div>
                </div>
                <div className="grid grid-cols-1 items-start gap-4 p-4 sm:grid-cols-2 sm:p-5 lg:grid-cols-3">
                {templates.length === 0 ? (
                    <div className="rounded-xl border border-dashed border-sky-200 bg-sky-50/40 px-4 py-8 text-center sm:col-span-full">
                        <div className="font-semibold text-ink">No templates yet</div>
                        <p className="mt-1 text-sm text-ink-muted">
                            Create a template to save reusable WhatsApp messages here.
                        </p>
                    </div>
                ) : filteredTemplates.length === 0 ? (
                    <div className="rounded-xl border border-dashed border-line px-4 py-8 text-center sm:col-span-full">
                        <div className="font-semibold text-ink">No matching templates</div>
                        <p className="mt-1 text-sm text-ink-muted">
                            Try another search or choose a different status.
                        </p>
                    </div>
                ) : (
                    filteredTemplates.map((tpl) => (
                        <div
                            key={tpl.id}
                            className="flex min-w-0 flex-col overflow-hidden rounded-2xl border border-line bg-white shadow-sm transition duration-200 hover:-translate-y-0.5 hover:border-sky-200 hover:shadow-lg"
                        >
                            <div className="p-4 pb-3">
                                <div className="flex min-w-0 items-start justify-between gap-2">
                                    <div className="min-w-0">
                                        <div className="break-words font-display text-base font-bold leading-5 text-ink">
                                            {tpl.name}
                                        </div>
                                    </div>
                                    <span
                                        className={
                                            'shrink-0 rounded-full px-2 py-1 text-[9px] font-bold uppercase tracking-wide ' +
                                            (tpl.wa_status === 'approved'
                                                ? 'bg-emerald-100 text-emerald-800'
                                                : tpl.wa_status === 'pending'
                                                  ? 'bg-amber-100 text-amber-900'
                                                  : tpl.wa_status === 'rejected'
                                                    ? 'bg-rose-100 text-rose-800'
                                                    : 'bg-mist text-ink-muted')
                                        }
                                    >
                                        {tpl.wa_status === 'approved'
                                            ? 'Approved'
                                            : tpl.wa_status === 'pending'
                                              ? 'Pending'
                                              : tpl.wa_status === 'rejected'
                                                ? 'Rejected'
                                                : tpl.wa_status === 'ready'
                                                  ? 'Local only'
                                                  : tpl.wa_status || 'draft'}
                                    </span>
                                </div>
                                <div className="mt-2 flex flex-wrap items-center gap-1.5 text-[11px] text-ink-muted">
                                        <span className="rounded-md bg-slate-100 px-1.5 py-0.5 font-semibold text-slate-600">
                                            {(tpl.category || 'utility').toUpperCase()}
                                        </span>
                                        <span className="rounded-md bg-slate-100 px-1.5 py-0.5">
                                            {tpl.language || 'en'}
                                        </span>
                                        {tpl.subject?.startsWith('meta:')
                                            ? <span className="break-all">Meta ID {tpl.subject.replace('meta:', '')}</span>
                                            : ''}
                                </div>
                                <div className="mt-3 flex items-center gap-2 border-t border-slate-100 pt-2">
                                    <button
                                        type="button"
                                        className="rounded-lg bg-sky-50 px-3 py-1.5 text-xs font-semibold text-sky-700 transition hover:bg-sky-100"
                                        onClick={() => {
                                            setEditingTemplateId(tpl.id);
                                            setActiveTab('create');
                                            templateForm.setData({
                                                name: tpl.name,
                                                body: tpl.body,
                                                category: tpl.category || 'utility',
                                                language: tpl.language || 'en_US',
                                                wa_status: tpl.wa_status || 'draft',
                                                submit_to_meta: false,
                                                header_format: tpl.components?.header?.format || 'NONE',
                                                header_text: tpl.components?.header?.text || '',
                                                header_media: null,
                                                footer:
                                                    tpl.components?.footer || 'Reply STOP to opt out',
                                                buttons: tpl.components?.buttons || [],
                                                components: tpl.components || {},
                                            });
                                        }}
                                    >
                                        Edit
                                    </button>
                                    <button
                                        type="button"
                                        className="rounded-lg px-3 py-1.5 text-xs font-semibold text-rose-600 transition hover:bg-rose-50"
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
                                    {tpl.subject?.startsWith('meta:') ? (
                                        <button
                                            type="button"
                                            className="ml-auto rounded-lg px-2 py-1.5 text-xs font-semibold text-slate-500 transition hover:bg-slate-100 hover:text-slate-700"
                                            onClick={() =>
                                                router.post(
                                                    route('whatsapp.templates.sync-meta', tpl.id),
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            Sync status
                                        </button>
                                    ) : null}
                                </div>
                            </div>
                            <div className="px-3 pb-3">
                                <div className="rounded-2xl border border-emerald-100 bg-[#e9f5ef] p-2">
                                    <div className="rounded-2xl rounded-br-sm bg-white px-3 py-3 text-sm leading-5 text-slate-700 shadow-sm">
                                        {tpl.components?.header?.format &&
                                        tpl.components.header.format !== 'NONE' ? (
                                            tpl.components.header.format === 'TEXT' ? (
                                                <div className="mb-2 font-semibold text-slate-800">
                                                    {tpl.components.header.text}
                                                </div>
                                            ) : (
                                                tpl.components.header.format === 'IMAGE' ? (
                                                    <img
                                                        src={route('whatsapp.templates.media', tpl.id)}
                                                        alt={tpl.components.header.filename || 'Template image'}
                                                        className="mb-2 aspect-[4/3] max-h-52 w-full rounded-xl object-cover"
                                                    />
                                                ) : (
                                                    <div className="mb-2 flex min-h-20 items-center justify-center rounded-lg bg-gradient-to-br from-emerald-100 to-teal-50 px-3 py-4 text-center text-xs font-semibold text-emerald-800">
                                                        {tpl.components.header.filename ||
                                                            `${tpl.components.header.format} header`}
                                                    </div>
                                                )
                                            )
                                        ) : null}
                                        <p className="whitespace-pre-wrap break-words">
                                            {tpl.body || (
                                                <span className="text-slate-400">
                                                    Your message preview will appear here.
                                                </span>
                                            )}
                                        </p>
                                        {tpl.components?.footer ? (
                                            <div className="mt-2 border-t border-slate-100 pt-1.5 text-[11px] text-slate-500">
                                                {tpl.components.footer}
                                            </div>
                                        ) : null}
                                        <div className="mt-1 text-right text-[10px] text-slate-400">
                                            now
                                        </div>
                                    </div>
                                    {tpl.components?.buttons?.length ? (
                                        <div className="mt-1 flex flex-col gap-1">
                                            {tpl.components.buttons.map((button, index) => (
                                                <div
                                                    key={`${tpl.id}-button-${index}`}
                                                    className="rounded-lg bg-white px-3 py-2 text-center text-xs font-semibold text-emerald-700 shadow-sm"
                                                >
                                                    {button.text || 'Button'}
                                                    {button.type === 'URL'
                                                        ? ' ↗'
                                                        : button.type === 'PHONE_NUMBER'
                                                          ? ' ☎'
                                                          : ''}
                                                </div>
                                            ))}
                                        </div>
                                    ) : null}
                                </div>
                            </div>
                        </div>
                    ))
                )}
                </div>
            </div>
            )}
        </section>
    );
}

function CampaignsView({
    campaigns,
    templates,
    leads,
    provider,
    sendLocked,
    leadGroups,
    importedGroupId,
    leadImportFields,
    audienceOptions,
}) {
    const stageOptions = audienceOptions?.stages || [];
    const sourceOptions = audienceOptions?.sources || [];
    const customFieldOptions = audienceOptions?.custom_fields || [];
    const form = useForm({
        name: '',
        channel: 'whatsapp',
        body: '',
        whatsapp_template_id: '',
        lead_ids: [],
        recipient_mode: importedGroupId ? 'group' : 'all',
        crm_lead_group_id: importedGroupId ? String(importedGroupId) : '',
        stages: audienceOptions?.default_stages || ['new', 'contacted', 'qualified'],
        sources: [],
        custom_field: '',
        custom_value: '',
        delivery: sendLocked ? 'draft' : 'now',
        scheduled_at: '',
    });
    const [activeTab, setActiveTab] = useState(importedGroupId > 0 ? 'create' : 'list');
    const [audience, setAudience] = useState(null);
    const [audienceLoading, setAudienceLoading] = useState(false);
    const audienceKey = JSON.stringify([
        form.data.recipient_mode,
        form.data.recipient_mode === 'selected' ? form.data.lead_ids : [],
        form.data.recipient_mode === 'group' ? form.data.crm_lead_group_id : '',
        form.data.recipient_mode === 'all'
            ? [form.data.stages, form.data.sources, form.data.custom_field, form.data.custom_value]
            : [],
        leadGroups.map((group) => `${group.id}:${group.status}:${group.leads_count}`),
    ]);

    useEffect(() => {
        let cancelled = false;
        setAudienceLoading(true);
        const timer = window.setTimeout(() => {
            window.axios
                .post(route('whatsapp.campaigns.audience-preview'), {
                    recipient_mode: form.data.recipient_mode,
                    lead_ids: form.data.recipient_mode === 'selected' ? form.data.lead_ids : [],
                    crm_lead_group_id:
                        form.data.recipient_mode === 'group' ? form.data.crm_lead_group_id || null : null,
                    stages: form.data.stages,
                    sources: form.data.sources,
                    custom_field: form.data.custom_field || null,
                    custom_value: form.data.custom_value || null,
                })
                .then((response) => {
                    if (!cancelled) setAudience(response.data);
                })
                .catch(() => {
                    if (!cancelled) setAudience(null);
                })
                .finally(() => {
                    if (!cancelled) setAudienceLoading(false);
                });
        }, 350);
        return () => {
            cancelled = true;
            window.clearTimeout(timer);
        };
    }, [audienceKey]);

    const toggleInList = (key, value) => {
        const list = new Set(form.data[key]);
        if (list.has(value)) list.delete(value);
        else list.add(value);
        form.setData(key, [...list]);
    };
    const groupForm = useForm({ name: '' });
    const importForm = useForm({
        crm_lead_group_id: importedGroupId ? String(importedGroupId) : '',
        csv_file: null,
    });
    const importableGroups = leadGroups.filter((group) => group.status !== 'processing');
    const importFileRef = useRef(null);
    const [showCsvImport, setShowCsvImport] = useState(false);
    const selectedTemplate = templates.find(
        (template) => String(template.id) === String(form.data.whatsapp_template_id),
    );
    const hasMetaProvider = provider === 'meta';
    const selectableLeads = leads;
    const selectedGroup = leadGroups.find(
        (group) => String(group.id) === String(form.data.crm_lead_group_id),
    );
    const sendableGroups = leadGroups.filter(
        (group) => group.status === 'completed' && group.leads_count > 0,
    );

    useEffect(() => {
        if (importedGroupId > 0) {
            importForm.setData('crm_lead_group_id', String(importedGroupId));
        }
    }, [importedGroupId]);

    useEffect(() => {
        if (importedGroupId > 0) {
            const importedGroup = leadGroups.find(
                (group) => String(group.id) === String(importedGroupId),
            );
            if (importedGroup && importedGroup.leads_count > 0) {
                form.setData({
                    ...form.data,
                    crm_lead_group_id: String(importedGroup.id),
                    recipient_mode: importedGroup.status === 'completed' ? 'group' : form.data.recipient_mode,
                });
            }
        }
    }, [importedGroupId, leadGroups]);

    useEffect(() => {
        if (leadGroups.some((group) => group.status === 'processing')) {
            const timer = window.setInterval(() => {
                router.reload({
                    only: ['leadGroups'],
                    preserveState: true,
                    preserveScroll: true,
                });
            }, 3000);
            return () => window.clearInterval(timer);
        }
    }, [leadGroups]);

    useEffect(() => {
        if (campaigns.some((campaign) => campaign.status === 'sending')) {
            const timer = window.setInterval(() => {
                router.reload({
                    only: ['campaigns'],
                    preserveState: true,
                    preserveScroll: true,
                });
            }, 3000);
            return () => window.clearInterval(timer);
        }
    }, [campaigns]);

    const toggleLead = (id) => {
        const selected = new Set(form.data.lead_ids);
        if (selected.has(id)) selected.delete(id);
        else selected.add(id);
        form.setData('lead_ids', [...selected]);
    };

    const submit = (event) => {
        event.preventDefault();
        if (!selectedTemplate) {
            toast.error('Choose an approved WhatsApp template.');
            return;
        }
        if (form.data.delivery === 'schedule' && !form.data.scheduled_at) {
            toast.error('Choose a date and time for the scheduled campaign.');
            return;
        }
        if (
            form.data.recipient_mode === 'group' &&
            (!selectedGroup || selectedGroup.status !== 'completed' || selectedGroup.leads_count === 0)
        ) {
            toast.error('Choose a completed contact group that contains phone numbers.');
            return;
        }
        if (form.data.delivery !== 'draft' && audience && audience.recipients === 0) {
            toast.error('No recipients match this audience.');
            return;
        }
        form.transform((data) => {
            const payload = {
                ...data,
                body: selectedTemplate.body,
                scheduled_at: data.delivery === 'schedule' ? data.scheduled_at : null,
                lead_ids: data.recipient_mode === 'selected' ? data.lead_ids || [] : [],
                crm_lead_group_id: data.recipient_mode === 'group' ? data.crm_lead_group_id : null,
                custom_field: data.custom_field || null,
                custom_value: data.custom_value || null,
            };
            if (data.recipient_mode !== 'all') {
                delete payload.stages;
                delete payload.sources;
                delete payload.custom_field;
                delete payload.custom_value;
            }
            return payload;
        });
        form.post(route('channels.store'), {
            preserveScroll: true,
            onSuccess: (page) => {
                if (page.props.flash?.error) return;
                form.reset('name', 'body', 'whatsapp_template_id', 'lead_ids', 'scheduled_at');
                setActiveTab('list');
            },
        });
    };

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap gap-1 rounded-lg border border-line bg-white p-1 shadow-sm">
                {[
                    { id: 'list', label: `Campaigns${campaigns.length ? ` · ${campaigns.length}` : ''}` },
                    { id: 'create', label: 'Create campaign' },
                ].map((tab) => (
                    <button
                        key={tab.id}
                        type="button"
                        aria-pressed={activeTab === tab.id}
                        onClick={() => setActiveTab(tab.id)}
                        className={
                            'rounded-md border px-3 py-1.5 text-xs font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-signal/30 ' +
                            (activeTab === tab.id
                                ? 'border-signal bg-signal text-white shadow-sm'
                                : 'border-line bg-mist/60 text-ink-soft hover:border-signal/40 hover:bg-signal-soft/50 hover:text-signal-strong')
                        }
                    >
                        {tab.label}
                    </button>
                ))}
            </div>
            {activeTab === 'create' ? (
            <section className="atlas-panel overflow-hidden">
                <div className="flex flex-wrap items-center justify-between gap-3 border-b border-line px-4 py-3">
                    <div>
                        <div className="font-display text-lg font-bold text-ink">Create WhatsApp campaign</div>
                        <p className="mt-0.5 text-sm text-ink-muted">
                            Campaigns are sent using an approved Meta template and each lead’s saved values.
                        </p>
                    </div>
                    <SecondaryButton
                        type="button"
                        className="!px-3 !py-1.5 !text-xs"
                        onClick={() => setShowCsvImport(true)}
                    >
                        ＋ Contact groups
                    </SecondaryButton>
                </div>
                {templates.length === 0 ? (
                    <div className="m-4 rounded-xl border border-dashed border-line bg-mist/40 p-5 text-sm text-ink-muted">
                        <p>No approved WhatsApp templates are available yet.</p>
                        <Link
                            href={waQuery('templates')}
                            className="mt-2 inline-flex font-semibold text-signal-strong"
                        >
                            Create or sync a template →
                        </Link>
                    </div>
                ) : (
                    <form onSubmit={submit} className="grid gap-4 p-4 xl:grid-cols-2">
                        <div className="space-y-3">
                            {!hasMetaProvider ? (
                                <div className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                                    Meta Cloud API must be connected to send approved template campaigns.
                                    <Link href={waQuery('setup')} className="ml-1 font-semibold underline">
                                        Open setup
                                    </Link>
                                    {provider === 'zavu' ? ' (Zavu cannot send Meta-approved templates).' : ''}
                                </div>
                            ) : null}
                            <div>
                                <InputLabel value="Campaign name" />
                                <TextInput
                                    className="mt-1 w-full"
                                    value={form.data.name}
                                    onChange={(event) => form.setData('name', event.target.value)}
                                    placeholder="e.g. October product update"
                                    required
                                />
                                {form.errors.name ? (
                                    <p className="mt-1 text-xs text-rose-600">{form.errors.name}</p>
                                ) : null}
                            </div>
                            <div>
                                <InputLabel value="Approved WhatsApp template" />
                                <div className="mt-1">
                                    <SelectMenu
                                        value={form.data.whatsapp_template_id}
                                        onChange={(id) => {
                                            const template = templates.find(
                                                (candidate) => String(candidate.id) === String(id),
                                            );
                                            form.setData({
                                                ...form.data,
                                                whatsapp_template_id: id,
                                                body: template?.body || '',
                                            });
                                        }}
                                        options={templates.map((template) => ({
                                            value: String(template.id),
                                            label: `${template.name} · ${(template.category || 'utility').toUpperCase()}`,
                                        }))}
                                    />
                                </div>
                                {form.errors.whatsapp_template_id ? (
                                    <p className="mt-1 text-xs text-rose-600">
                                        {form.errors.whatsapp_template_id}
                                    </p>
                                ) : null}
                            </div>
                            <div>
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <div className="flex items-center gap-2">
                                        <InputLabel value="Recipients" />
                                        <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold text-ink-muted">
                                            {form.data.recipient_mode === 'all'
                                                ? 'CRM leads'
                                                : form.data.recipient_mode === 'group'
                                                  ? selectedGroup?.name || 'Choose group'
                                                  : `${form.data.lead_ids.length} selected`}
                                        </span>
                                    </div>
                                    <button
                                        type="button"
                                        className="text-xs font-semibold text-signal-strong hover:underline"
                                        onClick={() => setShowCsvImport(true)}
                                    >
                                        ＋ Manage contact groups
                                    </button>
                                </div>
                                <div className="mb-2 mt-2 flex flex-wrap gap-2">
                                    <button
                                        type="button"
                                        onClick={() => form.setData('recipient_mode', 'all')}
                                        className={`rounded-md border px-2.5 py-1.5 text-xs font-semibold ${
                                            form.data.recipient_mode === 'all'
                                                ? 'border-signal bg-signal/10 text-signal-strong'
                                                : 'border-line bg-white text-ink-muted'
                                        }`}
                                    >
                                        CRM leads (filter)
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => form.setData('recipient_mode', 'selected')}
                                        className={`rounded-md border px-2.5 py-1.5 text-xs font-semibold ${
                                            form.data.recipient_mode === 'selected'
                                                ? 'border-signal bg-signal/10 text-signal-strong'
                                                : 'border-line bg-white text-ink-muted'
                                        }`}
                                    >
                                        Select specific leads
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => {
                                            const completedGroup = leadGroups.find(
                                                (group) => group.status === 'completed' && group.leads_count > 0,
                                            );
                                            form.setData({
                                                ...form.data,
                                                recipient_mode: 'group',
                                                crm_lead_group_id: completedGroup ? String(completedGroup.id) : '',
                                                lead_ids: [],
                                            });
                                        }}
                                        className={`rounded-md border px-2.5 py-1.5 text-xs font-semibold ${
                                            form.data.recipient_mode === 'group'
                                                ? 'border-signal bg-signal/10 text-signal-strong'
                                                : 'border-line bg-white text-ink-muted'
                                        }`}
                                    >
                                        Contact group
                                    </button>
                                </div>
                                {form.data.recipient_mode === 'group' ? (
                                    <div className="mb-2">
                                        <SelectMenu
                                            value={form.data.crm_lead_group_id}
                                            onChange={(value) => form.setData('crm_lead_group_id', value)}
                                            placeholder={sendableGroups.length ? 'Choose a contact group' : 'No groups with contacts yet'}
                                            disabled={sendableGroups.length === 0}
                                            options={sendableGroups.map((group) => ({
                                                value: String(group.id),
                                                label: `${group.name} · ${group.leads_count.toLocaleString()} contacts`,
                                            }))}
                                        />
                                        {sendableGroups.length === 0 ? (
                                            <p className="mt-1 text-xs text-ink-muted">
                                                Create a group and import contacts from{' '}
                                                <button
                                                    type="button"
                                                    className="font-semibold text-signal-strong hover:underline"
                                                    onClick={() => setShowCsvImport(true)}
                                                >
                                                    Manage contact groups
                                                </button>
                                                .
                                            </p>
                                        ) : null}
                                        {form.errors.crm_lead_group_id ? (
                                            <p className="mt-1 text-xs text-rose-600">{form.errors.crm_lead_group_id}</p>
                                        ) : null}
                                    </div>
                                ) : null}
                                {form.data.recipient_mode === 'all' ? (
                                    <div className="mb-2 space-y-3 rounded-lg border border-line p-3">
                                        <div>
                                            <div className="mb-1.5 text-xs font-semibold text-ink">
                                                CRM stage
                                            </div>
                                            <div className="flex flex-wrap gap-1.5">
                                                {stageOptions.map((stage) => {
                                                    const on = form.data.stages.includes(stage.value);
                                                    return (
                                                        <button
                                                            key={stage.value}
                                                            type="button"
                                                            onClick={() => toggleInList('stages', stage.value)}
                                                            className={`rounded-full border px-2.5 py-1 text-xs font-semibold ${
                                                                on
                                                                    ? 'border-signal bg-signal/10 text-signal-strong'
                                                                    : 'border-line bg-white text-ink-muted'
                                                            }`}
                                                        >
                                                            {on ? '✓ ' : ''}
                                                            {stage.label} · {stage.count}
                                                        </button>
                                                    );
                                                })}
                                            </div>
                                        </div>
                                        {sourceOptions.length > 0 ? (
                                            <div>
                                                <div className="mb-1.5 text-xs font-semibold text-ink">
                                                    Source{' '}
                                                    <span className="font-normal text-ink-muted">
                                                        (none selected = every source)
                                                    </span>
                                                </div>
                                                <div className="flex flex-wrap gap-1.5">
                                                    {sourceOptions.map((source) => {
                                                        const on = form.data.sources.includes(source.value);
                                                        return (
                                                            <button
                                                                key={source.value}
                                                                type="button"
                                                                onClick={() => toggleInList('sources', source.value)}
                                                                className={`rounded-full border px-2.5 py-1 text-xs font-semibold ${
                                                                    on
                                                                        ? 'border-signal bg-signal/10 text-signal-strong'
                                                                        : 'border-line bg-white text-ink-muted'
                                                                }`}
                                                            >
                                                                {on ? '✓ ' : ''}
                                                                {source.value} · {source.count}
                                                            </button>
                                                        );
                                                    })}
                                                </div>
                                            </div>
                                        ) : null}
                                        {customFieldOptions.length > 0 ? (
                                            <div>
                                                <div className="mb-1.5 text-xs font-semibold text-ink">
                                                    Custom field equals
                                                </div>
                                                <div className="grid gap-2 sm:grid-cols-2">
                                                    <SelectMenu
                                                        value={form.data.custom_field}
                                                        onChange={(value) => form.setData('custom_field', value)}
                                                        options={[
                                                            { value: '', label: 'Any field' },
                                                            ...customFieldOptions.map((field) => ({
                                                                value: field.key,
                                                                label: field.label,
                                                            })),
                                                        ]}
                                                    />
                                                    <TextInput
                                                        className="w-full"
                                                        value={form.data.custom_value}
                                                        disabled={!form.data.custom_field}
                                                        onChange={(event) =>
                                                            form.setData('custom_value', event.target.value)
                                                        }
                                                        placeholder="Exact value"
                                                    />
                                                </div>
                                            </div>
                                        ) : null}
                                    </div>
                                ) : (
                                    <p className="mb-1 text-xs text-ink-muted">
                                        {form.data.recipient_mode === 'group'
                                            ? selectedGroup
                                                ? `${selectedGroup.name} · ${selectedGroup.leads_count.toLocaleString()} contacts`
                                                : 'Choose a completed contact group above.'
                                            : 'Only checked leads will receive this campaign.'}
                                    </p>
                                )}
                                <div
                                    className={`mb-2 rounded-lg border px-3 py-2 text-sm ${
                                        audience && audience.recipients === 0
                                            ? 'border-danger/30 bg-danger-soft'
                                            : 'border-signal/20 bg-signal-soft'
                                    }`}
                                >
                                    {audience ? (
                                        <>
                                            <span className="font-semibold text-ink">
                                                {audience.recipients.toLocaleString()} recipient
                                                {audience.recipients === 1 ? '' : 's'}
                                            </span>{' '}
                                            <span className="text-ink-muted">will get this campaign</span>
                                            {audienceLoading ? (
                                                <span className="text-ink-muted"> · updating…</span>
                                            ) : null}
                                            {audience.duplicates > 0 || audience.opted_out > 0 ? (
                                                <div className="mt-0.5 text-xs text-ink-muted">
                                                    {audience.duplicates > 0
                                                        ? `${audience.duplicates.toLocaleString()} duplicate number${audience.duplicates === 1 ? '' : 's'} skipped`
                                                        : ''}
                                                    {audience.duplicates > 0 && audience.opted_out > 0 ? ' · ' : ''}
                                                    {audience.opted_out > 0
                                                        ? `${audience.opted_out.toLocaleString()} opted out (STOP) skipped`
                                                        : ''}
                                                </div>
                                            ) : null}
                                        </>
                                    ) : (
                                        <span className="text-ink-muted">
                                            {audienceLoading ? 'Counting recipients…' : 'Recipient count unavailable.'}
                                        </span>
                                    )}
                                </div>
                                {form.data.recipient_mode === 'selected' ? (
                                    <>
                                        <div className="mb-1 flex gap-3 text-xs">
                                            <button
                                                type="button"
                                                className="font-semibold text-signal-strong hover:underline"
                                                onClick={() =>
                                                    form.setData({
                                                        ...form.data,
                                                        lead_ids: selectableLeads.map((lead) => lead.id),
                                                    })
                                                }
                                            >
                                                Select all shown
                                            </button>
                                            <button
                                                type="button"
                                                className="font-semibold text-ink-muted hover:underline"
                                                onClick={() => form.setData('lead_ids', [])}
                                            >
                                                Clear selection
                                            </button>
                                        </div>
                                        <div className="max-h-44 space-y-1 overflow-y-auto rounded-lg border border-line p-2">
                                            {selectableLeads.length ? selectableLeads.map((lead) => (
                                                <label key={lead.id} className="flex items-center gap-2 rounded px-1 py-1 text-sm hover:bg-mist/60">
                                                    <input
                                                        type="checkbox"
                                                        checked={form.data.lead_ids.includes(lead.id)}
                                                        onChange={() => toggleLead(lead.id)}
                                                    />
                                                    <span className="min-w-0 flex-1 truncate font-medium text-ink">{lead.name}</span>
                                                    <span className="text-xs text-ink-muted">{lead.phone || 'No phone'}</span>
                                                </label>
                                            )) : (
                                                <p className="px-1 py-3 text-sm text-ink-muted">
                                                    Add eligible leads with phone numbers in CRM first.
                                                </p>
                                            )}
                                        </div>
                                    </>
                                ) : null}
                            </div>
                        </div>
                        <div className="space-y-3">
                            <div className="rounded-xl border border-line bg-slate-50 p-3">
                                <div className="mb-2 text-xs font-semibold uppercase tracking-wide text-ink-muted">
                                    Message preview
                                </div>
                                {selectedTemplate ? (
                                    <div className="max-w-md rounded-2xl bg-[#e9f5ef] p-2">
                                        <div className="rounded-2xl bg-white p-3 text-sm leading-5 text-slate-700 shadow-sm">
                                            {selectedTemplate.components?.header?.format === 'IMAGE' ? (
                                                <img
                                                    src={route('whatsapp.templates.media', selectedTemplate.id)}
                                                    alt={selectedTemplate.components.header.filename || 'Template image'}
                                                    className="mb-2 aspect-[4/3] max-h-52 w-full rounded-xl object-cover"
                                                />
                                            ) : selectedTemplate.components?.header?.format === 'TEXT' ? (
                                                <div className="mb-2 font-semibold">{selectedTemplate.components.header.text}</div>
                                            ) : null}
                                            <p className="whitespace-pre-wrap break-words">{selectedTemplate.body}</p>
                                            {selectedTemplate.components?.footer ? (
                                                <div className="mt-2 border-t border-slate-100 pt-1.5 text-[11px] text-slate-500">
                                                    {selectedTemplate.components.footer}
                                                </div>
                                            ) : null}
                                        </div>
                                        {selectedTemplate.components?.buttons?.map((button, index) => (
                                            <div key={index} className="mt-1 rounded-lg bg-white px-3 py-2 text-center text-xs font-semibold text-emerald-700">
                                                {button.text}
                                            </div>
                                        ))}
                                    </div>
                                ) : (
                                    <p className="rounded-lg border border-dashed border-line bg-white p-5 text-center text-sm text-ink-muted">
                                        Select a template to preview its approved message.
                                    </p>
                                )}
                            </div>
                            <div>
                                <InputLabel value="Delivery" />
                                <div className="mt-1 grid gap-2 sm:grid-cols-3">
                                    {[
                                        { id: 'now', label: 'Send now' },
                                        { id: 'schedule', label: 'Schedule' },
                                        { id: 'draft', label: 'Save draft' },
                                    ].map((option) => {
                                        const disabled = (sendLocked || !hasMetaProvider) && option.id !== 'draft';
                                        return (
                                            <button
                                                key={option.id}
                                                type="button"
                                                disabled={disabled}
                                                onClick={() => form.setData('delivery', option.id)}
                                                className={`rounded-lg border px-3 py-2 text-sm font-semibold ${
                                                    form.data.delivery === option.id
                                                        ? 'border-signal bg-signal/10 text-signal-strong'
                                                        : 'border-line bg-white text-ink-muted'
                                                } ${disabled ? 'cursor-not-allowed opacity-50' : ''}`}
                                            >
                                                {option.label}
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
                                            onChange={(value) => form.setData('scheduled_at', value)}
                                            placeholder="Pick date & time"
                                        />
                                    </div>
                                    {form.errors.scheduled_at ? (
                                        <p className="mt-1 text-xs text-rose-600">{form.errors.scheduled_at}</p>
                                    ) : null}
                                </div>
                            ) : null}
                            <PrimaryButton
                                processing={form.processing}
                                disabled={
                                    !selectedTemplate ||
                                    (form.data.recipient_mode === 'group' &&
                                        (!selectedGroup ||
                                            selectedGroup.status !== 'completed' ||
                                            selectedGroup.leads_count === 0))
                                }
                            >
                                {form.data.delivery === 'now'
                                    ? 'Create and send campaign'
                                    : form.data.delivery === 'schedule'
                                      ? 'Schedule campaign'
                                      : 'Save campaign draft'}
                            </PrimaryButton>
                        </div>
                    </form>
                )}
            </section>
            ) : null}

            {activeTab === 'list' ? (
            <section className="atlas-panel overflow-hidden">
                <div className="flex items-center justify-between border-b border-line p-3">
                    <div className="font-display text-lg font-bold text-ink">WhatsApp campaigns</div>
                    <div className="flex items-center gap-3">
                        <SecondaryButton
                            type="button"
                            className="!px-3 !py-1.5 !text-xs"
                            onClick={() => setShowCsvImport(true)}
                        >
                            ＋ Contact groups
                        </SecondaryButton>
                        <PrimaryButton
                            type="button"
                            className="!px-3 !py-1.5 !text-xs"
                            onClick={() => setActiveTab('create')}
                        >
                            ＋ New campaign
                        </PrimaryButton>
                    </div>
                </div>
                {campaigns.length === 0 ? (
                    <div className="p-6 text-center text-sm text-ink-muted">
                        <p>No WhatsApp campaigns yet.</p>
                        <button
                            type="button"
                            className="mt-2 font-semibold text-signal-strong hover:underline"
                            onClick={() => setActiveTab('create')}
                        >
                            Create your first campaign →
                        </button>
                    </div>
                ) : (
                    <ul className="divide-y divide-line">
                        {campaigns.map((campaign) => (
                            <li key={campaign.id} className="flex flex-wrap items-center justify-between gap-3 p-4">
                                <div className="min-w-0">
                                    <div className="font-semibold text-ink">{campaign.name}</div>
                                    <div className="mt-0.5 text-xs text-ink-muted">
                                        {campaign.status} · {campaign.provider} · {campaign.sent_count || 0} sent / {campaign.recipient_count || 0} recipients
                                        {campaign.whatsapp_template?.name ? ` · ${campaign.whatsapp_template.name}` : ''}
                                        {campaign.crm_lead_group?.name ? ` · Group: ${campaign.crm_lead_group.name}` : ''}
                                    </div>
                                    <p className="mt-1 line-clamp-2 max-w-3xl text-sm text-ink-muted">{campaign.body}</p>
                                </div>
                                <div className="flex items-center gap-3">
                                    {['draft', 'scheduled', 'failed'].includes(campaign.status) &&
                                    !sendLocked &&
                                    hasMetaProvider &&
                                    campaign.whatsapp_template_id ? (
                                        <button
                                            type="button"
                                            className="text-sm font-semibold text-signal-strong"
                                            onClick={() => router.post(route('channels.send', campaign.id))}
                                        >
                                            Send now
                                        </button>
                                    ) : null}
                                    {['draft', 'scheduled', 'failed', 'cancelled'].includes(campaign.status) ? (
                                        <button
                                            type="button"
                                            className="text-sm font-semibold text-rose-600"
                                            onClick={async () => {
                                                const confirmed = await confirmAsk({
                                                    title: 'Delete this campaign?',
                                                    message: `“${campaign.name}” will be removed.`,
                                                    confirmLabel: 'Delete',
                                                });
                                                if (confirmed) {
                                                    router.delete(route('channels.destroy', campaign.id));
                                                }
                                            }}
                                        >
                                            Delete
                                        </button>
                                    ) : null}
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </section>
            ) : null}
            <Modal
                show={showCsvImport}
                maxWidth="2xl"
                closeable={!importForm.processing}
                onClose={() => setShowCsvImport(false)}
            >
                <div className="flex items-center justify-between gap-4 border-b border-line px-5 py-3">
                    <h2 className="font-display text-lg font-bold text-ink">Contact groups</h2>
                    <button
                        type="button"
                        aria-label="Close contact groups"
                        disabled={importForm.processing}
                        onClick={() => setShowCsvImport(false)}
                        className="rounded-md px-2 py-1 text-lg leading-none text-ink-muted hover:bg-mist disabled:opacity-50"
                    >
                        ×
                    </button>
                </div>
                <div className="max-h-[75vh] space-y-4 overflow-y-auto p-5">
                    <section>
                        <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-ink-muted">New group</h3>
                        <form
                            className="flex items-start gap-2"
                            onSubmit={(event) => {
                                event.preventDefault();
                                if (!groupForm.data.name.trim()) {
                                    toast.error('Enter a name for this contact group.');
                                    return;
                                }
                                groupForm.post(route('whatsapp.campaigns.lead-groups.store'), {
                                    preserveScroll: true,
                                    onSuccess: () => groupForm.reset(),
                                });
                            }}
                        >
                            <div className="flex-1">
                                <TextInput
                                    className="w-full !py-1.5 text-sm"
                                    value={groupForm.data.name}
                                    onChange={(event) => groupForm.setData('name', event.target.value)}
                                    placeholder="Group name, e.g. October customers"
                                    maxLength={120}
                                />
                                {groupForm.errors.name ? (
                                    <p className="mt-1 text-xs text-rose-600">{groupForm.errors.name}</p>
                                ) : null}
                            </div>
                            <PrimaryButton
                                type="submit"
                                className="!px-3 !py-2 !text-xs"
                                processing={groupForm.processing}
                                disabled={!groupForm.data.name.trim()}
                            >
                                Create
                            </PrimaryButton>
                        </form>
                    </section>
                    <section className="border-t border-line pt-4">
                        <div className="mb-2 flex items-center justify-between gap-2">
                            <h3 className="text-xs font-semibold uppercase tracking-wide text-ink-muted">Import contacts</h3>
                            <a
                                title={
                                    leadImportFields.length
                                        ? `Custom field columns: ${leadImportFields.map((field) => field.key).join(', ')}`
                                        : undefined
                                }
                                href={`data:text/csv;charset=utf-8,${encodeURIComponent([
                                    ['name', 'phone', 'email', 'company', ...leadImportFields.map((field) => field.key)].join(','),
                                    ['Anil', '+919889995999', 'anil@example.com', 'Example Co', ...leadImportFields.map(() => '')].join(','),
                                ].join('\n'))}`}
                                download="whatsapp-contacts-sample.csv"
                                className="shrink-0 text-xs font-semibold text-signal-strong hover:underline"
                            >
                                Sample CSV
                            </a>
                        </div>
                        <form
                            className="grid gap-2 sm:grid-cols-[1fr_1fr_auto] sm:items-center"
                            onSubmit={(event) => {
                                event.preventDefault();
                                if (!importForm.data.crm_lead_group_id) {
                                    toast.error('Choose a group to import into.');
                                    return;
                                }
                                if (!importForm.data.csv_file) {
                                    toast.error('Choose a CSV file first.');
                                    return;
                                }
                                importForm.transform((data) => ({ csv_file: data.csv_file }));
                                importForm.post(
                                    route('whatsapp.campaigns.lead-groups.import', importForm.data.crm_lead_group_id),
                                    {
                                        forceFormData: true,
                                        preserveScroll: true,
                                        onSuccess: () => {
                                            importForm.setData('csv_file', null);
                                            if (importFileRef.current) importFileRef.current.value = '';
                                        },
                                    },
                                );
                            }}
                        >
                            <SelectMenu
                                value={importForm.data.crm_lead_group_id}
                                onChange={(value) => importForm.setData('crm_lead_group_id', value)}
                                placeholder={importableGroups.length ? 'Choose group' : 'Create a group first'}
                                disabled={importableGroups.length === 0}
                                buttonClassName="!py-1.5"
                                options={importableGroups.map((group) => ({
                                    value: String(group.id),
                                    label: `${group.name} · ${group.leads_count.toLocaleString()}`,
                                }))}
                            />
                            <label
                                htmlFor="whatsapp-campaign-csv"
                                className="flex min-w-0 cursor-pointer items-center gap-2 rounded-md border border-dashed border-line bg-white px-3 py-1.5 text-sm text-ink-muted transition hover:border-signal/50 hover:text-ink"
                            >
                                <svg viewBox="0 0 20 20" fill="currentColor" className="h-4 w-4 shrink-0 text-signal" aria-hidden>
                                    <path d="M9.25 13.25a.75.75 0 001.5 0V4.636l2.955 3.129a.75.75 0 001.09-1.03l-4.25-4.5a.75.75 0 00-1.09 0l-4.25 4.5a.75.75 0 101.09 1.03L9.25 4.636v8.614z" />
                                    <path d="M3.5 12.75a.75.75 0 00-1.5 0v2.5A2.75 2.75 0 004.75 18h10.5A2.75 2.75 0 0018 15.25v-2.5a.75.75 0 00-1.5 0v2.5c0 .69-.56 1.25-1.25 1.25H4.75c-.69 0-1.25-.56-1.25-1.25v-2.5z" />
                                </svg>
                                <span className={`truncate ${importForm.data.csv_file ? 'font-medium text-ink' : ''}`}>
                                    {importForm.data.csv_file?.name || 'Choose CSV / Excel'}
                                </span>
                            </label>
                            <input
                                ref={importFileRef}
                                id="whatsapp-campaign-csv"
                                type="file"
                                accept=".csv,.txt,.xls,.xlsx,text/csv,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                                onChange={(event) => importForm.setData('csv_file', event.target.files?.[0] || null)}
                                className="sr-only"
                            />
                            <PrimaryButton
                                type="submit"
                                className="!px-3 !py-2 !text-xs"
                                processing={importForm.processing}
                                disabled={!importForm.data.csv_file || !importForm.data.crm_lead_group_id}
                            >
                                Import
                            </PrimaryButton>
                        </form>
                        {importForm.errors.csv_file ? (
                            <p className="mt-1 text-xs text-rose-600">{importForm.errors.csv_file}</p>
                        ) : null}
                        <p className="mt-1.5 text-[11px] text-ink-muted">
                            CSV or Excel · name and phone columns required · up to 20,000 rows
                        </p>
                    </section>
                    <section className="border-t border-line pt-4">
                        <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-ink-muted">
                            Your groups{leadGroups.length ? ` · ${leadGroups.length}` : ''}
                        </h3>
                        {leadGroups.length > 0 ? (
                            <div className="divide-y divide-line rounded-lg border border-line">
                                {leadGroups.map((group) => {
                                    const selectable = group.status === 'completed' && group.leads_count > 0;
                                    const selected = form.data.recipient_mode === 'group' &&
                                        String(form.data.crm_lead_group_id) === String(group.id);

                                    return (
                                        <div key={group.id} className="flex items-center gap-3 px-3 py-2">
                                            <div className="min-w-0 flex-1">
                                                <div className="flex items-baseline gap-2">
                                                    <span className="truncate text-sm font-semibold text-ink">{group.name}</span>
                                                    <span className="shrink-0 text-xs text-ink-muted">
                                                        {group.status === 'processing'
                                                            ? `Importing… ${group.total_rows.toLocaleString()} rows`
                                                            : `${group.leads_count.toLocaleString()} contacts`}
                                                    </span>
                                                </div>
                                                {group.error_message && group.status !== 'processing' ? (
                                                    <div className="mt-0.5 truncate text-xs text-danger" title={group.error_message}>
                                                        {group.error_message}
                                                    </div>
                                                ) : null}
                                            </div>
                                            {group.status !== 'processing' ? (
                                                <button
                                                    type="button"
                                                    className="shrink-0 text-xs font-semibold text-signal-strong hover:underline"
                                                    onClick={() => {
                                                        importForm.setData('crm_lead_group_id', String(group.id));
                                                        importFileRef.current?.click();
                                                    }}
                                                >
                                                    Import
                                                </button>
                                            ) : null}
                                            {selectable ? (
                                                <SecondaryButton
                                                    type="button"
                                                    className="!px-2.5 !py-1 !text-xs"
                                                    onClick={() => {
                                                        form.setData({
                                                            ...form.data,
                                                            recipient_mode: 'group',
                                                            crm_lead_group_id: String(group.id),
                                                            lead_ids: [],
                                                        });
                                                        setShowCsvImport(false);
                                                    }}
                                                >
                                                    {selected ? 'Selected' : 'Use'}
                                                </SecondaryButton>
                                            ) : null}
                                            {group.status !== 'processing' ? (
                                                <button
                                                    type="button"
                                                    className="shrink-0 text-xs font-semibold text-rose-600 hover:underline"
                                                    onClick={async () => {
                                                        const confirmed = await confirmAsk({
                                                            title: 'Delete this contact group?',
                                                            message: `“${group.name}” will be removed from group selection. CRM contacts will remain.`,
                                                            confirmLabel: 'Delete group',
                                                        });
                                                        if (confirmed) {
                                                            router.delete(route('whatsapp.campaigns.lead-groups.destroy', group.id), {
                                                                preserveScroll: true,
                                                            });
                                                        }
                                                    }}
                                                >
                                                    Remove
                                                </button>
                                            ) : null}
                                        </div>
                                    );
                                })}
                            </div>
                        ) : (
                            <p className="text-xs text-ink-muted">No groups yet.</p>
                        )}
                    </section>
                </div>
            </Modal>
        </div>
    );
}

function SetupView({ metaSetup, workspace }) {
    const fields = metaSetup?.fields || [];
    const secretsSet = metaSetup?.secrets_set || {};
    const brandProfile = metaSetup?.brand_profile || {};
    const canManageMeta = !!metaSetup?.can_manage_meta;
    const onboardingStatus = metaSetup?.onboarding_status || 'not_started';
    const esConfig = metaSetup?.embedded_signup || { enabled: false };
    const [showManual, setShowManual] = useState(
        !esConfig.enabled || metaSetup?.connected_via === 'manual',
    );
    const [pin, setPin] = useState('');
    const [activating, setActivating] = useState(false);

    const activateNumber = () => {
        setActivating(true);
        router.post(
            route('whatsapp.register-number'),
            { pin },
            { preserveScroll: true, onFinish: () => setActivating(false) },
        );
    };

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
            enabled: metaSetup?.enabled ?? true,
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
        'app_id',
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
        : onboardingStatus === 'error'
          ? 'Credentials rejected'
          : onboardingStatus === 'paused'
            ? 'Paused'
            : 'Not connected';

    const statusClass = metaSetup?.connected
        ? 'bg-emerald-100 text-emerald-800'
        : onboardingStatus === 'error'
          ? 'bg-rose-100 text-rose-800'
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
        form.transform(() => payload);
        form.put(route('whatsapp.setup'), {
            preserveScroll: true,
            onSuccess: () =>
                form.setData('credentials', {
                    ...form.data.credentials,
                    access_token: '',
                    app_secret: '',
                }),
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
                    'rounded-xl border p-3 transition-colors ' +
                    (differs
                        ? 'border-amber-200 bg-amber-50/60'
                        : 'border-emerald-100 bg-gradient-to-br from-white to-emerald-50/50')
                }
            >
                <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                    <div className="text-sm font-semibold text-ink">{field.label}</div>
                    {differs ? (
                        <span className="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-bold text-amber-900">
                            Differs from Brand
                        </span>
                    ) : null}
                </div>

                <div className="grid grid-cols-1 items-end gap-3 sm:grid-cols-2">
                    <div className="min-w-0">
                        <div className="mb-1.5 flex items-center justify-between gap-2">
                            <div className="text-[10px] font-bold uppercase tracking-wide text-slate-500">
                                Brand
                            </div>
                            {brandVal ? (
                                <button
                                    type="button"
                                    className="shrink-0 rounded-full bg-white px-2 py-1 text-[10px] font-semibold text-emerald-700 shadow-sm transition hover:bg-emerald-100"
                                    onClick={() => copyBrandToWaba(key)}
                                >
                                    Copy →
                                </button>
                            ) : null}
                        </div>
                        <div className="flex min-h-10 items-center rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-600">
                            <span className="truncate">{brandDisplay}</span>
                        </div>
                    </div>

                    <div className="min-w-0">
                        <div className="mb-1.5 flex h-[26px] items-center gap-1">
                            <div className="text-[10px] font-bold uppercase tracking-wide text-emerald-700">
                                WhatsApp
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
        <form onSubmit={save} className="space-y-4">
            <section className="atlas-panel overflow-hidden rounded-2xl border border-sky-100 shadow-sm">
                <div className="h-1 bg-gradient-to-r from-emerald-400 via-teal-400 to-sky-400" />
                <div className="space-y-4 p-4 sm:p-5">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <div className="mb-1 text-[10px] font-bold uppercase tracking-[0.16em] text-emerald-700">
                                Step 1 · Connection
                            </div>
                            <h3 className="font-display text-lg font-bold text-ink">
                                WhatsApp connection
                            </h3>
                        </div>
                        <span
                            className={
                                'rounded-full px-3 py-1.5 text-xs font-bold ' + statusClass
                            }
                        >
                            {statusLabel}
                        </span>
                    </div>

                    {metaSetup?.connected ? (
                        <div className="rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2.5 text-sm text-emerald-950">
                            Connected · Sending from{' '}
                            <strong>
                                {[metaSetup?.verified_name, metaSetup?.verified_phone]
                                    .filter(Boolean)
                                    .join(' · ') || metaSetup?.business_phone || 'your number'}
                            </strong>
                        </div>
                    ) : (
                        <div className="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2.5 text-sm text-amber-950">
                            Connect a WhatsApp Business number to start sending messages.
                            {metaSetup?.last_error ? (
                                <span className="mt-1 block text-xs text-rose-700">
                                    Last error: {metaSetup.last_error}
                                </span>
                            ) : null}
                        </div>
                    )}

                    {canManageMeta && esConfig.enabled ? (
                        <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-sky-100 bg-sky-50/70 px-4 py-3">
                            <div className="max-w-xl">
                                <div className="text-sm font-semibold text-ink">
                                    {metaSetup?.connected ? 'Switch number' : 'Connect with Meta'}
                                </div>
                                <p className="mt-0.5 text-xs leading-5 text-ink-muted">
                                    Continue securely with Meta. Your account details are saved
                                    automatically.
                                </p>
                            </div>
                            <EmbeddedSignupButton config={esConfig} connected={!!metaSetup?.connected} />
                        </div>
                    ) : null}

                    {canManageMeta && onboardingStatus === 'needs_pin' ? (
                        <div className="space-y-2 rounded-xl border border-rose-200 bg-rose-50 px-3 py-3 text-sm text-rose-950">
                            <div className="font-semibold">Activate your number</div>
                            <p className="text-xs">Enter your 6-digit WhatsApp verification PIN.</p>
                            <div className="flex flex-wrap items-center gap-2">
                                <TextInput
                                    className="w-32"
                                    inputMode="numeric"
                                    maxLength={6}
                                    value={pin}
                                    placeholder="123456"
                                    autoComplete="off"
                                    onChange={(e) => setPin(e.target.value.replace(/\D/g, ''))}
                                />
                                <SecondaryButton
                                    type="button"
                                    disabled={pin.length !== 6 || activating}
                                    onClick={activateNumber}
                                >
                                    {activating ? 'Activating…' : 'Activate number'}
                                </SecondaryButton>
                            </div>
                        </div>
                    ) : null}
                </div>
            </section>

            <section className="atlas-panel overflow-hidden rounded-2xl border border-emerald-100 shadow-sm">
                <div className="flex flex-wrap items-center justify-between gap-3 border-b border-emerald-100 bg-emerald-50/70 px-4 py-3 sm:px-5">
                    <div>
                        <div className="text-[10px] font-bold uppercase tracking-[0.16em] text-emerald-700">
                            Step 2 · Business details
                        </div>
                        <h4 className="mt-0.5 text-base font-bold text-ink">WhatsApp profile</h4>
                    </div>
                    <button
                        type="button"
                        className="rounded-lg border border-emerald-200 bg-white px-3 py-2 text-xs font-semibold text-emerald-800 shadow-sm transition hover:bg-emerald-100"
                        onClick={copyAllBrandToWaba}
                    >
                        Copy all from Brand
                    </button>
                </div>
                <div className="grid gap-3 p-4 sm:p-5 lg:grid-cols-2">
                    {businessKeys.map(renderDualField)}
                </div>
            </section>

            {canManageMeta ? (
                <>
                    {esConfig.enabled && !showManual ? (
                        <button
                            type="button"
                            className="rounded-lg border border-violet-200 bg-violet-50 px-3 py-2 text-xs font-semibold text-violet-800 transition hover:bg-violet-100"
                            onClick={() => setShowManual(true)}
                        >
                            Show advanced Meta settings
                        </button>
                    ) : null}

                    {showManual ? (
                        <section className="atlas-panel overflow-hidden rounded-2xl border border-violet-100 shadow-sm">
                            <div className="border-b border-violet-100 bg-violet-50/70 px-4 py-3 sm:px-5">
                                <div className="text-[10px] font-bold uppercase tracking-[0.16em] text-violet-700">
                                    Advanced
                                </div>
                                <h4 className="mt-0.5 text-base font-bold text-ink">
                                    Meta Cloud API credentials
                                </h4>
                            </div>
                            <div className="space-y-4 p-4 sm:p-5">
                                <details className="rounded-xl border border-violet-100 bg-violet-50/40 px-3 py-2.5">
                                    <summary className="cursor-pointer text-xs font-semibold text-violet-900">
                                        Where do I find these details?
                                    </summary>
                                    <ol className="mt-3 list-decimal space-y-1 ps-5 text-xs leading-5 text-ink-muted">
                                        <li>
                                            Create a Business app at developers.facebook.com and add
                                            the WhatsApp product.
                                        </li>
                                        <li>
                                            From WhatsApp API Setup, copy the <strong>Phone number ID</strong>{' '}
                                            and <strong>WhatsApp Business Account ID</strong>.
                                        </li>
                                        <li>
                                            Generate a system-user token with
                                            whatsapp_business_messaging and
                                            whatsapp_business_management permissions.
                                        </li>
                                        <li>
                                            Add the callback URL and verify token in Meta, then
                                            subscribe to <code>messages</code>.
                                        </li>
                                    </ol>
                                </details>
                                <div className="grid gap-2 sm:grid-cols-2">
                                    <CopyBox
                                        label="Webhook callback URL"
                                        value={
                                            metaSetup?.webhook_url ||
                                            `${window.location.origin}/webhooks/meta/whatsapp/${workspace.id}`
                                        }
                                    />
                                    <CopyBox
                                        label="Webhook verify token"
                                        value={
                                            metaSetup?.values?.verify_token ||
                                            'Generated automatically when you save'
                                        }
                                        copyable={!!metaSetup?.values?.verify_token}
                                    />
                                </div>
                                <div className="grid gap-3 sm:grid-cols-2">
                                    {metaKeys.map(renderMetaField)}
                                </div>
                            </div>
                        </section>
                    ) : null}

                    <section className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-line bg-white p-4 shadow-sm">
                        <label className="inline-flex items-center gap-2 rounded-lg bg-slate-50 px-3 py-2 text-sm font-semibold text-ink">
                            <Toggle
                                checked={!!form.data.enabled}
                                onChange={(enabled) => form.setData('enabled', enabled)}
                            />
                            Enable WhatsApp for this workspace
                        </label>
                        <PrimaryButton type="submit" disabled={form.processing}>
                            {form.processing
                                ? 'Saving…'
                                : showManual
                                  ? 'Save & connect'
                                  : 'Save profile'}
                        </PrimaryButton>
                    </section>
                </>
            ) : (
                <section className="atlas-panel rounded-xl border border-amber-100 bg-amber-50/70 p-4 text-sm text-amber-900">
                    Only workspace owners and admins can connect WhatsApp. Ask your workspace owner
                    to add the Meta credentials here.
                </section>
            )}
        </form>
    );
}

function CopyBox({ label, value, copyable = true }) {
    const copy = async () => {
        try {
            await navigator.clipboard.writeText(value);
            toast.success(`${label} copied`);
        } catch {
            toast.error('Copy failed — select and copy manually.');
        }
    };

    return (
        <div className="rounded-lg border border-line bg-mist/50 px-3 py-2 text-sm">
            <div className="flex items-center justify-between gap-2">
                <div className="text-[10px] font-bold uppercase tracking-wide text-ink-muted">
                    {label}
                </div>
                {copyable ? (
                    <button
                        type="button"
                        className="text-[11px] font-semibold text-ink-muted underline hover:text-ink"
                        onClick={copy}
                    >
                        Copy
                    </button>
                ) : null}
            </div>
            <code className="mt-1 block break-all text-xs text-ink">{value}</code>
        </div>
    );
}

function PlaceholderRow({ placeholders, onInsert, onMouseDown }) {
    if (!placeholders?.length) return null;
    return (
        <div className="mt-1 flex flex-wrap gap-1">
            {placeholders.map((p) => (
                <button
                    key={p.token}
                    type="button"
                    className="rounded bg-mist px-1.5 py-0.5 text-[10px] font-semibold text-ink-muted hover:text-ink"
                    onMouseDown={onMouseDown}
                    onClick={() => onInsert(p.token)}
                >
                    {p.token}
                </button>
            ))}
        </div>
    );
}
