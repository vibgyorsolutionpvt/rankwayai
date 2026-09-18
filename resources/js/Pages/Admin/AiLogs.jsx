import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import JsonDetailsModal, { prettyJson } from '@/Components/JsonDetailsModal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectMenu from '@/Components/SelectMenu';
import TextInput from '@/Components/TextInput';
import { Head, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';

const PERIODS = [
    { id: 'today', label: 'Today' },
    { id: '7d', label: '7 days' },
    { id: '30d', label: '30 days' },
    { id: 'all', label: 'All time' },
];

const STATUSES = [
    { id: 'all', label: 'All' },
    { id: 'ok', label: 'Live OK' },
    { id: 'template', label: 'Template fallback' },
    { id: 'failed', label: 'Failed / fallback' },
];

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

function formatStamp(value) {
    if (!value) {
        return { date: '—', time: '' };
    }
    const [date, time] = String(value).split(' ');
    const parts = (date || '').split('-');
    if (parts.length !== 3) {
        return { date: value, time: time || '' };
    }
    const month = MONTHS[Number(parts[1]) - 1] || parts[1];
    return {
        date: `${Number(parts[2])} ${month} ${parts[0]}`,
        time: (time || '').slice(0, 5),
    };
}

function ChipRow({ items, value, onChange }) {
    return (
        <div className="flex flex-wrap gap-1.5">
            {items.map((item) => {
                const active = value === item.id;
                return (
                    <button
                        key={item.id}
                        type="button"
                        onClick={() => onChange(item.id)}
                        className={
                            'rounded-md border px-2.5 py-1.5 text-xs font-semibold transition ' +
                            (active
                                ? 'border-signal bg-signal text-white shadow-sm'
                                : 'border-line bg-white text-ink-muted hover:border-signal/50 hover:text-ink')
                        }
                    >
                        {item.label}
                    </button>
                );
            })}
        </div>
    );
}

function StatusPill({ log }) {
    if (log.is_template) {
        return (
            <span className="inline-flex rounded-md bg-amber-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-amber-900">
                Template
            </span>
        );
    }
    if (log.ok) {
        return (
            <span className="inline-flex rounded-md bg-emerald-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-emerald-800">
                Live OK
            </span>
        );
    }
    return (
        <span className="inline-flex rounded-md bg-rose-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-rose-800">
            Failed
        </span>
    );
}

function ProviderPill({ name, configured, tier }) {
    return (
        <div
            className={
                'rounded-lg border px-3 py-2 ' +
                (configured
                    ? 'border-emerald-200 bg-emerald-50/80'
                    : 'border-line bg-mist/40')
            }
        >
            <div className="flex items-center justify-between gap-2">
                <span className="text-xs font-bold text-ink">{name}</span>
                <span
                    className={
                        'text-[10px] font-bold uppercase tracking-wide ' +
                        (configured ? 'text-emerald-700' : 'text-ink-muted')
                    }
                >
                    {configured ? 'Key set' : 'Missing'}
                </span>
            </div>
            <div className="mt-1 text-[10px] font-semibold uppercase tracking-wide text-ink-muted">
                {tier || '—'}
            </div>
        </div>
    );
}

function CodeBlock({ title, value, empty = 'No data' }) {
    const text = useMemo(() => {
        if (value == null || value === '') {
            return '';
        }
        if (typeof value === 'string') {
            return value;
        }
        return prettyJson(value);
    }, [value]);

    return (
        <div className="overflow-hidden rounded-xl border border-line/80 bg-[#0f1419] shadow-inner">
            <div className="flex items-center justify-between border-b border-white/10 px-4 py-2.5">
                <span className="text-[11px] font-bold uppercase tracking-[0.16em] text-white/60">
                    {title}
                </span>
                {text ? (
                    <button
                        type="button"
                        className="text-[11px] font-semibold text-sky-300 hover:text-sky-200"
                        onClick={() => navigator.clipboard?.writeText(text)}
                    >
                        Copy
                    </button>
                ) : null}
            </div>
            <pre className="max-h-[28rem] overflow-auto px-4 py-3 font-mono text-[11px] leading-relaxed text-emerald-100/90">
                {text || empty}
            </pre>
        </div>
    );
}

function Pagination({ links = [] }) {
    if (!links?.length) {
        return null;
    }
    return (
        <div className="flex flex-wrap gap-1.5 border-t border-line/70 px-4 py-3">
            {links.map((link, index) => (
                <button
                    key={`${link.label}-${index}`}
                    type="button"
                    disabled={!link.url}
                    onClick={() => link.url && router.get(link.url, {}, { preserveState: true })}
                    className={
                        'rounded-md px-2.5 py-1 text-xs font-semibold transition ' +
                        (link.active
                            ? 'bg-signal text-white'
                            : link.url
                              ? 'bg-mist text-ink hover:bg-signal-soft'
                              : 'cursor-not-allowed bg-mist/50 text-ink-muted')
                    }
                    dangerouslySetInnerHTML={{ __html: link.label }}
                />
            ))}
        </div>
    );
}

export default function AiLogs({
    summary = {},
    logs,
    selected = null,
    providers = [],
    providerOptions = [],
    filterOptions = {},
    filters = {},
}) {
    const [q, setQ] = useState(filters.q || '');
    const [jsonModal, setJsonModal] = useState(null);

    const applyFilters = (patch = {}) => {
        router.get(
            route('admin.ai-logs'),
            {
                q: patch.q !== undefined ? patch.q : q,
                provider: patch.provider ?? filters.provider ?? 'all',
                status: patch.status ?? filters.status ?? 'all',
                period: patch.period ?? filters.period ?? '7d',
                workspace_id: patch.workspace_id ?? filters.workspace_id ?? '',
                id: patch.id ?? filters.id ?? '',
            },
            { preserveState: true, replace: true },
        );
    };

    const openLog = (id) => {
        applyFilters({ id });
    };

    const providerSelect = [
        { value: 'all', label: 'All providers' },
        ...providerOptions.map((id) => ({ value: id, label: id })),
    ];

    const workspaceSelect = [
        { value: '', label: 'All workspaces' },
        ...(filterOptions.workspaces || []).map((ws) => ({
            value: String(ws.id),
            label: ws.name,
        })),
    ];

    const stamp = formatStamp(selected?.created_at);

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <div className="text-xs font-semibold uppercase tracking-[0.18em] text-ink-muted">
                            Super admin
                        </div>
                        <h2 className="font-display text-2xl font-bold tracking-tight text-ink">
                            AI request logs
                        </h2>
                        <p className="mt-1 max-w-2xl text-sm text-ink-muted">
                            Full compose prompt → provider attempts → response. Use this to see
                            where garbage drafts come from (template fallback vs live model).
                        </p>
                    </div>
                    <SecondaryButton
                        type="button"
                        onClick={() => router.post(route('admin.ai-logs.clear-failover'))}
                    >
                        Clear failover / sticky
                    </SecondaryButton>
                </div>
            }
        >
            <Head title="Admin · AI logs" />

            <div className="atlas-shell space-y-5">
                <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                    {[
                        { label: 'Total hits', value: summary.total ?? 0 },
                        { label: 'Live OK', value: summary.live_ok ?? 0 },
                        { label: 'Template fallback', value: summary.template ?? 0 },
                        { label: 'Failed', value: summary.failed ?? 0 },
                        { label: 'Tokens', value: summary.tokens ?? 0 },
                    ].map((card) => (
                        <div key={card.label} className="atlas-panel px-4 py-3">
                            <div className="text-[10px] font-bold uppercase tracking-[0.16em] text-ink-muted">
                                {card.label}
                            </div>
                            <div className="mt-1 font-display text-3xl font-bold text-ink">
                                {card.value}
                            </div>
                        </div>
                    ))}
                </section>

                <section className="atlas-panel p-4">
                    <div className="mb-3 text-xs font-bold uppercase tracking-[0.16em] text-ink-muted">
                        Provider keys (production)
                    </div>
                    <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                        {providers.map((p) => (
                            <ProviderPill
                                key={p.id}
                                name={p.label || p.id}
                                configured={p.configured}
                                tier={p.tier}
                            />
                        ))}
                    </div>
                    <p className="mt-3 text-xs text-ink-muted">
                        If OpenAI shows <span className="font-semibold text-ink">Missing</span>,
                        compose falls to template — that is the bakchodi source, not the Social
                        page UI.
                    </p>
                </section>

                <section className="atlas-panel space-y-3 p-4">
                    <div className="flex flex-wrap items-end gap-3">
                        <div className="min-w-[14rem] flex-1">
                            <label className="mb-1 block text-[10px] font-bold uppercase tracking-wide text-ink-muted">
                                Search prompt / error
                            </label>
                            <div className="flex gap-2">
                                <TextInput
                                    value={q}
                                    onChange={(e) => setQ(e.target.value)}
                                    onKeyDown={(e) => {
                                        if (e.key === 'Enter') {
                                            applyFilters({ q, id: '' });
                                        }
                                    }}
                                    placeholder="agra mathura, 429, cerebras…"
                                    className="w-full"
                                />
                                <PrimaryButton
                                    type="button"
                                    onClick={() => applyFilters({ q, id: '' })}
                                >
                                    Search
                                </PrimaryButton>
                            </div>
                        </div>
                        <div className="w-44">
                            <label className="mb-1 block text-[10px] font-bold uppercase tracking-wide text-ink-muted">
                                Provider
                            </label>
                            <SelectMenu
                                value={filters.provider || 'all'}
                                onChange={(value) =>
                                    applyFilters({ provider: value, id: '' })
                                }
                                options={providerSelect}
                            />
                        </div>
                        <div className="w-52">
                            <label className="mb-1 block text-[10px] font-bold uppercase tracking-wide text-ink-muted">
                                Workspace
                            </label>
                            <SelectMenu
                                value={
                                    filters.workspace_id
                                        ? String(filters.workspace_id)
                                        : ''
                                }
                                onChange={(value) =>
                                    applyFilters({
                                        workspace_id: value || '',
                                        id: '',
                                    })
                                }
                                options={workspaceSelect}
                            />
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-4">
                        <div>
                            <div className="mb-1 text-[10px] font-bold uppercase tracking-wide text-ink-muted">
                                Status
                            </div>
                            <ChipRow
                                items={STATUSES}
                                value={filters.status || 'all'}
                                onChange={(status) => applyFilters({ status, id: '' })}
                            />
                        </div>
                        <div>
                            <div className="mb-1 text-[10px] font-bold uppercase tracking-wide text-ink-muted">
                                Period
                            </div>
                            <ChipRow
                                items={PERIODS}
                                value={filters.period || '7d'}
                                onChange={(period) => applyFilters({ period, id: '' })}
                            />
                        </div>
                    </div>
                </section>

                <section className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(22rem,28rem)]">
                    <div className="atlas-panel overflow-hidden">
                        <div className="border-b border-line/70 px-4 py-3">
                            <h3 className="font-display text-lg font-bold text-ink">
                                Compose attempts
                            </h3>
                        </div>
                        <div className="divide-y divide-line/60">
                            {(logs?.data || []).length === 0 ? (
                                <div className="px-4 py-10 text-center text-sm text-ink-muted">
                                    No AI compose logs for these filters.
                                </div>
                            ) : (
                                logs.data.map((log) => {
                                    const when = formatStamp(log.created_at);
                                    const active = selected?.id === log.id;
                                    return (
                                        <button
                                            key={log.id}
                                            type="button"
                                            onClick={() => openLog(log.id)}
                                            className={
                                                'flex w-full flex-col gap-2 px-4 py-3 text-left transition ' +
                                                (active
                                                    ? 'bg-signal-soft/60'
                                                    : 'hover:bg-mist/70')
                                            }
                                        >
                                            <div className="flex flex-wrap items-center gap-2">
                                                <StatusPill log={log} />
                                                <span className="rounded-md bg-ink px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-white">
                                                    {log.provider}
                                                </span>
                                                {log.http_status ? (
                                                    <span className="text-[11px] font-semibold text-ink-muted">
                                                        HTTP {log.http_status}
                                                    </span>
                                                ) : null}
                                                <span className="ml-auto text-[11px] text-ink-muted">
                                                    {when.date} · {when.time}
                                                </span>
                                            </div>
                                            <div className="line-clamp-2 text-sm font-semibold text-ink">
                                                {log.prompt}
                                            </div>
                                            <div className="flex flex-wrap gap-x-3 gap-y-1 text-[11px] text-ink-muted">
                                                <span>{log.workspace || '—'}</span>
                                                <span>{log.user || '—'}</span>
                                                {log.attempt_chain?.length ? (
                                                    <span className="font-mono text-[10px]">
                                                        {log.attempt_chain.join(' → ')}
                                                    </span>
                                                ) : null}
                                            </div>
                                            {log.error ? (
                                                <div className="line-clamp-2 rounded-md bg-rose-50 px-2 py-1 text-[11px] text-rose-800">
                                                    {log.error}
                                                </div>
                                            ) : null}
                                        </button>
                                    );
                                })
                            )}
                        </div>
                        <Pagination links={logs?.links} />
                    </div>

                    <aside className="atlas-panel overflow-hidden xl:sticky xl:top-4 xl:self-start">
                        {!selected ? (
                            <div className="px-5 py-12 text-center text-sm text-ink-muted">
                                Select a log to inspect request / response / failover chain.
                            </div>
                        ) : (
                            <div className="space-y-4 p-4">
                                <div>
                                    <div className="flex flex-wrap items-center gap-2">
                                        <StatusPill log={selected} />
                                        <span className="rounded-md bg-ink px-2 py-0.5 text-[10px] font-bold uppercase text-white">
                                            {selected.provider}
                                        </span>
                                    </div>
                                    <h3 className="mt-2 font-display text-xl font-bold text-ink">
                                        #{selected.id} · {stamp.date} {stamp.time}
                                    </h3>
                                    <p className="mt-1 text-sm text-ink-muted">
                                        {selected.workspace} · {selected.user} (
                                        {selected.user_email || '—'})
                                    </p>
                                </div>

                                <div className="rounded-xl border border-line bg-mist/40 p-3">
                                    <div className="text-[10px] font-bold uppercase tracking-wide text-ink-muted">
                                        User prompt
                                    </div>
                                    <p className="mt-1 text-sm font-semibold text-ink">
                                        {selected.prompt}
                                    </p>
                                    {selected.offer ? (
                                        <p className="mt-2 text-xs text-ink-muted">
                                            Offer: {selected.offer}
                                        </p>
                                    ) : null}
                                </div>

                                {selected.attempt_chain?.length ? (
                                    <div>
                                        <div className="mb-2 text-[10px] font-bold uppercase tracking-wide text-ink-muted">
                                            Failover chain
                                        </div>
                                        <div className="flex flex-wrap gap-1.5">
                                            {selected.attempt_chain.map((step, i) => (
                                                <span
                                                    key={`${step}-${i}`}
                                                    className="rounded-md border border-line bg-white px-2 py-1 font-mono text-[11px] text-ink"
                                                >
                                                    {step}
                                                </span>
                                            ))}
                                        </div>
                                    </div>
                                ) : null}

                                {selected.error ? (
                                    <div className="rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-900">
                                        {selected.error}
                                    </div>
                                ) : null}

                                <div className="grid gap-2 sm:grid-cols-2">
                                    <div className="rounded-lg border border-line px-3 py-2">
                                        <div className="text-[10px] font-bold uppercase text-ink-muted">
                                            Model
                                        </div>
                                        <div className="mt-0.5 truncate text-sm font-semibold text-ink">
                                            {selected.model || '—'}
                                        </div>
                                    </div>
                                    <div className="rounded-lg border border-line px-3 py-2">
                                        <div className="text-[10px] font-bold uppercase text-ink-muted">
                                            Tokens
                                        </div>
                                        <div className="mt-0.5 text-sm font-semibold text-ink">
                                            {selected.tokens ?? 0}
                                        </div>
                                    </div>
                                </div>

                                {selected.api_url ? (
                                    <div className="truncate rounded-lg border border-line bg-white px-3 py-2 font-mono text-[11px] text-ink-muted">
                                        {selected.api_url}
                                    </div>
                                ) : null}

                                <CodeBlock
                                    title="Request payload"
                                    value={selected.request_payload}
                                />
                                <CodeBlock
                                    title="Response text / JSON"
                                    value={
                                        selected.response_text || selected.response_payload
                                    }
                                />
                                <CodeBlock title="Attempts detail" value={selected.attempts} />
                                <CodeBlock
                                    title="Draft returned to UI"
                                    value={selected.draft}
                                />

                                <div className="flex flex-wrap gap-2">
                                    <SecondaryButton
                                        type="button"
                                        onClick={() =>
                                            setJsonModal({
                                                title: 'Full log JSON',
                                                value: selected,
                                            })
                                        }
                                    >
                                        Open full JSON
                                    </SecondaryButton>
                                </div>
                            </div>
                        )}
                    </aside>
                </section>
            </div>

            <JsonDetailsModal
                show={Boolean(jsonModal)}
                title={jsonModal?.title || 'Details'}
                value={jsonModal?.value}
                onClose={() => setJsonModal(null)}
            />
        </AuthenticatedLayout>
    );
}
