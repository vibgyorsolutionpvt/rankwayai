import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SelectMenu from '@/Components/SelectMenu';
import TextInput from '@/Components/TextInput';
import BrochureView from '@/Components/Studio/BrochureView';
import ColorPicker from '@/Components/ColorPicker';
import Toggle from '@/Components/Toggle';
import { toast } from '@/Components/ToastProvider';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

function sectionItemsText(section) {
    return Array.isArray(section.items) ? section.items.join('\n') : '';
}

export default function Edit({ workspace, brochure, templates = [], brandKits = [] }) {
    const form = useForm({
        title: brochure.title || '',
        template_key: brochure.template_key || 'agency',
        status: brochure.status || 'draft',
        headline: brochure.headline || '',
        subheadline: brochure.subheadline || '',
        brand_kit_id: brochure.brand_kit_id ? String(brochure.brand_kit_id) : '',
        is_public: brochure.is_public !== false,
        logo: null,
        remove_logo: false,
        colors: {
            primary_color: brochure.custom_colors?.primary_color || '',
            secondary_color: brochure.custom_colors?.secondary_color || '',
            accent_color: brochure.custom_colors?.accent_color || '',
        },
        sections: (brochure.sections || []).map((s) => ({
            ...s,
            items_text: sectionItemsText(s),
        })),
    });

    const [logoPreview, setLogoPreview] = useState(brochure.logo_url || null);
    const [logoKey, setLogoKey] = useState(0);

    const updateSection = (index, patch) => {
        const next = form.data.sections.map((section, i) =>
            i === index ? { ...section, ...patch } : section,
        );
        form.setData('sections', next);
    };

    const colorFields = [
        ['primary_color', 'Main color'],
        ['secondary_color', 'Dark color'],
        ['accent_color', 'Accent color'],
    ];
    const hasCustomColors = Object.values(form.data.colors).some(Boolean);
    const previewStyles = { ...brochure.styles };
    colorFields.forEach(([key]) => {
        if (form.data.colors[key]) previewStyles[key] = form.data.colors[key];
    });

    const copyLink = async () => {
        try {
            await navigator.clipboard.writeText(brochure.share_url);
            toast.success('Share link copied');
        } catch {
            toast.error('Could not copy link');
        }
    };

    return (
        <AuthenticatedLayout
            header={
                <div>
                    <div className="text-xs font-semibold uppercase tracking-[0.16em] text-ink-muted">
                        <Link href={route('studio.brochures.index')} className="hover:text-ink">
                            Brochures
                        </Link>{' '}
                        / Edit
                    </div>
                    <h2 className="font-display text-2xl font-bold text-ink">{brochure.title}</h2>
                </div>
            }
        >
            <Head title={brochure.title} />
            <div className="atlas-shell grid gap-4 lg:grid-cols-[1.15fr_0.85fr]">
                <form
                    className="atlas-panel space-y-4 p-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.transform((data) => ({
                            ...data,
                            sections: (data.sections || []).map(({ items_text, ...rest }) => ({
                                ...rest,
                                items: items_text,
                            })),
                        }));
                        form.post(route('studio.brochures.update', brochure.id));
                    }}
                >
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="sm:col-span-2">
                            <InputLabel value="Title *" />
                            <TextInput
                                className="mt-1 w-full"
                                value={form.data.title}
                                onChange={(e) => form.setData('title', e.target.value)}
                                required
                            />
                        </div>
                        <div>
                            <InputLabel value="Template" />
                            <div className="mt-1">
                                <SelectMenu
                                    value={form.data.template_key}
                                    onChange={(v) => form.setData('template_key', v)}
                                    options={templates.map((t) => ({
                                        value: t.value,
                                        label: t.label,
                                        meta: t.description,
                                    }))}
                                />
                            </div>
                        </div>
                        <div>
                            <InputLabel value="Status" />
                            <div className="mt-1">
                                <SelectMenu
                                    value={form.data.status}
                                    onChange={(v) => form.setData('status', v)}
                                    options={[
                                        { value: 'draft', label: 'Draft' },
                                        { value: 'published', label: 'Published' },
                                    ]}
                                />
                            </div>
                        </div>
                        <div>
                            <InputLabel value="Headline" />
                            <TextInput
                                className="mt-1 w-full"
                                value={form.data.headline}
                                onChange={(e) => form.setData('headline', e.target.value)}
                            />
                        </div>
                        <div>
                            <InputLabel value="Subheadline" />
                            <TextInput
                                className="mt-1 w-full"
                                value={form.data.subheadline}
                                onChange={(e) => form.setData('subheadline', e.target.value)}
                            />
                        </div>
                        <div>
                            <InputLabel value="Brand kit" />
                            <div className="mt-1">
                                <SelectMenu
                                    value={form.data.brand_kit_id || ''}
                                    onChange={(v) => form.setData('brand_kit_id', v)}
                                    options={[
                                        { value: '', label: 'Active kit (default)' },
                                        ...brandKits.map((k) => ({
                                            value: String(k.id),
                                            label: k.name + (k.is_active ? ' · Active' : ''),
                                        })),
                                    ]}
                                />
                            </div>
                        </div>
                        <div className="flex items-end pb-1">
                            <Toggle
                                checked={!!form.data.is_public}
                                onChange={(v) => form.setData('is_public', v)}
                                label="Public share link"
                            />
                        </div>
                    </div>

                    <div className="space-y-3 border-t border-line pt-4">
                        <h3 className="font-display text-sm font-bold text-ink">Brochure logo</h3>
                        <div className="flex flex-wrap items-center gap-4">
                            <div className="flex h-16 w-24 items-center justify-center overflow-hidden rounded-md border border-line bg-mist/40">
                                {logoPreview ? (
                                    <img src={logoPreview} alt="Logo" className="max-h-full max-w-full object-contain" />
                                ) : (
                                    <span className="text-[10px] font-semibold text-ink-muted">No logo</span>
                                )}
                            </div>
                            <div className="space-y-1.5">
                                <input
                                    key={logoKey}
                                    type="file"
                                    accept="image/png,image/jpeg,image/webp"
                                    onChange={(e) => {
                                        const file = e.target.files?.[0] || null;
                                        form.setData((d) => ({ ...d, logo: file, remove_logo: false }));
                                        setLogoPreview(file ? URL.createObjectURL(file) : brochure.logo_url || null);
                                    }}
                                    className="block text-xs"
                                />
                                <div className="text-[11px] text-ink-muted">
                                    {brochure.has_custom_logo || form.data.logo
                                        ? 'Custom logo for this brochure.'
                                        : 'Using the Brand kit logo. Upload to override.'}
                                </div>
                                {(brochure.has_custom_logo || form.data.logo) && !form.data.remove_logo ? (
                                    <button
                                        type="button"
                                        onClick={() => {
                                            form.setData((d) => ({ ...d, logo: null, remove_logo: !!brochure.has_custom_logo }));
                                            setLogoPreview(null);
                                            setLogoKey((k) => k + 1);
                                        }}
                                        className="text-xs font-semibold text-red-600 hover:underline"
                                    >
                                        Remove custom logo
                                    </button>
                                ) : null}
                            </div>
                        </div>
                        {form.errors.logo ? <div className="text-xs text-red-600">{form.errors.logo}</div> : null}
                    </div>

                    <div className="space-y-3 border-t border-line pt-4">
                        <div className="flex items-center justify-between">
                            <h3 className="font-display text-sm font-bold text-ink">Brochure colors</h3>
                            {hasCustomColors ? (
                                <button
                                    type="button"
                                    onClick={() =>
                                        form.setData('colors', { primary_color: '', secondary_color: '', accent_color: '' })
                                    }
                                    className="text-xs font-semibold text-ink-muted hover:text-ink"
                                >
                                    Reset to brand kit
                                </button>
                            ) : null}
                        </div>
                        <div className="grid gap-3 sm:grid-cols-3">
                            {colorFields.map(([key, label]) => (
                                <div key={key}>
                                    <InputLabel value={label} />
                                    <ColorPicker
                                        className="mt-1"
                                        value={form.data.colors[key] || previewStyles[key]}
                                        onChange={(hex) => form.setData('colors', { ...form.data.colors, [key]: hex })}
                                    />
                                </div>
                            ))}
                        </div>
                    </div>

                    <div className="space-y-3 border-t border-line pt-4">
                        <h3 className="font-display text-sm font-bold text-ink">Sections</h3>
                        {form.data.sections.map((section, index) => (
                            <div key={`${section.key}-${index}`} className="rounded-lg border border-line p-3">
                                <div className="mb-2 flex items-center justify-between gap-2">
                                    <div className="text-xs font-bold uppercase tracking-wide text-ink-muted">
                                        {section.key}
                                    </div>
                                    <Toggle
                                        checked={!!section.enabled}
                                        onChange={(v) => updateSection(index, { enabled: v })}
                                        label="On"
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <TextInput
                                        className="w-full"
                                        placeholder="Section title"
                                        value={section.title || ''}
                                        onChange={(e) => updateSection(index, { title: e.target.value })}
                                    />
                                    <textarea
                                        className="w-full rounded-md border-line text-sm shadow-sm focus:border-signal focus:ring-signal"
                                        rows={2}
                                        placeholder="Body text"
                                        value={section.body || ''}
                                        onChange={(e) => updateSection(index, { body: e.target.value })}
                                    />
                                    <textarea
                                        className="w-full rounded-md border-line text-sm shadow-sm focus:border-signal focus:ring-signal"
                                        rows={3}
                                        placeholder="Bullet items (one per line)"
                                        value={section.items_text || ''}
                                        onChange={(e) =>
                                            updateSection(index, { items_text: e.target.value })
                                        }
                                    />
                                </div>
                            </div>
                        ))}
                    </div>

                    <PrimaryButton processing={form.processing}>Save brochure</PrimaryButton>
                </form>

                <div className="space-y-4">
                    <div className="atlas-panel max-h-[640px] overflow-y-auto overflow-x-hidden">
                        <BrochureView
                            compact
                            brochure={{
                                ...brochure,
                                headline: form.data.headline || workspace.name,
                                subheadline: form.data.subheadline,
                                styles: previewStyles,
                                logo_url: logoPreview,
                            }}
                            sections={form.data.sections.map((sec) => ({
                                ...sec,
                                items: sec.items_text.split('\n').map((l) => l.trim()).filter(Boolean),
                            }))}
                        />
                    </div>

                    <div className="atlas-panel space-y-3 p-4">
                        <h3 className="font-display text-sm font-bold text-ink">Share & export</h3>
                        <div className="break-all rounded-md border border-line bg-mist/40 px-3 py-2 text-xs">
                            {brochure.share_url}
                        </div>
                        <div className="flex flex-wrap gap-2">
                            <button
                                type="button"
                                onClick={copyLink}
                                className="rounded-md border border-line px-3 py-1.5 text-xs font-semibold hover:bg-mist"
                            >
                                Copy link
                            </button>
                            <a
                                href={brochure.share_url}
                                target="_blank"
                                rel="noreferrer"
                                className="rounded-md border border-line px-3 py-1.5 text-xs font-semibold hover:bg-mist"
                            >
                                Open web
                            </a>
                            <a
                                href={route('studio.brochures.pdf', brochure.id)}
                                className="rounded-md border border-line px-3 py-1.5 text-xs font-semibold hover:bg-mist"
                            >
                                Download PDF
                            </a>
                            <button
                                type="button"
                                onClick={() => {
                                    if (window.confirm('Replace all sections with details from your workspace profile?')) {
                                        router.post(route('studio.brochures.sync', brochure.id), {}, { preserveScroll: true });
                                    }
                                }}
                                className="rounded-md border border-line px-3 py-1.5 text-xs font-semibold hover:bg-mist"
                            >
                                Refresh from workspace
                            </button>
                        </div>
                        {brochure.qr_url ? (
                            <img
                                src={brochure.qr_url}
                                alt="QR"
                                className="h-28 w-28 rounded-md border border-line bg-white p-2"
                            />
                        ) : null}
                        <div className="grid grid-cols-3 gap-2 border-t border-line pt-3 text-center text-xs">
                            <div className="rounded-md bg-sky-50 p-2">
                                <div className="font-bold text-sky-900">{brochure.analytics?.views ?? 0}</div>
                                <div className="text-sky-700">Views</div>
                            </div>
                            <div className="rounded-md bg-emerald-50 p-2">
                                <div className="font-bold text-emerald-900">
                                    {brochure.analytics?.downloads ?? 0}
                                </div>
                                <div className="text-emerald-700">Downloads</div>
                            </div>
                            <div className="rounded-md bg-amber-50 p-2">
                                <div className="font-bold text-amber-900">
                                    {brochure.analytics?.cta_clicks ?? 0}
                                </div>
                                <div className="text-amber-700">CTA</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
