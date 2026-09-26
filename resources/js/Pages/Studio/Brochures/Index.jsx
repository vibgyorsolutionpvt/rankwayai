import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import StudioSubnav from '@/Components/Studio/StudioSubnav';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SelectMenu from '@/Components/SelectMenu';
import TextInput from '@/Components/TextInput';
import { confirmAsk } from '@/Components/ConfirmProvider';
import { Head, Link, router, useForm } from '@inertiajs/react';

export default function Index({
    workspace,
    brochures = [],
    templates = [],
    defaults = {},
    brandKits = [],
}) {
    const form = useForm({
        title: 'Company brochure',
        template_key: templates[0]?.value || 'agency',
        status: 'published',
        headline: defaults.headline || workspace.name,
        subheadline: defaults.subheadline || '',
        brand_kit_id: defaults.brand_kit_id ? String(defaults.brand_kit_id) : '',
        is_public: true,
    });

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
            <Head title="Brochures" />
            <div className="atlas-shell space-y-4">
                <StudioSubnav active="brochures" />
                <form
                    className="atlas-panel grid gap-3 p-4 md:grid-cols-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(route('studio.brochures.store'));
                    }}
                >
                    <div className="md:col-span-2">
                        <h3 className="font-display text-lg font-bold text-ink">New brochure</h3>
                        <p className="text-sm text-ink-muted">
                            Sections auto-fill from Business Profile — then edit before sharing.
                        </p>
                    </div>
                    <div>
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
                    <div className="md:col-span-2">
                        <PrimaryButton processing={form.processing}>Create brochure</PrimaryButton>
                    </div>
                </form>

                <section className="atlas-panel overflow-hidden">
                    <div className="border-b border-line px-4 py-3">
                        <h3 className="font-display text-base font-bold text-ink">Your brochures</h3>
                    </div>
                    {brochures.length === 0 ? (
                        <p className="px-4 py-8 text-center text-sm text-ink-muted">
                            No brochures yet.
                        </p>
                    ) : (
                        <div className="divide-y divide-line">
                            {brochures.map((item) => (
                                <div
                                    key={item.id}
                                    className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between"
                                >
                                    <div>
                                        <div className="flex flex-wrap items-center gap-2">
                                            <div className="font-semibold text-ink">{item.title}</div>
                                            <span
                                                className={
                                                    'rounded px-1.5 py-0.5 text-[10px] font-bold uppercase ' +
                                                    (item.status === 'published'
                                                        ? 'bg-emerald-100 text-emerald-800'
                                                        : 'bg-mist text-ink-muted')
                                                }
                                            >
                                                {item.status}
                                            </span>
                                        </div>
                                        <div className="mt-1 text-xs text-ink-muted">
                                            {item.template_key} · {item.analytics?.views ?? 0} views ·{' '}
                                            {item.analytics?.downloads ?? 0} downloads
                                        </div>
                                    </div>
                                    <div className="flex flex-wrap gap-2">
                                        <Link
                                            href={route('studio.brochures.edit', item.id)}
                                            className="rounded-md border border-line px-3 py-1.5 text-xs font-semibold hover:bg-mist"
                                        >
                                            Edit
                                        </Link>
                                        <a
                                            href={item.share_url}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="rounded-md border border-line px-3 py-1.5 text-xs font-semibold hover:bg-mist"
                                        >
                                            Open
                                        </a>
                                        <a
                                            href={route('studio.brochures.pdf', item.id)}
                                            className="rounded-md border border-line px-3 py-1.5 text-xs font-semibold hover:bg-mist"
                                        >
                                            PDF
                                        </a>
                                        <button
                                            type="button"
                                            className="rounded-md border border-line px-3 py-1.5 text-xs font-semibold hover:bg-mist"
                                            onClick={() =>
                                                router.post(route('studio.brochures.duplicate', item.id))
                                            }
                                        >
                                            Duplicate
                                        </button>
                                        <button
                                            type="button"
                                            className="rounded-md border border-rose-200 px-3 py-1.5 text-xs font-semibold text-rose-700 hover:bg-rose-50"
                                            onClick={async () => {
                                                const ok = await confirmAsk({
                                                    title: 'Delete brochure?',
                                                    message: `"${item.title}" will be removed.`,
                                                    confirmLabel: 'Delete',
                                                });
                                                if (ok) {
                                                    router.delete(route('studio.brochures.destroy', item.id));
                                                }
                                            }}
                                        >
                                            Delete
                                        </button>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
