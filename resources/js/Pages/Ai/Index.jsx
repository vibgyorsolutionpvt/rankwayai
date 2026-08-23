import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import HelpGuide, { HELP } from '@/Components/HelpGuide';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectMenu from '@/Components/SelectMenu';
import TextInput from '@/Components/TextInput';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import axios from 'axios';
import { useState } from 'react';

export default function Index({
    workspace,
    settings,
    setup_complete: setupComplete = false,
    credits = null,
    draft_count: draftCount = 0,
    festivals = [],
    next_festival: nextFestival = null,
    generations = [],
    seo_drafts = [],
    plan = null,
    publish_platforms: publishPlatforms = [],
    publish_platform_labels: publishPlatformLabels = [],
}) {
    const { flash } = usePage().props;
    const [showExtras, setShowExtras] = useState(false);
    const [previewLoading, setPreviewLoading] = useState(false);
    const [previews, setPreviews] = useState([]);
    const [previewError, setPreviewError] = useState('');
    const [previewProvider, setPreviewProvider] = useState('');

    const form = useForm({
        industry: workspace.industry || '',
        location: workspace.city || '',
        tone: settings.tone || 'mixed',
        word_limit: String(settings.caption_word_limit || 50),
        brief: '',
        offer: '',
        festival_id: '',
        post_count: '1',
    });

    const postCount = String(form.data.post_count || '1');
    const postCountLabel = postCount === '1' ? '1 draft' : `${postCount} drafts`;

    const festivalOptions = [
        { value: '', label: 'No festival — topic only' },
        ...festivals.map((f) => ({
            value: String(f.id),
            label: `${f.name} · ${f.date_label}${f.days_label ? ` (${f.days_label})` : ''}`,
        })),
    ];

    const hasWorkspaceProfile = Boolean(workspace.has_business_profile);
    const contactParts = [workspace.phone, workspace.email, workspace.website].filter(Boolean);

    const available = credits?.available ?? 0;
    const aiLocked = plan && !plan.features?.ai && available < 1;
    const canSubmit = !aiLocked && available >= 1;

    const selectedFestival = festivals.find(
        (f) => String(f.id) === String(form.data.festival_id),
    );

    const briefPlaceholder = selectedFestival
        ? `e.g. ${selectedFestival.name} special — Goa packages 15% off, family trips from ${workspace.city || 'your city'}`
        : 'e.g. Lucknow to Goa monsoon package — 15% off, family friendly, limited seats till 30 Aug';

    const submit = (e) => {
        e.preventDefault();
        if (!canSubmit || form.processing) return;
        form.transform((data) => ({
            ...data,
            draft_count: Number(data.post_count || 1),
        })).post(route('ai.generate-today'));
    };

    const generatePreview = async () => {
        if (!canSubmit || previewLoading) return;

        setPreviewLoading(true);
        setPreviewError('');
        setPreviews([]);

        try {
            const festivalId = form.data.festival_id ? Number(form.data.festival_id) : null;
            const { data } = await axios.post(route('ai.preview-today'), {
                brief: form.data.brief,
                offer: form.data.offer,
                festival_id: festivalId,
                tone: form.data.tone,
                word_limit: Number(form.data.word_limit),
                draft_count: Number(postCount),
            });

            if (data.brief) {
                form.setData('brief', data.brief);
            }
            setPreviews(data.previews ?? []);
            setPreviewProvider(data.provider ?? '');
        } catch (err) {
            const message =
                err.response?.data?.message ||
                err.response?.data?.errors?.brief?.[0] ||
                'Could not generate preview. Try again.';
            setPreviewError(message);
        } finally {
            setPreviewLoading(false);
        }
    };

    return (
        <AuthenticatedLayout
            header={
                <div>
                    <div className="text-xs font-semibold uppercase tracking-[0.16em] text-ink-muted">
                        {workspace.name}
                    </div>
                    <div className="flex items-center gap-1.5">
                        <h2 className="font-display text-2xl font-bold text-ink">AI posts</h2>
                        <HelpGuide help={HELP.ai} />
                    </div>
                </div>
            }
        >
            <Head title="AI posts" />

            <div className="atlas-shell space-y-4">
                {flash?.success ? (
                    <div className="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
                        {flash.success}
                    </div>
                ) : null}

                {aiLocked ? (
                    <div className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm">
                        AI credits required.{' '}
                        <Link href={route('billing.index')} className="font-semibold text-signal-strong">
                            Billing →
                        </Link>
                    </div>
                ) : null}

                <section className="atlas-panel p-5 sm:p-6">
                    <h3 className="font-display text-lg font-bold text-ink">Create post drafts</h3>
                    <p className="mt-1 text-sm text-ink-muted">
                        {hasWorkspaceProfile
                            ? `${workspace.industry} · ${workspace.city} — enter your topic only; the rest comes from your workspace.`
                            : 'Save your business profile first (below or Settings → Workspace).'}
                        {publishPlatformLabels.length > 0 ? (
                            <>
                                {' '}
                                Drafts target:{' '}
                                <span className="font-medium text-ink">
                                    {publishPlatformLabels.join(', ')}
                                </span>
                                .
                            </>
                        ) : (
                            <>
                                {' '}
                                <Link
                                    href={route('social.index')}
                                    className="font-semibold text-signal-strong"
                                >
                                    Connect SMM accounts
                                </Link>{' '}
                                to choose platforms.
                            </>
                        )}
                    </p>

                    <form className="mt-5 space-y-4" onSubmit={submit}>
                        {hasWorkspaceProfile ? (
                            <div className="flex flex-wrap items-center gap-2 rounded-lg border border-line bg-mist/30 px-3 py-2 text-sm">
                                <span className="font-semibold text-ink">{workspace.name}</span>
                                <span className="text-ink-muted">·</span>
                                <span>{workspace.industry}</span>
                                <span className="text-ink-muted">·</span>
                                <span>{workspace.city}</span>
                                {contactParts.length > 0 ? (
                                    <>
                                        <span className="text-ink-muted">·</span>
                                        <span className="text-ink-muted">{contactParts.join(' · ')}</span>
                                    </>
                                ) : null}
                                <Link
                                    href={route('settings.index', { tab: 'workspace' })}
                                    className="ms-auto text-xs font-semibold text-signal-strong"
                                >
                                    Edit in Settings →
                                </Link>
                            </div>
                        ) : (
                            <div className="space-y-3 rounded-lg border border-amber-200 bg-amber-50/80 p-4">
                                <p className="text-sm text-amber-900">
                                    Workspace profile missing — save here or go to{' '}
                                    <Link
                                        href={route('settings.index', { tab: 'workspace' })}
                                        className="font-semibold text-signal-strong"
                                    >
                                        Settings → Workspace
                                    </Link>
                                    .
                                </p>
                                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                    <div>
                                        <InputLabel value="Business type *" />
                                        <TextInput
                                            className="mt-1 w-full"
                                            placeholder="Travel agency, IT company…"
                                            value={form.data.industry}
                                            onChange={(e) =>
                                                form.setData('industry', e.target.value)
                                            }
                                            required
                                        />
                                        {form.errors.industry ? (
                                            <p className="mt-1 text-xs text-red-600">
                                                {form.errors.industry}
                                            </p>
                                        ) : null}
                                    </div>
                                    <div>
                                        <InputLabel value="City *" />
                                        <TextInput
                                            className="mt-1 w-full"
                                            placeholder="Lucknow, Mumbai…"
                                            value={form.data.location}
                                            onChange={(e) =>
                                                form.setData('location', e.target.value)
                                            }
                                            required
                                        />
                                        {form.errors.location ? (
                                            <p className="mt-1 text-xs text-red-600">
                                                {form.errors.location}
                                            </p>
                                        ) : null}
                                    </div>
                                </div>
                            </div>
                        )}

                        {festivals.length > 0 ? (
                            <div className="rounded-lg border border-signal/20 bg-signal/5 p-4">
                                <InputLabel value="Festival / occasion (optional)" />
                                <p className="mt-0.5 text-xs text-ink-muted">
                                    Select first if this post is for a holiday — AI will weave the
                                    festival into every caption.
                                </p>
                                <div className="mt-2 max-w-md">
                                    <SelectMenu
                                        value={form.data.festival_id}
                                        onChange={(v) => {
                                            form.setData('festival_id', v);
                                            setPreviews([]);
                                            setPreviewError('');
                                        }}
                                        buttonClassName="!py-2"
                                        options={festivalOptions}
                                    />
                                </div>
                                {selectedFestival ? (
                                    <p className="mt-2 text-sm font-medium text-signal-strong">
                                        🎉 {selectedFestival.name} · {selectedFestival.date_label}
                                        {selectedFestival.days_label
                                            ? ` (${selectedFestival.days_label})`
                                            : ''}
                                    </p>
                                ) : null}
                                {form.errors.festival_id ? (
                                    <p className="mt-1 text-xs text-red-600">{form.errors.festival_id}</p>
                                ) : null}
                            </div>
                        ) : null}

                        <div className="grid gap-4 lg:grid-cols-4">
                            <div>
                                <InputLabel value="Language" />
                                <div className="mt-1">
                                    <SelectMenu
                                        value={form.data.tone}
                                        onChange={(v) => form.setData('tone', v)}
                                        buttonClassName="!py-2"
                                        options={[
                                            { value: 'mixed', label: 'Hindi + English' },
                                            { value: 'hindi', label: 'Hindi' },
                                            { value: 'english', label: 'English' },
                                        ]}
                                    />
                                </div>
                            </div>

                            <div>
                                <InputLabel value="How many drafts?" />
                                <div className="mt-1">
                                    <SelectMenu
                                        value={postCount}
                                        onChange={(v) => {
                                            form.setData('post_count', v || '1');
                                            setPreviews([]);
                                            setPreviewError('');
                                        }}
                                        buttonClassName="!py-2"
                                        options={[
                                            { value: '1', label: '1 draft' },
                                            { value: '2', label: '2 drafts' },
                                            { value: '3', label: '3 drafts' },
                                            { value: '4', label: '4 drafts' },
                                            { value: '5', label: '5 drafts' },
                                        ]}
                                    />
                                </div>
                                <p className="mt-1 text-xs text-ink-muted">
                                    Each draft gets its own angle + poster.
                                </p>
                            </div>

                            <div>
                                <InputLabel value="Post length" />
                                <div className="mt-1">
                                    <SelectMenu
                                        value={form.data.word_limit}
                                        onChange={(v) => {
                                            form.setData('word_limit', v);
                                            setPreviews([]);
                                            setPreviewError('');
                                        }}
                                        buttonClassName="!py-2"
                                        options={[
                                            { value: '50', label: 'Short (~50 words)' },
                                            { value: '80', label: 'Medium (~80 words)' },
                                            { value: '120', label: 'Long (~120 words)' },
                                        ]}
                                    />
                                </div>
                                <p className="mt-1 text-xs text-ink-muted">
                                    Main caption length — contact + hashtags are added separately.
                                </p>
                            </div>

                            <div>
                                <InputLabel value="Offer / CTA (optional)" />
                                <TextInput
                                    className="mt-1 w-full"
                                    placeholder={
                                        workspace.phone
                                            ? `Book now — Call ${workspace.phone}`
                                            : 'Book now — limited seats'
                                    }
                                    value={form.data.offer}
                                    onChange={(e) => form.setData('offer', e.target.value)}
                                />
                                {!workspace.has_contact ? (
                                    <p className="mt-1 text-xs text-amber-700">
                                        Add mobile, email, or website in{' '}
                                        <Link
                                            href={route('settings.index', { tab: 'workspace' })}
                                            className="font-semibold text-signal-strong"
                                        >
                                            Settings → Workspace
                                        </Link>{' '}
                                        — AI will append them to every post.
                                    </p>
                                ) : null}
                            </div>
                        </div>

                        <div>
                            <div className="flex flex-wrap items-end justify-between gap-2">
                                <InputLabel value="What should we post today? *" className="!mb-0" />
                                <SecondaryButton
                                    type="button"
                                    processing={previewLoading}
                                    disabled={!canSubmit || previewLoading}
                                    onClick={generatePreview}
                                >
                                    Generate preview
                                </SecondaryButton>
                            </div>
                            <p className="mt-1 text-xs text-ink-muted">
                                {selectedFestival
                                    ? `Describe your ${selectedFestival.name} offer or angle — or leave blank and Generate preview will suggest one.`
                                    : `Write a rough idea (or leave blank) → Generate preview to see ${postCount} sample post${postCount === '1' ? '' : 's'}. No credits used.`}
                            </p>
                            <textarea
                                className="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink shadow-sm focus:border-signal focus:ring-signal"
                                rows={4}
                                placeholder={briefPlaceholder}
                                value={form.data.brief}
                                onChange={(e) => {
                                    form.setData('brief', e.target.value);
                                    setPreviews([]);
                                    setPreviewError('');
                                }}
                                required={!selectedFestival}
                                minLength={selectedFestival ? 0 : 10}
                                maxLength={500}
                            />
                            {form.errors.brief ? (
                                <p className="mt-1 text-xs text-red-600">{form.errors.brief}</p>
                            ) : null}
                            {previewError ? (
                                <p className="mt-1 text-xs text-red-600">{previewError}</p>
                            ) : null}
                        </div>

                        {previews.length > 0 ? (
                            <div className="space-y-2 rounded-lg border border-line bg-mist/20 p-4">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <div className="text-sm font-semibold text-ink">AI preview (3 posts)</div>
                                    {previewProvider ? (
                                        <span className="text-xs text-ink-muted">via {previewProvider}</span>
                                    ) : null}
                                </div>
                                <p className="text-xs text-ink-muted">
                                    Edit the topic above if needed, then click Create 3 drafts to save in
                                    SMM.
                                </p>
                                <ul className="space-y-3">
                                    {previews.map((post, i) => (
                                        <li
                                            key={i}
                                            className="rounded-md border border-line bg-white p-3 text-sm"
                                        >
                                            <div className="font-semibold text-ink">{post.title}</div>
                                            <div className="mt-0.5 flex flex-wrap items-center gap-2 text-[10px] font-bold uppercase tracking-wide text-ink-muted">
                                                <span>{(post.platforms || []).join(' · ')}</span>
                                {post.word_count ? (
                                    <span className="normal-case tracking-normal">
                                        · {post.word_count} content words
                                    </span>
                                ) : null}
                                            </div>
                                            <pre className="mt-2 whitespace-pre-wrap font-sans text-ink-muted">
                                                {post.body}
                                            </pre>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        ) : null}

                        <div className="flex flex-wrap items-center gap-3 border-t border-line pt-4">
                            <PrimaryButton
                                type="submit"
                                disabled={!canSubmit || form.processing}
                                className="!min-w-[10rem]"
                            >
                                {form.processing ? 'Creating…' : `Create ${postCountLabel}`}
                            </PrimaryButton>
                            <span className="text-xs text-ink-muted">
                                Saves to SMM as drafts — review before publish ·{' '}
                                {available.toLocaleString()} credits
                                {draftCount > 0 ? ` · ${draftCount} draft in SMM` : ''}
                            </span>
                            {draftCount > 0 ? (
                                <Link
                                    href={route('social.index', { status: 'draft' })}
                                    className="text-sm font-semibold text-signal-strong"
                                >
                                    SMM drafts →
                                </Link>
                            ) : null}
                        </div>
                    </form>
                </section>

                {(generations.length > 0 || festivals.length > 0 || seo_drafts.length > 0) && (
                    <section className="atlas-panel overflow-hidden">
                        <button
                            type="button"
                            className="flex w-full items-center justify-between px-4 py-3 text-left text-sm"
                            onClick={() => setShowExtras((v) => !v)}
                        >
                            <span className="font-semibold text-ink">History & calendar</span>
                            <span className="text-signal-strong">{showExtras ? 'Hide' : 'Show'}</span>
                        </button>
                        {showExtras ? (
                            <div className="space-y-4 border-t border-line px-4 pb-4 pt-3 text-sm">
                                {generations.length > 0 ? (
                                    <ul className="divide-y divide-line rounded-lg border border-line">
                                        {generations.map((g) => (
                                            <li
                                                key={g.id}
                                                className="flex items-center justify-between gap-2 px-3 py-2"
                                            >
                                                <div className="min-w-0">
                                                    <div className="truncate font-semibold">
                                                        {g.title || 'Post pack'}
                                                    </div>
                                                    <div className="text-xs text-ink-muted">
                                                        {g.at}
                                                        {g.post_count ? ` · ${g.post_count} drafts` : ''}
                                                    </div>
                                                </div>
                                                <Link
                                                    href={route('social.index', { status: 'draft' })}
                                                    className="shrink-0 font-semibold text-signal-strong"
                                                >
                                                    SMM →
                                                </Link>
                                            </li>
                                        ))}
                                    </ul>
                                ) : null}
                                {festivals.length > 0 ? (
                                    <ul className="divide-y divide-line rounded-lg border border-line">
                                        {festivals.slice(0, 6).map((f) => (
                                            <li key={f.id} className="px-3 py-2">
                                                <div className="font-semibold">{f.name}</div>
                                                <div className="text-xs text-ink-muted">
                                                    {f.date_label}
                                                    {f.days_label ? ` · ${f.days_label}` : ''}
                                                </div>
                                            </li>
                                        ))}
                                    </ul>
                                ) : null}
                            </div>
                        ) : null}
                    </section>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
