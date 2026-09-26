export default function CompanyLogoField({
    preview,
    brandLogoUrl = null,
    onChange,
    onClearCustom,
    hasCustom = false,
}) {
    return (
        <div className="sm:col-span-2 rounded-lg border border-line bg-mist/20 p-3">
            <div className="text-sm font-semibold text-ink">Company logo</div>
            <p className="mt-0.5 text-xs text-ink-muted">
                Defaults from Brand Kit / workspace. Upload here to override for this card.
            </p>
            <div className="mt-3 flex items-center gap-4">
                <div className="flex h-14 w-28 shrink-0 items-center justify-center rounded-lg border border-line bg-white px-2">
                    {preview ? (
                        <img src={preview} alt="" className="max-h-12 max-w-full object-contain" />
                    ) : (
                        <span className="text-[11px] text-ink-muted">No logo</span>
                    )}
                </div>
                <div className="flex min-w-0 flex-1 flex-col gap-2">
                    <label className="inline-flex w-fit cursor-pointer items-center justify-center rounded-lg bg-ink px-3.5 py-2 text-sm font-semibold text-white transition hover:bg-ink/90">
                        {preview ? 'Change logo' : 'Upload logo'}
                        <input
                            type="file"
                            accept="image/*"
                            className="sr-only"
                            onChange={onChange}
                        />
                    </label>
                    {hasCustom && onClearCustom ? (
                        <button
                            type="button"
                            onClick={onClearCustom}
                            className="w-fit text-xs font-semibold text-rose-600 hover:underline"
                        >
                            Use Brand Kit logo
                            {brandLogoUrl ? '' : ' (clear upload)'}
                        </button>
                    ) : brandLogoUrl && preview === brandLogoUrl ? (
                        <span className="text-xs text-ink-muted">Using Brand Kit logo</span>
                    ) : null}
                </div>
            </div>
        </div>
    );
}
