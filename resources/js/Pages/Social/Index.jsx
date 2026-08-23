import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import DateTimePicker from '@/Components/DateTimePicker';
import HelpGuide, { HELP } from '@/Components/HelpGuide';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectMenu from '@/Components/SelectMenu';
import TextInput from '@/Components/TextInput';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { toast } from '@/Components/ToastProvider';
import { confirmAsk } from '@/Components/ConfirmProvider';
import Toggle from '@/Components/Toggle';
import { useEffect, useMemo, useState } from 'react';

const platformOptions = ['facebook', 'instagram', 'threads', 'linkedin', 'x'];

const platformLabels = {
    facebook: 'Facebook',
    instagram: 'Instagram',
    threads: 'Threads',
    linkedin: 'LinkedIn',
    x: 'X (Twitter)',
};

const platformTone = {
    facebook: 'bg-blue-100 text-blue-800 border-blue-200',
    instagram: 'bg-fuchsia-100 text-fuchsia-800 border-fuchsia-200',
    threads: 'bg-zinc-900 text-white border-zinc-800',
    linkedin: 'bg-sky-100 text-sky-800 border-sky-200',
    x: 'bg-zinc-200 text-zinc-800 border-zinc-300',
};

const statusTone = {
    draft: 'bg-mist text-ink-muted',
    scheduled: 'bg-amber-100 text-amber-800',
    publishing: 'bg-sky-100 text-sky-800',
    published: 'bg-emerald-100 text-emerald-800',
    partial: 'bg-orange-100 text-orange-800',
    failed: 'bg-rose-100 text-rose-700',
};

const calendarBadgeTone = {
    draft: 'border-zinc-200 bg-zinc-50 text-zinc-700',
    scheduled: 'border-amber-200 bg-amber-50 text-amber-900',
    publishing: 'border-sky-200 bg-sky-50 text-sky-900',
    published: 'border-emerald-200 bg-emerald-50 text-emerald-900',
    failed: 'border-rose-200 bg-rose-50 text-rose-800',
};

const calendarDotTone = {
    draft: 'bg-zinc-400',
    scheduled: 'bg-amber-500',
    publishing: 'bg-sky-500',
    published: 'bg-emerald-500',
    failed: 'bg-rose-500',
};

const healthTone = {
    healthy: 'text-emerald-700 bg-emerald-50 border-emerald-200',
    warning: 'text-amber-800 bg-amber-50 border-amber-200',
    error: 'text-rose-700 bg-rose-50 border-rose-200',
    unknown: 'text-ink-muted bg-mist border-line',
};

const STATUS_TABS = [
    { id: 'all', label: 'All' },
    { id: 'draft', label: 'Drafts' },
    { id: 'scheduled', label: 'Scheduled' },
    { id: 'published', label: 'Published' },
    { id: 'failed', label: 'Failed' },
];

const platformPublishTone = {
    published: 'border-emerald-300 bg-emerald-50 text-emerald-800 hover:bg-emerald-100',
    failed: 'border-rose-300 bg-rose-50 text-rose-800',
    pending: 'border-zinc-200 bg-zinc-50 text-zinc-500',
};

function PlatformStatusPill({ entry, onResend }) {
    const base =
        'inline-flex items-center gap-0.5 rounded border px-1.5 py-0.5 text-[10px] font-semibold leading-none';
    const tone = platformPublishTone[entry.status] || platformPublishTone.pending;

    if (entry.status === 'published' && entry.permalink) {
        return (
            <a
                href={entry.permalink}
                target="_blank"
                rel="noreferrer"
                className={base + ' ' + tone}
                title="View live post"
            >
                {entry.label} ✓
            </a>
        );
    }

    if (entry.status === 'published') {
        return (
            <span className={base + ' ' + tone} title="Published">
                {entry.label} ✓
            </span>
        );
    }

    if (entry.status === 'failed' && entry.can_resend) {
        return (
            <button
                type="button"
                onClick={() => onResend(entry.platform)}
                className={base + ' ' + tone + ' cursor-pointer hover:bg-rose-100'}
                title={entry.error ? `${entry.error} — click to resend` : 'Click to resend'}
            >
                {entry.label} ↻
            </button>
        );
    }

    if (entry.status === 'failed') {
        return (
            <span className={base + ' ' + tone} title={entry.error || 'Failed'}>
                {entry.label} ✗
            </span>
        );
    }

    return (
        <span className={base + ' ' + tone} title="Not published yet">
            {entry.label} …
        </span>
    );
}

function TrashIcon() {
    return (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" className="h-4 w-4">
            <path
                d="M4 7h16M10 4h4M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12M10 11v6M14 11v6"
                strokeLinecap="round"
                strokeLinejoin="round"
            />
        </svg>
    );
}

function socialQuery(filters = {}, extra = {}) {
    const params = {
        view: filters.view || 'posts',
        status: filters.status || 'all',
        platform: filters.platform || 'all',
        q: filters.q || '',
        month: filters.month,
        ...extra,
    };
    Object.keys(params).forEach((key) => {
        if (params[key] === '' || params[key] == null || params[key] === 'all') {
            if (key !== 'view') delete params[key];
        }
        if (key === 'status' && params[key] === 'all') delete params[key];
        if (key === 'platform' && params[key] === 'all') delete params[key];
        if (key === 'q' && !params[key]) delete params[key];
    });
    return params;
}

export default function Index({
    workspace,
    accounts,
    posts,
    filters: serverFilters = {},
    mediaOptions = [],
    brandKits = [],
    defaultBrandKitId = null,
    calendar,
    posterSizes = [],
    connectionModes = {},
    pendingPagePick = null,
    enabledPlatforms = null,
    connectedPlatforms = [],
    socialPublish = { isLocal: false, simulate: false },
}) {
    const availablePlatforms = useMemo(() => {
        if (!Array.isArray(enabledPlatforms) || enabledPlatforms.length === 0) {
            return platformOptions;
        }
        return platformOptions.filter((p) => enabledPlatforms.includes(p));
    }, [enabledPlatforms]);

    const filters = {
        view: serverFilters.view || 'posts',
        status: serverFilters.status || 'all',
        platform: serverFilters.platform || 'all',
        q: serverFilters.q || '',
        counts: serverFilters.counts || {},
        month: calendar?.month,
    };

    const postRows = Array.isArray(posts) ? posts : posts?.data || [];
    const view = filters.view;

    const canPublishPost = (post) => {
        if (!post.has_attached_media) return false;
        if (post.has_public_image) return true;
        return socialPublish.isLocal && socialPublish.simulate;
    };

    const displayStatus = (post) =>
        post.status === 'published' && post.has_publish_failures ? 'partial' : post.status;

    const canEditPost = (post) =>
        ['draft', 'scheduled', 'failed'].includes(post.status) ||
        (post.status === 'published' && post.has_publish_failures);

    const missingThreads =
        availablePlatforms.includes('threads') &&
        !connectedPlatforms.includes('threads');

    const postForm = useForm({
        title: '',
        body: '',
        platforms: ['instagram'],
        scheduled_at: '',
        delivery: 'draft',
        requires_approval: false,
        media_asset_id: '',
        public_media_url: '',
        brand_kit_id: defaultBrandKitId ? String(defaultBrandKitId) : '',
        generate_posters: true,
    });
    const accountForm = useForm({
        platform: 'facebook',
        account_name: workspace?.name || '',
        account_type: 'page',
        use_oauth: (connectionModes.facebook || 'sandbox') === 'oauth',
    });

    useEffect(() => {
        if (!availablePlatforms.length) return;
        if (!availablePlatforms.includes(accountForm.data.platform)) {
            const next = availablePlatforms[0];
            accountForm.setData({
                ...accountForm.data,
                platform: next,
                use_oauth: (connectionModes[next] || 'sandbox') === 'oauth',
            });
        }
        const platforms = (postForm.data.platforms || []).filter((p) =>
            availablePlatforms.includes(p),
        );
        if (platforms.length !== postForm.data.platforms.length) {
            postForm.setData(
                'platforms',
                platforms.length ? platforms : [availablePlatforms[0]],
            );
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [availablePlatforms.join(',')]);

    // Workspace switch / mode change: refresh connect form defaults (useForm does not).
    useEffect(() => {
        accountForm.setData({
            platform: 'facebook',
            account_name: workspace?.name || '',
            account_type: 'page',
            use_oauth: (connectionModes.facebook || 'sandbox') === 'oauth',
        });
        accountForm.clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps -- only sync on workspace/mode change
    }, [workspace?.id, workspace?.name, connectionModes.facebook]);

    const [activePreview, setActivePreview] = useState(postForm.data.platforms[0] || 'instagram');
    const [editingPostId, setEditingPostId] = useState(null);
    const [searchDraft, setSearchDraft] = useState(filters.q);
    const [openActionsId, setOpenActionsId] = useState(null);
    const [selectedPageId, setSelectedPageId] = useState(
        pendingPagePick?.suggested_id || pendingPagePick?.pages?.[0]?.id || '',
    );

    useEffect(() => {
        if (!pendingPagePick?.pages?.length) return;
        setSelectedPageId(pendingPagePick.suggested_id || pendingPagePick.pages[0].id);
    }, [pendingPagePick]);

    const brandKitOptions = useMemo(
        () =>
            brandKits.map((kit) => ({
                value: String(kit.id),
                label: kit.is_active ? `${kit.name} (default)` : kit.name,
                meta: kit.default_cta_label || kit.primary_color || undefined,
            })),
        [brandKits],
    );

    const visitView = (nextView, extra = {}) => {
        router.get(
            route('social.index'),
            socialQuery({ ...filters, view: nextView }, extra),
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const applyFilters = (patch) => {
        router.get(
            route('social.index'),
            socialQuery({ ...filters, ...patch, view: 'posts' }, { page: 1 }),
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const runPostAction = (url, data = {}) => {
        setOpenActionsId(null);
        router.post(url, data, {
            preserveScroll: true,
            onSuccess: () => {
                router.reload({ only: ['posts', 'filters'], preserveScroll: true });
            },
        });
    };

    const resendPlatform = (postId, platform) => {
        runPostAction(route('social.posts.retry', postId), { platform });
    };

    const blankPost = () => ({
        title: '',
        body: '',
        platforms: ['instagram'],
        scheduled_at: '',
        delivery: 'draft',
        requires_approval: false,
        media_asset_id: '',
        public_media_url: '',
        brand_kit_id: defaultBrandKitId ? String(defaultBrandKitId) : '',
        generate_posters: true,
    });

    const beginCompose = () => {
        setEditingPostId(null);
        postForm.setData(blankPost());
        postForm.clearErrors();
        visitView('compose');
    };

    const beginEdit = (post) => {
        const delivery = post.status === 'scheduled' ? 'schedule' : 'draft';
        const kitId = post.brand_kit_id || defaultBrandKitId;
        postForm.setData({
            title: post.title || '',
            body: post.body || '',
            platforms: post.platforms?.length ? post.platforms : ['instagram'],
            scheduled_at: post.scheduled_at_local || '',
            delivery,
            requires_approval: !!post.requires_approval,
            media_asset_id: post.media_asset_id ? String(post.media_asset_id) : '',
            public_media_url: '',
            brand_kit_id: kitId ? String(kitId) : '',
            generate_posters: false,
        });
        postForm.clearErrors();
        setEditingPostId(post.id);
        setOpenActionsId(null);
        visitView('compose');
    };

    const cancelEdit = () => {
        setEditingPostId(null);
        postForm.setData(blankPost());
        postForm.clearErrors();
        visitView('posts');
    };

    useEffect(() => {
        setSearchDraft(filters.q);
    }, [filters.q]);

    useEffect(() => {
        if (postForm.data.platforms.includes(activePreview)) return;
        setActivePreview(postForm.data.platforms[0] || 'instagram');
    }, [postForm.data.platforms, activePreview]);

    useEffect(() => {
        const close = () => setOpenActionsId(null);
        window.addEventListener('click', close);
        return () => window.removeEventListener('click', close);
    }, []);

    const selectedMedia = useMemo(() => {
        const id = String(postForm.data.media_asset_id || '');
        if (!id) return null;
        return mediaOptions.find((m) => String(m.id) === id) || null;
    }, [mediaOptions, postForm.data.media_asset_id]);

    const previewAccount =
        accounts.find((a) => a.platform === activePreview && a.status === 'connected') ||
        accounts.find((a) => a.platform === activePreview) ||
        null;

    const connectedCount = accounts.filter((a) => a.status === 'connected').length;
    const connectedAccounts = accounts.filter((a) => a.status === 'connected');
    const disconnectedAccounts = accounts.filter((a) => a.status !== 'connected');
    const todayKey = useMemo(() => {
        const d = new Date();
        return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    }, []);
    const navItems = [
        { id: 'posts', label: 'Posts', hint: filters.counts.all || 0 },
        { id: 'calendar', label: 'Calendar' },
        { id: 'accounts', label: 'Accounts', hint: connectedCount },
        { id: 'compose', label: editingPostId ? 'Edit' : 'Compose' },
    ];

    const submitPost = (e) => {
        e.preventDefault();

        if (postForm.data.delivery === 'schedule' && !postForm.data.scheduled_at) {
            const msg = 'Pick a schedule date & time.';
            postForm.setError('scheduled_at', msg);
            toast.error(msg);
            return;
        }

        if (postForm.data.platforms.length === 0) {
            const msg = 'Select at least one platform.';
            postForm.setError('platforms', msg);
            toast.error(msg);
            return;
        }

        if (
            postForm.data.platforms.length > 0 &&
            !postForm.data.media_asset_id &&
            !String(postForm.data.public_media_url || '').trim()
        ) {
            const msg = 'All social posts need an image — pick media or paste a public https URL.';
            postForm.setError('media_asset_id', msg);
            toast.error(msg);
            return;
        }

        postForm.clearErrors();
        postForm.transform((data) => ({
            ...data,
            media_asset_id: data.media_asset_id ? Number(data.media_asset_id) : null,
            public_media_url: data.public_media_url || null,
            brand_kit_id: data.brand_kit_id ? Number(data.brand_kit_id) : null,
            scheduled_at: data.delivery === 'schedule' ? data.scheduled_at || null : null,
            requires_approval: !!data.requires_approval,
            generate_posters: !!data.generate_posters,
        }));

        const opts = {
            preserveScroll: true,
            onSuccess: () => {
                setEditingPostId(null);
                postForm.setData(blankPost());
                visitView('posts');
            },
        };

        if (editingPostId) {
            postForm.patch(route('social.posts.update', editingPostId), opts);
        } else {
            postForm.post(route('social.posts.store'), opts);
        }
    };

    return (
        <AuthenticatedLayout
            header={
                <div className="flex min-w-0 flex-wrap items-center justify-between gap-3">
                    <div className="min-w-0">
                        <div className="truncate text-xs font-semibold uppercase tracking-[0.16em] text-ink-muted">
                            {workspace.name}
                        </div>
                        <div className="flex items-center gap-1.5">
                            <h2 className="font-display text-2xl font-bold leading-tight text-ink">SMM</h2>
                            <HelpGuide help={HELP.social} />
                        </div>
                    </div>
                    {view !== 'compose' ? (
                        <PrimaryButton
                            type="button"
                            onClick={beginCompose}
                            className="shrink-0 self-center"
                        >
                            New post
                        </PrimaryButton>
                    ) : null}
                </div>
            }
        >
            <Head title="SMM" />

            {pendingPagePick?.pages?.length ? (
                <div className="fixed inset-0 z-[80] flex items-center justify-center bg-ink/40 p-4">
                    <div className="w-full max-w-md rounded-lg border border-line bg-white p-4 shadow-xl">
                        <h3 className="font-display text-lg font-bold text-ink">
                            Choose {pendingPagePick.platform === 'instagram' ? 'Instagram' : 'Facebook'} page
                        </h3>
                        <p className="mt-1 text-sm text-ink-muted">
                            Workspace <span className="font-semibold text-ink">{workspace.name}</span> —
                            pick the correct page. Wrong page = posts go to the wrong brand.
                        </p>
                        <ul className="mt-3 max-h-72 space-y-2 overflow-y-auto">
                            {pendingPagePick.pages.map((page) => {
                                const selected = selectedPageId === page.id;
                                const suggested = pendingPagePick.suggested_id === page.id;
                                return (
                                    <li key={page.id}>
                                        <button
                                            type="button"
                                            onClick={() => setSelectedPageId(page.id)}
                                            className={
                                                'flex w-full items-start justify-between gap-2 rounded-md border px-3 py-2 text-left text-sm transition ' +
                                                (selected
                                                    ? 'border-signal bg-signal-soft/60'
                                                    : 'border-line bg-white hover:border-signal/40')
                                            }
                                        >
                                            <span>
                                                <span className="font-semibold text-ink">{page.name}</span>
                                                {page.instagram?.username ? (
                                                    <span className="mt-0.5 block text-xs text-ink-muted">
                                                        IG @{page.instagram.username}
                                                    </span>
                                                ) : null}
                                            </span>
                                            {suggested ? (
                                                <span className="shrink-0 rounded bg-emerald-100 px-1.5 py-0.5 text-[10px] font-bold uppercase text-emerald-800">
                                                    Likely match
                                                </span>
                                            ) : null}
                                        </button>
                                    </li>
                                );
                            })}
                        </ul>
                        <div className="mt-4 flex flex-wrap justify-end gap-2">
                            <SecondaryButton
                                type="button"
                                onClick={() => router.post(route('social.oauth.cancel-page-pick'))}
                            >
                                Cancel
                            </SecondaryButton>
                            <PrimaryButton
                                type="button"
                                disabled={!selectedPageId}
                                onClick={() =>
                                    router.post(route('social.oauth.select-page'), {
                                        page_id: selectedPageId,
                                    })
                                }
                            >
                                Connect this page
                            </PrimaryButton>
                        </div>
                    </div>
                </div>
            ) : null}

            <div className="atlas-shell space-y-4">
                {Object.keys(postForm.errors).length > 0 && view === 'compose' ? (
                    <div className="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">
                        <div className="font-semibold">Couldn’t save post</div>
                        <ul className="mt-1 list-disc space-y-0.5 ps-4">
                            {Object.entries(postForm.errors).map(([key, message]) => (
                                <li key={key}>{message}</li>
                            ))}
                        </ul>
                    </div>
                ) : null}

                <div className="flex flex-wrap gap-1 rounded-md border border-line bg-white p-1">
                    {navItems.map((item) => {
                        const active = view === item.id;
                        return (
                            <button
                                key={item.id}
                                type="button"
                                onClick={() =>
                                    item.id === 'compose' ? beginCompose() : visitView(item.id)
                                }
                                className={
                                    'inline-flex items-center gap-2 rounded px-3 py-2 text-sm font-semibold transition ' +
                                    (active
                                        ? 'bg-signal text-white'
                                        : 'text-ink-muted hover:bg-mist hover:text-ink')
                                }
                            >
                                {item.label}
                                {item.hint != null ? (
                                    <span
                                        className={
                                            'rounded px-1.5 py-0.5 text-[10px] tabular-nums ' +
                                            (active ? 'bg-white/20 text-white' : 'bg-mist text-ink-muted')
                                        }
                                    >
                                        {item.hint}
                                    </span>
                                ) : null}
                            </button>
                        );
                    })}
                </div>

                {view === 'posts' ? (
                    <section className="atlas-panel overflow-visible">
                        <div className="space-y-3 border-b border-line px-4 py-3">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <div>
                                    <div className="flex items-center gap-1.5">
                                        <div className="font-display text-lg font-bold text-ink">
                                            Content queue
                                        </div>
                                        <HelpGuide help={HELP.social} />
                                    </div>
                                    <p className="text-sm text-ink-muted">
                                        Filter, search, and act on posts.
                                    </p>
                                </div>
                                {!Array.isArray(posts) && posts?.total != null ? (
                                    <div className="text-xs text-ink-muted">
                                        {posts.total === 0
                                            ? '0 posts'
                                            : `${posts.from}–${posts.to} of ${posts.total}`}
                                    </div>
                                ) : null}
                            </div>

                            <div className="flex flex-wrap gap-1">
                                {STATUS_TABS.map((tab) => {
                                    const active = filters.status === tab.id;
                                    const count = filters.counts[tab.id] ?? 0;
                                    return (
                                        <button
                                            key={tab.id}
                                            type="button"
                                            onClick={() => applyFilters({ status: tab.id })}
                                            className={
                                                'rounded-md border px-2.5 py-1.5 text-xs font-semibold transition ' +
                                                (active
                                                    ? 'border-signal bg-signal-soft/70 text-ink'
                                                    : 'border-line bg-white text-ink-muted hover:border-signal/40')
                                            }
                                        >
                                            {tab.label}
                                            <span className="ms-1.5 tabular-nums opacity-70">{count}</span>
                                        </button>
                                    );
                                })}
                            </div>

                            {missingThreads ? (
                                <div className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                                    <span className="font-semibold">Threads not connected.</span>{' '}
                                    Connect a Threads account under the Accounts tab — AI posts only
                                    target platforms with a live connection (currently Facebook +
                                    Instagram).
                                </div>
                            ) : null}

                            <div className="flex flex-wrap gap-2">
                                <div className="min-w-[180px] flex-1">
                                    <TextInput
                                        value={searchDraft}
                                        placeholder="Search title or caption…"
                                        onChange={(e) => setSearchDraft(e.target.value)}
                                        onKeyDown={(e) => {
                                            if (e.key === 'Enter') {
                                                applyFilters({ q: searchDraft.trim() });
                                            }
                                        }}
                                        className="w-full"
                                    />
                                </div>
                                <div className="w-full sm:w-44">
                                    <SelectMenu
                                        value={filters.platform}
                                        onChange={(v) => applyFilters({ platform: v || 'all' })}
                                        options={[
                                            { value: 'all', label: 'All platforms' },
                                            ...availablePlatforms.map((p) => ({
                                                value: p,
                                                label: platformLabels[p] || p,
                                            })),
                                        ]}
                                    />
                                </div>
                                <SecondaryButton
                                    type="button"
                                    onClick={() => applyFilters({ q: searchDraft.trim() })}
                                >
                                    Search
                                </SecondaryButton>
                            </div>
                        </div>

                        <div className="hidden border-b border-line bg-mist/40 px-4 py-2 text-[10px] font-bold uppercase tracking-wide text-ink-muted md:grid md:grid-cols-[minmax(0,1.6fr)_minmax(150px,1fr)_130px_44px] md:gap-3">
                            <div>Post</div>
                            <div>Status</div>
                            <div>When</div>
                            <div />
                        </div>

                        <ul className="divide-y divide-line">
                            {postRows.length === 0 ? (
                                <li className="px-4 py-12 text-center">
                                    <div className="font-semibold text-ink">No posts here</div>
                                    <p className="mt-1 text-sm text-ink-muted">
                                        Try another filter, or create a new post.
                                    </p>
                                    <PrimaryButton className="mt-4" type="button" onClick={beginCompose}>
                                        New post
                                    </PrimaryButton>
                                </li>
                            ) : (
                                postRows.map((post) => (
                                    <li
                                        key={post.id}
                                        className={
                                            'grid gap-2 px-4 py-3 md:grid-cols-[minmax(0,1.6fr)_minmax(150px,1fr)_130px_44px] md:items-center md:gap-3 ' +
                                            (openActionsId === post.id ? 'relative z-30' : '')
                                        }
                                    >
                                        <div className="min-w-0">
                                            <div className="truncate font-semibold text-ink">
                                                {post.title || 'Untitled'}
                                            </div>
                                            <div className="mt-0.5 line-clamp-1 text-sm text-ink-muted">
                                                {post.body}
                                            </div>
                                            {post.failure_reason ? (
                                                <div className="mt-1 truncate text-xs text-rose-600">
                                                    {post.failure_reason}
                                                </div>
                                            ) : null}
                                            {post.status === 'draft' &&
                                            post.requires_approval &&
                                            post.approved_at &&
                                            !post.has_public_image ? (
                                                <div className="mt-1 text-xs text-amber-700">
                                                    {socialPublish.isLocal
                                                        ? socialPublish.simulate
                                                            ? 'Approved — use Test publish (local) below, or deploy to production for live Meta.'
                                                            : 'Approved — live Meta publish works on production (https). Local: set SOCIAL_SIMULATE_PUBLISH=true to test flow.'
                                                        : 'Approved — waiting for a public https image before publish.'}
                                                </div>
                                            ) : null}
                                            {post.status === 'draft' &&
                                            post.requires_approval &&
                                            !post.approved_at &&
                                            post.has_attached_media ? (
                                                <div className="mt-1 text-xs text-sky-700">
                                                    Ready for approval
                                                </div>
                                            ) : null}
                                        </div>
                                        <div>
                                            <div className="flex flex-col gap-1.5">
                                                <span
                                                    className={
                                                        'inline-flex w-fit rounded-md px-2 py-0.5 text-[10px] font-bold uppercase ' +
                                                        (statusTone[displayStatus(post)] ||
                                                            statusTone.draft)
                                                    }
                                                >
                                                    {displayStatus(post)}
                                                </span>
                                                {(post.platform_statuses || []).length > 0 ? (
                                                    <div className="flex flex-wrap gap-1">
                                                        {(post.platform_statuses || []).map(
                                                            (entry) => (
                                                                <PlatformStatusPill
                                                                    key={entry.platform}
                                                                    entry={entry}
                                                                    onResend={(platform) =>
                                                                        resendPlatform(
                                                                            post.id,
                                                                            platform,
                                                                        )
                                                                    }
                                                                />
                                                            ),
                                                        )}
                                                    </div>
                                                ) : null}
                                            </div>
                                        </div>
                                        <div className="text-xs text-ink-muted">
                                            {post.scheduled_at || post.published_at || '—'}
                                        </div>
                                        <div className="relative z-10 justify-self-end">
                                            <button
                                                type="button"
                                                aria-label="Actions"
                                                onClick={(e) => {
                                                    e.stopPropagation();
                                                    setOpenActionsId(
                                                        openActionsId === post.id ? null : post.id,
                                                    );
                                                }}
                                                className="inline-flex h-9 w-9 items-center justify-center rounded-md border border-line bg-white text-ink hover:border-signal/40"
                                            >
                                                ⋮
                                            </button>
                                            {openActionsId === post.id ? (
                                                <div
                                                    className="absolute right-0 top-full z-50 mt-1 w-48 rounded-md border border-line bg-white py-1 shadow-lg"
                                                    onClick={(e) => e.stopPropagation()}
                                                >
                                                    {post.has_publish_failures &&
                                                    post.approved_at &&
                                                    !post.has_public_image ? (
                                                        <ActionItem
                                                            onClick={() => beginEdit(post)}
                                                        >
                                                            Add public image URL
                                                        </ActionItem>
                                                    ) : null}
                                                    {canEditPost(post) ? (
                                                        <ActionItem onClick={() => beginEdit(post)}>
                                                            Edit
                                                        </ActionItem>
                                                    ) : null}
                                                    {post.requires_approval &&
                                                    !post.approved_at &&
                                                    post.has_attached_media ? (
                                                        <ActionItem
                                                            onClick={() =>
                                                                runPostAction(
                                                                    route(
                                                                        'social.posts.approve',
                                                                        post.id,
                                                                    ),
                                                                )
                                                            }
                                                        >
                                                            Approve
                                                        </ActionItem>
                                                    ) : null}
                                                    {['draft', 'scheduled', 'failed'].includes(
                                                        post.status,
                                                    ) &&
                                                    canPublishPost(post) &&
                                                    (!post.requires_approval || post.approved_at) ? (
                                                        <ActionItem
                                                            onClick={() =>
                                                                runPostAction(
                                                                    route(
                                                                        'social.posts.publish',
                                                                        post.id,
                                                                    ),
                                                                )
                                                            }
                                                        >
                                                            {socialPublish.isLocal &&
                                                            socialPublish.simulate &&
                                                            !post.has_public_image
                                                                ? 'Test publish (local)'
                                                                : 'Publish now'}
                                                        </ActionItem>
                                                    ) : null}
                                                    {['draft', 'scheduled', 'failed'].includes(
                                                        post.status,
                                                    ) &&
                                                    !post.has_attached_media ? (
                                                        <ActionItem
                                                            onClick={() =>
                                                                runPostAction(
                                                                    route('social.posts.posters', post.id),
                                                                )
                                                            }
                                                        >
                                                            Generate poster
                                                        </ActionItem>
                                                    ) : null}
                                                    {post.has_publish_failures &&
                                                    canPublishPost(post) &&
                                                    (!post.requires_approval || post.approved_at) ? (
                                                        <ActionItem
                                                            onClick={() =>
                                                                runPostAction(
                                                                    route(
                                                                        'social.posts.retry',
                                                                        post.id,
                                                                    ),
                                                                )
                                                            }
                                                        >
                                                            Resend failed
                                                        </ActionItem>
                                                    ) : null}
                                                    {post.status === 'failed' &&
                                                    !post.has_publish_failures ? (
                                                        <ActionItem
                                                            onClick={() =>
                                                                runPostAction(
                                                                    route(
                                                                        'social.posts.retry',
                                                                        post.id,
                                                                    ),
                                                                )
                                                            }
                                                        >
                                                            Retry
                                                        </ActionItem>
                                                    ) : null}
                                                    <ActionItem
                                                        onClick={() =>
                                                            runPostAction(
                                                                route('social.posts.posters', post.id),
                                                            )
                                                        }
                                                    >
                                                        Export posters
                                                    </ActionItem>
                                                    <ActionItem
                                                        danger
                                                        onClick={async () => {
                                                            const ok = await confirmAsk({
                                                                title: 'Delete this post?',
                                                                message:
                                                                    'This scheduled/draft post will be removed permanently.',
                                                                confirmLabel: 'Delete post',
                                                            });
                                                            if (ok) {
                                                                router.delete(
                                                                    route('social.posts.destroy', post.id),
                                                                );
                                                            }
                                                        }}
                                                    >
                                                        Delete
                                                    </ActionItem>
                                                </div>
                                            ) : null}
                                        </div>
                                    </li>
                                ))
                            )}
                        </ul>

                        {!Array.isArray(posts) && posts?.last_page > 1 ? (
                            <div className="flex flex-wrap items-center justify-between gap-2 border-t border-line px-4 py-3">
                                <div className="text-xs text-ink-muted">
                                    Page {posts.current_page} of {posts.last_page}
                                </div>
                                <div className="flex flex-wrap gap-1">
                                    {(posts.links || []).map((link, i) => {
                                        const label = String(link.label || '')
                                            .replace('&laquo;', '←')
                                            .replace('&raquo;', '→')
                                            .replace(/<[^>]+>/g, '');
                                        if (!link.url) {
                                            return (
                                                <span
                                                    key={`${label}-${i}`}
                                                    className="rounded-md px-2.5 py-1.5 text-xs font-semibold text-ink-muted opacity-40"
                                                >
                                                    {label}
                                                </span>
                                            );
                                        }
                                        return (
                                            <Link
                                                key={`${label}-${i}`}
                                                href={link.url}
                                                preserveScroll
                                                className={
                                                    'rounded-md border px-2.5 py-1.5 text-xs font-semibold transition ' +
                                                    (link.active
                                                        ? 'border-signal bg-signal text-white'
                                                        : 'border-line bg-white text-ink hover:border-signal/40')
                                                }
                                            >
                                                {label}
                                            </Link>
                                        );
                                    })}
                                </div>
                            </div>
                        ) : null}
                    </section>
                ) : null}

                {view === 'calendar' ? (
                    <section className="atlas-panel overflow-hidden">
                        <div className="flex flex-wrap items-center justify-between gap-2 border-b border-line px-4 py-3">
                            <div>
                                <div className="font-display text-lg font-bold text-ink">
                                    Content calendar
                                </div>
                                <p className="text-sm text-ink-muted">
                                    Scheduled posts for {calendar.label}
                                </p>
                            </div>
                            <div className="flex items-center gap-2 text-sm">
                                <Link
                                    href={route(
                                        'social.index',
                                        socialQuery(filters, {
                                            view: 'calendar',
                                            month: calendarPrev(calendar.month),
                                        }),
                                    )}
                                    className="rounded-md border border-line px-2 py-1 font-semibold text-ink hover:bg-mist"
                                >
                                    ←
                                </Link>
                                <span className="min-w-[120px] text-center font-semibold text-ink">
                                    {calendar.label}
                                </span>
                                <Link
                                    href={route(
                                        'social.index',
                                        socialQuery(filters, {
                                            view: 'calendar',
                                            month: calendarNext(calendar.month),
                                        }),
                                    )}
                                    className="rounded-md border border-line px-2 py-1 font-semibold text-ink hover:bg-mist"
                                >
                                    →
                                </Link>
                            </div>
                        </div>

                        <div className="flex flex-wrap gap-3 border-b border-line px-4 py-2.5">
                            {[
                                { id: 'draft', label: 'Draft' },
                                { id: 'scheduled', label: 'Scheduled' },
                                { id: 'published', label: 'Published' },
                                { id: 'failed', label: 'Failed' },
                            ].map((item) => (
                                <div
                                    key={item.id}
                                    className="inline-flex items-center gap-1.5 text-[11px] font-semibold text-ink-muted"
                                >
                                    <span
                                        className={
                                            'h-2 w-2 rounded-full ' +
                                            (calendarDotTone[item.id] || calendarDotTone.draft)
                                        }
                                    />
                                    {item.label}
                                </div>
                            ))}
                        </div>

                        <div className="grid grid-cols-7 border-b border-line bg-mist/50 text-center text-[10px] font-bold uppercase tracking-wide text-ink-muted">
                            {['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].map((d) => (
                                <div key={d} className="px-2 py-2.5">
                                    {d}
                                </div>
                            ))}
                        </div>
                        <div className="grid grid-cols-7">
                            {calendar.days.map((day) => {
                                const isToday = day.date === todayKey;
                                return (
                                    <div
                                        key={day.date}
                                        className={
                                            'min-h-[118px] border-b border-r border-line px-2.5 pb-2.5 pt-2 ' +
                                            (day.inMonth ? 'bg-white' : 'bg-mist/40')
                                        }
                                    >
                                        <div className="mb-2 flex items-center justify-between gap-1">
                                            <span
                                                className={
                                                    'inline-flex h-7 min-w-7 items-center justify-center rounded-full px-1.5 text-xs font-bold tabular-nums ' +
                                                    (isToday
                                                        ? 'bg-signal text-white'
                                                        : day.inMonth
                                                          ? 'bg-mist text-ink'
                                                          : 'bg-transparent text-ink-muted')
                                                }
                                            >
                                                {day.label}
                                            </span>
                                            {day.posts.length > 0 ? (
                                                <span className="rounded-full bg-mist px-1.5 py-0.5 text-[10px] font-bold tabular-nums text-ink-muted">
                                                    {day.posts.length}
                                                </span>
                                            ) : null}
                                        </div>
                                        <div className="space-y-1.5">
                                            {day.posts.slice(0, 3).map((post) => {
                                                const platform = post.platforms?.[0] || null;
                                                return (
                                                    <button
                                                        key={post.id}
                                                        type="button"
                                                        onClick={() => beginEdit(post)}
                                                        className={
                                                            'flex w-full items-start gap-1.5 rounded-md border px-1.5 py-1 text-left shadow-sm transition hover:-translate-y-px hover:shadow ' +
                                                            (calendarBadgeTone[post.status] ||
                                                                calendarBadgeTone.draft)
                                                        }
                                                        title={post.title || post.body}
                                                    >
                                                        <span
                                                            className={
                                                                'mt-1 h-1.5 w-1.5 shrink-0 rounded-full ' +
                                                                (calendarDotTone[post.status] ||
                                                                    calendarDotTone.draft)
                                                            }
                                                        />
                                                        <span className="min-w-0 flex-1">
                                                            <span className="block truncate text-[10px] font-bold leading-tight">
                                                                {post.title || 'Post'}
                                                            </span>
                                                            <span className="mt-0.5 flex flex-wrap items-center gap-1">
                                                                <span className="rounded bg-white/70 px-1 py-px text-[9px] font-bold uppercase tracking-wide opacity-80">
                                                                    {post.status}
                                                                </span>
                                                                {platform ? (
                                                                    <span
                                                                        className={
                                                                            'rounded border px-1 py-px text-[9px] font-bold capitalize ' +
                                                                            (platformTone[platform] ||
                                                                                '')
                                                                        }
                                                                    >
                                                                        {platform === 'instagram'
                                                                            ? 'IG'
                                                                            : platform === 'facebook'
                                                                              ? 'FB'
                                                                              : platform === 'threads'
                                                                                ? 'TH'
                                                                                : platform}
                                                                    </span>
                                                                ) : null}
                                                            </span>
                                                        </span>
                                                    </button>
                                                );
                                            })}
                                            {day.posts.length > 3 ? (
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        applyFilters({
                                                            status: 'all',
                                                            q: '',
                                                        })
                                                    }
                                                    className="w-full rounded-md border border-dashed border-line px-1.5 py-1 text-[10px] font-semibold text-ink-muted hover:border-signal/40 hover:text-ink"
                                                >
                                                    +{day.posts.length - 3} more
                                                </button>
                                            ) : null}
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    </section>
                ) : null}

                {view === 'accounts' ? (
                    <section className="grid gap-4 lg:grid-cols-[0.9fr_1.1fr]">
                        <form
                            className="atlas-panel space-y-3 p-4"
                            onSubmit={(e) => {
                                e.preventDefault();
                                if (accountForm.data.use_oauth) {
                                    // Full browser navigation — never Axios/Inertia XHR to Facebook.
                                    const platform = encodeURIComponent(
                                        accountForm.data.platform || 'facebook',
                                    );
                                    const accountType = encodeURIComponent(
                                        accountForm.data.account_type || 'page',
                                    );
                                    const accountName = encodeURIComponent(
                                        accountForm.data.account_name || workspace?.name || '',
                                    );
                                    window.location.href = `/social/oauth/${platform}/start?account_type=${accountType}&account_name=${accountName}`;
                                    return;
                                }
                                accountForm.post(route('social.accounts.store'), {
                                    onSuccess: () =>
                                        accountForm.setData('account_name', workspace?.name || ''),
                                });
                            }}
                        >
                            <h3 className="font-display text-lg font-bold text-ink">Connect account</h3>
                            <p className="text-sm text-ink-muted">
                                Type the exact Facebook/Instagram page name (e.g. Vibgyor Holidays). With
                                “Connect for real” ON, we match that Meta page — not just the first one.
                            </p>
                            <SelectMenu
                                value={accountForm.data.platform}
                                onChange={(v) => {
                                    accountForm.setData({
                                        ...accountForm.data,
                                        platform: v,
                                        use_oauth: (connectionModes[v] || 'sandbox') === 'oauth',
                                    });
                                }}
                                options={availablePlatforms.map((p) => ({
                                    value: p,
                                    label: platformLabels[p] || p,
                                    meta:
                                        (connectionModes[p] || 'sandbox') === 'oauth'
                                            ? 'live ready'
                                            : 'test mode',
                                }))}
                            />
                            <SelectMenu
                                value={accountForm.data.account_type}
                                onChange={(v) => accountForm.setData('account_type', v)}
                                options={[
                                    { value: 'page', label: 'Business page' },
                                    { value: 'profile', label: 'Personal profile' },
                                ]}
                            />
                            <div>
                                <InputLabel value="Facebook / Instagram / Threads account name" />
                                <TextInput
                                    className="mt-1 w-full"
                                    placeholder="e.g. Vibgyor Holidays or @handle"
                                    value={accountForm.data.account_name}
                                    onChange={(e) =>
                                        accountForm.setData('account_name', e.target.value)
                                    }
                                    required
                                />
                                <p className="mt-1 text-xs text-ink-muted">
                                    FB/IG: match Page name. Threads: your Threads handle. Toggle ON for
                                    live connect.
                                </p>
                            </div>
                            <Toggle
                                checked={!!accountForm.data.use_oauth}
                                onChange={(v) => accountForm.setData('use_oauth', v)}
                                disabled={
                                    (connectionModes[accountForm.data.platform] || 'sandbox') !==
                                    'oauth'
                                }
                                label="Connect for real (needs API keys)"
                            />
                            <div>
                                <PrimaryButton processing={accountForm.processing}>
                                    Connect account
                                </PrimaryButton>
                            </div>
                        </form>

                        <div className="space-y-4">
                            <div className="atlas-panel overflow-hidden">
                                <div className="border-b border-line px-4 py-3 font-display text-lg font-bold text-ink">
                                    Connected ({connectedAccounts.length})
                                </div>
                                <ul className="divide-y divide-line">
                                    {connectedAccounts.length === 0 ? (
                                        <li className="px-4 py-8 text-sm text-ink-muted">
                                            No connected accounts. Connect one to publish.
                                        </li>
                                    ) : (
                                        connectedAccounts.map((account) => (
                                            <li key={account.id} className="px-4 py-3 text-sm">
                                                <div className="flex items-center justify-between gap-2">
                                                    <span className="font-semibold capitalize text-ink">
                                                        {account.platform} · {account.account_name}
                                                        <span className="ms-1 text-xs font-medium normal-case text-ink-muted">
                                                            ({account.account_type || 'page'} ·{' '}
                                                            {account.connection_mode === 'oauth'
                                                                ? 'live'
                                                                : 'test mode'})
                                                        </span>
                                                    </span>
                                                    <span
                                                        className={
                                                            'rounded-md border px-1.5 py-0.5 text-[10px] font-bold uppercase ' +
                                                            (healthTone[account.health] ||
                                                                healthTone.unknown)
                                                        }
                                                    >
                                                        {account.status}/{account.health}
                                                    </span>
                                                </div>
                                                {account.last_error ? (
                                                    <div className="mt-1 text-xs text-rose-600">
                                                        {account.last_error}
                                                    </div>
                                                ) : null}
                                                <div className="mt-2 flex flex-wrap gap-2">
                                                    <SecondaryButton
                                                        type="button"
                                                        onClick={async () => {
                                                            const ok = await confirmAsk({
                                                                title: 'Disconnect account?',
                                                                message:
                                                                    'Status will become disconnected. You can reconnect later.',
                                                                confirmLabel: 'Disconnect',
                                                            });
                                                            if (ok) {
                                                                router.post(
                                                                    route(
                                                                        'social.accounts.disconnect',
                                                                        account.id,
                                                                    ),
                                                                );
                                                            }
                                                        }}
                                                    >
                                                        Disconnect
                                                    </SecondaryButton>
                                                    <button
                                                        type="button"
                                                        title="Remove permanently"
                                                        aria-label="Remove permanently"
                                                        onClick={async () => {
                                                            const ok = await confirmAsk({
                                                                title: 'Remove account?',
                                                                message:
                                                                    'This deletes the account from this workspace.',
                                                                confirmLabel: 'Remove',
                                                            });
                                                            if (ok) {
                                                                router.delete(
                                                                    route(
                                                                        'social.accounts.destroy',
                                                                        account.id,
                                                                    ),
                                                                );
                                                            }
                                                        }}
                                                        className="inline-flex h-9 w-9 items-center justify-center rounded-md border border-rose-200 bg-rose-50 text-rose-600 hover:bg-rose-100"
                                                    >
                                                        <TrashIcon />
                                                    </button>
                                                </div>
                                            </li>
                                        ))
                                    )}
                                </ul>
                            </div>

                            {disconnectedAccounts.length > 0 ? (
                                <div className="atlas-panel overflow-hidden">
                                    <div className="border-b border-line px-4 py-3 font-display text-lg font-bold text-ink">
                                        Disconnected ({disconnectedAccounts.length})
                                    </div>
                                    <ul className="divide-y divide-line">
                                        {disconnectedAccounts.map((account) => (
                                            <li key={account.id} className="px-4 py-3 text-sm">
                                                <div className="flex items-center justify-between gap-2">
                                                    <span className="font-semibold capitalize text-ink-muted">
                                                        {account.platform} · {account.account_name}
                                                    </span>
                                                    <span
                                                        className={
                                                            'rounded-md border px-1.5 py-0.5 text-[10px] font-bold uppercase ' +
                                                            (healthTone[account.health] ||
                                                                healthTone.unknown)
                                                        }
                                                    >
                                                        {account.status}
                                                    </span>
                                                </div>
                                                <div className="mt-2 flex flex-wrap gap-2">
                                                    <SecondaryButton
                                                        type="button"
                                                        onClick={() => {
                                                            const platform = encodeURIComponent(
                                                                account.platform || 'facebook',
                                                            );
                                                            const accountType = encodeURIComponent(
                                                                account.account_type || 'page',
                                                            );
                                                            const accountName = encodeURIComponent(
                                                                account.account_name ||
                                                                    workspace?.name ||
                                                                    '',
                                                            );
                                                            window.location.href = `/social/oauth/${platform}/start?account_type=${accountType}&account_name=${accountName}`;
                                                        }}
                                                    >
                                                        Reconnect
                                                    </SecondaryButton>
                                                    <button
                                                        type="button"
                                                        title="Remove permanently"
                                                        aria-label="Remove permanently"
                                                        onClick={async () => {
                                                            const ok = await confirmAsk({
                                                                title: 'Remove account?',
                                                                message:
                                                                    'This deletes the account from this workspace.',
                                                                confirmLabel: 'Remove',
                                                            });
                                                            if (ok) {
                                                                router.delete(
                                                                    route(
                                                                        'social.accounts.destroy',
                                                                        account.id,
                                                                    ),
                                                                );
                                                            }
                                                        }}
                                                        className="inline-flex h-9 w-9 items-center justify-center rounded-md border border-rose-200 bg-rose-50 text-rose-600 hover:bg-rose-100"
                                                    >
                                                        <TrashIcon />
                                                    </button>
                                                </div>
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            ) : null}
                        </div>
                    </section>
                ) : null}

                {view === 'compose' ? (
                    <form
                        className="grid gap-4 xl:grid-cols-[1.1fr_0.9fr]"
                        onSubmit={submitPost}
                    >
                        <div className="atlas-panel space-y-3 p-4">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <h3 className="font-display text-lg font-bold text-ink">
                                    {editingPostId ? `Edit post #${editingPostId}` : 'Compose post'}
                                </h3>
                                <button
                                    type="button"
                                    className="text-sm font-semibold text-ink-muted underline"
                                    onClick={cancelEdit}
                                >
                                    Back to posts
                                </button>
                            </div>
                            {editingPostId ? (
                                <div className="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                                    Editing draft/scheduled post — save to update.
                                </div>
                            ) : null}
                            <div>
                                <InputLabel value="Title" />
                                <TextInput
                                    className="mt-1.5 block w-full"
                                    value={postForm.data.title}
                                    onChange={(e) => postForm.setData('title', e.target.value)}
                                />
                            </div>
                            <div>
                                <InputLabel value="Caption" />
                                <textarea
                                    className="atlas-input mt-1.5 min-h-[120px]"
                                    value={postForm.data.body}
                                    onChange={(e) => postForm.setData('body', e.target.value)}
                                    required
                                />
                            </div>
                            <div className="flex flex-wrap gap-2">
                                {availablePlatforms.map((platform) => {
                                    const checked = postForm.data.platforms.includes(platform);
                                    return (
                                        <label
                                            key={platform}
                                            className={
                                                'cursor-pointer rounded-md border px-2.5 py-1.5 text-xs font-semibold capitalize ' +
                                                (checked
                                                    ? platformTone[platform]
                                                    : 'border-line text-ink-muted')
                                            }
                                        >
                                            <input
                                                type="checkbox"
                                                className="sr-only"
                                                checked={checked}
                                                onChange={() => {
                                                    const next = checked
                                                        ? postForm.data.platforms.filter(
                                                              (p) => p !== platform,
                                                          )
                                                        : [...postForm.data.platforms, platform];
                                                    postForm.setData('platforms', next);
                                                }}
                                            />
                                            {platform}
                                        </label>
                                    );
                                })}
                            </div>
                            <div>
                                <InputLabel
                                    value={
                                        postForm.data.platforms.length > 0
                                            ? 'Media (required)'
                                            : 'Media'
                                    }
                                />
                                <div className="mt-1.5">
                                    <SelectMenu
                                        value={postForm.data.media_asset_id}
                                        onChange={(v) => {
                                            postForm.setData({
                                                ...postForm.data,
                                                media_asset_id: v,
                                                public_media_url: v ? '' : postForm.data.public_media_url,
                                            });
                                        }}
                                        placeholder="Pick an image"
                                        options={[
                                            { value: '', label: 'Pick an image' },
                                            ...mediaOptions.map((asset) => ({
                                                value: String(asset.id),
                                                label: asset.name,
                                                meta: asset.mime_type,
                                            })),
                                        ]}
                                    />
                                </div>
                                {postForm.data.platforms.length > 0 ? (
                                    <div className="mt-2">
                                        <InputLabel value="Or public https image URL" />
                                        <TextInput
                                            className="mt-1 w-full"
                                            type="url"
                                            placeholder="https://…/image.jpg"
                                            value={postForm.data.public_media_url || ''}
                                            onChange={(e) =>
                                                postForm.setData({
                                                    ...postForm.data,
                                                    public_media_url: e.target.value,
                                                    media_asset_id: e.target.value
                                                        ? ''
                                                        : postForm.data.media_asset_id,
                                                })
                                            }
                                        />
                                        <p className="mt-1 text-xs text-ink-muted">
                                            Every post needs an image. On localhost, paste a public https
                                            URL — Meta cannot fetch local files.
                                        </p>
                                    </div>
                                ) : null}
                            </div>
                            <div>
                                <InputLabel value="Delivery" />
                                <div className="mt-2 grid gap-2 sm:grid-cols-3">
                                    {[
                                        { id: 'now', label: 'Send now', hint: 'Publish immediately' },
                                        { id: 'schedule', label: 'Schedule', hint: 'Pick date & time' },
                                        { id: 'draft', label: 'Draft', hint: 'Save without publishing' },
                                    ].map((opt) => {
                                        const active = postForm.data.delivery === opt.id;
                                        return (
                                            <button
                                                key={opt.id}
                                                type="button"
                                                onClick={() => {
                                                    postForm.setData('delivery', opt.id);
                                                    if (opt.id !== 'schedule') {
                                                        postForm.clearErrors('scheduled_at');
                                                    }
                                                }}
                                                className={
                                                    'rounded-md border px-3 py-2.5 text-left transition ' +
                                                    (active
                                                        ? 'border-signal bg-signal-soft/60 shadow-sm'
                                                        : 'border-line bg-white hover:border-signal/40')
                                                }
                                            >
                                                <div className="text-sm font-semibold text-ink">
                                                    {opt.label}
                                                </div>
                                                <div className="mt-0.5 text-[11px] text-ink-muted">
                                                    {opt.hint}
                                                </div>
                                            </button>
                                        );
                                    })}
                                </div>
                            </div>

                            {postForm.data.delivery === 'schedule' ? (
                                <div>
                                    <InputLabel value="Schedule at" />
                                    <div className="mt-1.5">
                                        <DateTimePicker
                                            value={postForm.data.scheduled_at}
                                            onChange={(v) => {
                                                postForm.setData('scheduled_at', v);
                                                postForm.clearErrors('scheduled_at');
                                            }}
                                            placeholder="Pick date & time"
                                        />
                                    </div>
                                    {postForm.errors.scheduled_at ? (
                                        <p className="mt-1 text-xs font-medium text-rose-600">
                                            {postForm.errors.scheduled_at}
                                        </p>
                                    ) : (
                                        <p className="mt-1 text-xs text-ink-muted">
                                            Auto-publishes when due via `social:publish-due`.
                                        </p>
                                    )}
                                </div>
                            ) : null}

                            <Toggle
                                checked={!!postForm.data.requires_approval}
                                onChange={(v) => postForm.setData('requires_approval', v)}
                                label="Ask someone to approve before posting"
                            />
                            {brandKits.length > 0 ? (
                                <div>
                                    <InputLabel value="Brand kit (for posters)" />
                                    <div className="mt-1.5">
                                        <SelectMenu
                                            value={postForm.data.brand_kit_id}
                                            onChange={(v) => postForm.setData('brand_kit_id', v)}
                                            placeholder="Select brand kit"
                                            options={brandKitOptions}
                                        />
                                    </div>
                                </div>
                            ) : null}
                            <Toggle
                                checked={!!postForm.data.generate_posters}
                                onChange={(v) => postForm.setData('generate_posters', v)}
                                label={`Also make poster sizes (${
                                    posterSizes.join(', ') || 'Instagram / Facebook / LinkedIn'
                                })`}
                            />
                            <div className="flex flex-wrap gap-2">
                                <PrimaryButton processing={postForm.processing}>
                                    {editingPostId
                                        ? postForm.data.delivery === 'now'
                                            ? 'Update + send now'
                                            : postForm.data.delivery === 'schedule'
                                              ? 'Update schedule'
                                              : 'Update draft'
                                        : postForm.data.delivery === 'now'
                                          ? 'Create + send now'
                                          : postForm.data.delivery === 'schedule'
                                            ? 'Schedule post'
                                            : 'Save draft'}
                                </PrimaryButton>
                                <SecondaryButton type="button" onClick={cancelEdit}>
                                    Cancel
                                </SecondaryButton>
                            </div>
                        </div>

                        <div className="atlas-panel space-y-3 p-4">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <div className="text-xs font-semibold uppercase tracking-wide text-ink-muted">
                                    Live preview
                                </div>
                                <div className="flex flex-wrap gap-1">
                                    {(postForm.data.platforms.length
                                        ? postForm.data.platforms
                                        : ['instagram']
                                    ).map((p) => (
                                        <button
                                            key={p}
                                            type="button"
                                            onClick={() => setActivePreview(p)}
                                            className={
                                                'rounded-md border px-2 py-1 text-[10px] font-bold uppercase ' +
                                                (activePreview === p
                                                    ? platformTone[p]
                                                    : 'border-line text-ink-muted')
                                            }
                                        >
                                            {p}
                                        </button>
                                    ))}
                                </div>
                            </div>
                            <SocialPreview
                                platform={activePreview}
                                title={postForm.data.title}
                                body={postForm.data.body}
                                media={selectedMedia}
                                accountName={
                                    previewAccount?.account_name || workspace.name || 'Your brand'
                                }
                            />
                        </div>
                    </form>
                ) : null}
            </div>
        </AuthenticatedLayout>
    );
}

function ActionItem({ children, onClick, danger = false }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={
                'block w-full px-3 py-2 text-left text-sm font-semibold transition hover:bg-mist ' +
                (danger ? 'text-rose-600' : 'text-ink')
            }
        >
            {children}
        </button>
    );
}

function SocialPreview({ platform, title, body, media, accountName }) {
    const handle =
        String(accountName || 'brand')
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '')
            .slice(0, 18) || 'brand';
    const caption = body?.trim() || 'Your caption will appear here…';
    const headline = title?.trim();
    const imageUrl = media?.url || null;
    const isImage = !media?.mime_type || String(media.mime_type).startsWith('image/');

    const frameClass =
        platform === 'instagram'
            ? 'max-w-[300px]'
            : platform === 'x'
              ? 'max-w-[340px]'
              : 'max-w-full';

    return (
        <div
            className={`mx-auto overflow-hidden rounded-xl border border-line bg-white shadow-sm ${frameClass}`}
        >
            <div className="flex items-center gap-2.5 border-b border-line/70 px-3 py-2.5">
                <div className="flex h-8 w-8 items-center justify-center rounded-full bg-gradient-to-br from-fuchsia-500 via-rose-500 to-amber-400 text-xs font-bold text-white">
                    {String(accountName || 'A').charAt(0).toUpperCase()}
                </div>
                <div className="min-w-0 flex-1">
                    <div className="truncate text-sm font-semibold text-ink">{accountName}</div>
                    <div className="truncate text-[11px] text-ink-muted">
                        {platform === 'linkedin'
                            ? 'Company page · Preview'
                            : platform === 'x'
                              ? `@${handle}`
                              : platform === 'facebook'
                                ? 'Page · Just now'
                                : `@${handle}`}
                    </div>
                </div>
                <span className="rounded-md bg-mist px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-ink-muted">
                    {platform}
                </span>
            </div>

            <div
                className={
                    platform === 'instagram'
                        ? 'relative aspect-square bg-mist'
                        : platform === 'x'
                          ? 'relative aspect-[16/9] bg-mist'
                          : 'relative aspect-[1.91/1] bg-mist'
                }
            >
                {imageUrl && isImage ? (
                    <img
                        src={imageUrl}
                        alt={media?.name || 'Post media'}
                        className="h-full w-full object-cover"
                    />
                ) : imageUrl && !isImage ? (
                    <div className="flex h-full flex-col items-center justify-center gap-2 p-4 text-center">
                        <div className="rounded-md border border-line bg-white px-3 py-2 text-xs font-semibold text-ink">
                            {media?.name || 'Attached file'}
                        </div>
                        <div className="text-[11px] text-ink-muted">{media?.mime_type}</div>
                    </div>
                ) : (
                    <div className="flex h-full flex-col items-center justify-center gap-1 bg-gradient-to-br from-mist via-white to-signal-soft px-4 text-center">
                        <div className="text-xs font-semibold text-ink-muted">No media selected</div>
                        {headline ? (
                            <div className="font-display text-lg font-bold text-ink">{headline}</div>
                        ) : (
                            <div className="text-[11px] text-ink-muted">
                                Pick an image from Media to preview the post
                            </div>
                        )}
                    </div>
                )}
            </div>

            <div className="space-y-2 px-3 py-2.5">
                {platform === 'instagram' || platform === 'facebook' ? (
                    <div className="flex gap-3 text-ink-muted">
                        <HeartIcon />
                        <CommentIcon />
                        <ShareIcon />
                    </div>
                ) : null}

                {headline && imageUrl ? (
                    <div className="text-sm font-semibold text-ink">{headline}</div>
                ) : null}

                <p className="whitespace-pre-wrap text-sm leading-snug text-ink">
                    {(platform === 'instagram' || platform === 'x') && (
                        <span className="font-semibold">{handle} </span>
                    )}
                    <span className="text-ink/90">{caption}</span>
                </p>

                {platform === 'linkedin' ? (
                    <div className="text-[11px] font-medium text-ink-muted">
                        Like · Comment · Repost · Send
                    </div>
                ) : null}
                {platform === 'x' ? (
                    <div className="flex gap-4 text-[11px] font-medium text-ink-muted">
                        <span>Reply</span>
                        <span>Repost</span>
                        <span>Like</span>
                        <span>Share</span>
                    </div>
                ) : null}
            </div>
        </div>
    );
}

function HeartIcon() {
    return (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" className="h-5 w-5">
            <path
                d="M12 21s-7-4.5-9.5-8.5C.5 8.5 3 5 6.5 5c2 0 3.5 1.2 4.5 2.5C12 6.2 13.5 5 15.5 5 19 5 21.5 8.5 21.5 12.5 19 16.5 12 21 12 21Z"
                strokeLinejoin="round"
            />
        </svg>
    );
}

function CommentIcon() {
    return (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" className="h-5 w-5">
            <path d="M21 12a8 8 0 0 1-8 8H7l-4 3V12a8 8 0 1 1 18 0Z" strokeLinejoin="round" />
        </svg>
    );
}

function ShareIcon() {
    return (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" className="h-5 w-5">
            <path
                d="M4 12v7a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-7M12 3v12M8 7l4-4 4 4"
                strokeLinecap="round"
                strokeLinejoin="round"
            />
        </svg>
    );
}

function calendarPrev(month) {
    const [y, m] = month.split('-').map(Number);
    const d = new Date(y, m - 2, 1);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
}

function calendarNext(month) {
    const [y, m] = month.split('-').map(Number);
    const d = new Date(y, m, 1);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
}
