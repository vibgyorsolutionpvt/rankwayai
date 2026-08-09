import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import Toggle from '@/Components/Toggle';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, useForm } from '@inertiajs/react';

export default function Login({ status, canResetPassword }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    const submit = (e) => {
        e.preventDefault();

        post(route('login'), {
            onFinish: () => reset('password'),
        });
    };

    return (
        <GuestLayout title="Welcome back" subtitle="Log in to your RankwayAI workspace.">
            <Head title="Log in — RankwayAI">
                <meta head-key="robots" name="robots" content="noindex, nofollow" />
            </Head>

            {status && (
                <div className="mb-4 rounded-md bg-signal-soft px-3 py-2 text-sm font-medium text-signal-strong">
                    {status}
                </div>
            )}

            <form onSubmit={submit} className="space-y-4">
                <div>
                    <InputLabel htmlFor="email" value="Email" />
                    <TextInput
                        id="email"
                        type="email"
                        name="email"
                        value={data.email}
                        className="mt-1.5 block w-full"
                        autoComplete="username"
                        isFocused={true}
                        onChange={(e) => setData('email', e.target.value)}
                    />
                    <InputError message={errors.email} className="mt-2" />
                </div>

                <div>
                    <InputLabel htmlFor="password" value="Password" />
                    <TextInput
                        id="password"
                        type="password"
                        name="password"
                        value={data.password}
                        className="mt-1.5 block w-full"
                        autoComplete="current-password"
                        onChange={(e) => setData('password', e.target.value)}
                    />
                    <InputError message={errors.password} className="mt-2" />
                </div>

                <div className="flex items-center justify-between gap-3">
                    <Toggle
                        checked={!!data.remember}
                        onChange={(v) => setData('remember', v)}
                        label="Remember me"
                    />
                    {canResetPassword && (
                        <Link
                            href={route('password.request')}
                            className="text-sm font-medium text-signal-strong hover:text-signal"
                        >
                            Forgot password?
                        </Link>
                    )}
                </div>

                <PrimaryButton className="w-full" processing={processing}>
                    Log in
                </PrimaryButton>

                <p className="text-center text-sm text-ink-muted">
                    New here?{' '}
                    <Link href={route('register')} className="font-semibold text-ink hover:text-signal-strong">
                        Create account
                    </Link>
                </p>
            </form>
        </GuestLayout>
    );
}
