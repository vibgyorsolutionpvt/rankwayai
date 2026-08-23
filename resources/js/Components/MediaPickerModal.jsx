import Modal from '@/Components/Modal';
import TextInput from '@/Components/TextInput';
import axios from 'axios';
import { useEffect, useState } from 'react';

export default function MediaPickerModal({
    show,
    onClose,
    onSelect,
    multiple = true,
}) {
    const [assets, setAssets] = useState([]);
    const [selected, setSelected] = useState([]);
    const [q, setQ] = useState('');
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);

    useEffect(() => {
        if (!show) {
            return;
        }

        setSelected([]);
        setError(null);
        setLoading(true);

        const timer = setTimeout(() => {
            axios
                .get(route('media.picker'), { params: { q: q.trim() || undefined } })
                .then(({ data }) => {
                    setAssets(Array.isArray(data?.assets) ? data.assets : []);
                })
                .catch((err) => {
                    setError(
                        err?.response?.data?.message ||
                            'Could not load media library.',
                    );
                    setAssets([]);
                })
                .finally(() => setLoading(false));
        }, q ? 250 : 0);

        return () => clearTimeout(timer);
    }, [show, q]);

    const toggle = (asset) => {
        if (!asset?.url) {
            return;
        }
        setSelected((prev) => {
            const exists = prev.some((item) => item.id === asset.id);
            if (exists) {
                return prev.filter((item) => item.id !== asset.id);
            }
            if (!multiple) {
                return [asset];
            }
            return [...prev, asset];
        });
    };

    const insert = () => {
        if (selected.length === 0) {
            return;
        }
        onSelect?.(selected);
        onClose?.();
    };

    return (
        <Modal show={show} maxWidth="2xl" onClose={onClose}>
            <div className="px-5 py-5 sm:px-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 className="font-display text-lg font-bold text-ink">
                            Media library
                        </h2>
                        <p className="mt-1 text-sm text-ink-muted">
                            Select image(s) from your workspace media.
                        </p>
                    </div>
                    <a
                        href={route('media.index')}
                        target="_blank"
                        rel="noreferrer"
                        className="text-sm font-semibold text-signal-strong hover:underline"
                    >
                        Open Media →
                    </a>
                </div>

                <div className="mt-4">
                    <TextInput
                        className="w-full"
                        value={q}
                        onChange={(e) => setQ(e.target.value)}
                        placeholder="Search media…"
                    />
                </div>

                <div className="mt-4 max-h-[22rem] overflow-y-auto rounded-md border border-line bg-mist/30 p-2">
                    {loading ? (
                        <div className="px-3 py-10 text-center text-sm text-ink-muted">
                            Loading media…
                        </div>
                    ) : error ? (
                        <div className="px-3 py-10 text-center text-sm text-danger">
                            {error}
                        </div>
                    ) : assets.length === 0 ? (
                        <div className="px-3 py-10 text-center text-sm text-ink-muted">
                            No images yet. Upload in Media, then come back.
                        </div>
                    ) : (
                        <div className="grid grid-cols-3 gap-2 sm:grid-cols-4 md:grid-cols-5">
                            {assets.map((asset) => {
                                const active = selected.some(
                                    (item) => item.id === asset.id,
                                );
                                return (
                                    <button
                                        key={asset.id}
                                        type="button"
                                        onClick={() => toggle(asset)}
                                        className={
                                            'group relative overflow-hidden rounded-md border bg-white text-left transition ' +
                                            (active
                                                ? 'border-signal ring-2 ring-signal/40'
                                                : 'border-line hover:border-signal/40')
                                        }
                                    >
                                        <div className="aspect-square bg-mist">
                                            {asset.thumb_url ? (
                                                <img
                                                    src={asset.thumb_url}
                                                    alt={asset.name || ''}
                                                    className="h-full w-full object-cover"
                                                    loading="lazy"
                                                />
                                            ) : null}
                                        </div>
                                        <div className="truncate px-1.5 py-1 text-[10px] text-ink-muted">
                                            {asset.name}
                                        </div>
                                        {active ? (
                                            <span className="absolute right-1 top-1 rounded bg-signal px-1.5 py-0.5 text-[10px] font-bold text-white">
                                                ✓
                                            </span>
                                        ) : null}
                                    </button>
                                );
                            })}
                        </div>
                    )}
                </div>

                <div className="mt-5 flex flex-wrap items-center justify-between gap-2">
                    <div className="text-sm text-ink-muted">
                        {selected.length > 0
                            ? `${selected.length} selected`
                            : 'Select images to insert'}
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <button
                            type="button"
                            onClick={onClose}
                            className="rounded-md border border-line px-4 py-2 text-sm font-semibold text-ink hover:border-signal/40"
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            disabled={selected.length === 0}
                            onClick={insert}
                            className="rounded-md bg-signal px-4 py-2 text-sm font-semibold text-white hover:bg-signal-strong disabled:opacity-50"
                        >
                            Insert
                            {selected.length > 1 ? ` ${selected.length}` : ''}
                        </button>
                    </div>
                </div>
            </div>
        </Modal>
    );
}
