import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import ColorPicker from '@/Components/ColorPicker';
import FontPicker from '@/Components/FontPicker';
import HelpGuide, { HELP } from '@/Components/HelpGuide';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import Toggle from '@/Components/Toggle';
import { confirmAsk } from '@/Components/ConfirmProvider';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';

export default function Edit({ workspace, brand, kits = [] }) {
    const [logoPreview, setLogoPreview] = useState(brand.logo_url || null);
    const [logoInputKey, setLogoInputKey] = useState(0);
    const form = useForm({
        name: brand.name || 'Default',
        primary_color: brand.primary_color || '#0E9F90',
        secondary_color: brand.secondary_color || '#0B1220',
        font_family: brand.font_family || 'Plus Jakarta Sans',
        website_url: brand.website_url || '',
        phone: brand.phone || '',
        email: brand.email || '',
        default_cta_label: brand.default_cta_label || '',
        default_cta_url: brand.default_cta_url || '',
        social_links: {
            facebook: brand.social_links?.facebook || '',
            instagram: brand.social_links?.instagram || '',
            linkedin: brand.social_links?.linkedin || '',
            x: brand.social_links?.x || '',
        },
        logo: null,
    });
    const createForm = useForm({ name: '', make_active: false });

    useEffect(() => {
        form.setData({
            name: brand.name || 'Default',
            primary_color: brand.primary_color || '#0E9F90',
            secondary_color: brand.secondary_color || '#0B1220',
            font_family: brand.font_family || 'Plus Jakarta Sans',
            website_url: brand.website_url || '',
            phone: brand.phone || '',
            email: brand.email || '',
            default_cta_label: brand.default_cta_label || '',
            default_cta_url: brand.default_cta_url || '',
            social_links: {
                facebook: brand.social_links?.facebook || '',
                instagram: brand.social_links?.instagram || '',
                linkedin: brand.social_links?.linkedin || '',
                x: brand.social_links?.x || '',
            },
            logo: null,
        });
        form.clearErrors();
        setLogoPreview(brand.logo_url || null);
        setLogoInputKey((k) => k + 1);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [brand.id]);

    const onLogoChange = (e) => {
        const file = e.target.files?.[0] || null;
        form.setData('logo', file);
        if (file) {
            setLogoPreview(URL.createObjectURL(file));
        }
    };

    const removeLogo = async () => {
        if (brand.logo_url) {
            const ok = await confirmAsk({
                title: 'Remove logo?',
                message: 'The current brand logo will be deleted.',
                confirmLabel: 'Remove logo',
            });
            if (!ok) {
                return;
            }
            form.setData('logo', null);
            setLogoInputKey((k) => k + 1);
            router.delete(route('brand.logo.destroy', brand.id), {
                preserveScroll: true,
                onSuccess: () => setLogoPreview(null),
            });
            return;
        }
        form.setData('logo', null);
        setLogoInputKey((k) => k + 1);
        setLogoPreview(null);
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
                <section className="atlas-panel space-y-3 p-3">
                    <div className="flex flex-wrap items-end justify-between gap-2">
                        <div>
                            <div className="flex items-center gap-1">
                                <h3 className="font-display text-base font-bold text-ink">
                                    Your kits
                                </h3>
                                <HelpGuide help={HELP.brand} className="!h-6 !w-6" />
                            </div>
                            <p className="text-[11px] text-ink-muted">
                                Multiple kits per business. The{' '}
                                <span className="font-semibold text-ink">Active</span> kit is used
                                in SMM, Channels, and AI.
                            </p>
                        </div>
                        <form
                            className="flex flex-wrap items-end gap-2"
                            onSubmit={(e) => {
                                e.preventDefault();
                                createForm.post(route('brand.store'), {
                                    onSuccess: () => createForm.reset(),
                                });
                            }}
                        >
                            <div>
                                <InputLabel value="New kit name" />
                                <TextInput
                                    className="mt-1 w-44"
                                    placeholder="e.g. Festival look"
                                    value={createForm.data.name}
                                    onChange={(e) => createForm.setData('name', e.target.value)}
                                    required
                                />
                            </div>
                            <Toggle
                                className="mb-2"
                                checked={!!createForm.data.make_active}
                                onChange={(v) => createForm.setData('make_active', v)}
                                label="Make active"
                            />
                            <PrimaryButton
                                className="h-[38px]"
                                processing={createForm.processing}
                            >
                                Add kit
                            </PrimaryButton>
                        </form>
                    </div>

                    <div className="flex flex-wrap gap-2">
                        {kits.map((kit) => {
                            const selected = kit.id === brand.id;
                            return (
                                <div
                                    key={kit.id}
                                    className={
                                        'flex items-center gap-2 rounded-md border px-2.5 py-1.5 ' +
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

                <div className="grid gap-2.5 lg:grid-cols-[1.1fr_0.9fr]">
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

                        <div className="grid gap-3 sm:grid-cols-2">
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
                        </div>

                        <div>
                            <InputLabel value="Font" />
                            <p className="mt-0.5 text-xs text-ink-muted">
                                Used in Live preview (right) and creatives — settings form stays Atlas UI font.
                            </p>
                            <div className="mt-1.5">
                                <FontPicker
                                    value={form.data.font_family}
                                    onChange={(v) => form.setData('font_family', v)}
                                />
                            </div>
                            <InputError className="mt-1" message={form.errors.font_family} />
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

                        <div>
                            <InputLabel value="Logo" />
                            {logoPreview ? (
                                <div className="mt-1.5 flex items-center gap-3 rounded-md border border-line bg-mist/50 p-2.5">
                                    <img
                                        src={logoPreview}
                                        alt="Logo"
                                        className="h-12 w-12 rounded-md border border-line bg-white object-contain p-1"
                                    />
                                    <div className="min-w-0 flex-1">
                                        <div className="truncate text-sm font-semibold text-ink">
                                            {form.data.logo?.name || 'Current logo'}
                                        </div>
                                        <div className="text-xs text-ink-muted">
                                            Replace below or remove
                                        </div>
                                    </div>
                                    <button
                                        type="button"
                                        title="Remove logo"
                                        aria-label="Remove logo"
                                        onClick={removeLogo}
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
                                key={logoInputKey}
                                type="file"
                                accept="image/*"
                                className="mt-1.5 block w-full text-sm"
                                onChange={onLogoChange}
                            />
                        </div>

                        <PrimaryButton processing={form.processing}>Save brand kit</PrimaryButton>
                    </form>

                    <div className="atlas-panel overflow-hidden font-sans">
                        <div className="border-b border-line px-4 py-3 text-xs font-semibold uppercase tracking-[0.14em] text-ink-muted">
                            Live preview · brand font
                        </div>
                        <div
                            className="flex min-h-[280px] flex-col justify-end p-5 text-white"
                            style={{
                                background: `linear-gradient(145deg, ${form.data.secondary_color}, ${form.data.primary_color})`,
                                fontFamily: /[,\s]/.test(form.data.font_family || '')
                                    ? `"${form.data.font_family}"`
                                    : form.data.font_family || undefined,
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
                            <div className="text-2xl font-bold">{workspace.name}</div>
                            <div className="mt-1 text-sm opacity-80">{form.data.name}</div>
                            <div className="mt-2 text-sm opacity-90">
                                One Platform. Complete Digital Marketing.
                            </div>
                            <button
                                type="button"
                                className="mt-4 w-fit rounded-md bg-white px-3 py-2 text-sm font-semibold text-ink"
                            >
                                {form.data.default_cta_label || 'Get started'}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
