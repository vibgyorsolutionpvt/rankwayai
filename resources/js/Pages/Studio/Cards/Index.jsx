import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import BusinessCardPreview from '@/Components/Studio/BusinessCardPreview';
import PersonPhotoField from '@/Components/Studio/PersonPhotoField';
import CompanyLogoField from '@/Components/Studio/CompanyLogoField';
import StudioSubnav from '@/Components/Studio/StudioSubnav';
import TemplatePicker from '@/Components/Studio/TemplatePicker';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SelectMenu from '@/Components/SelectMenu';
import TextInput from '@/Components/TextInput';
import Toggle from '@/Components/Toggle';
import { confirmAsk } from '@/Components/ConfirmProvider';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

export default function Index({
    workspace,
    cards = [],
    templates = [],
    defaults = {},
    brandKits = [],
}) {
    const [photoPreview, setPhotoPreview] = useState(null);
    const [coverPreview, setCoverPreview] = useState(null);
    const [logoPreview, setLogoPreview] = useState(null);
    const form = useForm({
        title: 'My business card',
        template_key: templates[0]?.value || 'classic',
        status: 'published',
        person_name: '',
        person_title: '',
        company_name: defaults.company_name || workspace.name,
        tagline: defaults.tagline || '',
        phone: defaults.phone || '',
        whatsapp: defaults.whatsapp || defaults.phone || '',
        email: defaults.email || '',
        website: defaults.website || '',
        address: defaults.address || '',
        brand_kit_id: defaults.brand_kit_id ? String(defaults.brand_kit_id) : '',
        is_public: true,
        social_links: {
            facebook: '',
            instagram: '',
            linkedin: '',
            x: '',
        },
        person_photo: null,
        cover_image: null,
        logo: null,
    });

    const activeKit =
        brandKits.find((k) => String(k.id) === String(form.data.brand_kit_id)) ||
        brandKits.find((k) => k.is_active) ||
        null;

    const resolvedLogo = logoPreview || activeKit?.logo_url || null;

    const previewCard = {
        ...form.data,
        company_name: form.data.company_name || workspace.name,
        person_photo_url: photoPreview,
        cover_image_url: coverPreview,
        logo_url: resolvedLogo,
        styles: {
            primary_color: activeKit?.primary_color || '#0E9F90',
            secondary_color: activeKit?.secondary_color || '#0B1220',
            accent_color: activeKit?.accent_color || '#C9A227',
            heading_font: activeKit?.heading_font || 'Outfit',
            body_font: activeKit?.font_family || 'Plus Jakarta Sans',
        },
    };

    const needsPhoto = true;
    const needsCover = form.data.template_key === 'connect';

    return (
        <AuthenticatedLayout
            header={
                <div>
                    <h2 className="font-display text-2xl font-bold leading-tight text-ink">
                        Business Studio
                    </h2>
                </div>
            }
        >
            <Head title="Business cards" />
            <div className="atlas-shell space-y-4">
                <StudioSubnav active="cards" />
                <form
                    className="atlas-panel grid items-start gap-4 p-4 lg:grid-cols-[1.15fr_0.85fr]"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(route('studio.cards.store'), { forceFormData: true });
                    }}
                >
                    <div className="space-y-3">
                        <h3 className="font-display text-lg font-bold text-ink">New business card</h3>

                        <TemplatePicker
                            templates={templates}
                            value={form.data.template_key}
                            onChange={(v) => form.setData('template_key', v)}
                            sampleCard={previewCard}
                            defaultOpen
                        />

                        <div className="grid gap-3 sm:grid-cols-2">
                            <div className="sm:col-span-2">
                                <InputLabel value="Card title *" />
                                <TextInput
                                    className="mt-1 w-full"
                                    value={form.data.title}
                                    onChange={(e) => form.setData('title', e.target.value)}
                                    required
                                />
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
                            <div className="flex items-end pb-1 sm:col-span-2">
                                <Toggle
                                    checked={!!form.data.is_public}
                                    onChange={(v) => form.setData('is_public', v)}
                                    label="Public share link"
                                />
                            </div>
                            <div>
                                <InputLabel value="Person name" />
                                <TextInput
                                    className="mt-1 w-full"
                                    value={form.data.person_name}
                                    onChange={(e) => form.setData('person_name', e.target.value)}
                                />
                            </div>
                            <div>
                                <InputLabel value="Role / title" />
                                <TextInput
                                    className="mt-1 w-full"
                                    value={form.data.person_title}
                                    onChange={(e) => form.setData('person_title', e.target.value)}
                                />
                            </div>
                            <div>
                                <InputLabel value="Company" />
                                <TextInput
                                    className="mt-1 w-full"
                                    value={form.data.company_name}
                                    onChange={(e) => form.setData('company_name', e.target.value)}
                                />
                            </div>
                            <div className="sm:col-span-2">
                                <InputLabel value="Description" />
                                <p className="mt-0.5 text-xs text-ink-muted">
                                    Left-aligned on the card. Enter for new line.
                                </p>
                                <textarea
                                    className="mt-1 w-full rounded-lg border-line text-sm leading-relaxed shadow-sm focus:border-signal focus:ring-signal"
                                    rows={4}
                                    placeholder="Short bio shown on the card"
                                    value={form.data.tagline}
                                    onChange={(e) => form.setData('tagline', e.target.value)}
                                />
                            </div>
                            <div>
                                <InputLabel value="Phone" />
                                <TextInput
                                    className="mt-1 w-full"
                                    value={form.data.phone}
                                    onChange={(e) => form.setData('phone', e.target.value)}
                                />
                            </div>
                            <div>
                                <InputLabel value="WhatsApp" />
                                <TextInput
                                    className="mt-1 w-full"
                                    value={form.data.whatsapp}
                                    onChange={(e) => form.setData('whatsapp', e.target.value)}
                                />
                            </div>
                            <div>
                                <InputLabel value="Email" />
                                <TextInput
                                    className="mt-1 w-full"
                                    value={form.data.email}
                                    onChange={(e) => form.setData('email', e.target.value)}
                                />
                            </div>
                            <div>
                                <InputLabel value="Website" />
                                <TextInput
                                    className="mt-1 w-full"
                                    value={form.data.website}
                                    onChange={(e) => form.setData('website', e.target.value)}
                                />
                            </div>
                            <div className="sm:col-span-2">
                                <InputLabel value="Address" />
                                <p className="mt-0.5 text-xs text-ink-muted">
                                    Connect pe Direction icon — click par Google Maps open hota hai.
                                </p>
                                <TextInput
                                    className="mt-1 w-full"
                                    value={form.data.address}
                                    onChange={(e) => form.setData('address', e.target.value)}
                                />
                            </div>
                            <div className="sm:col-span-2 grid gap-3 rounded-lg border border-line bg-mist/30 p-3 sm:grid-cols-2">
                                <div className="sm:col-span-2 text-sm font-semibold text-ink">
                                    Social links
                                </div>
                                <div>
                                    <InputLabel value="Facebook" />
                                    <TextInput
                                        className="mt-1 w-full"
                                        placeholder="https://facebook.com/…"
                                        value={form.data.social_links?.facebook || ''}
                                        onChange={(e) =>
                                            form.setData('social_links', {
                                                ...form.data.social_links,
                                                facebook: e.target.value,
                                            })
                                        }
                                    />
                                </div>
                                <div>
                                    <InputLabel value="Instagram" />
                                    <TextInput
                                        className="mt-1 w-full"
                                        placeholder="https://instagram.com/…"
                                        value={form.data.social_links?.instagram || ''}
                                        onChange={(e) =>
                                            form.setData('social_links', {
                                                ...form.data.social_links,
                                                instagram: e.target.value,
                                            })
                                        }
                                    />
                                </div>
                                <div>
                                    <InputLabel value="LinkedIn" />
                                    <TextInput
                                        className="mt-1 w-full"
                                        placeholder="https://linkedin.com/in/…"
                                        value={form.data.social_links?.linkedin || ''}
                                        onChange={(e) =>
                                            form.setData('social_links', {
                                                ...form.data.social_links,
                                                linkedin: e.target.value,
                                            })
                                        }
                                    />
                                </div>
                                <div>
                                    <InputLabel value="X (Twitter)" />
                                    <TextInput
                                        className="mt-1 w-full"
                                        placeholder="https://x.com/…"
                                        value={form.data.social_links?.x || ''}
                                        onChange={(e) =>
                                            form.setData('social_links', {
                                                ...form.data.social_links,
                                                x: e.target.value,
                                            })
                                        }
                                    />
                                </div>
                            </div>
                            <CompanyLogoField
                                preview={resolvedLogo}
                                brandLogoUrl={activeKit?.logo_url || null}
                                hasCustom={!!form.data.logo}
                                onChange={(e) => {
                                    const file = e.target.files?.[0] || null;
                                    form.setData('logo', file);
                                    setLogoPreview(file ? URL.createObjectURL(file) : null);
                                }}
                                onClearCustom={() => {
                                    form.setData('logo', null);
                                    setLogoPreview(null);
                                }}
                            />
                            {needsPhoto ? (
                                <div className="sm:col-span-2 rounded-lg border border-emerald-200 bg-emerald-50/40 p-3">
                                    <PersonPhotoField
                                        preview={photoPreview}
                                        initial={
                                            form.data.person_name ||
                                            form.data.company_name ||
                                            'A'
                                        }
                                        primary={previewCard.styles.primary_color}
                                        secondary={previewCard.styles.secondary_color}
                                        accent={previewCard.styles.accent_color}
                                        onChange={(e) => {
                                            const file = e.target.files?.[0] || null;
                                            form.setData('person_photo', file);
                                            setPhotoPreview(
                                                file ? URL.createObjectURL(file) : null,
                                            );
                                        }}
                                        onClear={() => {
                                            form.setData('person_photo', null);
                                            setPhotoPreview(null);
                                        }}
                                    />
                                </div>
                            ) : null}
                            {needsCover ? (
                                <div className="sm:col-span-2">
                                    <InputLabel value="Cover banner" />
                                    {coverPreview ? (
                                        <img
                                            src={coverPreview}
                                            alt=""
                                            className="mt-2 h-24 w-full rounded-md border border-line object-cover"
                                        />
                                    ) : null}
                                    <input
                                        type="file"
                                        accept="image/*"
                                        className="mt-1.5 block w-full text-sm"
                                        onChange={(e) => {
                                            const file = e.target.files?.[0] || null;
                                            form.setData('cover_image', file);
                                            setCoverPreview(
                                                file ? URL.createObjectURL(file) : null,
                                            );
                                        }}
                                    />
                                </div>
                            ) : null}
                        </div>
                        <PrimaryButton processing={form.processing}>Create card</PrimaryButton>
                    </div>
                    <div className="sticky top-[5.25rem] self-start rounded-lg border border-emerald-200 bg-emerald-50/40 p-3">
                        <div className="mb-2 text-xs font-semibold uppercase tracking-wide text-emerald-800">
                            Live preview
                        </div>
                        <div className="flex justify-center">
                            <BusinessCardPreview card={previewCard} size="lg" />
                        </div>
                    </div>
                </form>

                <section className="atlas-panel overflow-hidden">
                    <div className="border-b border-line px-4 py-3">
                        <h3 className="font-display text-base font-bold text-ink">Your cards</h3>
                        <p className="mt-0.5 text-xs text-ink-muted">
                            Edit, share public link, QR, or download PDF.
                        </p>
                    </div>
                    {cards.length === 0 ? (
                        <p className="px-4 py-8 text-center text-sm text-ink-muted">
                            No cards yet — create one above.
                        </p>
                    ) : (
                        <div className="divide-y divide-line">
                            {cards.map((card) => {
                                const cardId = String(card.id);
                                return (
                                    <div
                                        key={cardId}
                                        className="flex flex-col gap-4 p-4 lg:flex-row lg:items-center lg:justify-between"
                                    >
                                        <div className="flex min-w-0 flex-1 flex-col gap-3 sm:flex-row sm:items-center">
                                            <BusinessCardPreview card={card} size="sm" showCta={false} />
                                            <div className="min-w-0">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <div className="font-semibold text-ink">{card.title}</div>
                                                    <span
                                                        className={
                                                            'rounded px-1.5 py-0.5 text-[10px] font-bold uppercase ' +
                                                            (card.status === 'published'
                                                                ? 'bg-emerald-100 text-emerald-800'
                                                                : 'bg-mist text-ink-muted')
                                                        }
                                                    >
                                                        {card.status}
                                                    </span>
                                                </div>
                                                <div className="mt-1 text-xs text-ink-muted">
                                                    {card.person_name || card.company_name} ·{' '}
                                                    {card.template_key} · {card.analytics?.views ?? 0}{' '}
                                                    views
                                                </div>
                                            </div>
                                        </div>
                                        <div className="flex flex-wrap gap-2">
                                            <Link
                                                href={route('studio.cards.edit', cardId)}
                                                className="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-900 hover:bg-emerald-100"
                                            >
                                                Edit
                                            </Link>
                                            <a
                                                href={card.share_url}
                                                target="_blank"
                                                rel="noreferrer"
                                                className="rounded-md border border-line px-3 py-1.5 text-xs font-semibold text-ink hover:bg-mist"
                                            >
                                                Open link
                                            </a>
                                            <a
                                                href={route('studio.cards.pdf', cardId)}
                                                className="rounded-md border border-line px-3 py-1.5 text-xs font-semibold text-ink hover:bg-mist"
                                            >
                                                PDF
                                            </a>
                                            <button
                                                type="button"
                                                className="rounded-md border border-line px-3 py-1.5 text-xs font-semibold text-ink hover:bg-mist"
                                                onClick={() =>
                                                    router.post(route('studio.cards.duplicate', cardId))
                                                }
                                            >
                                                Duplicate
                                            </button>
                                            <button
                                                type="button"
                                                className="rounded-md border border-rose-200 px-3 py-1.5 text-xs font-semibold text-rose-700 hover:bg-rose-50"
                                                onClick={async () => {
                                                    const ok = await confirmAsk({
                                                        title: 'Delete card?',
                                                        message: `"${card.title}" will be removed.`,
                                                        confirmLabel: 'Delete',
                                                    });
                                                    if (ok) {
                                                        router.delete(
                                                            route('studio.cards.destroy', cardId),
                                                        );
                                                    }
                                                }}
                                            >
                                                Delete
                                            </button>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    )}
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
