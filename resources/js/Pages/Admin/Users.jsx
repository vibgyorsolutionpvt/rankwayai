import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import Toggle from '@/Components/Toggle';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

export default function Users({ stats, users, filters = {} }) {
    const { auth } = usePage().props;
    const [showCreate, setShowCreate] = useState(false);
    const createForm = useForm({
        name: '',
        email: '',
        password: '',
        workspace_name: '',
    });

    const search = (e) => {
        e.preventDefault();
        const q = new FormData(e.target).get('q') || '';
        router.get(route('admin.users'), { q }, { preserveState: true, replace: true });
    };

    const submitCreate = (e) => {
        e.preventDefault();
        createForm.post(route('admin.users.store'), {
            preserveScroll: true,
            onSuccess: () => {
                createForm.reset();
                setShowCreate(false);
            },
        });
    };

    return (
        <AuthenticatedLayout
            header={
                <div>
                    <div className="text-xs font-semibold uppercase tracking-[0.18em] text-ink-muted">
                        Super admin
                    </div>
                    <h2 className="font-display text-2xl font-bold tracking-tight text-ink">Users</h2>
                </div>
            }
        >
            <Head title="Admin · Users" />

            <div className="atlas-shell space-y-6">
                <section className="flex flex-wrap items-end justify-between gap-4">
                    <form onSubmit={search} className="flex flex-wrap items-end gap-2">
                        <div>
                            <InputLabel htmlFor="q" value="Search" />
                            <TextInput
                                id="q"
                                name="q"
                                defaultValue={filters.q || ''}
                                placeholder="Name or email"
                                className="mt-1 w-64"
                            />
                        </div>
                        <SecondaryButton type="submit">Search</SecondaryButton>
                    </form>
                    <PrimaryButton type="button" onClick={() => setShowCreate((v) => !v)}>
                        {showCreate ? 'Close' : 'Add user'}
                    </PrimaryButton>
                </section>

                {showCreate ? (
                    <section className="atlas-panel p-6">
                        <h3 className="font-display text-lg font-bold text-ink">Create client account</h3>
                        <p className="mt-1 text-sm text-ink-muted">
                            Creates a client with their own workspace (free plan). Super admin cannot
                            be assigned here.
                        </p>
                        <form onSubmit={submitCreate} className="mt-4 grid gap-4 sm:grid-cols-2">
                            <div>
                                <InputLabel htmlFor="name" value="Name" />
                                <TextInput
                                    id="name"
                                    className="mt-1 w-full"
                                    value={createForm.data.name}
                                    onChange={(e) => createForm.setData('name', e.target.value)}
                                    required
                                />
                                <InputError message={createForm.errors.name} className="mt-2" />
                            </div>
                            <div>
                                <InputLabel htmlFor="email" value="Email" />
                                <TextInput
                                    id="email"
                                    type="email"
                                    className="mt-1 w-full"
                                    value={createForm.data.email}
                                    onChange={(e) => createForm.setData('email', e.target.value)}
                                    required
                                />
                                <InputError message={createForm.errors.email} className="mt-2" />
                            </div>
                            <div>
                                <InputLabel htmlFor="password" value="Password" />
                                <TextInput
                                    id="password"
                                    type="password"
                                    className="mt-1 w-full"
                                    value={createForm.data.password}
                                    onChange={(e) => createForm.setData('password', e.target.value)}
                                    required
                                />
                                <InputError message={createForm.errors.password} className="mt-2" />
                            </div>
                            <div>
                                <InputLabel
                                    htmlFor="workspace_name"
                                    value="Workspace name (optional)"
                                />
                                <TextInput
                                    id="workspace_name"
                                    className="mt-1 w-full"
                                    value={createForm.data.workspace_name}
                                    onChange={(e) =>
                                        createForm.setData('workspace_name', e.target.value)
                                    }
                                    placeholder="Defaults to Name's workspace"
                                />
                                <InputError
                                    message={createForm.errors.workspace_name}
                                    className="mt-2"
                                />
                            </div>
                            <div className="flex items-end sm:col-span-2">
                                <PrimaryButton processing={createForm.processing}>
                                    Create user
                                </PrimaryButton>
                            </div>
                        </form>
                    </section>
                ) : null}

                <section className="atlas-panel overflow-hidden">
                    <div className="border-b border-line/70 px-6 py-5">
                        <h3 className="font-display text-lg font-bold text-ink">All accounts</h3>
                        <p className="mt-1 text-sm text-ink-muted">
                            {stats.users} users · {stats.active_users ?? '—'} active · {stats.clients}{' '}
                            clients
                        </p>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-left text-sm">
                            <thead className="bg-mist/80 text-ink-muted">
                                <tr>
                                    <th className="px-6 py-3 font-semibold">Name</th>
                                    <th className="px-6 py-3 font-semibold">Email</th>
                                    <th className="px-6 py-3 font-semibold">Workspaces</th>
                                    <th className="px-6 py-3 font-semibold">Joined</th>
                                    <th className="px-6 py-3 font-semibold">Role</th>
                                    <th className="px-6 py-3 font-semibold">Active</th>
                                </tr>
                            </thead>
                            <tbody>
                                {users.data.map((user) => (
                                    <tr key={user.id} className="border-t border-line/70">
                                        <td className="px-6 py-3 font-semibold text-ink">
                                            {user.name}
                                            {user.id === auth.user?.id ? (
                                                <span className="ml-2 text-[10px] font-semibold uppercase text-ink-muted">
                                                    you
                                                </span>
                                            ) : null}
                                        </td>
                                        <td className="px-6 py-3 text-ink-muted">{user.email}</td>
                                        <td className="px-6 py-3 text-ink">{user.workspaces_count}</td>
                                        <td className="px-6 py-3 text-ink-muted">{user.created_at}</td>
                                        <td className="px-6 py-3">
                                            <span
                                                className={
                                                    'rounded-md px-2.5 py-1 text-xs font-semibold ' +
                                                    (user.is_superadmin
                                                        ? 'bg-ink text-white'
                                                        : 'bg-signal-soft text-signal-strong')
                                                }
                                            >
                                                {user.is_superadmin ? 'super admin' : 'client'}
                                            </span>
                                        </td>
                                        <td className="px-6 py-3">
                                            {user.is_superadmin ? (
                                                <span className="text-xs font-semibold text-ink-muted">
                                                    —
                                                </span>
                                            ) : (
                                                <Toggle
                                                    checked={user.is_active}
                                                    onChange={(is_active) =>
                                                        router.patch(
                                                            route('admin.users.update', user.id),
                                                            { is_active },
                                                            { preserveScroll: true },
                                                        )
                                                    }
                                                />
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    {users.links?.length > 3 ? (
                        <div className="flex flex-wrap gap-2 border-t border-line/70 px-6 py-4">
                            {users.links.map((link, i) => (
                                <button
                                    key={i}
                                    type="button"
                                    disabled={!link.url}
                                    onClick={() => link.url && router.get(link.url)}
                                    className={
                                        'rounded-md px-2.5 py-1 text-xs font-semibold ' +
                                        (link.active
                                            ? 'bg-ink text-white'
                                            : 'border border-line text-ink-muted disabled:opacity-40')
                                    }
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                />
                            ))}
                        </div>
                    ) : null}
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
