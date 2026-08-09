import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import ColorPicker from '@/Components/ColorPicker';
import HelpGuide, { HELP } from '@/Components/HelpGuide';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import { Head, router, useForm } from '@inertiajs/react';
import { confirmAsk } from '@/Components/ConfirmProvider';

export default function Index({ workspace, funnels = [] }) {
    const form = useForm({
        name: '',
        headline: '',
        subheadline: '',
        cta_label: 'Get started',
        cta_url: '#lead',
        body_html: '',
        primary_color: '#0F766E',
        status: 'draft',
    });

    return (
        <AuthenticatedLayout
            header={
                <div>
                    <div className="text-xs font-semibold uppercase tracking-[0.16em] text-ink-muted">
                        {workspace.name}
                    </div>
                    <div className="flex items-center gap-1.5">
                        <h2 className="font-display text-2xl font-bold text-ink">Funnels</h2>
                        <HelpGuide help={HELP.funnels} />
                    </div>
                </div>
            }
        >
            <Head title="Funnels" />
            <div className="atlas-shell space-y-4">
                <form
                    className="atlas-panel grid gap-3 p-4 md:grid-cols-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(route('funnels.store'), {
                            onSuccess: () =>
                                form.reset('name', 'headline', 'subheadline', 'body_html'),
                        });
                    }}
                >
                    <div className="md:col-span-2">
                        <div className="flex items-center gap-1.5">
                            <h3 className="font-display text-lg font-bold text-ink">
                                New landing funnel
                            </h3>
                            <HelpGuide help={HELP.funnels} />
                        </div>
                        <p className="text-sm text-ink-muted">
                            Simple public page. Form leads go into CRM.
                        </p>
                    </div>
                    <div>
                        <InputLabel value="Name" />
                        <TextInput
                            className="mt-1.5 w-full"
                            value={form.data.name}
                            onChange={(e) => form.setData('name', e.target.value)}
                            required
                        />
                    </div>
                    <div>
                        <InputLabel value="Headline" />
                        <TextInput
                            className="mt-1.5 w-full"
                            value={form.data.headline}
                            onChange={(e) => form.setData('headline', e.target.value)}
                        />
                    </div>
                    <div className="md:col-span-2">
                        <InputLabel value="Subheadline" />
                        <TextInput
                            className="mt-1.5 w-full"
                            value={form.data.subheadline}
                            onChange={(e) => form.setData('subheadline', e.target.value)}
                        />
                    </div>
                    <div>
                        <InputLabel value="Primary color" />
                        <div className="mt-1.5">
                            <ColorPicker
                                value={form.data.primary_color}
                                onChange={(v) => form.setData('primary_color', v)}
                            />
                        </div>
                    </div>
                    <div>
                        <InputLabel value="Button text" />
                        <TextInput
                            className="mt-1.5 w-full"
                            value={form.data.cta_label}
                            onChange={(e) => form.setData('cta_label', e.target.value)}
                        />
                    </div>
                    <div className="md:col-span-2">
                        <PrimaryButton processing={form.processing}>Create funnel</PrimaryButton>
                    </div>
                </form>

                    <div className="atlas-panel overflow-hidden">
                        <div className="border-b border-line px-4 py-3 font-display text-lg font-bold text-ink">
                            Your funnels
                        </div>
                        <ul className="divide-y divide-line">
                            {funnels.length === 0 ? (
                                <li className="px-4 py-8 text-sm text-ink-muted">No funnels yet.</li>
                            ) : (
                                funnels.map((f) => (
                                    <li key={f.id} className="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                                        <div>
                                            <div className="font-semibold text-ink">{f.name}</div>
                                            <div className="text-xs text-ink-muted">
                                                {f.status} · /f/{f.slug} · {f.views} views · {f.leads} leads
                                            </div>
                                        </div>
                                        <div className="flex flex-wrap gap-2">
                                            {f.status !== 'published' ? (
                                                <button
                                                    type="button"
                                                    className="text-sm font-semibold text-signal-strong"
                                                    onClick={() =>
                                                        router.patch(route('funnels.update', f.id), {
                                                            status: 'published',
                                                        })
                                                    }
                                                >
                                                    Publish
                                                </button>
                                            ) : (
                                                <a
                                                    href={`/f/${f.slug}`}
                                                    target="_blank"
                                                    rel="noreferrer"
                                                    className="text-sm font-semibold text-signal-strong"
                                                >
                                                    Open
                                                </a>
                                            )}
                                            <button
                                                type="button"
                                                className="text-sm font-semibold text-rose-600"
                                                onClick={async () => {
                                                    const ok = await confirmAsk({
                                                        title: 'Delete this funnel?',
                                                        message: f.name
                                                            ? `“${f.name}” will be removed permanently.`
                                                            : 'This funnel will be removed permanently.',
                                                        confirmLabel: 'Delete funnel',
                                                    });
                                                    if (ok) {
                                                        router.delete(route('funnels.destroy', f.id));
                                                    }
                                                }}
                                            >
                                                Delete
                                            </button>
                                        </div>
                                    </li>
                                ))
                            )}
                        </ul>
                    </div>
                </div>
        </AuthenticatedLayout>
    );
}
