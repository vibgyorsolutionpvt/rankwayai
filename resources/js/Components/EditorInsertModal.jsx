import Modal from '@/Components/Modal';
import { useEffect, useRef, useState } from 'react';

export default function EditorInsertModal({
    show,
    mode = 'link',
    initialUrl = '',
    onClose,
    onSubmit,
    uploading = false,
    error = null,
}) {
    const [url, setUrl] = useState(initialUrl || '');
    const inputRef = useRef(null);
    const isImage = mode === 'image';

    useEffect(() => {
        if (!show) {
            return;
        }
        setUrl(initialUrl || (isImage ? '' : 'https://'));
        const timer = setTimeout(() => {
            inputRef.current?.focus();
            inputRef.current?.select?.();
        }, 50);
        return () => clearTimeout(timer);
    }, [show, initialUrl, isImage]);

    const apply = () => {
        onSubmit({ url: url.trim() });
    };

    return (
        <Modal show={show} maxWidth="md" onClose={onClose}>
            <div className="px-5 py-5 sm:px-6">
                <h2 className="font-display text-lg font-bold text-ink">
                    {isImage ? 'Insert image URL' : 'Insert link'}
                </h2>
                <p className="mt-1 text-sm text-ink-muted">
                    {isImage
                        ? 'Paste a public image URL.'
                        : 'Add a web link. Leave empty and apply to remove the current link.'}
                </p>

                <div className="mt-4">
                    <input
                        ref={inputRef}
                        type="text"
                        inputMode="url"
                        value={url}
                        onChange={(e) => setUrl(e.target.value)}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') {
                                e.preventDefault();
                                apply();
                            }
                        }}
                        placeholder="https://example.com"
                        className="mt-1 block w-full rounded-md border-line shadow-sm focus:border-signal focus:ring-signal"
                    />
                    {error && (
                        <p className="mt-2 text-sm font-medium text-danger">
                            {error}
                        </p>
                    )}
                </div>

                <div className="mt-6 flex flex-wrap justify-end gap-2">
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-md border border-line px-4 py-2 text-sm font-semibold text-ink hover:border-signal/40"
                    >
                        Cancel
                    </button>
                    {!isImage && initialUrl && (
                        <button
                            type="button"
                            onClick={() => onSubmit({ url: '' })}
                            className="rounded-md border border-line px-4 py-2 text-sm font-semibold text-danger hover:border-danger/40"
                        >
                            Remove link
                        </button>
                    )}
                    <button
                        type="button"
                        disabled={uploading || (!url.trim() && !initialUrl)}
                        onClick={apply}
                        className="rounded-md bg-signal px-4 py-2 text-sm font-semibold text-white hover:bg-signal-strong disabled:opacity-50"
                    >
                        {isImage ? 'Insert image' : 'Apply link'}
                    </button>
                </div>
            </div>
        </Modal>
    );
}
