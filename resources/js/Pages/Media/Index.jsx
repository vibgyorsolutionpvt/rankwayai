import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import HelpGuide, { HELP } from '@/Components/HelpGuide';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import { Head, router, useForm } from '@inertiajs/react';
import { confirmAsk } from '@/Components/ConfirmProvider';
import { useState } from 'react';

function AssetCard({ asset }) {
    const [editing, setEditing] = useState(false);
    const [folder, setFolder] = useState(asset.folder === 'Unsorted' ? '' : asset.folder || '');
    const [tags, setTags] = useState((asset.tags || []).join(', '));
    const [copied, setCopied] = useState(false);

    const save = (e) => {
        e.preventDefault();
        router.patch(
            route('media.update', asset.id),
            { folder, tags },
            { preserveScroll: true, onSuccess: () => setEditing(false) },
        );
    };

    const copyUrl = async () => {
        if (!asset.cdn_url) return;
        await navigator.clipboard.writeText(asset.cdn_url);
        setCopied(true);
        setTimeout(() => setCopied(false), 1500);
    };

    return (
        <div className="atlas-panel overflow-hidden">
            <div className="relative flex h-36 items-center justify-center bg-mist">
                {asset.mime_type?.startsWith('image/') ? (
                    <img
                        src={asset.thumb_url || asset.url}
                        alt={asset.original_name}
                        className="h-full w-full object-cover"
                    />
                ) : (
                    <span className="text-xs font-semibold uppercase text-ink-muted">
                        {asset.mime_type || 'file'}
                    </span>
                )}
                {asset.status === 'processing' ? (
                    <span className="absolute left-2 top-2 rounded-md bg-white/90 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-ink-muted">
                        Processing
                    </span>
                ) : null}
            </div>
            <div className="space-y-2 p-3">
                <div className="truncate text-sm font-semibold text-ink" title={asset.original_name}>
                    {asset.original_name}
                </div>
                <div className="flex flex-wrap gap-1">
                    <span className="rounded-md bg-mist px-1.5 py-0.5 text-[11px] font-medium text-ink-muted">
                        {asset.folder || 'Unsorted'}
                    </span>
                    {(asset.tags || []).map((tag) => (
                        <span
                            key={tag}
                            className="rounded-md bg-signal-soft px-1.5 py-0.5 text-[11px] font-medium text-signal-strong"
                        >
                            {tag}
                        </span>
                    ))}
                </div>

                {editing ? (
                    <form className="space-y-2" onSubmit={save}>
                        <TextInput
                            value={folder}
                            onChange={(e) => setFolder(e.target.value)}
                            placeholder="Folder"
                            className="w-full text-sm"
                        />
                        <TextInput
                            value={tags}
                            onChange={(e) => setTags(e.target.value)}
                            placeholder="Tags (comma separated)"
                            className="w-full text-sm"
                        />
                        <div className="flex gap-2">
                            <PrimaryButton type="submit">Save</PrimaryButton>
                            <SecondaryButton type="button" onClick={() => setEditing(false)}>
                                Cancel
                            </SecondaryButton>
                        </div>
                    </form>
                ) : (
                    <div className="flex flex-wrap gap-2">
                        <SecondaryButton type="button" onClick={() => setEditing(true)}>
                            Tag / folder
                        </SecondaryButton>
                        <SecondaryButton type="button" onClick={copyUrl}>
                            {copied ? 'Copied' : 'Copy URL'}
                        </SecondaryButton>
                        <button
                            type="button"
                            title="Delete"
                            aria-label="Delete"
                            onClick={async () => {
                                const ok = await confirmAsk({
                                    title: 'Delete this file?',
                                    message: asset.original_name
                                        ? `“${asset.original_name}” will be removed from the library.`
                                        : 'This file will be removed from the library.',
                                    confirmLabel: 'Delete',
                                });
                                if (ok) {
                                    router.delete(route('media.destroy', asset.id));
                                }
                            }}
                            className="inline-flex h-9 w-9 items-center justify-center rounded-md border border-rose-200 bg-rose-50 text-rose-600 transition duration-200 hover:-translate-y-0.5 hover:border-rose-300 hover:bg-rose-100 hover:text-rose-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-300/50"
                        >
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                strokeWidth="1.8"
                                strokeLinecap="round"
                                strokeLinejoin="round"
                                className="h-[18px] w-[18px]"
                                aria-hidden="true"
                            >
                                <path d="M4 7h16" />
                                <path d="M10 4h4" />
                                <path d="M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12" />
                                <path d="M10 11v6" />
                                <path d="M14 11v6" />
                            </svg>
                        </button>
                    </div>
                )}
            </div>
        </div>
    );
}

export default function Index({ workspace, assets, folders = [], tags = [], filters, disk }) {
    const form = useForm({ files: [], folder: '', tags: '' });
    const [search, setSearch] = useState(filters?.q || '');

    const applyFilters = (next = {}) => {
        router.get(
            route('media.index'),
            {
                q: next.q ?? search,
                folder: next.folder ?? filters?.folder ?? '',
                tag: next.tag ?? filters?.tag ?? '',
            },
            { preserveState: true, replace: true },
        );
    };

    return (
        <AuthenticatedLayout
            header={
                <div>
                    <div className="text-xs font-semibold uppercase tracking-[0.16em] text-ink-muted">
                        {workspace.name}
                    </div>
                    <div className="flex items-center gap-1.5">
                        <h2 className="font-display text-2xl font-bold text-ink">Media library</h2>
                        <HelpGuide help={HELP.media} />
                    </div>
                </div>
            }
        >
            <Head title="Media" />
            <div className="atlas-shell grid gap-4 lg:grid-cols-[200px_1fr]">
                    <aside className="space-y-4">
                        <div className="atlas-panel p-3">
                            <div className="mb-2 text-xs font-semibold uppercase tracking-wide text-ink-muted">
                                Folders
                            </div>
                            <button
                                type="button"
                                onClick={() => applyFilters({ folder: '' })}
                                className={`mb-1 block w-full rounded-md px-2 py-1.5 text-left text-sm ${
                                    !filters?.folder
                                        ? 'bg-signal-soft font-semibold text-signal-strong'
                                        : 'text-ink hover:bg-mist'
                                }`}
                            >
                                All
                            </button>
                            {folders.map((folder) => (
                                <button
                                    key={folder}
                                    type="button"
                                    onClick={() => applyFilters({ folder })}
                                    className={`mb-1 block w-full rounded-md px-2 py-1.5 text-left text-sm ${
                                        filters?.folder === folder
                                            ? 'bg-signal-soft font-semibold text-signal-strong'
                                            : 'text-ink hover:bg-mist'
                                    }`}
                                >
                                    {folder}
                                </button>
                            ))}
                        </div>

                        <div className="atlas-panel p-3">
                            <div className="mb-2 text-xs font-semibold uppercase tracking-wide text-ink-muted">
                                Tags
                            </div>
                            <button
                                type="button"
                                onClick={() => applyFilters({ tag: '' })}
                                className={`mb-1 block w-full rounded-md px-2 py-1.5 text-left text-sm ${
                                    !filters?.tag
                                        ? 'bg-signal-soft font-semibold text-signal-strong'
                                        : 'text-ink hover:bg-mist'
                                }`}
                            >
                                All
                            </button>
                            {tags.map((tag) => (
                                <button
                                    key={tag}
                                    type="button"
                                    onClick={() => applyFilters({ tag })}
                                    className={`mb-1 block w-full rounded-md px-2 py-1.5 text-left text-sm ${
                                        filters?.tag === tag
                                            ? 'bg-signal-soft font-semibold text-signal-strong'
                                            : 'text-ink hover:bg-mist'
                                    }`}
                                >
                                    {tag}
                                </button>
                            ))}
                            {tags.length === 0 ? (
                                <p className="text-xs text-ink-muted">No tags yet</p>
                            ) : null}
                        </div>

                        <p className="px-1 text-[11px] text-ink-muted">Storage: {disk}</p>
                    </aside>

                    <div className="space-y-4">
<form
                            className="atlas-panel flex flex-wrap items-end gap-3 p-4"
                            onSubmit={(e) => {
                                e.preventDefault();
                                form.post(route('media.store'), {
                                    forceFormData: true,
                                    onSuccess: () => form.reset('files', 'tags'),
                                });
                            }}
                        >
                            <div className="min-w-[200px] flex-1">
                                <label className="text-sm font-semibold text-ink">Upload files</label>
                                <input
                                    type="file"
                                    multiple
                                    className="mt-1.5 block w-full text-sm"
                                    onChange={(e) =>
                                        form.setData('files', Array.from(e.target.files || []))
                                    }
                                />
                            </div>
                            <div className="w-36">
                                <label className="text-sm font-semibold text-ink">Folder</label>
                                <TextInput
                                    value={form.data.folder}
                                    onChange={(e) => form.setData('folder', e.target.value)}
                                    placeholder="Campaigns"
                                    className="mt-1.5 w-full text-sm"
                                />
                            </div>
                            <div className="min-w-[160px] flex-1">
                                <label className="text-sm font-semibold text-ink">Tags</label>
                                <TextInput
                                    value={form.data.tags}
                                    onChange={(e) => form.setData('tags', e.target.value)}
                                    placeholder="hero, social"
                                    className="mt-1.5 w-full text-sm"
                                />
                            </div>
                            <PrimaryButton processing={form.processing} disabled={!form.data.files?.length}>
                                Upload
                            </PrimaryButton>
                        </form>

                        <form
                            className="flex gap-2"
                            onSubmit={(e) => {
                                e.preventDefault();
                                applyFilters({ q: search });
                            }}
                        >
                            <TextInput
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Search name, folder, tags…"
                                className="w-full"
                            />
                            <PrimaryButton type="submit">Search</PrimaryButton>
                        </form>

                        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                            {assets.length === 0 ? (
                                <div className="atlas-panel col-span-full px-4 py-10 text-center text-sm text-ink-muted">
                                    No assets yet. Upload images for social posters and SEO content.
                                </div>
                            ) : (
                                assets.map((asset) => <AssetCard key={asset.id} asset={asset} />)
                            )}
                        </div>
                    </div>
                </div>
        </AuthenticatedLayout>
    );
}
