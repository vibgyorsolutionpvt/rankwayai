import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import HelpGuide, { HELP } from '@/Components/HelpGuide';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectMenu from '@/Components/SelectMenu';
import TextInput from '@/Components/TextInput';
import { Head, router } from '@inertiajs/react';
import { confirmAsk } from '@/Components/ConfirmProvider';
import { toast } from '@/Components/ToastProvider';
import { useEffect, useMemo, useRef, useState } from 'react';

const NO_FOLDER = '';
const NEW_FOLDER = '__new__';
const MAX_FILE_BYTES = 2 * 1024 * 1024;
const ALLOWED_IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
const ALLOWED_IMAGE_EXTS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

function isAllowedImage(file) {
    const type = String(file?.type || '').toLowerCase();
    if (ALLOWED_IMAGE_TYPES.includes(type)) return true;
    const ext = String(file?.name || '').split('.').pop()?.toLowerCase();
    return ALLOWED_IMAGE_EXTS.includes(ext);
}

function folderOptions(folders = []) {
    const named = folders.filter((f) => f && f !== 'Unsorted');
    return [
        { value: NO_FOLDER, label: 'Unsorted (no folder)' },
        ...named.map((f) => ({ value: f, label: f })),
        { value: NEW_FOLDER, label: 'Create new folder…' },
    ];
}

function resolveUploadFolder(filtersFolder) {
    if (!filtersFolder || filtersFolder === 'Unsorted') return NO_FOLDER;
    return filtersFolder;
}

function formatBytes(bytes) {
    const n = Number(bytes) || 0;
    if (n < 1024) return `${n} B`;
    if (n < 1024 * 1024) return `${(n / 1024).toFixed(1)} KB`;
    return `${(n / (1024 * 1024)).toFixed(1)} MB`;
}

function UploadIcon({ size = 20, className = '' }) {
    return (
        <svg
            width={size}
            height={size}
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.8"
            className={`inline-block shrink-0 ${className}`}
            style={{ width: size, height: size }}
            aria-hidden="true"
        >
            <path strokeLinecap="round" strokeLinejoin="round" d="M12 16V4m0 0 4 4m-4-4-4 4" />
            <path
                strokeLinecap="round"
                strokeLinejoin="round"
                d="M4 16.5V18a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-1.5"
            />
        </svg>
    );
}

function FolderIcon({ size = 28, className = '' }) {
    return (
        <svg
            width={size}
            height={size}
            viewBox="0 0 24 24"
            fill="currentColor"
            className={`inline-block shrink-0 ${className}`}
            style={{ width: size, height: size }}
            aria-hidden="true"
        >
            <path d="M3.5 6.75A2.25 2.25 0 0 1 5.75 4.5h3.586a1.5 1.5 0 0 1 1.06.44l1.122 1.12a1.5 1.5 0 0 0 1.06.44H18.25A2.25 2.25 0 0 1 20.5 8.75v8.5A2.25 2.25 0 0 1 18.25 19.5H5.75A2.25 2.25 0 0 1 3.5 17.25v-10.5Z" />
        </svg>
    );
}

function AttachmentDetails({ asset, folders, onClose, onSaved }) {
    const [folderChoice, setFolderChoice] = useState(
        asset.folder && asset.folder !== 'Unsorted' ? asset.folder : NO_FOLDER,
    );
    const [newFolder, setNewFolder] = useState('');
    const [fileName, setFileName] = useState(asset.original_name || '');
    const [tags, setTags] = useState((asset.tags || []).join(', '));
    const [copied, setCopied] = useState(false);
    const isImage = asset.mime_type?.startsWith('image/');

    useEffect(() => {
        setFolderChoice(asset.folder && asset.folder !== 'Unsorted' ? asset.folder : NO_FOLDER);
        setNewFolder('');
        setFileName(asset.original_name || '');
        setTags((asset.tags || []).join(', '));
        setCopied(false);
    }, [asset.id]);

    const save = (e) => {
        e.preventDefault();
        const folder =
            folderChoice === NEW_FOLDER
                ? newFolder.trim()
                : folderChoice === NO_FOLDER
                  ? ''
                  : folderChoice;
        if (folderChoice === NEW_FOLDER && !folder) return;
        const name = fileName.trim();
        if (!name) {
            toast.error('File name cannot be empty.');
            return;
        }
        router.patch(
            route('media.update', asset.id),
            { folder, tags, original_name: name },
            {
                preserveScroll: true,
                onSuccess: () => onSaved?.(),
            },
        );
    };

    const copyUrl = async () => {
        if (!asset.cdn_url) return;
        await navigator.clipboard.writeText(asset.cdn_url);
        setCopied(true);
        setTimeout(() => setCopied(false), 1500);
    };

    const remove = async () => {
        const ok = await confirmAsk({
            title: 'Delete permanently?',
            message: asset.original_name
                ? `“${asset.original_name}” will be removed from the library.`
                : 'This file will be removed.',
            confirmLabel: 'Delete',
        });
        if (ok) {
            router.delete(route('media.destroy', asset.id), {
                preserveScroll: true,
                onSuccess: () => onClose?.(),
            });
        }
    };

    return (
        <aside className="flex h-full flex-col border-t border-line bg-white lg:border-l lg:border-t-0">
            <div className="flex items-center justify-between border-b border-line px-3 py-2.5">
                <div className="text-xs font-bold uppercase tracking-wide text-ink-muted">
                    Attachment details
                </div>
                <button
                    type="button"
                    onClick={onClose}
                    className="text-xs font-semibold text-ink-muted hover:text-ink"
                >
                    Close
                </button>
            </div>

            <div className="flex-1 space-y-4 overflow-y-auto p-3">
                <div className="flex aspect-square items-center justify-center overflow-hidden rounded-md border border-line bg-mist">
                    {isImage ? (
                        <img
                            src={asset.medium_url || asset.url}
                            alt={asset.original_name}
                            className="max-h-full max-w-full object-contain"
                        />
                    ) : (
                        <span className="text-xs font-bold uppercase text-ink-muted">
                            {asset.mime_type || 'File'}
                        </span>
                    )}
                </div>

                <div className="grid grid-cols-2 gap-2 text-xs text-ink-muted">
                    <div>
                        <div className="font-bold uppercase tracking-wide">Type</div>
                        <div className="mt-0.5 text-ink">{asset.mime_type || '—'}</div>
                    </div>
                    <div>
                        <div className="font-bold uppercase tracking-wide">Size</div>
                        <div className="mt-0.5 text-ink">{formatBytes(asset.size)}</div>
                    </div>
                    <div className="col-span-2">
                        <div className="font-bold uppercase tracking-wide">Uploaded</div>
                        <div className="mt-0.5 text-ink">{asset.created_at || '—'}</div>
                    </div>
                </div>

                <div>
                    <div className="text-[11px] font-bold uppercase tracking-wide text-ink-muted">
                        File URL
                    </div>
                    <div className="mt-1 flex gap-2">
                        <TextInput
                            readOnly
                            value={asset.cdn_url || ''}
                            className="w-full !py-1.5 text-xs"
                            onFocus={(e) => e.target.select()}
                        />
                        <SecondaryButton type="button" onClick={copyUrl} className="!py-1.5 shrink-0">
                            {copied ? 'Copied' : 'Copy'}
                        </SecondaryButton>
                    </div>
                </div>

                <form className="space-y-3" onSubmit={save}>
                    <div>
                        <label className="text-[11px] font-bold uppercase tracking-wide text-ink-muted">
                            File name
                        </label>
                        <TextInput
                            value={fileName}
                            onChange={(e) => setFileName(e.target.value)}
                            className="mt-1 w-full text-sm"
                            required
                        />
                    </div>
                    <div>
                        <label className="text-[11px] font-bold uppercase tracking-wide text-ink-muted">
                            Folder
                        </label>
                        <div className="mt-1">
                            <SelectMenu
                                value={
                                    folderChoice === NEW_FOLDER ||
                                    folderChoice === NO_FOLDER ||
                                    folders.includes(folderChoice)
                                        ? folderChoice
                                        : NO_FOLDER
                                }
                                onChange={(v) => {
                                    setFolderChoice(v);
                                    if (v !== NEW_FOLDER) setNewFolder('');
                                }}
                                options={folderOptions(
                                    folderChoice &&
                                        folderChoice !== NEW_FOLDER &&
                                        folderChoice !== NO_FOLDER &&
                                        !folders.includes(folderChoice)
                                        ? [...folders, folderChoice]
                                        : folders,
                                )}
                            />
                        </div>
                        {folderChoice === NEW_FOLDER ? (
                            <TextInput
                                value={newFolder}
                                onChange={(e) => setNewFolder(e.target.value)}
                                placeholder="New folder name"
                                className="mt-2 w-full text-sm"
                                autoFocus
                            />
                        ) : null}
                    </div>

                    <div>
                        <label className="text-[11px] font-bold uppercase tracking-wide text-ink-muted">
                            Tags <span className="font-medium normal-case">(optional)</span>
                        </label>
                        <TextInput
                            value={tags}
                            onChange={(e) => setTags(e.target.value)}
                            placeholder="hero, social"
                            className="mt-1 w-full text-sm"
                        />
                    </div>

                    <PrimaryButton type="submit" className="w-full">
                        Save changes
                    </PrimaryButton>
                </form>
            </div>

            <div className="border-t border-line p-3">
                <button
                    type="button"
                    onClick={remove}
                    className="text-sm font-semibold text-rose-600 hover:text-rose-700"
                >
                    Delete permanently
                </button>
            </div>
        </aside>
    );
}

export default function Index({
    workspace,
    assets,
    folders = [],
    folderStats = [],
    tags = [],
    filters,
    disk,
}) {
    const initialFolder = resolveUploadFolder(filters?.folder);
    const [mode, setMode] = useState('library'); // upload | library
    const [folderChoice, setFolderChoice] = useState(
        initialFolder === NO_FOLDER ? NO_FOLDER : initialFolder,
    );
    const [newFolder, setNewFolder] = useState('');
    const [pendingFiles, setPendingFiles] = useState([]);
    const [uploading, setUploading] = useState(false);
    const [search, setSearch] = useState(filters?.q || '');
    const [typeFilter, setTypeFilter] = useState('all');
    const [selectedId, setSelectedId] = useState(null);
    const [dragging, setDragging] = useState(false);
    const [previewUrls, setPreviewUrls] = useState([]);
    const fileRef = useRef(null);
    const dragDepth = useRef(0);

    const namedFolders = useMemo(
        () => folders.filter((f) => f && f !== 'Unsorted'),
        [folders],
    );

    const atRoot = !filters?.folder;
    const isSearching = Boolean(filters?.q || filters?.tag);

    const visibleFolders = useMemo(() => {
        const stats = Array.isArray(folderStats) ? folderStats : [];
        return stats.filter((f) => f.name && f.name !== 'Unsorted');
    }, [folderStats]);

    const assetRows = useMemo(() => {
        const rows = Array.isArray(assets) ? assets : assets?.data || [];
        if (typeFilter === 'image') {
            return rows.filter((a) => a.mime_type?.startsWith('image/'));
        }
        if (typeFilter === 'video') {
            return rows.filter((a) => a.mime_type?.startsWith('video/'));
        }
        if (typeFilter === 'other') {
            return rows.filter(
                (a) => !a.mime_type?.startsWith('image/') && !a.mime_type?.startsWith('video/'),
            );
        }
        return rows;
    }, [assets, typeFilter]);

    const selected = useMemo(
        () => assetRows.find((a) => a.id === selectedId) || null,
        [assetRows, selectedId],
    );

    useEffect(() => {
        const next = resolveUploadFolder(filters?.folder);
        setFolderChoice(next === NO_FOLDER ? NO_FOLDER : next);
        setNewFolder('');
    }, [filters?.folder]);

    useEffect(() => {
        if (selectedId && !assetRows.some((a) => a.id === selectedId)) {
            setSelectedId(assetRows[0]?.id ?? null);
        }
    }, [assetRows, selectedId]);

    useEffect(() => {
        const urls = pendingFiles.map((file) => {
            try {
                return file?.type?.startsWith('image/') ? URL.createObjectURL(file) : null;
            } catch {
                return null;
            }
        });
        setPreviewUrls(urls);
        return () => {
            urls.forEach((url) => {
                if (url) URL.revokeObjectURL(url);
            });
        };
    }, [pendingFiles]);

    const mediaQuery = (next = {}) => {
        const q = String(next.q ?? search ?? '').trim();
        const folder = String(next.folder ?? filters?.folder ?? '').trim();
        const tag = String(next.tag ?? filters?.tag ?? '').trim();
        const params = {};
        if (folder) params.folder = folder;
        if (q) params.q = q;
        if (tag) params.tag = tag;
        return params;
    };

    const applyFilters = (next = {}) => {
        router.get(route('media.index'), mediaQuery(next), {
            preserveState: true,
            replace: true,
        });
    };

    const openFolder = (name) => {
        setSelectedId(null);
        setSearch('');
        router.get(
            route('media.index'),
            mediaQuery({ folder: name, q: '', tag: '' }),
            { preserveState: true, replace: true },
        );
    };

    const setUploadFolderChoice = (value) => {
        setFolderChoice(value);
        if (value !== NEW_FOLDER) setNewFolder('');
    };

    const takeFiles = (fileList) => {
        const incoming = Array.from(fileList || []);
        if (!incoming.length) return;

        const tooBig = incoming.filter((file) => file.size > MAX_FILE_BYTES);
        const notImage = incoming.filter((file) => !isAllowedImage(file));
        const allowed = incoming.filter(
            (file) => file.size <= MAX_FILE_BYTES && isAllowedImage(file),
        );

        if (notImage.length) {
            toast.error(
                notImage.length === 1
                    ? `${notImage[0].name} is not an image. Only JPG, PNG, WebP, GIF.`
                    : `${notImage.length} files skipped — only images are allowed.`,
            );
        }
        if (tooBig.length) {
            toast.error(
                tooBig.length === 1
                    ? `${tooBig[0].name} is over 2 MB.`
                    : `${tooBig.length} files are over 2 MB and were skipped.`,
            );
        }
        if (!allowed.length) return;

        setPendingFiles((existing) => {
            const merged = [...existing];
            for (const file of allowed) {
                const dup = merged.some(
                    (f) =>
                        f.name === file.name &&
                        f.size === file.size &&
                        f.lastModified === file.lastModified,
                );
                if (!dup) merged.push(file);
            }
            return merged;
        });
        setMode('upload');
    };

    const removeFileAt = (index) => {
        setPendingFiles((files) => files.filter((_, i) => i !== index));
        if (fileRef.current) fileRef.current.value = '';
    };

    const clearFiles = () => {
        setPendingFiles([]);
        if (fileRef.current) fileRef.current.value = '';
    };

    const fileCount = pendingFiles.length;

    const filePreviews = pendingFiles.map((file, index) => ({
        index,
        file,
        isImage: file.type?.startsWith('image/'),
        url: previewUrls[index] || null,
    }));

    const submitUpload = (e) => {
        e?.preventDefault?.();

        const folder =
            folderChoice === NEW_FOLDER
                ? newFolder.trim()
                : folderChoice === NO_FOLDER
                  ? ''
                  : folderChoice;

        if (folderChoice === NEW_FOLDER && !folder) {
            toast.error('Folder name likho, ya Unsorted choose karo.');
            return;
        }

        if (!pendingFiles.length) {
            toast.error('Pehle files select karo.');
            return;
        }

        const oversized = pendingFiles.filter((file) => file.size > MAX_FILE_BYTES);
        if (oversized.length) {
            toast.error('Har image 2 MB se chhoti honi chahiye.');
            return;
        }

        const invalid = pendingFiles.filter((file) => !isAllowedImage(file));
        if (invalid.length) {
            toast.error('Sirf images upload ho sakti hain: JPG, PNG, WebP, GIF.');
            return;
        }

        const data = new FormData();
        pendingFiles.forEach((file, i) => {
            data.append(`files[${i}]`, file);
        });
        data.append('folder', folder);

        setUploading(true);
        router.post(route('media.store'), data, {
            forceFormData: true,
            preserveScroll: true,
            onFinish: () => setUploading(false),
            onSuccess: () => {
                clearFiles();
                if (folderChoice === NEW_FOLDER && folder) {
                    setFolderChoice(folder);
                    setNewFolder('');
                }
                setMode('library');
                toast.success('Upload complete');
            },
            onError: (errors) => {
                const first =
                    errors.files ||
                    errors['files.0'] ||
                    errors['files.1'] ||
                    errors.folder ||
                    Object.values(errors || {})[0];
                toast.error(
                    typeof first === 'string'
                        ? first
                        : Array.isArray(first)
                          ? first[0]
                          : 'Upload failed. Only images up to 2 MB (JPG, PNG, WebP, GIF).',
                );
            },
        });
    };

    const tabClass = (active) =>
        'border-b-2 px-4 py-2.5 text-sm font-semibold transition ' +
        (active
            ? 'border-signal text-signal-strong'
            : 'border-transparent text-ink-muted hover:text-ink');

    return (
        <AuthenticatedLayout
            header={
                <div className="min-w-0">
                    <div className="truncate text-xs font-semibold uppercase tracking-[0.16em] text-ink-muted">
                        {workspace.name}
                    </div>
                    <div className="flex items-center gap-1.5">
                        <h2 className="font-display text-2xl font-bold leading-tight text-ink">
                            Media
                        </h2>
                        <HelpGuide help={HELP.media} />
                    </div>
                </div>
            }
        >
            <Head title="Media" />

            <input
                ref={fileRef}
                type="file"
                multiple
                accept="image/jpeg,image/png,image/gif,image/webp,.jpg,.jpeg,.png,.gif,.webp"
                className="hidden"
                onChange={(e) => takeFiles(e.target.files)}
            />

            <div className="atlas-shell">
                <div className="atlas-panel overflow-hidden">
                    {/* WP-style tabs */}
                    <div className="flex flex-wrap items-center justify-between gap-2 border-b border-line px-2 sm:px-3">
                        <div className="flex items-center">
                            <button
                                type="button"
                                className={tabClass(mode === 'upload')}
                                onClick={() => setMode('upload')}
                            >
                                Upload files
                            </button>
                            <button
                                type="button"
                                className={tabClass(mode === 'library')}
                                onClick={() => setMode('library')}
                            >
                                Media Library
                            </button>
                        </div>
                        <div className="px-2 py-2 text-[11px] text-ink-muted">Storage · {disk}</div>
                    </div>

                    {mode === 'upload' ? (
                        <form
                            onSubmit={submitUpload}
                            className="space-y-4 p-4 sm:p-6"
                            onDragEnter={(e) => {
                                e.preventDefault();
                                dragDepth.current += 1;
                                setDragging(true);
                            }}
                            onDragOver={(e) => e.preventDefault()}
                            onDragLeave={(e) => {
                                e.preventDefault();
                                dragDepth.current = Math.max(0, dragDepth.current - 1);
                                if (dragDepth.current === 0) setDragging(false);
                            }}
                            onDrop={(e) => {
                                e.preventDefault();
                                dragDepth.current = 0;
                                setDragging(false);
                                takeFiles(e.dataTransfer.files);
                            }}
                        >
                            <div
                                className={
                                    'rounded-lg border-2 border-dashed px-4 py-6 transition sm:px-6 ' +
                                    (dragging
                                        ? 'border-signal bg-signal-soft/40'
                                        : 'border-line bg-mist/30')
                                }
                            >
                                {fileCount === 0 ? (
                                    <div
                                        role="button"
                                        tabIndex={0}
                                        onClick={() => fileRef.current?.click()}
                                        onKeyDown={(e) => {
                                            if (e.key === 'Enter' || e.key === ' ') {
                                                e.preventDefault();
                                                fileRef.current?.click();
                                            }
                                        }}
                                        className="flex min-h-[180px] cursor-pointer flex-col items-center justify-center gap-3 text-center hover:opacity-90"
                                    >
                                        <span className="flex h-12 w-12 items-center justify-center rounded-full bg-signal-soft text-signal-strong">
                                            <UploadIcon size={22} />
                                        </span>
                                        <div>
                                            <div className="text-base font-semibold text-ink">
                                                Drop files to upload
                                            </div>
                                            <div className="mt-1 text-sm text-ink-muted">
                                                or{' '}
                                                <span className="font-semibold text-signal-strong underline">
                                                    Select Files
                                                </span>
                                            </div>
                                        </div>
                                        <p className="text-xs text-ink-muted">
                                            JPG, PNG, WebP, GIF · max 2 MB each
                                        </p>
                                    </div>
                                ) : (
                                    <div className="space-y-4">
                                        <div className="flex flex-wrap items-center justify-between gap-2">
                                            <div className="text-sm font-semibold text-ink">
                                                {fileCount} file{fileCount === 1 ? '' : 's'} ready
                                            </div>
                                            <div className="flex items-center gap-3">
                                                <button
                                                    type="button"
                                                    className="text-xs font-semibold text-signal-strong hover:underline"
                                                    onClick={() => fileRef.current?.click()}
                                                >
                                                    + Add more
                                                </button>
                                                <button
                                                    type="button"
                                                    className="text-xs font-semibold text-ink-muted hover:text-ink"
                                                    onClick={clearFiles}
                                                >
                                                    Clear all
                                                </button>
                                            </div>
                                        </div>

                                        <ul className="grid grid-cols-2 gap-3 p-1 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5">
                                            {filePreviews.map((item) => (
                                                <li
                                                    key={`${item.file.name}-${item.file.size}-${item.index}`}
                                                    className="relative rounded-md border border-line bg-white shadow-sm"
                                                >
                                                    <button
                                                        type="button"
                                                        title="Remove"
                                                        aria-label={`Remove ${item.file.name}`}
                                                        onClick={(e) => {
                                                            e.preventDefault();
                                                            e.stopPropagation();
                                                            removeFileAt(item.index);
                                                        }}
                                                        className="absolute -right-1.5 -top-1.5 z-10 flex h-6 w-6 items-center justify-center rounded-full border border-line bg-white text-ink shadow-sm hover:border-rose-300 hover:bg-rose-50 hover:text-rose-700"
                                                    >
                                                        <svg
                                                            width="10"
                                                            height="10"
                                                            viewBox="0 0 12 12"
                                                            fill="none"
                                                            stroke="currentColor"
                                                            strokeWidth="1.8"
                                                            aria-hidden="true"
                                                        >
                                                            <path
                                                                d="M2 2l8 8M10 2L2 10"
                                                                strokeLinecap="round"
                                                            />
                                                        </svg>
                                                    </button>
                                                    <div className="overflow-hidden rounded-t-md">
                                                        <div className="flex aspect-square items-center justify-center bg-mist">
                                                            {item.isImage && item.url ? (
                                                                <img
                                                                    src={item.url}
                                                                    alt={item.file.name}
                                                                    className="h-full w-full object-cover"
                                                                />
                                                            ) : (
                                                                <div className="px-2 text-center">
                                                                    <div className="text-[10px] font-bold uppercase text-ink-muted">
                                                                        {item.file.type?.split('/')[1] ||
                                                                            'file'}
                                                                    </div>
                                                                </div>
                                                            )}
                                                        </div>
                                                    </div>
                                                    <div className="truncate px-2 py-1.5 text-[11px] font-medium text-ink">
                                                        {item.file.name}
                                                    </div>
                                                    <div className="px-2 pb-1.5 text-[10px] text-ink-muted">
                                                        {formatBytes(item.file.size)}
                                                    </div>
                                                </li>
                                            ))}
                                        </ul>

                                        <p className="text-center text-xs text-ink-muted">
                                            Drop more files here, or use Add more
                                        </p>
                                    </div>
                                )}
                            </div>

                            <div className="mx-auto grid max-w-xl gap-3 sm:grid-cols-[1fr_auto] sm:items-end">
                                <div className="min-w-0">
                                    <label className="text-[11px] font-bold uppercase tracking-wide text-ink-muted">
                                        Folder{' '}
                                        <span className="font-medium normal-case">(optional)</span>
                                    </label>
                                    <div className="mt-1.5">
                                        <SelectMenu
                                            value={
                                                folderChoice === NEW_FOLDER ||
                                                folderChoice === NO_FOLDER ||
                                                namedFolders.includes(folderChoice)
                                                    ? folderChoice
                                                    : NO_FOLDER
                                            }
                                            onChange={setUploadFolderChoice}
                                            options={folderOptions(
                                                folderChoice &&
                                                    folderChoice !== NEW_FOLDER &&
                                                    folderChoice !== NO_FOLDER &&
                                                    !namedFolders.includes(folderChoice)
                                                    ? [...namedFolders, folderChoice]
                                                    : namedFolders,
                                            )}
                                        />
                                    </div>
                                    {folderChoice === NEW_FOLDER ? (
                                        <TextInput
                                            value={newFolder}
                                            onChange={(e) => setNewFolder(e.target.value)}
                                            placeholder="New folder name"
                                            className="mt-2 w-full text-sm"
                                            autoFocus
                                        />
                                    ) : null}
                                </div>
                                <PrimaryButton
                                    type="button"
                                    processing={uploading}
                                    disabled={!fileCount || uploading}
                                    className="w-full sm:w-auto"
                                    onClick={submitUpload}
                                >
                                    {uploading ? 'Uploading…' : 'Upload'}
                                </PrimaryButton>
                            </div>

                            <p className="text-center text-xs text-ink-muted">
                                After upload you’ll return to the Media Library.
                            </p>
                        </form>
                    ) : (
                        <div
                            className={
                                'grid min-h-[70vh] ' +
                                (selected ? 'lg:grid-cols-[minmax(0,1fr)_300px]' : '')
                            }
                            onDragEnter={(e) => {
                                e.preventDefault();
                                dragDepth.current += 1;
                                setDragging(true);
                            }}
                            onDragOver={(e) => e.preventDefault()}
                            onDragLeave={(e) => {
                                e.preventDefault();
                                dragDepth.current = Math.max(0, dragDepth.current - 1);
                                if (dragDepth.current === 0) setDragging(false);
                            }}
                            onDrop={(e) => {
                                e.preventDefault();
                                dragDepth.current = 0;
                                setDragging(false);
                                takeFiles(e.dataTransfer.files);
                            }}
                        >
                            <div className="relative flex min-w-0 flex-col">
                                {dragging ? (
                                    <div className="pointer-events-none absolute inset-0 z-20 flex items-center justify-center bg-signal-soft/70">
                                        <div className="rounded-lg border-2 border-dashed border-signal bg-white px-8 py-6 text-center shadow-sm">
                                            <span className="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-signal-soft text-signal-strong">
                                                <UploadIcon size={22} />
                                            </span>
                                            <div className="mt-2 text-sm font-semibold text-ink">
                                                Drop to upload
                                            </div>
                                        </div>
                                    </div>
                                ) : null}

                                {/* Breadcrumb + toolbar */}
                                <div className="flex flex-wrap items-center gap-2 border-b border-line bg-mist/20 px-3 py-2.5">
                                    <nav className="flex min-w-0 flex-1 items-center gap-1.5 text-sm">
                                        {filters?.folder ? (
                                            <button
                                                type="button"
                                                onClick={() => {
                                                    setSelectedId(null);
                                                    applyFilters({ folder: '', q: search });
                                                }}
                                                className="shrink-0 rounded-md border border-line bg-white px-2 py-1 text-xs font-semibold text-ink hover:border-signal/40"
                                            >
                                                ← Back
                                            </button>
                                        ) : null}
                                        <button
                                            type="button"
                                            onClick={() => {
                                                setSelectedId(null);
                                                applyFilters({ folder: '', q: search });
                                            }}
                                            className={
                                                'truncate font-semibold ' +
                                                (!filters?.folder
                                                    ? 'text-ink'
                                                    : 'text-signal-strong hover:underline')
                                            }
                                        >
                                            All files
                                        </button>
                                        {filters?.folder ? (
                                            <>
                                                <span className="text-ink-muted">/</span>
                                                <span className="truncate font-semibold text-ink">
                                                    {filters.folder}
                                                </span>
                                            </>
                                        ) : null}
                                    </nav>

                                    <select
                                        className="atlas-select !w-auto !py-1.5 text-sm"
                                        value={typeFilter}
                                        onChange={(e) => setTypeFilter(e.target.value)}
                                    >
                                        <option value="all">All types</option>
                                        <option value="image">Images</option>
                                        <option value="video">Videos</option>
                                        <option value="other">Documents</option>
                                    </select>

                                    <form
                                        className="flex min-w-[11rem] items-center gap-2 sm:max-w-xs sm:flex-1"
                                        onSubmit={(e) => {
                                            e.preventDefault();
                                            applyFilters({ q: search });
                                        }}
                                    >
                                        <TextInput
                                            value={search}
                                            onChange={(e) => setSearch(e.target.value)}
                                            placeholder="Search…"
                                            className="w-full !py-1.5 text-sm"
                                        />
                                        <SecondaryButton type="submit" className="!py-1.5 shrink-0">
                                            Search
                                        </SecondaryButton>
                                    </form>
                                </div>

                                <div className="flex-1 overflow-y-auto p-3">
                                    {atRoot && !isSearching && visibleFolders.length === 0 && assetRows.length === 0 ? (
                                        <div className="flex flex-col items-center justify-center gap-3 px-4 py-20 text-center">
                                            <p className="text-sm text-ink-muted">
                                                No media yet. Upload files or create folders while uploading.
                                            </p>
                                            <PrimaryButton type="button" onClick={() => setMode('upload')}>
                                                Upload files
                                            </PrimaryButton>
                                        </div>
                                    ) : !atRoot && assetRows.length === 0 ? (
                                        <div className="flex flex-col items-center justify-center gap-3 px-4 py-16 text-center">
                                            <p className="text-sm text-ink-muted">
                                                This folder is empty.
                                            </p>
                                            <div className="flex flex-wrap justify-center gap-2">
                                                <SecondaryButton
                                                    type="button"
                                                    onClick={() => {
                                                        setSelectedId(null);
                                                        applyFilters({ folder: '' });
                                                    }}
                                                >
                                                    ← Back
                                                </SecondaryButton>
                                                <PrimaryButton type="button" onClick={() => setMode('upload')}>
                                                    Upload here
                                                </PrimaryButton>
                                            </div>
                                        </div>
                                    ) : (
                                        <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 xl:grid-cols-5 2xl:grid-cols-6">
                                            {atRoot && !isSearching
                                                ? visibleFolders.map((folder) => (
                                                      <li key={`folder-${folder.name}`}>
                                                          <button
                                                              type="button"
                                                              onDoubleClick={() => openFolder(folder.name)}
                                                              onClick={() => openFolder(folder.name)}
                                                              className="group flex aspect-square w-full flex-col items-center justify-center gap-2 rounded-lg border border-line bg-white p-3 text-center shadow-sm transition hover:border-signal/45 hover:shadow-md"
                                                              title={`Open ${folder.name}`}
                                                          >
                                                              <span className="text-amber-500">
                                                                  <FolderIcon size={40} />
                                                              </span>
                                                              <span className="w-full truncate text-sm font-semibold text-ink">
                                                                  {folder.name}
                                                              </span>
                                                              <span className="text-[11px] text-ink-muted">
                                                                  {folder.count} item
                                                                  {folder.count === 1 ? '' : 's'}
                                                              </span>
                                                          </button>
                                                      </li>
                                                  ))
                                                : null}

                                            {assetRows.map((asset) => {
                                                const active = selectedId === asset.id;
                                                const isImage = asset.mime_type?.startsWith('image/');
                                                return (
                                                    <li key={asset.id}>
                                                        <button
                                                            type="button"
                                                            onClick={() => setSelectedId(asset.id)}
                                                            className={
                                                                'group relative flex w-full flex-col overflow-hidden rounded-lg border-2 bg-white text-left shadow-sm transition ' +
                                                                (active
                                                                    ? 'border-signal ring-2 ring-signal/25'
                                                                    : 'border-line hover:border-signal/35')
                                                            }
                                                            title={asset.original_name}
                                                        >
                                                            <div className="relative aspect-square bg-mist">
                                                                {isImage ? (
                                                                    <img
                                                                        src={asset.thumb_url || asset.url}
                                                                        alt=""
                                                                        className="h-full w-full object-cover"
                                                                    />
                                                                ) : (
                                                                    <div className="flex h-full flex-col items-center justify-center gap-1 p-2">
                                                                        <span className="text-[10px] font-bold uppercase text-ink-muted">
                                                                            {asset.mime_type?.split('/')[1] ||
                                                                                'file'}
                                                                        </span>
                                                                    </div>
                                                                )}
                                                                {active ? (
                                                                    <span className="absolute left-1.5 top-1.5 flex h-5 w-5 items-center justify-center rounded-sm bg-signal text-white shadow-sm">
                                                                        <svg
                                                                            width="12"
                                                                            height="12"
                                                                            viewBox="0 0 20 20"
                                                                            fill="currentColor"
                                                                            aria-hidden
                                                                        >
                                                                            <path
                                                                                fillRule="evenodd"
                                                                                d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z"
                                                                                clipRule="evenodd"
                                                                            />
                                                                        </svg>
                                                                    </span>
                                                                ) : null}
                                                            </div>
                                                            <div className="truncate px-2 py-1.5 text-[11px] font-medium text-ink">
                                                                {asset.original_name}
                                                            </div>
                                                        </button>
                                                    </li>
                                                );
                                            })}
                                        </ul>
                                    )}
                                </div>

                                <div className="flex items-center justify-between border-t border-line px-3 py-2 text-xs text-ink-muted">
                                    <span>
                                        {atRoot && !isSearching
                                            ? `${visibleFolders.length} folder${visibleFolders.length === 1 ? '' : 's'} · ${assetRows.length} file${assetRows.length === 1 ? '' : 's'}`
                                            : `${assetRows.length} file${assetRows.length === 1 ? '' : 's'}`}
                                    </span>
                                    <button
                                        type="button"
                                        className="font-semibold text-signal-strong hover:underline"
                                        onClick={() => setMode('upload')}
                                    >
                                        + Add new
                                    </button>
                                </div>
                            </div>

                            {selected ? (
                                <AttachmentDetails
                                    asset={selected}
                                    folders={namedFolders}
                                    onClose={() => setSelectedId(null)}
                                    onSaved={() => {}}
                                />
                            ) : null}
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
