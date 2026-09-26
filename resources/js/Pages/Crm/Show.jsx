import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import HelpGuide, { HELP } from '@/Components/HelpGuide';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import { confirmAsk } from '@/Components/ConfirmProvider';
import { Head, Link, router, useForm } from '@inertiajs/react';

const stages = ['new', 'contacted', 'qualified', 'won', 'lost'];
const stageLabel = {
    new: 'New',
    contacted: 'Contacted',
    qualified: 'Qualified',
    won: 'Won',
    lost: 'Lost',
};

const activityLabel = {
    note: 'Note',
    stage_change: 'Stage',
    created: 'Created',
    whatsapp: 'WhatsApp',
    file: 'File',
    quotation: 'Quote',
    score: 'Score',
    follow_up: 'Follow-up',
};

const kindLabel = {
    pdf: 'PDF',
    spreadsheet: 'Excel',
    image: 'Image',
    file: 'File',
};

function formatBytes(bytes) {
    const n = Number(bytes || 0);
    if (n < 1024) return `${n} B`;
    if (n < 1024 * 1024) return `${(n / 1024).toFixed(1)} KB`;
    return `${(n / (1024 * 1024)).toFixed(1)} MB`;
}

export default function Show({ workspace, lead, activities = [], conversations = [], attachments = [] }) {
    const noteForm = useForm({ body: '', files: [] });
    const detailsForm = useForm({
        name: lead.name || '',
        email: lead.email || '',
        phone: lead.phone || '',
        company: lead.company || '',
        value_cents: lead.value_cents || 0,
        notes: lead.notes || '',
    });

    const canSubmitNote = Boolean(noteForm.data.body?.trim()) || Boolean(noteForm.data.files?.length);
    return (
        <AuthenticatedLayout
            header={
                <div>
                    <div className="text-xs font-semibold uppercase tracking-[0.16em] text-ink-muted">
                        <Link href={route('crm.index')} className="hover:text-ink">
                            {workspace.name} · CRM
                        </Link>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <h2 className="font-display text-2xl font-bold text-ink">{lead.name}</h2>
                        <HelpGuide help={HELP.crm} />
                        <span className="rounded border border-line px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-ink-muted">
                            {stageLabel[lead.stage] || lead.stage}
                        </span>
                        {lead.score_band ? (
                            <span
                                className={
                                    'rounded border px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide ' +
                                    (lead.score_band === 'hot'
                                        ? 'border-rose-200 bg-rose-50 text-rose-800'
                                        : lead.score_band === 'warm'
                                          ? 'border-amber-200 bg-amber-50 text-amber-900'
                                          : 'border-line bg-mist text-ink-muted')
                                }
                            >
                                {lead.score} {lead.score_band}
                            </span>
                        ) : null}
                    </div>
                </div>
            }
        >
            <Head title={`${lead.name} · CRM`} />

            <div className="atlas-shell space-y-4">
                <div className="flex flex-wrap gap-1.5">
                    {stages.map((s) => (
                        <button
                            key={s}
                            type="button"
                            disabled={s === lead.stage}
                            className={`rounded border px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide ${
                                s === lead.stage
                                    ? 'border-signal/40 bg-signal/10 text-ink'
                                    : 'border-line text-ink-muted hover:border-signal/40 hover:text-ink'
                            }`}
                            onClick={() => router.patch(route('crm.update', lead.id), { stage: s })}
                        >
                            {stageLabel[s]}
                        </button>
                    ))}
                    <button
                        type="button"
                        className="ml-auto rounded border border-rose-200 px-2.5 py-1 text-[11px] font-semibold uppercase text-rose-600"
                        onClick={async () => {
                            const ok = await confirmAsk({
                                title: 'Delete this lead?',
                                message: 'This permanently removes the lead, notes, and files.',
                                confirmLabel: 'Delete lead',
                            });
                            if (ok) {
                                router.delete(route('crm.destroy', lead.id));
                            }
                        }}
                    >
                        Delete
                    </button>
                </div>

                <div className="grid gap-4 lg:grid-cols-[320px_minmax(0,1fr)]">
                    <aside className="space-y-4">
                        <section className="atlas-panel p-4">
                            <h3 className="font-display text-lg font-bold text-ink">AI score</h3>
                            <div className="mt-3 flex flex-wrap items-center gap-2">
                                {lead.score != null ? (
                                    <>
                                        <span
                                            className={
                                                'rounded-md px-2.5 py-1 text-sm font-bold ' +
                                                (lead.score_band === 'hot'
                                                    ? 'bg-rose-100 text-rose-800'
                                                    : lead.score_band === 'warm'
                                                      ? 'bg-amber-100 text-amber-900'
                                                      : 'bg-slate-100 text-slate-700')
                                            }
                                        >
                                            {lead.score} · {(lead.score_band || '').toUpperCase()}
                                        </span>
                                        <span className="text-[11px] text-ink-muted">
                                            {lead.score_source || '—'}
                                            {lead.scored_at ? ` · ${lead.scored_at}` : ''}
                                        </span>
                                    </>
                                ) : (
                                    <p className="text-sm text-ink-muted">Not scored yet.</p>
                                )}
                            </div>
                            {lead.score_reason ? (
                                <p className="mt-2 text-xs leading-relaxed text-ink-muted">{lead.score_reason}</p>
                            ) : null}
                            {lead.next_action ? (
                                <p className="mt-2 text-sm font-semibold text-ink">Next: {lead.next_action}</p>
                            ) : null}
                            <div className="mt-3 flex flex-wrap gap-2">
                                <SecondaryButton
                                    type="button"
                                    onClick={() => router.post(route('crm.score', lead.id))}
                                >
                                    Score lead
                                </SecondaryButton>
                                <SecondaryButton
                                    type="button"
                                    onClick={() => router.post(route('crm.follow-up', lead.id))}
                                >
                                    Suggest follow-up
                                </SecondaryButton>
                            </div>
                            {lead.follow_up_suggestion ? (
                                <div className="mt-3 rounded-lg border border-line bg-mist/40 p-3">
                                    <div className="text-[10px] font-bold uppercase tracking-wide text-ink-muted">
                                        Suggested message
                                        {lead.follow_up_due_at ? ` · due ${lead.follow_up_due_at}` : ''}
                                    </div>
                                    <p className="mt-1 whitespace-pre-wrap text-sm text-ink">
                                        {lead.follow_up_suggestion}
                                    </p>
                                    {lead.phone ? (
                                        <a
                                            href={`https://wa.me/${String(lead.phone).replace(/\D+/g, '')}?text=${encodeURIComponent(lead.follow_up_suggestion)}`}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="mt-2 inline-block text-xs font-semibold text-emerald-700 hover:underline"
                                        >
                                            Send on WhatsApp
                                        </a>
                                    ) : null}
                                </div>
                            ) : null}
                        </section>

                        <section className="atlas-panel p-4">
                            <h3 className="font-display text-lg font-bold text-ink">Contact</h3>
                            <form
                                className="mt-3 space-y-3"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    detailsForm.patch(route('crm.update', lead.id));
                                }}
                            >
                                <div>
                                    <InputLabel value="Name" />
                                    <TextInput
                                        className="mt-1.5 w-full"
                                        value={detailsForm.data.name}
                                        onChange={(e) => detailsForm.setData('name', e.target.value)}
                                        required
                                    />
                                </div>
                                <div>
                                    <InputLabel value="Email" />
                                    <TextInput
                                        type="email"
                                        className="mt-1.5 w-full"
                                        value={detailsForm.data.email}
                                        onChange={(e) => detailsForm.setData('email', e.target.value)}
                                    />
                                </div>
                                <div>
                                    <InputLabel value="Phone" />
                                    <TextInput
                                        className="mt-1.5 w-full"
                                        value={detailsForm.data.phone}
                                        onChange={(e) => detailsForm.setData('phone', e.target.value)}
                                    />
                                </div>
                                <div>
                                    <InputLabel value="Company" />
                                    <TextInput
                                        className="mt-1.5 w-full"
                                        value={detailsForm.data.company}
                                        onChange={(e) => detailsForm.setData('company', e.target.value)}
                                    />
                                </div>
                                <div>
                                    <InputLabel value="Deal value (USD)" />
                                    <TextInput
                                        type="number"
                                        className="mt-1.5 w-full"
                                        value={detailsForm.data.value_cents / 100}
                                        onChange={(e) =>
                                            detailsForm.setData(
                                                'value_cents',
                                                Math.round(Number(e.target.value || 0) * 100),
                                            )
                                        }
                                    />
                                </div>
                                <PrimaryButton processing={detailsForm.processing}>Save details</PrimaryButton>
                            </form>
                            <div className="mt-3 border-t border-line pt-3 text-xs text-ink-muted">
                                Source: {lead.source || '—'}
                                {lead.last_contacted_at ? ` · Last contact ${lead.last_contacted_at}` : ''}
                            </div>
                        </section>

                        <section className="atlas-panel p-4">
                            <div className="flex items-center justify-between gap-2">
                                <h3 className="font-display text-lg font-bold text-ink">WhatsApp</h3>
                                <SecondaryButton
                                    type="button"
                                    disabled={!lead.phone}
                                    onClick={() => router.post(route('crm.whatsapp.open', lead.id))}
                                >
                                    Open chat
                                </SecondaryButton>
                            </div>
                            {!lead.phone ? (
                                <p className="mt-2 text-sm text-ink-muted">Add a phone number to start a chat.</p>
                            ) : conversations.length === 0 ? (
                                <p className="mt-2 text-sm text-ink-muted">No conversation linked yet.</p>
                            ) : (
                                <ul className="mt-3 divide-y divide-line">
                                    {conversations.map((c) => (
                                        <li key={c.id} className="py-2">
                                            <Link
                                                href={route('whatsapp.index', {
                                                    view: 'conversations',
                                                    conversation: c.id,
                                                })}
                                                className="block hover:text-signal"
                                            >
                                                <div className="text-sm font-semibold text-ink">{c.phone}</div>
                                                <div className="truncate text-xs text-ink-muted">
                                                    {c.last_message_preview || 'No messages yet'}
                                                </div>
                                                <div className="mt-0.5 text-[11px] text-ink-muted">
                                                    {c.last_message_at || '—'}
                                                    {c.unread_count > 0 ? ` · ${c.unread_count} unread` : ''}
                                                </div>
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </section>
                    </aside>

                    <div className="space-y-4">
                        <section className="atlas-panel p-4">
                            <h3 className="font-display text-lg font-bold text-ink">Timeline</h3>
                            <form
                                className="mt-3 space-y-3"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    if (!canSubmitNote) return;
                                    noteForm.post(route('crm.notes.store', lead.id), {
                                        forceFormData: true,
                                        onSuccess: () => noteForm.reset('body', 'files'),
                                    });
                                }}
                            >
                                <textarea
                                    className="atlas-input min-h-[88px] w-full"
                                    placeholder="Add a note, call summary, next step…"
                                    value={noteForm.data.body}
                                    onChange={(e) => noteForm.setData('body', e.target.value)}
                                />
                                <div>
                                    <InputLabel value="Attach PDF / Excel / images" />
                                    <input
                                        type="file"
                                        multiple
                                        accept=".pdf,.xls,.xlsx,.csv,image/jpeg,image/png,image/gif,image/webp,.jpg,.jpeg,.png,.gif,.webp"
                                        className="mt-1.5 block w-full text-sm text-ink file:mr-3 file:rounded-md file:border-0 file:bg-signal/10 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-ink hover:file:bg-signal/20"
                                        onChange={(e) =>
                                            noteForm.setData('files', Array.from(e.target.files || []))
                                        }
                                    />
                                    {noteForm.data.files?.length ? (
                                        <p className="mt-1 text-xs text-ink-muted">
                                            {noteForm.data.files.length} file
                                            {noteForm.data.files.length > 1 ? 's' : ''} selected
                                        </p>
                                    ) : null}
                                    {noteForm.errors.files || noteForm.errors['files.0'] ? (
                                        <p className="mt-1 text-xs text-rose-600">
                                            {noteForm.errors.files || noteForm.errors['files.0']}
                                        </p>
                                    ) : null}
                                </div>
                                <PrimaryButton processing={noteForm.processing} disabled={!canSubmitNote}>
                                    Add note
                                </PrimaryButton>
                            </form>

                            <ul className="mt-5 space-y-0">
                                {activities.length === 0 ? (
                                    <li className="py-6 text-sm text-ink-muted">No activity yet.</li>
                                ) : (
                                    activities.map((a) => {
                                        const files = a.meta?.attachments || [];
                                        return (
                                            <li key={a.id} className="relative border-l border-line py-3 pl-4">
                                                <span className="absolute -left-1.5 top-4 h-3 w-3 rounded-full border-2 border-white bg-signal/70" />
                                                <div className="flex flex-wrap items-baseline gap-2">
                                                    <span className="text-[10px] font-semibold uppercase tracking-wide text-ink-muted">
                                                        {activityLabel[a.type] || a.type}
                                                    </span>
                                                    <span className="text-[11px] text-ink-muted">{a.created_at}</span>
                                                    {a.user_name ? (
                                                        <span className="text-[11px] text-ink-muted">
                                                            · {a.user_name}
                                                        </span>
                                                    ) : null}
                                                </div>
                                                <p className="mt-1 whitespace-pre-wrap text-sm text-ink">{a.body}</p>
                                                {files.length > 0 ? (
                                                    <ul className="mt-2 flex flex-wrap gap-2">
                                                        {files.map((f) => (
                                                            <li key={f.id}>
                                                                <a
                                                                    href={route('crm.attachments.download', [
                                                                        lead.id,
                                                                        f.id,
                                                                    ])}
                                                                    className="inline-flex items-center gap-1.5 rounded border border-line px-2 py-1 text-xs font-semibold text-ink hover:border-signal/40"
                                                                >
                                                                    {f.kind === 'image' && f.url ? (
                                                                        <img
                                                                            src={f.url}
                                                                            alt=""
                                                                            className="h-6 w-6 rounded object-cover"
                                                                        />
                                                                    ) : null}
                                                                    <span>
                                                                        {kindLabel[f.kind] || 'File'}: {f.name}
                                                                    </span>
                                                                </a>
                                                            </li>
                                                        ))}
                                                    </ul>
                                                ) : null}
                                            </li>
                                        );
                                    })
                                )}
                            </ul>

                            {attachments.length > 0 ? (
                                <div className="mt-5 border-t border-line pt-4">
                                    <h4 className="text-xs font-semibold uppercase tracking-wide text-ink-muted">
                                        All files
                                    </h4>
                                    <ul className="mt-2 divide-y divide-line">
                                        {attachments.map((file) => (
                                            <li
                                                key={file.id}
                                                className="flex flex-wrap items-center justify-between gap-2 py-2"
                                            >
                                                <div className="min-w-0">
                                                    <div className="truncate text-sm font-semibold text-ink">
                                                        {file.original_name}
                                                    </div>
                                                    <div className="text-[11px] text-ink-muted">
                                                        {kindLabel[file.kind] || 'File'} · {formatBytes(file.size)}
                                                    </div>
                                                </div>
                                                <div className="flex gap-3">
                                                    <a
                                                        href={route('crm.attachments.download', [lead.id, file.id])}
                                                        className="text-[10px] font-semibold uppercase text-signal"
                                                    >
                                                        Download
                                                    </a>
                                                    <button
                                                        type="button"
                                                        className="text-[10px] font-semibold uppercase text-rose-600"
                                                        onClick={async () => {
                                                            const ok = await confirmAsk({
                                                                title: 'Remove this file?',
                                                                message: file.original_name,
                                                                confirmLabel: 'Remove',
                                                            });
                                                            if (ok) {
                                                                router.delete(
                                                                    route('crm.attachments.destroy', [
                                                                        lead.id,
                                                                        file.id,
                                                                    ]),
                                                                );
                                                            }
                                                        }}
                                                    >
                                                        Remove
                                                    </button>
                                                </div>
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            ) : null}
                        </section>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
