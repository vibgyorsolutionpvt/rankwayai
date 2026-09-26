import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import ColorPicker from '@/Components/ColorPicker';
import FontPicker from '@/Components/FontPicker';
import HelpGuide, { HELP } from '@/Components/HelpGuide';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SelectMenu from '@/Components/SelectMenu';
import TextInput from '@/Components/TextInput';
import Toggle from '@/Components/Toggle';
import { confirmAsk } from '@/Components/ConfirmProvider';
import { toast } from '@/Components/ToastProvider';
import { compressImageIfNeeded } from '@/Utils/imageCompressor';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';

function quotedFont(name) {
    if (!name) return undefined;
    return /[,\s]/.test(name) ? `"${name}"` : name;
}

function AssetPreview({
    preview,
    label,
    fileName,
    onRemove,
    inputKey,
    onChange,
    accept = 'image/*',
    hint,
}) {
    return (
        <div>
            <InputLabel value={label} />
            {hint ? <p className="mt-0.5 text-xs text-ink-muted">{hint}</p> : null}
            {preview ? (
                <div className="mt-1.5 flex items-center gap-3 rounded-md border border-line bg-mist/50 p-2.5">
                    <img
                        src={preview}
                        alt=""
                        className="h-12 w-12 rounded-md border border-line bg-white object-contain p-1"
                    />
                    <div className="min-w-0 flex-1">
                        <div className="truncate text-sm font-semibold text-ink">
                            {fileName || 'Current file'}
                        </div>
                        <div className="text-xs text-ink-muted">Replace below or remove</div>
                    </div>
                    <button
                        type="button"
                        title="Remove"
                        aria-label="Remove"
                        onClick={onRemove}
                        className="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-md border border-rose-200 bg-rose-50 text-rose-600 transition hover:bg-rose-100"
                    >
                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            strokeWidth="1.8"
                            className="h-4 w-4"
                        >
                            <path
                                d="M4 7h16M10 4h4M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12M10 11v6M14 11v6"
                                strokeLinecap="round"
                                strokeLinejoin="round"
                            />
                        </svg>
                    </button>
                </div>
            ) : null}
            <input
                key={inputKey}
                type="file"
                accept={accept}
                className="mt-1.5 block w-full text-sm"
                onChange={onChange}
            />
        </div>
    );
}

export default function Edit({ workspace, brand, kits = [], brandTones = [] }) {
    const [logoPreview, setLogoPreview] = useState(brand.logo_url || null);
    const [secondaryLogoPreview, setSecondaryLogoPreview] = useState(
        brand.secondary_logo_url || null,
    );
    const [faviconPreview, setFaviconPreview] = useState(brand.favicon_url || null);
    const [logoInputKey, setLogoInputKey] = useState(0);
    const [secondaryLogoInputKey, setSecondaryLogoInputKey] = useState(0);
    const [faviconInputKey, setFaviconInputKey] = useState(0);

    const formDefaults = (kit) => ({
        name: kit.name || 'Default',
        primary_color: kit.primary_color || '#0E9F90',
        secondary_color: kit.secondary_color || '#0B1220',
        accent_color: kit.accent_color || '#F59E0B',
        font_family: kit.font_family || 'Plus Jakarta Sans',
        heading_font: kit.heading_font || kit.font_family || 'Outfit',
        brand_tone: kit.brand_tone || 'professional',
        website_url: kit.website_url || '',
        phone: kit.phone || '',
        email: kit.email || '',
        default_cta_label: kit.default_cta_label || '',
        default_cta_url: kit.default_cta_url || '',
        default_header: kit.default_header || '',
        default_footer: kit.default_footer || '',
        social_links: {
            facebook: kit.social_links?.facebook || '',
            instagram: kit.social_links?.instagram || '',
            linkedin: kit.social_links?.linkedin || '',
            x: kit.social_links?.x || '',
        },
        logo: null,
        secondary_logo: null,
        favicon: null,
    });

    const form = useForm(formDefaults(brand));
    const createForm = useForm({ name: '', make_active: false });

    useEffect(() => {
        form.setData(formDefaults(brand));
        form.clearErrors();
        setLogoPreview(brand.logo_url || null);
        setSecondaryLogoPreview(brand.secondary_logo_url || null);
        setFaviconPreview(brand.favicon_url || null);
        setLogoInputKey((k) => k + 1);
        setSecondaryLogoInputKey((k) => k + 1);
        setFaviconInputKey((k) => k + 1);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [brand.id]);

    const onImageChange = async (e, field, setPreview) => {
        const file = e.target.files?.[0] || null;
        if (!file) {
            form.setData(field, null);
            setPreview(null);
            return;
        }

        if (file.size > 2 * 1024 * 1024) {
            const res = await compressImageIfNeeded(file, {
                maxBytes: 2 * 1024 * 1024,
                targetBytes: 1.5 * 1024 * 1024,
                maxDimension: field === 'favicon' ? 512 : 2048,
            });
            form.setData(field, res.file);
            setPreview(URL.createObjectURL(res.file));
            if (res.compressed) {
                toast.success('Image auto-compressed to under 2 MB.');
            }
        } else {
            form.setData(field, file);
            setPreview(URL.createObjectURL(file));
        }
    };

    const removeAsset = async (hasUrl, destroyRoute, field, setPreview, setKey) => {
        if (hasUrl) {
            const ok = await confirmAsk({
                title: 'Remove image?',
                message: 'This brand asset will be deleted.',
                confirmLabel: 'Remove',
            });
            if (!ok) {
                return;
            }
            form.setData(field, null);
            setKey((k) => k + 1);
            router.delete(destroyRoute, {
                preserveScroll: true,
                onSuccess: () => setPreview(null),
            });
            return;
        }
        form.setData(field, null);
        setKey((k) => k + 1);
        setPreview(null);
    };

    return (
        <AuthenticatedLayout
            header={
                <div>
                    <div className="text-xs font-semibold uppercase tracking-[0.16em] text-ink-muted">
                        {workspace.name}
                    </div>
                    <div className="flex items-center gap-1.5">
                        <h2 className="font-display text-2xl font-bold text-ink">Brand kits</h2>
                        <HelpGuide help={HELP.brand} />
                    </div>
                </div>
            }
        >
            <Head title="Brand kits" />
            <div className="atlas-shell space-y-2.5">
                <section className="atlas-panel p-3">
                    <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-2">
                        <div className="min-w-0">
                            <div className="flex items-center gap-1">
                                <h3 className="font-display text-base font-bold leading-tight text-ink">
                                    Your kits
                                </h3>
                                <HelpGuide help={HELP.brand} className="!h-6 !w-6" />
                            </div>
                            <p className="mt-0.5 text-[11px] leading-snug text-ink-muted">
                                Multiple kits per business. The{' '}
                                <span className="font-semibold text-ink">Active</span> kit is used
                                in SMM, Channels, and AI.
                            </p>
                        </div>
                        <form
                            className="flex flex-wrap items-center gap-2"
                            onSubmit={(e) => {
                                e.preventDefault();
                                createForm.post(route('brand.store'), {
                                    onSuccess: () => createForm.reset(),
                                });
                            }}
                        >
                            <TextInput
                                className="w-44"
                                placeholder="New kit name"
                                value={createForm.data.name}
                                onChange={(e) => createForm.setData('name', e.target.value)}
                                required
                                aria-label="New kit name"
                            />
                            <Toggle
                                checked={!!createForm.data.make_active}
                                onChange={(v) => createForm.setData('make_active', v)}
                                label="Make active"
                            />
                            <PrimaryButton
                                className="!py-2"
                                processing={createForm.processing}
                            >
                                Add kit
                            </PrimaryButton>
                        </form>
                    </div>

                    <div className="mt-2 flex flex-wrap gap-1.5">
                        {kits.map((kit) => {
                            const selected = kit.id === brand.id;
                            return (
                                <div
                                    key={kit.id}
                                    className={
                                        'flex items-center gap-2 rounded-md border px-2.5 py-1 ' +
                                        (selected
                                            ? 'border-signal bg-signal-soft/50'
                                            : 'border-line bg-white')
                                    }
                                >
                                    <Link
                                        href={route('brand.edit', { kit: kit.id })}
                                        className="text-sm font-semibold text-ink"
                                    >
                                        {kit.name}
                                    </Link>
                                    {kit.is_active ? (
                                        <span className="rounded bg-signal px-1.5 py-0.5 text-[10px] font-bold uppercase text-white">
                                            Active
                                        </span>
                                    ) : (
                                        <button
                                            type="button"
                                            className="text-[10px] font-bold uppercase text-signal-strong"
                                            onClick={() =>
                                                router.post(route('brand.activate', kit.id))
                                            }
                                        >
                                            Set active
                                        </button>
                                    )}
                                    {kits.length > 1 ? (
                                        <button
                                            type="button"
                                            className="text-[10px] font-bold uppercase text-rose-600"
                                            onClick={async () => {
                                                const ok = await confirmAsk({
                                                    title: 'Delete brand kit?',
                                                    message: `“${kit.name}” will be removed permanently.`,
                                                    confirmLabel: 'Delete',
                                                });
                                                if (ok) {
                                                    router.delete(route('brand.destroy', kit.id));
                                                }
                                            }}
                                        >
                                            Delete
                                        </button>
                                    ) : null}
                                </div>
                            );
                        })}
                    </div>
                </section>

                <div className="grid items-start gap-2.5 lg:grid-cols-[1.1fr_0.9fr]">
                    <form
                        className="atlas-panel space-y-3 p-4 font-sans"
                        style={{ fontFamily: '"Plus Jakarta Sans", system-ui, sans-serif' }}
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post(route('brand.update', brand.id), {
                                forceFormData: true,
                            });
                        }}
                    >
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <h3 className="font-display text-base font-bold text-ink">
                                Edit: {brand.name}
                            </h3>
                            {!brand.is_active ? (
                                <button
                                    type="button"
                                    className="text-sm font-semibold text-signal-strong"
                                    onClick={() =>
                                        router.post(route('brand.activate', brand.id))
                                    }
                                >
                                    Use this for SMM / Channels / AI
                                </button>
                            ) : (
                                <span className="text-xs font-semibold uppercase text-signal-strong">
                                    Currently active
                                </span>
                            )}
                        </div>

                        <div>
                            <InputLabel value="Kit name" />
                            <TextInput
                                className="mt-1.5 block w-full"
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                                required
                            />
                        </div>

                        <div className="grid gap-3 sm:grid-cols-3">
                            <div>
                                <InputLabel value="Primary color" />
                                <div className="mt-1.5">
                                    <ColorPicker
                                        value={form.data.primary_color}
                                        onChange={(v) => form.setData('primary_color', v)}
                                    />
                                </div>
                                <InputError
                                    className="mt-1"
                                    message={form.errors.primary_color}
                                />
                            </div>
                            <div>
                                <InputLabel value="Secondary color" />
                                <div className="mt-1.5">
                                    <ColorPicker
                                        value={form.data.secondary_color}
                                        onChange={(v) => form.setData('secondary_color', v)}
                                    />
                                </div>
                                <InputError
                                    className="mt-1"
                                    message={form.errors.secondary_color}
                                />
                            </div>
                            <div>
                                <InputLabel value="Accent color" />
                                <div className="mt-1.5">
                                    <ColorPicker
                                        value={form.data.accent_color}
                                        onChange={(v) => form.setData('accent_color', v)}
                                    />
                                </div>
                                <InputError
                                    className="mt-1"
                                    message={form.errors.accent_color}
                                />
                            </div>
                        </div>

                        <div className="grid gap-3 sm:grid-cols-2">
                            <div>
                                <InputLabel value="Heading font" />
                                <p className="mt-0.5 text-xs text-ink-muted">
                                    Titles on cards, brochures, PDFs.
                                </p>
                                <div className="mt-1.5">
                                    <FontPicker
                                        value={form.data.heading_font}
                                        onChange={(v) => form.setData('heading_font', v)}
                                    />
                                </div>
                                <InputError className="mt-1" message={form.errors.heading_font} />
                            </div>
                            <div>
                                <InputLabel value="Body font" />
                                <p className="mt-0.5 text-xs text-ink-muted">
                                    Paragraphs and UI body text in creatives.
                                </p>
                                <div className="mt-1.5">
                                    <FontPicker
                                        value={form.data.font_family}
                                        onChange={(v) => form.setData('font_family', v)}
                                    />
                                </div>
                                <InputError className="mt-1" message={form.errors.font_family} />
                            </div>
                        </div>

                        <div>
                            <InputLabel value="Brand tone" />
                            <p className="mt-0.5 text-xs text-ink-muted">
                                Guides AI copy for documents and campaigns.
                            </p>
                            <div className="mt-1.5">
                                <SelectMenu
                                    value={form.data.brand_tone}
                                    onChange={(v) => form.setData('brand_tone', v)}
                                    options={brandTones}
                                    placeholder="Select tone…"
                                />
                            </div>
                            <InputError className="mt-1" message={form.errors.brand_tone} />
                        </div>

                        <div className="grid gap-3 sm:grid-cols-2">
                            <div>
                                <InputLabel value="Website" />
                                <TextInput
                                    className="mt-1.5 block w-full"
                                    value={form.data.website_url}
                                    onChange={(e) =>
                                        form.setData('website_url', e.target.value)
                                    }
                                />
                            </div>
                            <div>
                                <InputLabel value="Phone" />
                                <TextInput
                                    className="mt-1.5 block w-full"
                                    value={form.data.phone}
                                    onChange={(e) => form.setData('phone', e.target.value)}
                                />
                            </div>
                        </div>

                        <div className="grid gap-3 sm:grid-cols-2">
                            <div>
                                <InputLabel value="Email" />
                                <TextInput
                                    className="mt-1.5 block w-full"
                                    value={form.data.email}
                                    onChange={(e) => form.setData('email', e.target.value)}
                                />
                            </div>
                            <div>
                                <InputLabel value="Button text" />
                                <TextInput
                                    className="mt-1.5 block w-full"
                                    value={form.data.default_cta_label}
                                    onChange={(e) =>
                                        form.setData('default_cta_label', e.target.value)
                                    }
                                />
                            </div>
                        </div>

                        <div>
                            <InputLabel value="Button link" />
                            <TextInput
                                className="mt-1.5 block w-full"
                                value={form.data.default_cta_url}
                                onChange={(e) =>
                                    form.setData('default_cta_url', e.target.value)
                                }
                            />
                        </div>

                        <div className="grid gap-3 sm:grid-cols-2">
                            <div>
                                <InputLabel value="Default header" />
                                <textarea
                                    className="mt-1.5 block w-full rounded-md border-line text-sm shadow-sm focus:border-signal focus:ring-signal"
                                    rows={2}
                                    placeholder="Optional text above documents"
                                    value={form.data.default_header}
                                    onChange={(e) =>
                                        form.setData('default_header', e.target.value)
                                    }
                                />
                            </div>
                            <div>
                                <InputLabel value="Default footer" />
                                <textarea
                                    className="mt-1.5 block w-full rounded-md border-line text-sm shadow-sm focus:border-signal focus:ring-signal"
                                    rows={2}
                                    placeholder="Optional footer / disclaimer"
                                    value={form.data.default_footer}
                                    onChange={(e) =>
                                        form.setData('default_footer', e.target.value)
                                    }
                                />
                            </div>
                        </div>

                        <AssetPreview
                            label="Primary logo"
                            hint="Main mark for cards, PDFs, and creatives."
                            preview={logoPreview}
                            fileName={form.data.logo?.name}
                            inputKey={logoInputKey}
                            onChange={(e) => onImageChange(e, 'logo', setLogoPreview)}
                            onRemove={() =>
                                removeAsset(
                                    brand.logo_url,
                                    route('brand.logo.destroy', brand.id),
                                    'logo',
                                    setLogoPreview,
                                    setLogoInputKey,
                                )
                            }
                        />

                        <div className="grid gap-3 sm:grid-cols-2">
                            <AssetPreview
                                label="Secondary logo"
                                hint="Optional light/dark or mono mark."
                                preview={secondaryLogoPreview}
                                fileName={form.data.secondary_logo?.name}
                                inputKey={secondaryLogoInputKey}
                                onChange={(e) =>
                                    onImageChange(e, 'secondary_logo', setSecondaryLogoPreview)
                                }
                                onRemove={() =>
                                    removeAsset(
                                        brand.secondary_logo_url,
                                        route('brand.secondary_logo.destroy', brand.id),
                                        'secondary_logo',
                                        setSecondaryLogoPreview,
                                        setSecondaryLogoInputKey,
                                    )
                                }
                            />
                            <AssetPreview
                                label="Favicon"
                                hint="Small square icon (PNG/ICO)."
                                preview={faviconPreview}
                                fileName={form.data.favicon?.name}
                                inputKey={faviconInputKey}
                                onChange={(e) => onImageChange(e, 'favicon', setFaviconPreview)}
                                onRemove={() =>
                                    removeAsset(
                                        brand.favicon_url,
                                        route('brand.favicon.destroy', brand.id),
                                        'favicon',
                                        setFaviconPreview,
                                        setFaviconInputKey,
                                    )
                                }
                            />
                        </div>

                        <PrimaryButton processing={form.processing}>Save brand kit</PrimaryButton>
                    </form>

                    <div className="atlas-panel sticky top-[5.25rem] z-10 overflow-hidden font-sans self-start">
                        <div className="border-b border-line px-4 py-2.5 text-xs font-semibold uppercase tracking-[0.14em] text-ink-muted">
                            Live preview · brand fonts
                        </div>
                        <div
                            className="flex min-h-[280px] flex-col justify-end p-5 text-white"
                            style={{
                                background: `linear-gradient(145deg, ${form.data.secondary_color}, ${form.data.primary_color})`,
                                fontFamily: quotedFont(form.data.font_family),
                            }}
                        >
                            {logoPreview ? (
                                <img
                                    src={logoPreview}
                                    alt=""
                                    className="mb-auto h-10 w-auto object-contain"
                                />
                            ) : (
                                <div className="mb-auto text-sm opacity-80">No logo</div>
                            )}
                            <div
                                className="text-2xl font-bold"
                                style={{ fontFamily: quotedFont(form.data.heading_font) }}
                            >
                                {workspace.name}
                            </div>
                            <div className="mt-1 text-sm opacity-80">{form.data.name}</div>
                            <div className="mt-2 text-sm opacity-90">
                                One Platform. Complete Digital Marketing.
                            </div>
                            {form.data.default_header ? (
                                <div className="mt-2 text-xs opacity-70">{form.data.default_header}</div>
                            ) : null}
                            <button
                                type="button"
                                className="mt-4 w-fit rounded-md px-3 py-2 text-sm font-semibold text-ink"
                                style={{ backgroundColor: form.data.accent_color || '#fff' }}
                            >
                                {form.data.default_cta_label || 'Get started'}
                            </button>
                            {form.data.default_footer ? (
                                <div className="mt-3 text-[10px] opacity-60">{form.data.default_footer}</div>
                            ) : null}
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
