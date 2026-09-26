import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SelectMenu from '@/Components/SelectMenu';
import TextInput from '@/Components/TextInput';
import Toggle from '@/Components/Toggle';
import { toast } from '@/Components/ToastProvider';
import { Head, Link, useForm } from '@inertiajs/react';

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
        sections: (brochure.sections || []).map((s) => ({
            ...s,
            items_text: sectionItemsText(s),
        })),
    });

    const updateSection = (index, patch) => {
        const next = form.data.sections.map((section, i) =>
            i === index ? { ...section, ...patch } : section,
        );
        form.setData('sections', next);
    };

    const copyLink = async () => {
        try {
            await navigator.clipboard.writeText(brochure.share_url);
            toast.success('Share link copied');
        } catch {
            toast.error('Could not copy link');
        }
    };

    const primary = brochure.styles?.primary_color || '#0E9F90';
    const secondary = brochure.styles?.secondary_color || '#0B1220';
    const accent = brochure.styles?.accent_color || '#F59E0B';

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
                        form
                            .transform((data) => ({
                                ...data,
                                sections: (data.sections || []).map(({ items_text, ...rest }) => ({
                                    ...rest,
                                    items: items_text,
                                })),
                            }))
                            .post(route('studio.brochures.update', brochure.id));
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
                    <div
                        className="atlas-panel overflow-hidden"
                        style={{ borderTop: `4px solid ${primary}` }}
                    >
                        <div
                            className="px-4 py-8 text-center text-white"
                            style={{
                                background: `linear-gradient(145deg, ${secondary}, ${primary})`,
                            }}
                        >
                            {brochure.logo_url ? (
                                <img
                                    src={brochure.logo_url}
                                    alt=""
                                    className="mx-auto mb-3 h-10 w-auto object-contain"
                                />
                            ) : null}
                            <div className="font-display text-2xl font-bold">
                                {form.data.headline || workspace.name}
                            </div>
                            <div className="mt-1 text-sm opacity-90">
                                {form.data.subheadline || 'Brochure preview'}
                            </div>
                            <span
                                className="mt-3 inline-block rounded px-2 py-1 text-[10px] font-bold text-ink"
                                style={{ background: accent }}
                            >
                                {form.data.title}
                            </span>
                        </div>
                        <div className="max-h-[420px] space-y-3 overflow-y-auto p-4">
                            {form.data.sections
                                .filter((s) => s.enabled && s.key !== 'cover')
                                .map((section, i) => (
                                    <div key={`${section.key}-prev-${i}`}>
                                        <div
                                            className="text-sm font-bold"
                                            style={{ color: primary }}
                                        >
                                            {section.title}
                                        </div>
                                        {section.body ? (
                                            <p className="mt-1 text-xs text-ink-muted">{section.body}</p>
                                        ) : null}
                                        {section.items_text ? (
                                            <ul className="mt-1 list-disc pl-4 text-xs text-ink">
                                                {section.items_text
                                                    .split('\n')
                                                    .filter(Boolean)
                                                    .map((line) => (
                                                        <li key={line}>{line}</li>
                                                    ))}
                                            </ul>
                                        ) : null}
                                    </div>
                                ))}
                        </div>
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
