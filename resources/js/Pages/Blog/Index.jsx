import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import InputError from '@/Components/InputError';
import RichTextEditor from '@/Components/RichTextEditor';
import PanelTitle from '@/Components/PanelTitle';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectMenu from '@/Components/SelectMenu';
import TextInput from '@/Components/TextInput';
import { toast } from '@/Components/ToastProvider';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

const TABS = [
    { id: 'write', label: 'Write' },
    { id: 'posts', label: 'Posts' },
    { id: 'askefy', label: 'Askefy' },
    { id: 'wordpress', label: 'WordPress' },
];

function usernameFromPageName(name) {
    let slug = String(name || '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/_+/g, '_')
        .replace(/^_|_$/g, '');
    if (slug.length < 3) {
        slug = (slug + '_page').slice(0, 30);
    }
    return slug.slice(0, 30);
}

function sourceTone(source) {
    if (source === 'rss') return 'bg-emerald-50 text-emerald-800 border-emerald-200';
    if (source === 'sitemap') return 'bg-sky-50 text-sky-800 border-sky-200';
    if (source === 'demo') return 'bg-amber-50 text-amber-900 border-amber-200';
    return 'bg-mist text-ink-muted border-line';
}

const SHARE_ICONS = {
    whatsapp: {
        bg: '#25D366',
        path: 'M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.435 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z',
    },
    facebook: {
        bg: '#1877F2',
        path: 'M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z',
    },
    x: {
        bg: '#111111',
        path: 'M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z',
    },
    linkedin: {
        bg: '#0A66C2',
        path: 'M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433c-1.144 0-2.063-.926-2.063-2.065 0-1.138.92-2.063 2.063-2.063 1.14 0 2.064.925 2.064 2.063 0 1.139-.925 2.065-2.064 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z',
    },
    threads: {
        bg: '#101010',
        path: 'M12.186 24h-.007c-3.581-.024-6.334-1.205-8.184-3.509C2.35 18.44 1.5 15.586 1.472 12.01v-.017c.03-3.579.879-6.43 2.525-8.482C5.865 1.205 8.615.024 12.195 0h.014c2.746.02 5.093.823 6.977 2.384 1.841 1.521 3.058 3.704 3.622 6.493.207 1.04.31 2.148.31 3.292-.004 3.564-.903 6.502-2.673 8.73-1.705 2.147-4.145 3.327-7.249 3.502l-.01-.001zm.865-5.006c.24-.024.464-.053.672-.087 2.59-.405 4.32-1.763 5.146-4.037.55-1.513.692-3.204.692-4.87 0-.974-.084-1.907-.252-2.777-.545-2.833-1.74-4.93-3.55-6.233-1.62-1.167-3.66-1.76-6.061-1.76h-.01c-3.06.02-5.358.99-6.83 2.88-1.343 1.72-2.026 4.11-2.05 7.11v.014c.023 2.996.706 5.383 2.05 7.1 1.475 1.894 3.775 2.866 6.84 2.885h.006c.78-.005 1.49-.06 2.147-.125z',
    },
    telegram: {
        bg: '#26A5E4',
        path: 'M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0a12 12 0 0 0-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.788.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z',
    },
    reddit: {
        bg: '#FF4500',
        path: 'M12 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0zm5.01 4.744c.688 0 1.25.561 1.25 1.249a1.25 1.25 0 0 1-2.498.056l-2.597-.547-.8 3.747c1.824.07 3.48.632 4.674 1.488.308-.309.73-.491 1.207-.491.968 0 1.754.786 1.754 1.754 0 .716-.435 1.333-1.01 1.614a3.111 3.111 0 0 1 .042.52c0 2.769-3.209 5.002-7.14 5.002-3.932 0-7.141-2.234-7.141-5.002 0-.175.012-.346.035-.514A1.756 1.756 0 0 1 4.026 12a1.75 1.75 0 0 1 1.753-1.754c.474 0 .895.182 1.204.49 1.207-.883 2.878-1.447 4.724-1.502l.885-4.182a.342.342 0 0 1 .14-.197.35.35 0 0 1 .238-.042l2.914.564a1.214 1.214 0 0 1 1.108-.701zM9.25 12C8.561 12 8 12.562 8 13.25c0 .687.561 1.248 1.25 1.248.687 0 1.248-.561 1.248-1.249 0-.688-.561-1.249-1.249-1.249zm5.5 0c-.687 0-1.248.561-1.248 1.25 0 .687.561 1.248 1.249 1.248.688 0 1.249-.561 1.249-1.249 0-.687-.562-1.249-1.25-1.249zm-5.038 3.75a.5.5 0 0 0-.145.888c.849.532 1.822.795 2.933.795.841 0 1.767-.192 2.665-.7a.5.5 0 1 0-.488-.876c-.74.42-1.491.58-2.177.58-.898 0-1.701-.219-2.388-.65a.5.5 0 0 0-.4-.037z',
    },
};

function ShareBrandIcon({ id }) {
    const icon = SHARE_ICONS[id];
    if (!icon) {
        return (
            <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-ink text-xs font-bold text-white">
                ?
            </span>
        );
    }

    return (
        <span
            className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg"
            style={{ backgroundColor: icon.bg }}
        >
            <svg viewBox="0 0 24 24" className="h-4 w-4 fill-white" aria-hidden>
                <path d={icon.path} />
            </svg>
        </span>
    );
}

function LinkChainIcon() {
    return (
        <svg viewBox="0 0 24 24" className="h-4 w-4 fill-none stroke-white" strokeWidth="2" aria-hidden>
            <path
                strokeLinecap="round"
                strokeLinejoin="round"
                d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"
            />
            <path
                strokeLinecap="round"
                strokeLinejoin="round"
                d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"
            />
        </svg>
    );
}


function Pagination({ links = [] }) {
    if (!Array.isArray(links) || links.length <= 3) {
        return null;
    }

    return (
        <div className="flex flex-wrap items-center justify-center gap-1.5 border-t border-line px-4 py-3">
            {links.map((link, i) => (
                <button
                    key={`${link.label}-${i}`}
                    type="button"
                    disabled={!link.url}
                    onClick={() => link.url && router.get(link.url, {}, { preserveScroll: true })}
                    className={
                        'min-w-9 rounded-lg px-3 py-1.5 text-xs font-semibold transition ' +
                        (link.active
                            ? 'bg-ink text-white shadow-sm'
                            : 'border border-line bg-white text-ink-muted hover:border-signal/40 hover:text-ink disabled:opacity-40')
                    }
                    dangerouslySetInnerHTML={{ __html: link.label }}
                />
            ))}
        </div>
    );
}

export default function Index({
    workspace,
    sites = [],
    site = null,
    plan = null,
    askefy = {
        connected: false,
        connection_id: null,
        base_url: '',
        page_slug: null,
        page_name: null,
        email: null,
        label: null,
        pages: [],
    },
    cms_connections = [],
    content_drafts = { data: [], links: [], meta: { total: 0 } },
    draft_filters = { status: 'all', counts: {} },
    blog_posts = { data: [], links: [], meta: {} },
    blog_share_channels = [],
    blog_synced_at = null,
    blog_feed_url = null,
}) {
    const { flash, auth } = usePage().props;
    const account = auth?.user || {};
    const seoCmsLocked = plan && !plan.features?.seo_cms;
    const draftRows = Array.isArray(content_drafts?.data)
        ? content_drafts.data
        : Array.isArray(content_drafts)
          ? content_drafts
          : [];
    const draftTotal = content_drafts?.meta?.total ?? draftRows.length;
    const draftLinks = content_drafts?.links || [];
    const draftCounts = draft_filters?.counts || {};
    const posts = Array.isArray(blog_posts?.data)
        ? blog_posts.data
        : Array.isArray(blog_posts)
          ? blog_posts
          : [];
    const postTotal = blog_posts?.meta?.total ?? posts.length;
    const postLinks = blog_posts?.links || [];

    const initialTab = (() => {
        const q = new URLSearchParams(window.location.search).get('tab');
        return TABS.some((t) => t.id === q) ? q : 'write';
    })();
    const [tab, setTab] = useState(initialTab);
    const [askefyMode, setAskefyMode] = useState('signup');
    const [syncingBlogs, setSyncingBlogs] = useState(false);
    const [sharingBlogId, setSharingBlogId] = useState(null);
    const [shareMenuPostId, setShareMenuPostId] = useState(null);
    const [publishingAskefyId, setPublishingAskefyId] = useState(null);
    const [reviewingDraft, setReviewingDraft] = useState(null);
    const [editorMode, setEditorMode] = useState('review');
    const [savingReview, setSavingReview] = useState(false);

    const askefyForm = useForm({
        mode: 'signup',
        name: account.name || workspace?.name || '',
        email: account.email || '',
        password: '',
        password_confirmation: '',
        category: 'technology',
    });
    const cmsForm = useForm({
        base_url: '',
        username: '',
        app_password: '',
        label: 'WordPress',
    });
    const draftForm = useForm({
        keyword: '',
        seo_keyword_id: '',
        audience: '',
        intent: 'guide',
        length: 'standard',
        notes: '',
        tone: '',
    });
    const reviewForm = useForm({
        title: '',
        body_html: '',
        meta_title: '',
        meta_description: '',
        mark_reviewed: true,
    });

    const askefyConnectionId =
        askefy?.connection_id ||
        cms_connections.find((c) => c.provider === 'askefy' || c.provider === 'verba')?.id ||
        null;
    const wordpressConnectionId =
        cms_connections.find((c) => c.provider === 'wordpress')?.id || null;

    useEffect(() => {
        if (flash?.share_open_url) {
            window.open(flash.share_open_url, '_blank', 'noopener,noreferrer');
        }
    }, [flash?.share_open_url]);

    const openReview = (draft) => {
        setReviewingDraft(draft);
        setEditorMode('review');
        reviewForm.setData({
            title: draft.title || '',
            body_html: draft.body_html || '',
            meta_title: draft.meta_title || '',
            meta_description: draft.meta_description || '',
            mark_reviewed: true,
        });
        reviewForm.clearErrors();
    };

    const openEdit = (draft) => {
        setReviewingDraft(draft);
        setEditorMode('edit');
        reviewForm.setData({
            title: draft.title || '',
            body_html: draft.body_html || '',
            meta_title: draft.meta_title || '',
            meta_description: draft.meta_description || '',
            mark_reviewed: false,
        });
        reviewForm.clearErrors();
    };

    const closeReview = () => {
        setReviewingDraft(null);
        setEditorMode('review');
        reviewForm.reset();
        reviewForm.clearErrors();
    };

    const saveReview = () => {
        if (!reviewingDraft?.id) {
            toast.error('Draft not found. Open it again from the list.');
            return;
        }

        const title = String(reviewForm.data.title || '').trim();
        const bodyHtml = String(reviewForm.data.body_html || '').trim();
        const markReviewed = editorMode === 'review';

        if (!title) {
            reviewForm.setError('title', 'Title is required.');
            toast.error('Title is required.');
            return;
        }

        if (!bodyHtml || bodyHtml === '<p></p>') {
            reviewForm.setError('body_html', 'Article body is required.');
            toast.error('Article body is required.');
            return;
        }

        reviewForm.clearErrors();
        setSavingReview(true);

        router.patch(
            route('blog.content.update', reviewingDraft.id),
            {
                title,
                body_html: bodyHtml,
                meta_title: reviewForm.data.meta_title || '',
                meta_description: reviewForm.data.meta_description || '',
                mark_reviewed: markReviewed,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    closeReview();
                },
                onError: (errors) => {
                    Object.entries(errors || {}).forEach(([key, message]) => {
                        reviewForm.setError(key, message);
                    });
                    const first =
                        errors?.title ||
                        errors?.body_html ||
                        errors?.meta_title ||
                        errors?.meta_description ||
                        'Could not save draft.';
                    toast.error(
                        typeof first === 'string' ? first : 'Could not save draft.',
                    );
                },
                onFinish: () => setSavingReview(false),
            },
        );
    };

    const applyDraftFilter = (status) => {
        router.get(
            route('blog.index'),
            {
                tab: 'write',
                site: site?.id,
                ...(status !== 'all' ? { draft_status: status } : {}),
            },
            { preserveState: true, preserveScroll: true },
        );
    };

    const switchTab = (id) => {
        setTab(id);
        const url = new URL(window.location.href);
        url.searchParams.set('tab', id);
        window.history.replaceState({}, '', url);
    };

    return (
        <AuthenticatedLayout
            header={
                <div>
                    <div className="text-[11px] font-semibold uppercase tracking-[0.16em] text-ink-muted">
                        Content
                    </div>
                    <div className="mt-1 flex items-center gap-1.5">
                        <h1 className="font-display text-2xl font-bold leading-none tracking-tight text-ink sm:text-3xl">
                            Blog
                        </h1>
                    </div>
                    {workspace?.name ? (
                        <p className="mt-1.5 text-sm text-ink-muted">{workspace.name}</p>
                    ) : null}
                </div>
            }
        >
            <Head title="Blog" />

            <div className="atlas-shell space-y-5">
                <section className="atlas-panel overflow-hidden">
                    <div className="flex flex-col gap-4 border-b border-line/70 bg-gradient-to-r from-signal-soft/35 via-white to-mist/50 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-5 sm:py-5">
                        <div className="min-w-0 flex-1">
                            <div className="text-[11px] font-semibold uppercase tracking-[0.14em] text-signal-strong">
                                Website
                            </div>
                            <div className="mt-2 w-full max-w-sm">
                                {sites.length > 0 ? (
                                    <SelectMenu
                                        value={site?.id || ''}
                                        onChange={(id) =>
                                            router.get(
                                                route('blog.index'),
                                                { site: id, tab },
                                                { preserveState: true },
                                            )
                                        }
                                        placeholder="Choose a site"
                                        options={sites.map((item) => ({
                                            value: item.id,
                                            label: item.domain,
                                        }))}
                                    />
                                ) : (
                                    <p className="text-sm text-ink-muted">
                                        Add a website in SEO first.
                                    </p>
                                )}
                            </div>
                        </div>

                        <div className="inline-flex flex-wrap gap-1 rounded-xl border border-line bg-white/90 p-1.5 shadow-sm">
                            {TABS.map((t) => (
                                <button
                                    key={t.id}
                                    type="button"
                                    onClick={() => switchTab(t.id)}
                                    className={`rounded-lg px-4 py-2 text-sm font-semibold transition ${
                                        tab === t.id
                                            ? 'bg-ink text-white shadow-sm'
                                            : 'text-ink-muted hover:bg-mist hover:text-ink'
                                    }`}
                                >
                                    {t.label}
                                    {t.id === 'write' && draftTotal > 0 ? (
                                        <span className="ml-1.5 text-[10px] font-bold tabular-nums opacity-80">
                                            {draftTotal}
                                        </span>
                                    ) : null}
                                    {t.id === 'posts' && postTotal > 0 ? (
                                        <span className="ml-1.5 text-[10px] font-bold tabular-nums opacity-80">
                                            {postTotal}
                                        </span>
                                    ) : null}
                                    {t.id === 'askefy' && askefy?.connected ? (
                                        <span className="ml-1.5 text-[10px] font-bold text-emerald-300">
                                            on
                                        </span>
                                    ) : null}
                                </button>
                            ))}
                        </div>
                    </div>
                </section>

                {tab === 'write' ? (
                    <section className="atlas-panel overflow-hidden">
                        {reviewingDraft && !seoCmsLocked ? (
                            <div className="flex max-h-[calc(100svh-8rem)] flex-col">
                                <div className="sticky top-0 z-10 flex shrink-0 flex-wrap items-center justify-between gap-3 border-b border-line bg-white px-5 py-4">
                                    <div>
                                        <div className="text-xs font-semibold uppercase tracking-wide text-ink-muted">
                                            {editorMode === 'edit'
                                                ? 'Edit after approve'
                                                : 'Review before approve'}
                                        </div>
                                        <div className="mt-0.5 font-display text-lg font-bold text-ink">
                                            {editorMode === 'edit'
                                                ? 'Edit article'
                                                : 'Review article'}
                                        </div>
                                        {editorMode === 'edit' ? (
                                            <p className="mt-1 text-xs text-amber-700">
                                                Save ke baad dubara review → approve → publish.
                                            </p>
                                        ) : null}
                                    </div>
                                    <div className="flex flex-wrap items-center gap-2">
                                        <SecondaryButton type="button" onClick={closeReview}>
                                            Back to list
                                        </SecondaryButton>
                                        <PrimaryButton
                                            type="button"
                                            processing={savingReview}
                                            onClick={saveReview}
                                        >
                                            {savingReview
                                                ? 'Saving…'
                                                : editorMode === 'edit'
                                                  ? 'Save changes'
                                                  : 'Save & mark reviewed'}
                                        </PrimaryButton>
                                    </div>
                                </div>

                                {(reviewForm.errors.title ||
                                    reviewForm.errors.body_html ||
                                    reviewForm.errors.meta_title ||
                                    reviewForm.errors.meta_description) && (
                                    <div className="shrink-0 space-y-1 border-b border-danger/20 bg-danger-soft px-4 py-2">
                                        <InputError message={reviewForm.errors.title} />
                                        <InputError message={reviewForm.errors.body_html} />
                                        <InputError message={reviewForm.errors.meta_title} />
                                        <InputError
                                            message={reviewForm.errors.meta_description}
                                        />
                                    </div>
                                )}

                                <div className="shrink-0 space-y-3 border-b border-line bg-mist/20 px-4 py-3">
                                    <div>
                                        <label className="text-xs font-semibold text-ink-muted">
                                            Title
                                        </label>
                                        <TextInput
                                            className="mt-1 w-full"
                                            value={reviewForm.data.title}
                                            onChange={(e) =>
                                                reviewForm.setData('title', e.target.value)
                                            }
                                        />
                                    </div>
                                    <div className="grid gap-3 sm:grid-cols-2">
                                        <div>
                                            <label className="text-xs font-semibold text-ink-muted">
                                                Meta title
                                            </label>
                                            <TextInput
                                                className="mt-1 w-full"
                                                value={reviewForm.data.meta_title}
                                                onChange={(e) =>
                                                    reviewForm.setData(
                                                        'meta_title',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </div>
                                        <div>
                                            <label className="text-xs font-semibold text-ink-muted">
                                                Meta description
                                            </label>
                                            <TextInput
                                                className="mt-1 w-full"
                                                value={reviewForm.data.meta_description}
                                                onChange={(e) =>
                                                    reviewForm.setData(
                                                        'meta_description',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </div>
                                    </div>
                                </div>

                                <div className="min-h-0 flex-1 overflow-y-auto p-4">
                                    <RichTextEditor
                                        value={reviewForm.data.body_html}
                                        onChange={(html) =>
                                            reviewForm.setData('body_html', html)
                                        }
                                        placeholder="Write or edit the article body…"
                                    />
                                </div>
                            </div>
                        ) : (
                            <>
                                <PanelTitle
                                    title="Write with AI"
                                    subtitle="ChatGPT-style brief do — topic + audience + angle. Better brief = stronger draft."
                                />
                                {seoCmsLocked ? (
                                    <div className="border-t border-line px-5 py-8 text-sm text-ink-muted">
                                        Blog writing needs a paid plan or credit top-up.{' '}
                                        <a
                                            href={route('billing.index')}
                                            className="font-semibold text-signal-strong"
                                        >
                                            Billing →
                                        </a>
                                    </div>
                                ) : (
                                    <>
                                <div className="border-t border-line px-5 py-6 sm:px-6">
                                    <form
                                        className="mx-auto max-w-3xl space-y-4"
                                        onSubmit={(e) => {
                                            e.preventDefault();
                                            draftForm.post(route('blog.content.store'), {
                                                onSuccess: () =>
                                                    draftForm.reset(
                                                        'keyword',
                                                        'notes',
                                                        'audience',
                                                    ),
                                            });
                                        }}
                                    >
                                        <div className="rounded-lg border border-signal/25 bg-gradient-to-br from-signal-soft/40 via-white to-mist/40 px-4 py-3.5">
                                            <div className="text-[11px] font-semibold uppercase tracking-[0.14em] text-signal-strong">
                                                Publish flow
                                            </div>
                                            <p className="mt-1.5 text-sm leading-relaxed text-ink">
                                                <strong>1 Review</strong>
                                                <span className="text-ink-muted"> → </span>
                                                <strong>2 Approve</strong>
                                                <span className="text-ink-muted"> → </span>
                                                <strong>3 Publish</strong>
                                                <span className="text-ink-muted">
                                                    {' '}
                                                    (Askefy preferred; WordPress optional).
                                                </span>
                                            </p>
                                        </div>

                                        <div>
                                            <label className="text-xs font-semibold text-ink-muted">
                                                Topic / brief
                                            </label>
                                            <textarea
                                                className="atlas-input mt-1.5 min-h-[7.5rem] w-full resize-y text-sm leading-relaxed"
                                                placeholder="ChatGPT jaisa detail do. Example: Write a practical guide on Goa family trip packages from Noida — cover budget ranges, best months, itinerary tips, and a soft CTA for Vibgyor Holidays."
                                                value={draftForm.data.keyword}
                                                onChange={(e) =>
                                                    draftForm.setData('keyword', e.target.value)
                                                }
                                                required
                                                minLength={8}
                                            />
                                            <p className="mt-1.5 text-[11px] text-ink-muted">
                                                Outcome + audience + angle likho. Sirf ek keyword se
                                                generic draft aata hai.
                                            </p>
                                            <InputError
                                                message={draftForm.errors.keyword}
                                                className="mt-1"
                                            />
                                        </div>

                                        <div className="grid gap-3 sm:grid-cols-2">
                                            <div>
                                                <label className="text-xs font-semibold text-ink-muted">
                                                    Article type
                                                </label>
                                                <SelectMenu
                                                    className="mt-1.5"
                                                    value={draftForm.data.intent}
                                                    onChange={(value) =>
                                                        draftForm.setData('intent', value)
                                                    }
                                                    searchPlaceholder="Search type…"
                                                    options={[
                                                        {
                                                            value: 'guide',
                                                            label: 'Practical guide',
                                                            meta: 'Teach + clarify + next step',
                                                        },
                                                        {
                                                            value: 'howto',
                                                            label: 'How-to / steps',
                                                            meta: 'Numbered actionable steps',
                                                        },
                                                        {
                                                            value: 'listicle',
                                                            label: 'Listicle',
                                                            meta: '7 tips, 5 mistakes…',
                                                        },
                                                        {
                                                            value: 'comparison',
                                                            label: 'Comparison',
                                                            meta: 'Options, pros/cons',
                                                        },
                                                        {
                                                            value: 'local',
                                                            label: 'Local SEO',
                                                            meta: 'City / near-me angle',
                                                        },
                                                    ]}
                                                />
                                            </div>
                                            <div>
                                                <label className="text-xs font-semibold text-ink-muted">
                                                    Length
                                                </label>
                                                <SelectMenu
                                                    className="mt-1.5"
                                                    value={draftForm.data.length}
                                                    onChange={(value) =>
                                                        draftForm.setData('length', value)
                                                    }
                                                    searchPlaceholder="Search length…"
                                                    options={[
                                                        {
                                                            value: 'short',
                                                            label: 'Short',
                                                            meta: '~800 words',
                                                        },
                                                        {
                                                            value: 'standard',
                                                            label: 'Standard',
                                                            meta: '~1,200 words',
                                                        },
                                                        {
                                                            value: 'long',
                                                            label: 'Long',
                                                            meta: '~1,800 words',
                                                        },
                                                    ]}
                                                />
                                            </div>
                                            <div>
                                                <label className="text-xs font-semibold text-ink-muted">
                                                    Audience
                                                </label>
                                                <TextInput
                                                    className="mt-1.5 w-full"
                                                    placeholder="e.g. Noida families planning Goa"
                                                    value={draftForm.data.audience}
                                                    onChange={(e) =>
                                                        draftForm.setData(
                                                            'audience',
                                                            e.target.value,
                                                        )
                                                    }
                                                />
                                            </div>
                                            <div>
                                                <label className="text-xs font-semibold text-ink-muted">
                                                    Language
                                                </label>
                                                <SelectMenu
                                                    className="mt-1.5"
                                                    value={draftForm.data.tone || ''}
                                                    onChange={(value) =>
                                                        draftForm.setData('tone', value)
                                                    }
                                                    searchPlaceholder="Search language…"
                                                    options={[
                                                        {
                                                            value: '',
                                                            label: 'Workspace default',
                                                        },
                                                        {
                                                            value: 'english',
                                                            label: 'English',
                                                        },
                                                        {
                                                            value: 'hinglish',
                                                            label: 'English + light Hinglish',
                                                        },
                                                        {
                                                            value: 'hindi',
                                                            label: 'Hindi',
                                                        },
                                                    ]}
                                                />
                                            </div>
                                        </div>

                                        <div>
                                            <label className="text-xs font-semibold text-ink-muted">
                                                Extra notes{' '}
                                                <span className="font-normal">(optional)</span>
                                            </label>
                                            <textarea
                                                className="atlas-input mt-1.5 min-h-[4.5rem] w-full resize-y text-sm"
                                                placeholder="Must include: budget tips, monsoon months to avoid, soft CTA for package enquiry…"
                                                value={draftForm.data.notes}
                                                onChange={(e) =>
                                                    draftForm.setData('notes', e.target.value)
                                                }
                                            />
                                        </div>

                                        <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center">
                                            <PrimaryButton processing={draftForm.processing}>
                                                {draftForm.processing
                                                    ? 'Writing…'
                                                    : 'Generate AI draft'}
                                            </PrimaryButton>
                                            {!askefyConnectionId && !wordpressConnectionId ? (
                                                <span className="text-xs leading-relaxed text-amber-700 sm:max-w-sm">
                                                    Publish ke liye pehle Askefy connect karo
                                                    (WordPress optional).
                                                </span>
                                            ) : !askefyConnectionId && wordpressConnectionId ? (
                                                <span className="text-xs leading-relaxed text-ink-muted sm:max-w-sm">
                                                    Askefy connect karo for preferred publish —
                                                    WordPress bhi available hai.
                                                </span>
                                            ) : null}
                                        </div>
                                    </form>
                                </div>

                                <div className="flex flex-wrap gap-1.5 border-t border-line bg-mist/30 px-5 py-3.5">
                                    {[
                                        { id: 'all', label: 'All' },
                                        { id: 'needs_review', label: 'Needs review' },
                                        { id: 'draft', label: 'Drafts' },
                                        { id: 'approved', label: 'Approved' },
                                        { id: 'published', label: 'Published' },
                                        { id: 'failed', label: 'Failed' },
                                    ].map((f) => {
                                        const active =
                                            (draft_filters?.status || 'all') === f.id;
                                        const count = draftCounts[f.id] ?? 0;
                                        return (
                                            <button
                                                key={f.id}
                                                type="button"
                                                onClick={() => applyDraftFilter(f.id)}
                                                className={
                                                    'rounded-lg border px-3 py-1.5 text-xs font-semibold transition ' +
                                                    (active
                                                        ? 'border-signal bg-signal-soft/70 text-ink'
                                                        : 'border-line bg-white text-ink-muted hover:border-signal/40')
                                                }
                                            >
                                                {f.label}
                                                <span className="ms-1 tabular-nums opacity-70">
                                                    {count}
                                                </span>
                                            </button>
                                        );
                                    })}
                                </div>

                                <ul className="divide-y divide-line">
                                    {draftRows.length === 0 ? (
                                        <li className="px-5 py-14 text-center text-sm text-ink-muted">
                                            Is filter mein koi draft nahi — upar topic se generate
                                            karo.
                                        </li>
                                    ) : (
                                        draftRows.map((d) => (
                                            <li
                                                key={d.id}
                                                className="flex flex-wrap items-center justify-between gap-3 px-5 py-4"
                                            >
                                                <div className="min-w-0 flex-1">
                                                    <div className="font-semibold text-ink">
                                                        {d.title}
                                                    </div>
                                                    {d.excerpt ? (
                                                        <p className="mt-0.5 line-clamp-2 text-sm text-ink-muted">
                                                            {d.excerpt}
                                                        </p>
                                                    ) : null}
                                                    <div className="mt-1 flex flex-wrap items-center gap-1.5 text-xs uppercase text-ink-muted">
                                                        <span>{d.status}</span>
                                                        <span>·</span>
                                                        <span
                                                            className={
                                                                d.is_reviewed
                                                                    ? 'text-emerald-700'
                                                                    : 'font-semibold text-amber-700'
                                                            }
                                                        >
                                                            {d.is_reviewed
                                                                ? 'Reviewed'
                                                                : 'Needs review'}
                                                        </span>
                                                        {d.word_count ? (
                                                            <>
                                                                <span>·</span>
                                                                <span>{d.word_count} words</span>
                                                            </>
                                                        ) : null}
                                                        {d.published_url ? (
                                                            <>
                                                                <span>·</span>
                                                                <span className="normal-case">
                                                                    {d.published_url}
                                                                </span>
                                                            </>
                                                        ) : null}
                                                    </div>
                                                </div>
                                                <div className="flex flex-wrap gap-2">
                                                    {!d.is_reviewed &&
                                                    d.status !== 'published' ? (
                                                        <SecondaryButton
                                                            type="button"
                                                            onClick={() => openReview(d)}
                                                        >
                                                            Review
                                                        </SecondaryButton>
                                                    ) : null}
                                                    {d.status === 'approved' ? (
                                                        <SecondaryButton
                                                            type="button"
                                                            onClick={() => openEdit(d)}
                                                        >
                                                            Edit
                                                        </SecondaryButton>
                                                    ) : null}
                                                    {(d.status === 'draft' ||
                                                        d.status === 'failed') &&
                                                    d.is_reviewed ? (
                                                        <>
                                                            <SecondaryButton
                                                                type="button"
                                                                onClick={() =>
                                                                    router.post(
                                                                        route(
                                                                            'blog.content.approve',
                                                                            d.id,
                                                                        ),
                                                                    )
                                                                }
                                                            >
                                                                Approve
                                                            </SecondaryButton>
                                                            <span className="self-center text-xs font-semibold text-signal-strong">
                                                                Step 2 — phir Publish
                                                            </span>
                                                        </>
                                                    ) : null}
                                                    {d.status === 'approved' &&
                                                    !askefyConnectionId &&
                                                    !wordpressConnectionId ? (
                                                        <span className="self-center text-xs font-semibold text-amber-700">
                                                            Step 3 — Askefy tab se connect karo
                                                            (preferred)
                                                        </span>
                                                    ) : null}
                                                    {d.status === 'approved' &&
                                                    askefyConnectionId ? (
                                                        <PrimaryButton
                                                            type="button"
                                                            onClick={() =>
                                                                router.post(
                                                                    route(
                                                                        'blog.content.publish',
                                                                        d.id,
                                                                    ),
                                                                    {
                                                                        cms_connection_id:
                                                                            askefyConnectionId,
                                                                    },
                                                                )
                                                            }
                                                        >
                                                            Publish Askefy
                                                        </PrimaryButton>
                                                    ) : null}
                                                    {d.status === 'approved' &&
                                                    wordpressConnectionId ? (
                                                        askefyConnectionId ? (
                                                            <SecondaryButton
                                                                type="button"
                                                                onClick={() =>
                                                                    router.post(
                                                                        route(
                                                                            'blog.content.publish',
                                                                            d.id,
                                                                        ),
                                                                        {
                                                                            cms_connection_id:
                                                                                wordpressConnectionId,
                                                                        },
                                                                    )
                                                                }
                                                            >
                                                                Publish WP
                                                            </SecondaryButton>
                                                        ) : (
                                                            <PrimaryButton
                                                                type="button"
                                                                onClick={() =>
                                                                    router.post(
                                                                        route(
                                                                            'blog.content.publish',
                                                                            d.id,
                                                                        ),
                                                                        {
                                                                            cms_connection_id:
                                                                                wordpressConnectionId,
                                                                        },
                                                                    )
                                                                }
                                                            >
                                                                Publish WP
                                                            </PrimaryButton>
                                                        )
                                                    ) : null}
                                                </div>
                                            </li>
                                        ))
                                    )}
                                </ul>
                                <Pagination links={draftLinks} />
                                    </>
                                )}
                            </>
                        )}
                    </section>
                ) : null}

                {tab === 'posts' ? (
                    <section className="atlas-panel overflow-hidden">
                        <PanelTitle
                            title="Your blogs"
                            action={
                                <PrimaryButton
                                    type="button"
                                    disabled={!site}
                                    processing={syncingBlogs}
                                    className="rounded-full px-5"
                                    onClick={() => {
                                        if (!site) return;
                                        setSyncingBlogs(true);
                                        router.post(
                                            route('blog.posts.sync', site.id),
                                            {},
                                            {
                                                preserveScroll: true,
                                                onFinish: () => setSyncingBlogs(false),
                                            },
                                        );
                                    }}
                                >
                                    Fetch blogs
                                </PrimaryButton>
                            }
                        />
                        {(blog_feed_url || blog_synced_at) && (
                            <div className="border-b border-line px-5 py-3 text-xs text-ink-muted">
                                {blog_feed_url ? `Feed: ${blog_feed_url}` : null}
                                {blog_synced_at ? ` · Synced ${blog_synced_at}` : ''}
                                {postTotal ? ` · ${postTotal} post(s)` : ''}
                            </div>
                        )}

                        {posts.length === 0 ? (
                            <div className="px-4 py-14 text-center">
                                <div className="mx-auto max-w-md space-y-2">
                                    <p className="text-base font-semibold text-ink">
                                        No blogs yet
                                    </p>
                                    <p className="text-sm text-ink-muted">
                                        Fetch pulls RSS / sitemap /blog URLs for this site. Demo
                                        posts appear if none are found.
                                    </p>
                                </div>
                            </div>
                        ) : (
                            <div className="grid gap-3 p-4 sm:grid-cols-2 xl:grid-cols-3">
                                {posts.map((post) => (
                                    <article
                                        key={post.id}
                                        className="flex h-full flex-col rounded-2xl border border-line bg-white p-4 shadow-sm transition hover:-translate-y-0.5 hover:border-signal/30 hover:shadow-md"
                                    >
                                        <div className="mb-3 flex flex-wrap items-center gap-1.5">
                                            <span
                                                className={`inline-flex rounded-full border px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide ${sourceTone(post.source)}`}
                                            >
                                                {post.source || 'post'}
                                            </span>
                                            {post.published_at ? (
                                                <span className="text-[11px] text-ink-muted">
                                                    {post.published_at}
                                                </span>
                                            ) : null}
                                            {post.share_count > 0 ? (
                                                <span className="text-[11px] text-ink-muted">
                                                    · {post.share_count} shares
                                                </span>
                                            ) : null}
                                        </div>

                                        <a
                                            href={post.url}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="line-clamp-2 text-base font-bold leading-snug text-ink hover:text-signal-strong"
                                        >
                                            {post.title}
                                        </a>

                                        {post.excerpt ? (
                                            <p className="mt-2 line-clamp-3 text-sm leading-relaxed text-ink-muted">
                                                {String(post.excerpt).replace(/<[^>]+>/g, '')}
                                            </p>
                                        ) : null}

                                        <div className="mt-2 truncate text-[11px] text-ink-muted">
                                            {post.url}
                                        </div>

                                        <div className="mt-auto space-y-2 pt-4">
                                            <div className="grid grid-cols-2 gap-2">
                                                {post.askefy_published ? (
                                                    post.askefy_published_url ? (
                                                        <a
                                                            href={post.askefy_published_url}
                                                            target="_blank"
                                                            rel="noreferrer"
                                                            className="inline-flex w-full items-center justify-center rounded-full border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-800 transition hover:bg-emerald-100"
                                                        >
                                                            Published on Askefy
                                                        </a>
                                                    ) : (
                                                        <span className="inline-flex w-full items-center justify-center rounded-full border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-800">
                                                            Published on Askefy
                                                        </span>
                                                    )
                                                ) : (
                                                    <PrimaryButton
                                                        type="button"
                                                        disabled={
                                                            seoCmsLocked || !askefy?.connected
                                                        }
                                                        processing={
                                                            publishingAskefyId === post.id
                                                        }
                                                        className="w-full rounded-full px-3"
                                                        onClick={() => {
                                                            setShareMenuPostId(null);
                                                            setPublishingAskefyId(post.id);
                                                            router.post(
                                                                route(
                                                                    'blog.posts.askefy',
                                                                    post.id,
                                                                ),
                                                                {},
                                                                {
                                                                    preserveScroll: true,
                                                                    onFinish: () =>
                                                                        setPublishingAskefyId(
                                                                            null,
                                                                        ),
                                                                },
                                                            );
                                                        }}
                                                    >
                                                        Publish to Askefy
                                                    </PrimaryButton>
                                                )}
                                                <div
                                                    className="relative"
                                                    onMouseEnter={() => setShareMenuPostId(post.id)}
                                                    onMouseLeave={() => setShareMenuPostId(null)}
                                                >
                                                    <PrimaryButton
                                                        type="button"
                                                        processing={sharingBlogId === post.id}
                                                        className="w-full rounded-full px-3"
                                                        onClick={() =>
                                                            setShareMenuPostId((id) =>
                                                                id === post.id ? null : post.id,
                                                            )
                                                        }
                                                    >
                                                        Share
                                                    </PrimaryButton>
                                                    {shareMenuPostId === post.id ? (
                                                        <div className="absolute bottom-full right-0 z-30 w-[280px] pb-2">
                                                            <div className="overflow-hidden rounded-xl border border-stone-200 bg-white p-3 shadow-lg">
                                                                <div className="border-b border-stone-200 pb-2">
                                                                    <p className="text-[11px] font-semibold uppercase tracking-[0.18em] text-stone-400">
                                                                        Share to
                                                                    </p>
                                                                </div>
                                                                <div className="grid grid-cols-2 gap-x-3 gap-y-2.5 py-3">
                                                                    {blog_share_channels.map(
                                                                        (ch) => (
                                                                            <button
                                                                                key={ch.id}
                                                                                type="button"
                                                                                className="flex items-center gap-2.5 rounded-lg px-1 py-1 text-left transition hover:bg-stone-50"
                                                                                onClick={() => {
                                                                                    setSharingBlogId(
                                                                                        post.id,
                                                                                    );
                                                                                    setShareMenuPostId(
                                                                                        null,
                                                                                    );
                                                                                    router.post(
                                                                                        route(
                                                                                            'blog.posts.share',
                                                                                            post.id,
                                                                                        ),
                                                                                        {
                                                                                            channel:
                                                                                                ch.id,
                                                                                        },
                                                                                        {
                                                                                            preserveScroll: true,
                                                                                            onFinish:
                                                                                                () =>
                                                                                                    setSharingBlogId(
                                                                                                        null,
                                                                                                    ),
                                                                                        },
                                                                                    );
                                                                                }}
                                                                            >
                                                                                <ShareBrandIcon
                                                                                    id={ch.id}
                                                                                />
                                                                                <span className="text-sm font-medium text-stone-900">
                                                                                    {ch.label}
                                                                                </span>
                                                                            </button>
                                                                        ),
                                                                    )}
                                                                </div>
                                                                <button
                                                                    type="button"
                                                                    className="flex w-full items-center justify-center gap-2 rounded-lg px-3 py-2.5 text-sm font-semibold text-white transition hover:brightness-110"
                                                                    style={{
                                                                        backgroundColor: '#A04A25',
                                                                    }}
                                                                    onClick={async () => {
                                                                        setSharingBlogId(post.id);
                                                                        setShareMenuPostId(null);
                                                                        try {
                                                                            await navigator.clipboard.writeText(
                                                                                post.url,
                                                                            );
                                                                        } catch {
                                                                            // ignore clipboard errors
                                                                        }
                                                                        router.post(
                                                                            route(
                                                                                'blog.posts.share',
                                                                                post.id,
                                                                            ),
                                                                            { channel: 'copy' },
                                                                            {
                                                                                preserveScroll: true,
                                                                                onFinish: () =>
                                                                                    setSharingBlogId(
                                                                                        null,
                                                                                    ),
                                                                            },
                                                                        );
                                                                    }}
                                                                >
                                                                    <span className="flex h-7 w-7 items-center justify-center rounded-md bg-white/15">
                                                                        <LinkChainIcon />
                                                                    </span>
                                                                    Copy link
                                                                </button>
                                                            </div>
                                                        </div>
                                                    ) : null}
                                                </div>
                                            </div>
                                        </div>
                                    </article>
                                ))}
                            </div>
                        )}

                        <Pagination links={postLinks} />
                    </section>
                ) : null}

                {tab === 'askefy' ? (
                    <section className="atlas-panel overflow-hidden">
                        <PanelTitle
                            title="Askefy"
                            subtitle="Preferred publish destination from RankwayAI"
                            action={
                                askefy?.connected ? (
                                    <SecondaryButton
                                        type="button"
                                        disabled={seoCmsLocked}
                                        onClick={() =>
                                            router.post(route('blog.askefy.disconnect'))
                                        }
                                    >
                                        Disconnect
                                    </SecondaryButton>
                                ) : null
                            }
                        />
                        {seoCmsLocked && !askefy?.connected ? (
                            <div className="border-t border-line px-4 py-6 text-sm text-ink-muted">
                                Needs paid plan or credit top-up.
                            </div>
                        ) : null}
                        {askefy?.connected ? (
                            <div className="space-y-3 border-t border-line p-4">
                                <div className="rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-950">
                                    Connected
                                    {askefy.email ? ` · ${askefy.email}` : ''}
                                    {Array.isArray(askefy.pages) && askefy.pages.length > 0
                                        ? ` · ${askefy.pages.length} page(s)`
                                        : ''}
                                </div>
                                {Array.isArray(askefy.pages) && askefy.pages.length > 0 ? (
                                    <ul className="divide-y divide-line rounded-xl border border-line">
                                        {askefy.pages.map((p) => (
                                            <li
                                                key={`${p.domain}-${p.slug}`}
                                                className="flex flex-wrap items-center justify-between gap-2 px-3 py-2 text-sm"
                                            >
                                                <span className="font-semibold text-ink">
                                                    {p.domain}
                                                </span>
                                                <span className="text-xs text-ink-muted">
                                                    {p.name}
                                                    {p.username ? ` · @${p.username}` : ''}
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                ) : null}
                            </div>
                        ) : !seoCmsLocked ? (
                            <form
                                className="space-y-5 border-t border-line p-4"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    askefyForm.transform((data) => ({
                                        ...data,
                                        mode: askefyMode,
                                    }));
                                    askefyForm.post(route('blog.askefy.connect'), {
                                        preserveScroll: true,
                                        onSuccess: () =>
                                            askefyForm.reset(
                                                'password',
                                                'password_confirmation',
                                            ),
                                    });
                                }}
                            >
                                <div className="flex flex-wrap gap-2 text-xs">
                                    <button
                                        type="button"
                                        className={`rounded-lg border px-3 py-1.5 font-semibold ${
                                            askefyMode === 'signup'
                                                ? 'border-ink bg-ink text-white'
                                                : 'border-line text-ink-muted'
                                        }`}
                                        onClick={() => {
                                            setAskefyMode('signup');
                                            askefyForm.setData('mode', 'signup');
                                        }}
                                    >
                                        New signup
                                    </button>
                                    <button
                                        type="button"
                                        className={`rounded-lg border px-3 py-1.5 font-semibold ${
                                            askefyMode === 'login'
                                                ? 'border-ink bg-ink text-white'
                                                : 'border-line text-ink-muted'
                                        }`}
                                        onClick={() => {
                                            setAskefyMode('login');
                                            askefyForm.setData('mode', 'login');
                                        }}
                                    >
                                        Login
                                    </button>
                                </div>

                                <div>
                                    <h4 className="mb-2 text-sm font-bold text-ink">
                                        Your account
                                    </h4>
                                    <div className="grid gap-3 md:grid-cols-2">
                                        {askefyMode === 'signup' ? (
                                            <TextInput
                                                placeholder="Full name"
                                                value={askefyForm.data.name}
                                                readOnly
                                                className="bg-mist/60"
                                            />
                                        ) : null}
                                        <TextInput
                                            type="email"
                                            placeholder="Email"
                                            value={askefyForm.data.email}
                                            readOnly
                                            className="bg-mist/60"
                                        />
                                        <TextInput
                                            type="password"
                                            placeholder="Password (min 8)"
                                            value={askefyForm.data.password}
                                            onChange={(e) =>
                                                askefyForm.setData('password', e.target.value)
                                            }
                                            required
                                        />
                                        {askefyMode === 'signup' ? (
                                            <TextInput
                                                type="password"
                                                placeholder="Confirm password"
                                                value={askefyForm.data.password_confirmation}
                                                onChange={(e) =>
                                                    askefyForm.setData(
                                                        'password_confirmation',
                                                        e.target.value,
                                                    )
                                                }
                                                required
                                            />
                                        ) : null}
                                    </div>
                                </div>

                                <div>
                                    <h4 className="mb-2 text-sm font-bold text-ink">
                                        Business pages from your domains
                                    </h4>
                                    {sites.length === 0 ? (
                                        <p className="text-sm text-ink-muted">
                                            Add websites in SEO first.
                                        </p>
                                    ) : (
                                        <ul className="divide-y divide-line rounded-xl border border-line">
                                            {sites.map((s) => (
                                                <li
                                                    key={s.id}
                                                    className="flex flex-wrap items-center justify-between gap-2 px-3 py-2 text-sm"
                                                >
                                                    <span className="font-semibold text-ink">
                                                        {s.domain}
                                                    </span>
                                                    <span className="text-xs text-ink-muted">
                                                        @{usernameFromPageName(s.domain)}
                                                    </span>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </div>

                                <PrimaryButton
                                    processing={askefyForm.processing}
                                    disabled={sites.length === 0}
                                >
                                    {askefyMode === 'signup'
                                        ? 'Signup + create pages'
                                        : 'Login + sync pages'}
                                </PrimaryButton>
                            </form>
                        ) : null}
                    </section>
                ) : null}

                {tab === 'wordpress' ? (
                    <section className="atlas-panel overflow-hidden">
                        <PanelTitle
                            title="WordPress"
                            subtitle="Optional — Askefy is the preferred publish destination"
                        />
                        {seoCmsLocked ? (
                            <div className="border-t border-line px-4 py-6 text-sm text-ink-muted">
                                Needs paid plan or credit top-up.
                            </div>
                        ) : (
                            <div className="border-t border-line p-4 sm:max-w-lg">
                                {wordpressConnectionId ? (
                                    <p className="mb-3 text-sm text-emerald-800">
                                        WordPress connected — optional destination. Prefer Askefy
                                        when both are connected. AI drafts from the{' '}
                                        <button
                                            type="button"
                                            className="font-semibold underline"
                                            onClick={() => switchTab('write')}
                                        >
                                            Write
                                        </button>{' '}
                                        tab can still publish here.
                                    </p>
                                ) : null}
                                <form
                                    className="space-y-2"
                                    onSubmit={(e) => {
                                        e.preventDefault();
                                        cmsForm.post(route('blog.cms.store'), {
                                            onSuccess: () => cmsForm.reset(),
                                        });
                                    }}
                                >
                                    <h4 className="text-sm font-bold text-ink">
                                        {wordpressConnectionId
                                            ? 'Reconnect WordPress'
                                            : 'Connect WordPress'}
                                    </h4>
                                    <p className="text-xs text-ink-muted">
                                        Sirf CMS connection. Article AI Write tab se banta hai.
                                    </p>
                                    <TextInput
                                        placeholder="https://yoursite.com"
                                        value={cmsForm.data.base_url}
                                        onChange={(e) =>
                                            cmsForm.setData('base_url', e.target.value)
                                        }
                                        required
                                    />
                                    <TextInput
                                        placeholder="Username"
                                        value={cmsForm.data.username}
                                        onChange={(e) =>
                                            cmsForm.setData('username', e.target.value)
                                        }
                                        required
                                    />
                                    <TextInput
                                        type="password"
                                        placeholder="Application password"
                                        value={cmsForm.data.app_password}
                                        onChange={(e) =>
                                            cmsForm.setData('app_password', e.target.value)
                                        }
                                        required
                                    />
                                    <PrimaryButton processing={cmsForm.processing}>
                                        Connect
                                    </PrimaryButton>
                                </form>
                            </div>
                        )}
                    </section>
                ) : null}
            </div>
        </AuthenticatedLayout>
    );
}
