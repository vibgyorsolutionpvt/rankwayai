export default function PersonPhotoField({
    preview,
    onChange,
    onClear,
    primary = '#0E9F90',
    initial = 'A',
    label = 'Profile photo',
    hint = 'Used on Digital & Connect templates. Square face photo works best.',
}) {
    const letter = (initial || 'A').charAt(0).toUpperCase();

    return (
        <div>
            <div className="text-sm font-semibold text-ink">{label}</div>
            <p className="mt-0.5 text-xs text-ink-muted">{hint}</p>

            <div className="mt-3 flex items-center gap-4">
                <div
                    className="relative h-20 w-20 shrink-0 overflow-hidden rounded-full border-2 border-line bg-white shadow-sm"
                    style={preview ? undefined : { background: primary, borderColor: primary }}
                >
                    {preview ? (
                        <img src={preview} alt="" className="h-full w-full object-cover" />
                    ) : (
                        <span className="flex h-full w-full items-center justify-center text-2xl font-bold text-white">
                            {letter}
                        </span>
                    )}
                </div>

                <div className="flex min-w-0 flex-1 flex-col gap-2">
                    <label className="inline-flex w-fit cursor-pointer items-center justify-center rounded-lg bg-ink px-3.5 py-2 text-sm font-semibold text-white transition hover:bg-ink/90">
                        {preview ? 'Change photo' : 'Upload photo'}
                        <input
                            type="file"
                            accept="image/*"
                            className="sr-only"
                            onChange={onChange}
                        />
                    </label>
                    {preview && onClear ? (
                        <button
                            type="button"
                            onClick={onClear}
                            className="w-fit text-xs font-semibold text-rose-600 hover:underline"
                        >
                            Remove photo
                        </button>
                    ) : (
                        <span className="text-xs text-ink-muted">PNG or JPG, up to a few MB.</span>
                    )}
                </div>
            </div>
        </div>
    );
}
