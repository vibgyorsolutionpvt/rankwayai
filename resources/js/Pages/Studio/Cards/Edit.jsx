import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import BusinessCardPreview from '@/Components/Studio/BusinessCardPreview';
import PersonPhotoField from '@/Components/Studio/PersonPhotoField';
import CompanyLogoField from '@/Components/Studio/CompanyLogoField';
import TemplatePicker from '@/Components/Studio/TemplatePicker';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SelectMenu from '@/Components/SelectMenu';
import TextInput from '@/Components/TextInput';
import Toggle from '@/Components/Toggle';
import { toast } from '@/Components/ToastProvider';
import { Head, Link, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';

export default function Edit({ workspace, card, templates = [], brandKits = [] }) {
    const [photoPreview, setPhotoPreview] = useState(card.person_photo_url || null);
    const [coverPreview, setCoverPreview] = useState(card.cover_image_url || null);
    const [logoPreview, setLogoPreview] = useState(card.logo_url || null);
    const [removeLogo, setRemoveLogo] = useState(false);
    const [shareOpen, setShareOpen] = useState(false);
    const [drawerTop, setDrawerTop] = useState(88);
    const form = useForm({
        title: card.title || '',
        template_key: card.template_key || 'classic',
        status: card.status || 'draft',
        person_name: card.person_name || '',
        person_title: card.person_title || '',
        company_name: card.company_name || '',
        tagline: card.tagline || '',
        phone: card.phone || '',
        whatsapp: card.whatsapp || '',
        email: card.email || '',
        website: card.website || '',
        address: card.address || '',
        brand_kit_id: card.brand_kit_id ? String(card.brand_kit_id) : '',
        is_public: card.is_public !== false,
        social_links: {
            facebook: card.social_links?.facebook || '',
            instagram: card.social_links?.instagram || '',
            linkedin: card.social_links?.linkedin || '',
            x: card.social_links?.x || '',
        },
        person_photo: null,
        cover_image: null,
        logo: null,
        remove_logo: false,
    });

    useEffect(() => {
        setPhotoPreview(card.person_photo_url || null);
        setCoverPreview(card.cover_image_url || null);
        setLogoPreview(card.logo_url || null);
        setRemoveLogo(false);
    }, [card.id, card.person_photo_url, card.cover_image_url, card.logo_url]);

    useEffect(() => {
        if (!shareOpen) return undefined;

        const measure = () => {
            const header = document.querySelector('header.sticky');
            const bottom = header ? header.getBoundingClientRect().bottom : 88;
            setDrawerTop(Math.max(0, Math.round(bottom + 8)));
        };

        measure();
        window.addEventListener('resize', measure);

        const onKey = (e) => {
            if (e.key === 'Escape') setShareOpen(false);
        };
        document.addEventListener('keydown', onKey);
        document.body.style.overflow = 'hidden';

        return () => {
            window.removeEventListener('resize', measure);
            document.removeEventListener('keydown', onKey);
            document.body.style.overflow = '';
        };
    }, [shareOpen]);

    const selectedKit =
        brandKits.find((k) => String(k.id) === String(form.data.brand_kit_id)) ||
        brandKits.find((k) => k.is_active) ||
        null;

    const resolvedLogo = (() => {
        if (logoPreview && form.data.logo) return logoPreview;
        if (removeLogo) return selectedKit?.logo_url || card.brand_logo_url || null;
        if (logoPreview) return logoPreview;
        return selectedKit?.logo_url || card.brand_logo_url || card.logo_url || null;
    })();

    const live = {
        ...card,
        ...form.data,
        company_name: form.data.company_name || workspace.name,
        person_photo_url: photoPreview,
        cover_image_url: coverPreview,
        logo_url: resolvedLogo,
        styles: {
            ...(card.styles || {}),
            primary_color: selectedKit?.primary_color || card.styles?.primary_color,
            secondary_color: selectedKit?.secondary_color || card.styles?.secondary_color,
            accent_color: selectedKit?.accent_color || card.styles?.accent_color,
            heading_font: selectedKit?.heading_font || card.styles?.heading_font,
            body_font: selectedKit?.font_family || card.styles?.body_font,
        },
    };

    const needsPhoto = true;
    const needsCover = form.data.template_key === 'connect';
    const styles = card.styles || {};

    const copyLink = async () => {
        try {
            await navigator.clipboard.writeText(card.share_url);
            toast.success('Share link copied');
        } catch {
            toast.error('Could not copy link');
        }
    };

    const shareDrawer =
        shareOpen && typeof document !== 'undefined'
            ? createPortal(
                  <div
                      className="fixed inset-x-0 bottom-0 z-[60] flex justify-end"
                      style={{ top: drawerTop }}
                  >
                      <button
                          type="button"
                          className="absolute inset-0 bg-ink/40 backdrop-blur-[1px]"
                          aria-label="Close share panel"
                          onClick={() => setShareOpen(false)}
                      />
                      <aside
                          className="relative z-10 flex h-full w-full max-w-md flex-col border-l border-t border-line bg-white shadow-2xl motion-safe:animate-[slideInRight_0.22s_ease-out]"
                          role="dialog"
                          aria-modal="true"
                          aria-labelledby="share-drawer-title"
                      >
                          <div className="flex shrink-0 items-center gap-3 border-b border-line bg-white px-4 py-3.5">
                              <div className="min-w-0 flex-1">
                                  <div className="text-[10px] font-semibold uppercase tracking-[0.14em] text-ink-muted">
                                      Share & export
                                  </div>
                                  <h3
                                      id="share-drawer-title"
                                      className="truncate font-display text-lg font-bold text-ink"
                                      title={form.data.title || card.title}
                                  >
                                      {form.data.title || card.title || 'Business card'}
                                  </h3>
                              </div>
                              <button
                                  type="button"
                                  onClick={() => setShareOpen(false)}
                                  className="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-line text-ink-muted transition hover:bg-mist hover:text-ink"
                                  aria-label="Close"
                              >
                                  <svg
                                      viewBox="0 0 24 24"
                                      fill="none"
                                      stroke="currentColor"
                                      strokeWidth="2"
                                      className="h-4 w-4"
                                      aria-hidden
                                  >
                                      <path d="M6 6l12 12M18 6L6 18" />
                                  </svg>
                              </button>
                          </div>
                          <div className="flex-1 space-y-4 overflow-y-auto p-4">
                              <div>
                                  <div className="mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-ink-muted">
                                      Public link
                                  </div>
                                  <div className="break-all rounded-md border border-line bg-mist/40 px-3 py-2 text-xs text-ink">
                                      {card.share_url}
                                  </div>
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
                                      href={card.share_url}
                                      target="_blank"
                                      rel="noreferrer"
                                      className="rounded-md border border-line px-3 py-1.5 text-xs font-semibold hover:bg-mist"
                                  >
                                      Open web card
                                  </a>
                                  <a
                                      href={route('studio.cards.pdf', String(card.id))}
                                      className="rounded-md border border-line px-3 py-1.5 text-xs font-semibold hover:bg-mist"
                                  >
                                      Download PDF
                                  </a>
                              </div>
                              {card.qr_url ? (
                                  <div>
                                      <div className="mb-2 text-[11px] font-semibold uppercase tracking-wide text-ink-muted">
                                          QR code
                                      </div>
                                      <img
                                          src={card.qr_url}
                                          alt="QR"
                                          className="h-40 w-40 rounded-md border border-line bg-white p-2"
                                      />
                                  </div>
                              ) : null}
                              <div>
                                  <div className="mb-2 text-[11px] font-semibold uppercase tracking-wide text-ink-muted">
                                      Analytics
                                  </div>
                                  <div className="grid grid-cols-2 gap-2 text-center text-xs">
                                      <div className="rounded-md bg-sky-50 p-3">
                                          <div className="font-bold text-sky-900">
                                              {card.analytics?.views ?? 0}
                                          </div>
                                          <div className="text-sky-700">Views</div>
                                      </div>
                                      <div className="rounded-md bg-emerald-50 p-3">
                                          <div className="font-bold text-emerald-900">
                                              {(card.analytics?.phone_clicks ?? 0) +
                                                  (card.analytics?.whatsapp_clicks ?? 0)}
                                          </div>
                                          <div className="text-emerald-700">Call / WA</div>
                                      </div>
                                      <div className="rounded-md bg-amber-50 p-3">
                                          <div className="font-bold text-amber-900">
                                              {card.analytics?.email_clicks ?? 0}
                                          </div>
                                          <div className="text-amber-700">Email</div>
                                      </div>
                                      <div className="rounded-md bg-violet-50 p-3">
                                          <div className="font-bold text-violet-900">
                                              {card.analytics?.website_clicks ?? 0}
                                          </div>
                                          <div className="text-violet-700">Website</div>
                                      </div>
                                  </div>
                              </div>
                          </div>
                      </aside>
                  </div>,
                  document.body,
              )
            : null;

    return (
        <AuthenticatedLayout
            header={
                <div>
                    <div className="text-xs font-semibold uppercase tracking-[0.16em] text-ink-muted">
                        <Link href={route('studio.cards.index')} className="hover:text-ink">
                            Studio
                        </Link>{' '}
                        / Card
                    </div>
                    <h2 className="font-display text-2xl font-bold text-ink">{card.title}</h2>
                </div>
            }
        >
            <Head title={card.title} />
            <div className="atlas-shell grid items-start gap-4 lg:grid-cols-[1.05fr_0.95fr]">
                <form
                    className="atlas-panel space-y-3 p-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(route('studio.cards.update', String(card.id)), { forceFormData: true });
                    }}
                >
                    <TemplatePicker
                        templates={templates}
                        value={form.data.template_key}
                        onChange={(v) => form.setData('template_key', v)}
                        sampleCard={live}
                    />

                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="sm:col-span-2">
                            <InputLabel value="Title *" />
                            <TextInput
                                className="mt-1 w-full"
                                value={form.data.title}
                                onChange={(e) => form.setData('title', e.target.value)}
                                required
                            />
                            <InputError className="mt-1" message={form.errors.title} />
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
                                type="email"
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
                                    value={form.data.social_links.facebook}
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
                                    value={form.data.social_links.instagram}
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
                                    value={form.data.social_links.linkedin}
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
                                    value={form.data.social_links.x}
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
                            brandLogoUrl={selectedKit?.logo_url || card.brand_logo_url || null}
                            hasCustom={!!(form.data.logo || (card.has_custom_logo && !removeLogo))}
                            onChange={(e) => {
                                const file = e.target.files?.[0] || null;
                                form.setData('logo', file);
                                form.setData('remove_logo', false);
                                setRemoveLogo(false);
                                setLogoPreview(file ? URL.createObjectURL(file) : null);
                            }}
                            onClearCustom={() => {
                                form.setData('logo', null);
                                form.setData('remove_logo', true);
                                setRemoveLogo(true);
                                setLogoPreview(selectedKit?.logo_url || card.brand_logo_url || null);
                            }}
                        />
                        {needsPhoto ? (
                            <div className="sm:col-span-2 rounded-lg border border-emerald-200 bg-emerald-50/40 p-3">
                                <PersonPhotoField
                                    preview={photoPreview}
                                    initial={form.data.person_name || form.data.company_name || 'A'}
                                    primary={styles.primary_color || '#0E9F90'}
                                    secondary={styles.secondary_color || '#0B1220'}
                                    accent={styles.accent_color || '#C9A227'}
                                    onChange={(e) => {
                                        const file = e.target.files?.[0] || null;
                                        form.setData('person_photo', file);
                                        setPhotoPreview(
                                            file
                                                ? URL.createObjectURL(file)
                                                : card.person_photo_url,
                                        );
                                    }}
                                    onClear={() => {
                                        form.setData('person_photo', null);
                                        setPhotoPreview(null);
                                    }}
                                />
                            </div>
                        ) : (
                            <div className="sm:col-span-2">
                                <InputLabel value="Photo (optional)" />
                                {photoPreview ? (
                                    <img
                                        src={photoPreview}
                                        alt=""
                                        className="mt-1 h-16 w-16 rounded-full border border-line object-cover"
                                    />
                                ) : null}
                                <input
                                    type="file"
                                    accept="image/*"
                                    className="mt-1 block w-full text-sm"
                                    onChange={(e) => {
                                        const file = e.target.files?.[0] || null;
                                        form.setData('person_photo', file);
                                        setPhotoPreview(
                                            file
                                                ? URL.createObjectURL(file)
                                                : card.person_photo_url,
                                        );
                                    }}
                                />
                            </div>
                        )}
                        {needsCover ? (
                            <div className="sm:col-span-2">
                                <InputLabel value="Cover banner (Connect template)" />
                                <p className="mt-0.5 text-xs text-ink-muted">
                                    Wide photo for the top banner. Optional.
                                </p>
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
                                            file
                                                ? URL.createObjectURL(file)
                                                : card.cover_image_url,
                                        );
                                    }}
                                />
                            </div>
                        ) : null}
                    </div>
                    <PrimaryButton processing={form.processing}>Save card</PrimaryButton>
                </form>

                <div className="sticky top-[5.25rem] space-y-3 self-start">
                    <div className="atlas-panel p-4">
                        <div className="mb-3 text-xs font-semibold uppercase tracking-wide text-ink-muted">
                            Preview
                        </div>
                        <div className="flex justify-center">
                            <BusinessCardPreview card={live} size="lg" />
                        </div>
                    </div>

                    <button
                        type="button"
                        onClick={() => setShareOpen(true)}
                        className="flex w-full items-center justify-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-950 transition hover:bg-emerald-100"
                    >
                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            strokeWidth="1.8"
                            className="h-4 w-4"
                            aria-hidden
                        >
                            <circle cx="18" cy="5" r="2.5" />
                            <circle cx="6" cy="12" r="2.5" />
                            <circle cx="18" cy="19" r="2.5" />
                            <path d="m8.2 10.8 5.6-3.6M8.2 13.2l5.6 3.6" />
                        </svg>
                        Share, QR & analytics
                    </button>
                </div>
            </div>

            {shareDrawer}
        </AuthenticatedLayout>
    );
}
