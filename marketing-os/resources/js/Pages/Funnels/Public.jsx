import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import { AppFeedback } from '@/Components/ToastProvider';
import { Head, useForm } from '@inertiajs/react';

export default function Public({ funnel }) {
    const form = useForm({
        name: '',
        email: '',
        phone: '',
    });
    const color = funnel.primary_color || '#0F766E';

    return (
        <div
            className="min-h-screen"
            style={{
                background: `linear-gradient(160deg, ${color}18 0%, #fff 45%, ${color}10 100%)`,
            }}
        >
            <AppFeedback />
            <Head title={funnel.headline || funnel.name} />
            <main className="mx-auto flex min-h-screen max-w-3xl flex-col justify-center px-6 py-16">
                <div
                    className="font-display text-sm font-semibold uppercase tracking-[0.2em]"
                    style={{ color }}
                >
                    {funnel.name}
                </div>
                <h1 className="mt-4 font-display text-4xl font-bold leading-tight text-ink sm:text-5xl">
                    {funnel.headline || funnel.name}
                </h1>
                {funnel.subheadline ? (
                    <p className="mt-4 text-lg text-ink-muted">{funnel.subheadline}</p>
                ) : null}

                <form
                    id="lead"
                    className="mt-10 space-y-3"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(route('funnels.lead', funnel.slug), {
                            onSuccess: () => form.reset(),
                        });
                    }}
                >
                    <TextInput
                        className="w-full"
                        placeholder="Name"
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                    />
                    <TextInput
                        type="email"
                        className="w-full"
                        placeholder="Email"
                        value={form.data.email}
                        onChange={(e) => form.setData('email', e.target.value)}
                        required
                    />
                    <TextInput
                        className="w-full"
                        placeholder="Phone"
                        value={form.data.phone}
                        onChange={(e) => form.setData('phone', e.target.value)}
                    />
                    <PrimaryButton
                        processing={form.processing}
                        style={{ background: color }}
                        className="w-full justify-center"
                    >
                        {funnel.cta_label || 'Get started'}
                    </PrimaryButton>
                </form>
            </main>
        </div>
    );
}
