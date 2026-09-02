import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import HelpGuide, { HELP } from '@/Components/HelpGuide';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SelectMenu from '@/Components/SelectMenu';
import TextInput from '@/Components/TextInput';
import { confirmAsk } from '@/Components/ConfirmProvider';
import { Head, router, useForm, usePage } from '@inertiajs/react';

const roleTone = {
    owner: 'bg-ink text-white',
    admin: 'bg-signal-soft text-signal-strong',
    editor: 'bg-mist-deep text-ink',
    viewer: 'bg-white text-ink-muted border border-line',
};

export default function Index({ workspaces, activeWorkspace, members, roles, onboarding = false }) {
    const { auth } = usePage().props;
    const createForm = useForm({ name: '' });
    const inviteForm = useForm({ email: '', role: 'editor' });

    const canManage =
        activeWorkspace?.role === 'owner' || activeWorkspace?.role === 'admin';

    const inviteRoles = roles.filter((role) => role !== 'owner');

    return (
        <AuthenticatedLayout
            header={
                <div>
                    <div className="text-xs font-semibold uppercase tracking-[0.18em] text-ink-muted">
                        {onboarding ? 'Get started' : 'Team space'}
                    </div>
                    <div className="flex items-center gap-1.5">
                        <h2 className="font-display text-2xl font-bold tracking-tight text-ink">
                            {onboarding ? 'Create your first workspace' : 'Workspaces'}
                        </h2>
                        {!onboarding ? <HelpGuide help={HELP.workspaces} /> : null}
                    </div>
                </div>
            }
        >
            <Head title={onboarding ? 'Create workspace' : 'Workspaces'} />

            <div className="atlas-shell space-y-6 stagger">
                    <section className="atlas-panel overflow-hidden">
                        <div className="border-b border-line/70 bg-gradient-to-r from-signal-soft/60 to-transparent px-6 py-5">
                            <div className="flex items-center gap-1.5">
                                <h3 className="font-display text-lg font-bold text-ink">
                                    Active workspace
                                </h3>
                                <HelpGuide help={HELP.workspaces} />
                            </div>
                            <p className="mt-1 text-sm text-ink-muted">
                                Switch context instantly. Every module scopes to this workspace.
                            </p>
                        </div>

                        <div className="space-y-6 p-6">
                            {workspaces.length === 0 ? (
                                <div className="rounded-md border border-dashed border-line bg-mist/50 px-5 py-8 text-center">
                                    <p className="font-display text-xl font-semibold text-ink">
                                        Welcome — add your brand or company
                                    </p>
                                    <p className="mt-2 text-sm text-ink-muted">
                                        Pick a workspace name below. You choose how many brands to add — nothing is created from your personal name.
                                    </p>
                                </div>
                            ) : (
                                <div className="grid gap-3 sm:grid-cols-2">
                                    {workspaces.map((workspace) => {
                                        const active = activeWorkspace?.id === workspace.id;
                                        return (
                                            <button
                                                key={workspace.id}
                                                type="button"
                                                onClick={() =>
                                                    router.post(route('workspaces.switch', workspace.id), {}, {
                                                        preserveState: false,
                                                    })
                                                }
                                                className={
                                                    'group rounded-md border px-4 py-4 text-left transition duration-200 ' +
                                                    (active
                                                        ? 'border-signal bg-signal-soft/50 shadow-lift'
                                                        : 'border-line bg-white hover:-translate-y-0.5 hover:border-signal/40 hover:shadow-panel')
                                                }
                                            >
                                                <div className="flex items-start justify-between gap-3">
                                                    <div>
                                                        <div className="font-semibold text-ink">
                                                            {workspace.name}
                                                        </div>
                                                        <div className="mt-1 text-xs text-ink-muted">
                                                            /{workspace.slug}
                                                        </div>
                                                    </div>
                                                    <span
                                                        className={
                                                            'rounded-lg px-2 py-1 text-[11px] font-semibold capitalize ' +
                                                            (roleTone[workspace.role] || roleTone.viewer)
                                                        }
                                                    >
                                                        {workspace.role}
                                                    </span>
                                                </div>
                                                {active ? (
                                                    <div className="mt-3 text-xs font-semibold uppercase tracking-[0.14em] text-signal-strong">
                                                        Selected
                                                    </div>
                                                ) : (
                                                    <div className="mt-3 text-xs font-medium text-ink-muted opacity-0 transition group-hover:opacity-100">
                                                        Switch to this workspace
                                                    </div>
                                                )}
                                            </button>
                                        );
                                    })}
                                </div>
                            )}

                            <form
                                className="grid gap-3 rounded-md border border-line bg-mist/40 p-4 sm:grid-cols-[1fr_auto] sm:items-end"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    createForm.post(route('workspaces.store'), {
                                        onSuccess: () => createForm.reset(),
                                    });
                                }}
                            >
                                <div>
                                    <InputLabel
                                        htmlFor="name"
                                        value={onboarding ? 'Workspace name' : 'New workspace'}
                                    />
                                    <TextInput
                                        id="name"
                                        className="mt-1.5 block w-full"
                                        value={createForm.data.name}
                                        onChange={(e) =>
                                            createForm.setData('name', e.target.value)
                                        }
                                        placeholder="Company or brand name"
                                        required
                                    />
                                    <InputError
                                        className="mt-2"
                                        message={createForm.errors.name}
                                    />
                                </div>
                                <PrimaryButton processing={createForm.processing}>
                                    {onboarding ? 'Create workspace and continue' : 'Create workspace'}
                                </PrimaryButton>
                            </form>
                        </div>
                    </section>

                    {activeWorkspace ? (
                        <section className="atlas-panel overflow-hidden">
                            <div className="flex flex-wrap items-end justify-between gap-3 border-b border-line/70 px-6 py-5">
                                <div>
                                    <h3 className="font-display text-lg font-bold text-ink">
                                        Members
                                    </h3>
                                    <p className="mt-1 text-sm text-ink-muted">
                                        People who can work on{' '}
                                        <span className="font-semibold text-ink">
                                            {activeWorkspace.name}
                                        </span>
                                        . Change roles below.
                                    </p>
                                </div>
                                <div className="rounded-md bg-mist px-3 py-1.5 text-xs font-semibold text-ink-muted">
                                    {members.length}{' '}
                                    {members.length === 1 ? 'person' : 'people'}
                                </div>
                            </div>

                            <div className="space-y-4 p-4 sm:p-5">
                                {canManage ? (
                                    <form
                                        className="space-y-2 rounded-md border border-line bg-mist/40 p-3"
                                        onSubmit={(e) => {
                                            e.preventDefault();
                                            inviteForm.post(
                                                route(
                                                    'workspaces.members.store',
                                                    activeWorkspace.id,
                                                ),
                                                {
                                                    onSuccess: () =>
                                                        inviteForm.reset('email'),
                                                },
                                            );
                                        }}
                                    >
                                        <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
                                            <div className="min-w-0 flex-1">
                                                <InputLabel
                                                    htmlFor="email"
                                                    value="Add member by email"
                                                />
                                                <TextInput
                                                    id="email"
                                                    type="email"
                                                    className="mt-1.5 block w-full"
                                                    value={inviteForm.data.email}
                                                    onChange={(e) =>
                                                        inviteForm.setData(
                                                            'email',
                                                            e.target.value,
                                                        )
                                                    }
                                                    required
                                                />
                                            </div>
                                            <div className="w-full shrink-0 sm:w-36">
                                                <InputLabel htmlFor="role" value="Role" />
                                                <div className="mt-1.5">
                                                    <SelectMenu
                                                        value={inviteForm.data.role}
                                                        onChange={(role) =>
                                                            inviteForm.setData('role', role)
                                                        }
                                                        buttonClassName="!py-2"
                                                        options={inviteRoles.map((role) => ({
                                                            value: role,
                                                            label: role,
                                                        }))}
                                                    />
                                                </div>
                                            </div>
                                            <PrimaryButton
                                                className="h-[38px] w-full shrink-0 sm:w-auto"
                                                processing={inviteForm.processing}
                                            >
                                                Add member
                                            </PrimaryButton>
                                        </div>
                                        <InputError message={inviteForm.errors.email} />
                                    </form>
                                ) : null}

                                <div className="overflow-hidden rounded-md border border-line">
                                    <div className="grid grid-cols-[1fr_auto] gap-2 border-b border-line bg-mist/50 px-3 py-1.5 text-[10px] font-semibold uppercase tracking-wide text-ink-muted sm:grid-cols-[1.4fr_1fr_auto]">
                                        <span>Name</span>
                                        <span className="hidden sm:inline">Email</span>
                                        <span className="text-right">Role</span>
                                    </div>
                                    <ul className="divide-y divide-line">
                                        {members.map((member) => (
                                            <li
                                                key={member.id}
                                                className="grid grid-cols-[1fr_auto] items-center gap-2 px-3 py-2 sm:grid-cols-[1.4fr_1fr_auto]"
                                            >
                                                <div className="min-w-0">
                                                    <div className="truncate text-sm font-semibold text-ink">
                                                        {member.name}
                                                        {member.id === auth.user.id ? (
                                                            <span className="ms-1.5 text-[11px] font-medium text-ink-muted">
                                                                (you)
                                                            </span>
                                                        ) : null}
                                                    </div>
                                                    <div className="truncate text-[11px] text-ink-muted sm:hidden">
                                                        {member.email}
                                                    </div>
                                                </div>
                                                <div className="hidden min-w-0 truncate text-sm text-ink-muted sm:block">
                                                    {member.email}
                                                </div>
                                                <div className="flex items-center justify-end gap-1.5">
                                                    {canManage ? (
                                                        <SelectMenu
                                                            className="w-auto min-w-[7.5rem]"
                                                            buttonClassName="!py-1.5 text-sm"
                                                            value={member.role}
                                                            disabled={
                                                                member.id === auth.user.id &&
                                                                member.role === 'owner'
                                                            }
                                                            onChange={(role) =>
                                                                router.patch(
                                                                    route(
                                                                        'workspaces.members.update',
                                                                        [
                                                                            activeWorkspace.id,
                                                                            member.id,
                                                                        ],
                                                                    ),
                                                                    { role },
                                                                    { preserveScroll: true },
                                                                )
                                                            }
                                                            options={roles.map((role) => ({
                                                                value: role,
                                                                label: role,
                                                            }))}
                                                        />
                                                    ) : (
                                                        <span
                                                            className={
                                                                'rounded px-2 py-0.5 text-[11px] font-semibold capitalize ' +
                                                                (roleTone[member.role] ||
                                                                    roleTone.viewer)
                                                            }
                                                        >
                                                            {member.role}
                                                        </span>
                                                    )}
                                                    {canManage &&
                                                    member.id !== auth.user.id ? (
                                                        <button
                                                            type="button"
                                                            className="text-xs font-semibold text-rose-600 hover:underline"
                                                            onClick={async () => {
                                                                const ok = await confirmAsk({
                                                                    title: 'Remove member?',
                                                                    message: member.name
                                                                        ? `Remove “${member.name}” from this workspace?`
                                                                        : 'Remove this member from the workspace?',
                                                                    confirmLabel: 'Remove',
                                                                });
                                                                if (ok) {
                                                                    router.delete(
                                                                        route(
                                                                            'workspaces.members.destroy',
                                                                            [
                                                                                activeWorkspace.id,
                                                                                member.id,
                                                                            ],
                                                                        ),
                                                                        { preserveScroll: true },
                                                                    );
                                                                }
                                                            }}
                                                        >
                                                            Remove
                                                        </button>
                                                    ) : null}
                                                </div>
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            </div>
                        </section>
                    ) : null}
            </div>
        </AuthenticatedLayout>
    );
}
