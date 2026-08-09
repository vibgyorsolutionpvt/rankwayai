import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import { Head, useForm } from '@inertiajs/react';

export default function System({ settings, runtime }) {
    const form = useForm({
        contact_email: settings.contact_email || '',
        contact_phone: settings.contact_phone || '',
    });

    const submit = (e) => {
        e.preventDefault();
        form.patch(route('admin.system.update'), { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout
            header={
                <div>
                    <div className="text-xs font-semibold uppercase tracking-[0.18em] text-ink-muted">
                        Super admin
                    </div>
                    <h2 className="font-display text-2xl font-bold tracking-tight text-ink">
                        System
                    </h2>
                </div>
            }
        >
            <Head title="Admin · System" />

            <div className="atlas-shell grid gap-6 lg:grid-cols-2">
                <section className="atlas-panel p-6">
                    <h3 className="font-display text-lg font-bold text-ink">Public contact</h3>
                    <p className="mt-1 text-sm text-ink-muted">
                        Shown on marketing Contact page and used for contact form delivery.
                    </p>
                    <form onSubmit={submit} className="mt-5 space-y-4">
                        <div>
                            <InputLabel htmlFor="contact_email" value="Contact email" />
                            <TextInput
                                id="contact_email"
                                type="email"
                                className="mt-1 w-full"
                                value={form.data.contact_email}
                                onChange={(e) => form.setData('contact_email', e.target.value)}
                                required
                            />
                            <InputError message={form.errors.contact_email} className="mt-2" />
                        </div>
                        <div>
                            <InputLabel htmlFor="contact_phone" value="Contact phone" />
                            <TextInput
                                id="contact_phone"
                                className="mt-1 w-full"
                                value={form.data.contact_phone}
                                onChange={(e) => form.setData('contact_phone', e.target.value)}
                                required
                            />
                            <InputError message={form.errors.contact_phone} className="mt-2" />
                        </div>
                        <PrimaryButton processing={form.processing}>Save settings</PrimaryButton>
                    </form>
                </section>

                <section className="atlas-panel p-6">
                    <h3 className="font-display text-lg font-bold text-ink">Runtime</h3>
                    <p className="mt-1 text-sm text-ink-muted">Read-only environment snapshot.</p>
                    <dl className="mt-5 space-y-3 text-sm">
                        {Object.entries(runtime || {}).map(([key, value]) => (
                            <div
                                key={key}
                                className="flex items-start justify-between gap-4 border-b border-line/60 pb-2"
                            >
                                <dt className="font-semibold uppercase tracking-wide text-ink-muted">
                                    {key.replaceAll('_', ' ')}
                                </dt>
                                <dd className="text-right font-medium text-ink">{String(value)}</dd>
                            </div>
                        ))}
                    </dl>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
