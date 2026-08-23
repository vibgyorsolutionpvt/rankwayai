import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SelectMenu from '@/Components/SelectMenu';
import TextInput from '@/Components/TextInput';
import Toggle from '@/Components/Toggle';
import { moduleTone, toneForModule } from '@/Components/moduleTones';
import { confirmAsk } from '@/Components/ConfirmProvider';
import { router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';

const roleTone = {
    owner: 'bg-ink text-white',
    admin: 'bg-signal-soft text-signal-strong',
    editor: 'bg-mist-deep text-ink',
    viewer: 'bg-white text-ink-muted border border-line',
};

export default function WorkspacePanel({
    workspaces = [],
    activeWorkspace,
    members = [],
    roles = [],
    moduleCatalog = null,
    socialPlatformCatalog = null,
}) {
    const { auth } = usePage().props;
    const createForm = useForm({ name: '' });
    const profileForm = useForm({
        industry: activeWorkspace?.industry || '',
        city: activeWorkspace?.city || '',
        phone: activeWorkspace?.phone || '',
        email: activeWorkspace?.email || '',
        website: activeWorkspace?.website || '',
    });
    const inviteForm = useForm({ email: '', name: '', role: 'editor' });
    const [editingMemberId, setEditingMemberId] = useState(null);

    const canManage =
        activeWorkspace?.role === 'owner' || activeWorkspace?.role === 'admin';
    const inviteRoles = roles.filter((role) => role !== 'owner');

    useEffect(() => {
        profileForm.setData({
            industry: activeWorkspace?.industry || '',
            city: activeWorkspace?.city || '',
            phone: activeWorkspace?.phone || '',
            email: activeWorkspace?.email || '',
            website: activeWorkspace?.website || '',
        });
        profileForm.clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [activeWorkspace?.id]);

    const workspaceSelected = useMemo(() => {
        if (!moduleCatalog) return [];
        if (moduleCatalog.workspace_modules === null || moduleCatalog.workspace_modules === undefined) {
            return moduleCatalog.items.filter((i) => i.globally_enabled).map((i) => i.key);
        }
        return moduleCatalog.workspace_modules;
    }, [moduleCatalog]);

    const socialSelected = useMemo(() => {
        if (!socialPlatformCatalog) return [];
        if (
            socialPlatformCatalog.workspace_platforms === null ||
            socialPlatformCatalog.workspace_platforms === undefined
        ) {
            return socialPlatformCatalog.items
                .filter((i) => i.globally_enabled !== false)
                .map((i) => i.key);
        }
        return socialPlatformCatalog.workspace_platforms;
    }, [socialPlatformCatalog]);

    const saveWorkspaceModules = (nextKeys) => {
        if (!activeWorkspace) return;
        router.put(
            route('workspaces.modules.update', activeWorkspace.id),
            { modules: nextKeys, inherit_all: false },
            { preserveScroll: true },
        );
    };

    const toggleWorkspaceModule = (key, enabled) => {
        const set = new Set(workspaceSelected);
        if (enabled) set.add(key);
        else set.delete(key);
        saveWorkspaceModules([...set]);
    };

    const saveSocialPlatforms = (nextKeys) => {
        if (!activeWorkspace) return;
        router.put(
            route('workspaces.social-platforms.update', activeWorkspace.id),
            { platforms: nextKeys, inherit_all: false },
            { preserveScroll: true },
        );
    };

    const toggleSocialPlatform = (key, enabled) => {
        const set = new Set(socialSelected);
        if (enabled) set.add(key);
        else set.delete(key);
        saveSocialPlatforms([...set]);
    };

    const saveMemberModules = (memberId, nextKeys, inheritAll = false) => {
        router.put(
            route('workspaces.members.modules.update', [activeWorkspace.id, memberId]),
            inheritAll ? { inherit_all: true } : { modules: nextKeys, inherit_all: false },
            { preserveScroll: true },
        );
    };

    return (
        <div className="space-y-4">
            <section className="atlas-panel overflow-hidden">
                <div className="border-b border-line px-4 py-3.5">
                    <h3 className="font-display text-base font-bold text-ink">Active workspace</h3>
                    <p className="mt-0.5 text-sm text-ink-muted">
                        Switch context. Every module scopes to this workspace.
                    </p>
                </div>

                <div className="space-y-4 p-4">
                    {workspaces.length === 0 ? (
                        <div className="rounded-md border border-dashed border-line bg-mist/50 px-5 py-8 text-center">
                            <p className="font-display text-lg font-semibold text-ink">
                                Create your first workspace
                            </p>
                            <p className="mt-1 text-sm text-ink-muted">
                                A company or brand name. You become the owner.
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
                                            router.post(route('workspaces.switch', workspace.id))
                                        }
                                        className={
                                            'rounded-md border px-4 py-3.5 text-left transition ' +
                                            (active
                                                ? 'border-signal bg-signal-soft/50 shadow-lift'
                                                : 'border-line bg-white hover:border-signal/40')
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
                                            <div className="mt-2 text-xs font-semibold uppercase tracking-wide text-signal-strong">
                                                Selected
                                            </div>
                                        ) : null}
                                    </button>
                                );
                            })}
                        </div>
                    )}

                    {activeWorkspace && canManage ? (
                        <form
                            className="rounded-md border border-line bg-white p-4"
                            onSubmit={(e) => {
                                e.preventDefault();
                                profileForm.patch(
                                    route('workspaces.profile.update', activeWorkspace.id),
                                    { preserveScroll: true },
                                );
                            }}
                        >
                            <div className="font-display text-sm font-bold text-ink">
                                Business profile
                            </div>
                            <p className="mt-0.5 text-xs text-ink-muted">
                                Used by AI posts — business type, city, and contact details.
                            </p>
                            <div className="mt-3 grid gap-3 sm:grid-cols-2">
                                <div>
                                    <InputLabel value="Business type *" />
                                    <TextInput
                                        className="mt-1 w-full"
                                        placeholder="Travel agency, IT company…"
                                        value={profileForm.data.industry}
                                        onChange={(e) =>
                                            profileForm.setData('industry', e.target.value)
                                        }
                                        required
                                    />
                                    <InputError
                                        message={profileForm.errors.industry}
                                        className="mt-1"
                                    />
                                </div>
                                <div>
                                    <InputLabel value="City *" />
                                    <TextInput
                                        className="mt-1 w-full"
                                        placeholder="Lucknow, Mumbai…"
                                        value={profileForm.data.city}
                                        onChange={(e) => profileForm.setData('city', e.target.value)}
                                        required
                                    />
                                    <InputError message={profileForm.errors.city} className="mt-1" />
                                </div>
                                <div>
                                    <InputLabel value="Mobile / WhatsApp" />
                                    <TextInput
                                        className="mt-1 w-full"
                                        placeholder="+91 98XXXX XXXX"
                                        value={profileForm.data.phone}
                                        onChange={(e) =>
                                            profileForm.setData('phone', e.target.value)
                                        }
                                    />
                                    <InputError message={profileForm.errors.phone} className="mt-1" />
                                </div>
                                <div>
                                    <InputLabel value="Email" />
                                    <TextInput
                                        className="mt-1 w-full"
                                        type="email"
                                        placeholder="hello@yourbusiness.com"
                                        value={profileForm.data.email}
                                        onChange={(e) =>
                                            profileForm.setData('email', e.target.value)
                                        }
                                    />
                                    <InputError message={profileForm.errors.email} className="mt-1" />
                                </div>
                                <div className="sm:col-span-2">
                                    <InputLabel value="Website" />
                                    <TextInput
                                        className="mt-1 w-full"
                                        placeholder="https://yourbusiness.com"
                                        value={profileForm.data.website}
                                        onChange={(e) =>
                                            profileForm.setData('website', e.target.value)
                                        }
                                    />
                                    <InputError message={profileForm.errors.website} className="mt-1" />
                                </div>
                            </div>
                            <PrimaryButton className="mt-3" processing={profileForm.processing}>
                                Save profile
                            </PrimaryButton>
                        </form>
                    ) : null}

                    <form
                        className="rounded-md border border-line bg-mist/40 p-3"
                        onSubmit={(e) => {
                            e.preventDefault();
                            createForm.post(route('workspaces.store'), {
                                onSuccess: () => createForm.reset(),
                            });
                        }}
                    >
                        <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
                            <div className="min-w-0 flex-1">
                                <InputLabel htmlFor="settings-ws-name" value="New workspace" />
                                <TextInput
                                    id="settings-ws-name"
                                    className="mt-1.5 block w-full"
                                    value={createForm.data.name}
                                    onChange={(e) => createForm.setData('name', e.target.value)}
                                    placeholder="Company or brand name"
                                    required
                                />
                            </div>
                            <PrimaryButton
                                processing={createForm.processing}
                                className="w-full shrink-0 sm:w-auto"
                            >
                                Create workspace
                            </PrimaryButton>
                        </div>
                        <InputError className="mt-2" message={createForm.errors.name} />
                    </form>
                </div>
            </section>

            {activeWorkspace && moduleCatalog && canManage ? (
                <section className="atlas-panel overflow-hidden">
                    <div className="border-b border-line px-4 py-3.5">
                        <h3 className="font-display text-base font-bold text-ink">
                            Workspace modules
                        </h3>
                        <p className="mt-0.5 text-sm text-ink-muted">
                            Choose which menus this workspace can use. Items turned off by platform
                            admin stay locked.
                        </p>
                    </div>
                    <div className="grid gap-2 p-4 sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5">
                        {moduleCatalog.items.map((item) => {
                            const on = workspaceSelected.includes(item.key);
                            const locked = !item.globally_enabled;
                            const tone = moduleTone(toneForModule(item));
                            return (
                                <div
                                    key={item.key}
                                    className={
                                        'flex items-center justify-between gap-2 rounded-lg border px-2.5 py-2 shadow-sm transition ' +
                                        (locked || !on ? tone.off : tone.card)
                                    }
                                >
                                    <div className="min-w-0">
                                        <span
                                            className={
                                                'inline-flex truncate rounded px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide ' +
                                                tone.chip
                                            }
                                        >
                                            {item.label}
                                        </span>
                                        {locked ? (
                                            <div className="mt-0.5 text-[9px] font-medium text-rose-600">
                                                Locked
                                            </div>
                                        ) : null}
                                    </div>
                                    <div className="flex shrink-0 flex-col items-center gap-0.5">
                                        <Toggle
                                            checked={on && !locked}
                                            disabled={locked}
                                            onChange={(enabled) =>
                                                toggleWorkspaceModule(item.key, enabled)
                                            }
                                        />
                                        <span className="text-[9px] font-semibold uppercase tracking-wide text-ink-muted">
                                            {locked ? 'Off' : on ? 'Show' : 'Hide'}
                                        </span>
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </section>
            ) : null}

            {activeWorkspace && socialPlatformCatalog && canManage ? (
                <section className="atlas-panel overflow-hidden">
                    <div className="border-b border-line px-4 py-3.5">
                        <h3 className="font-display text-base font-bold text-ink">
                            SMM platforms
                        </h3>
                        <p className="mt-0.5 text-sm text-ink-muted">
                            Choose which social networks appear in Connect / Compose. Items turned
                            off by platform admin stay locked.
                        </p>
                    </div>
                    <div className="grid gap-2 p-4 sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-5">
                        {socialPlatformCatalog.items.map((item) => {
                            const on = socialSelected.includes(item.key);
                            const locked = item.globally_enabled === false;
                            const tone = moduleTone(item.tone || 'ink');
                            return (
                                <div
                                    key={item.key}
                                    className={
                                        'flex items-center justify-between gap-2 rounded-lg border px-2.5 py-2 shadow-sm transition ' +
                                        (locked || !on ? tone.off : tone.card)
                                    }
                                >
                                    <div className="min-w-0 shrink-0">
                                        <span
                                            className={
                                                'inline-flex rounded px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide ' +
                                                tone.chip
                                            }
                                        >
                                            {item.label}
                                        </span>
                                        {locked ? (
                                            <div className="mt-0.5 text-[9px] font-medium text-rose-600">
                                                Locked
                                            </div>
                                        ) : null}
                                    </div>
                                    <div className="flex shrink-0 flex-col items-center gap-0.5">
                                        <Toggle
                                            checked={on && !locked}
                                            disabled={locked}
                                            onChange={(enabled) =>
                                                toggleSocialPlatform(item.key, enabled)
                                            }
                                        />
                                        <span className="text-[9px] font-semibold uppercase tracking-wide text-ink-muted">
                                            {locked ? 'Off' : on ? 'Show' : 'Hide'}
                                        </span>
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </section>
            ) : null}

            {activeWorkspace ? (
                <section className="atlas-panel overflow-hidden">
                    <div className="flex flex-wrap items-end justify-between gap-3 border-b border-line px-4 py-3.5">
                        <div>
                            <h3 className="font-display text-base font-bold text-ink">Members</h3>
                            <p className="mt-0.5 text-sm text-ink-muted">
                                <span className="font-semibold text-ink">{activeWorkspace.name}</span>
                                {' — '}
                                role decide karta hai manage rights;{' '}
                                <span className="font-semibold text-ink">Set permissions</span> se
                                har user ke sidebar menus on/off karo.
                            </p>
                        </div>
                        <div className="rounded-md bg-mist px-3 py-1.5 text-xs font-semibold text-ink-muted">
                            {members.length} {members.length === 1 ? 'person' : 'people'}
                        </div>
                    </div>

                    <div className="space-y-4 p-4">
                        {canManage ? (
                            <form
                                className="space-y-2 rounded-md border border-line bg-mist/40 p-3"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    inviteForm.post(
                                        route('workspaces.members.store', activeWorkspace.id),
                                        {
                                            onSuccess: () => inviteForm.reset('email', 'name'),
                                        },
                                    );
                                }}
                            >
                                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-[1fr_1.2fr_9rem_auto] lg:items-end">
                                    <div className="min-w-0">
                                        <InputLabel
                                            htmlFor="settings-member-name"
                                            value="Name (optional)"
                                        />
                                        <TextInput
                                            id="settings-member-name"
                                            className="mt-1.5 block w-full"
                                            value={inviteForm.data.name}
                                            onChange={(e) =>
                                                inviteForm.setData('name', e.target.value)
                                            }
                                            placeholder="Full name"
                                        />
                                    </div>
                                    <div className="min-w-0">
                                        <InputLabel
                                            htmlFor="settings-member-email"
                                            value="Invite by email"
                                        />
                                        <TextInput
                                            id="settings-member-email"
                                            type="email"
                                            className="mt-1.5 block w-full"
                                            value={inviteForm.data.email}
                                            onChange={(e) =>
                                                inviteForm.setData('email', e.target.value)
                                            }
                                            placeholder="user@company.com"
                                            required
                                        />
                                    </div>
                                    <div className="w-full shrink-0">
                                        <InputLabel value="Role" />
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
                                        className="h-[38px] w-full shrink-0 lg:w-auto"
                                        processing={inviteForm.processing}
                                    >
                                        Add member
                                    </PrimaryButton>
                                </div>
                                <p className="text-xs text-ink-muted">
                                    Agar account nahi hai to naya user banega aur password set karne
                                    ka email jayega.
                                </p>
                                <InputError message={inviteForm.errors.email} />
                                <InputError message={inviteForm.errors.name} />
                            </form>
                        ) : null}

                        <ul className="space-y-3">
                            {members.map((member) => {
                                const inheritAll = member.enabled_modules === null;
                                const memberKeys = inheritAll
                                    ? workspaceSelected
                                    : member.enabled_modules || [];
                                const editing = editingMemberId === member.id;
                                const allowedItems = (moduleCatalog?.items || []).filter((i) =>
                                    i.workspace_enabled,
                                );
                                const previewLabels = allowedItems
                                    .filter((i) => memberKeys.includes(i.key))
                                    .map((i) => i.label);

                                return (
                                    <li
                                        key={member.id}
                                        className="rounded-md border border-line bg-white p-3 sm:p-4"
                                    >
                                        <div className="flex flex-wrap items-start justify-between gap-3">
                                            <div className="min-w-0">
                                                <div className="truncate text-sm font-semibold text-ink">
                                                    {member.name}
                                                    {member.id === auth.user.id ? (
                                                        <span className="ms-1.5 text-[11px] font-medium text-ink-muted">
                                                            (you)
                                                        </span>
                                                    ) : null}
                                                </div>
                                                <div className="truncate text-xs text-ink-muted">
                                                    {member.email}
                                                </div>
                                                <div className="mt-2 flex flex-wrap gap-1">
                                                    {previewLabels.length === 0 ? (
                                                        <span className="rounded bg-mist px-2 py-0.5 text-[10px] font-semibold uppercase text-ink-muted">
                                                            No menus
                                                        </span>
                                                    ) : (
                                                        allowedItems
                                                            .filter((i) => memberKeys.includes(i.key))
                                                            .slice(0, 6)
                                                            .map((item) => {
                                                                const tone = moduleTone(
                                                                    toneForModule(item),
                                                                );
                                                                return (
                                                                    <span
                                                                        key={item.key}
                                                                        className={
                                                                            'rounded px-2 py-0.5 text-[10px] font-bold uppercase ' +
                                                                            tone.chip
                                                                        }
                                                                    >
                                                                        {item.label}
                                                                    </span>
                                                                );
                                                            })
                                                    )}
                                                    {previewLabels.length > 6 ? (
                                                        <span className="rounded bg-mist px-2 py-0.5 text-[10px] font-semibold text-ink-muted">
                                                            +{previewLabels.length - 6}
                                                        </span>
                                                    ) : null}
                                                    {inheritAll ? (
                                                        <span className="rounded border border-line px-2 py-0.5 text-[10px] font-semibold uppercase text-ink-muted">
                                                            All workspace menus
                                                        </span>
                                                    ) : null}
                                                </div>
                                            </div>

                                            <div className="flex flex-wrap items-center gap-2">
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
                                                                route('workspaces.members.update', [
                                                                    activeWorkspace.id,
                                                                    member.id,
                                                                ]),
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
                                                            (roleTone[member.role] || roleTone.viewer)
                                                        }
                                                    >
                                                        {member.role}
                                                    </span>
                                                )}
                                                {canManage && moduleCatalog ? (
                                                    <button
                                                        type="button"
                                                        className="rounded-md border border-line px-2.5 py-1.5 text-xs font-semibold text-signal-strong hover:border-signal/40"
                                                        onClick={() =>
                                                            setEditingMemberId(member.id)
                                                        }
                                                    >
                                                        Set permissions
                                                    </button>
                                                ) : null}
                                                {canManage && member.id !== auth.user.id ? (
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
                                        </div>

                                        {editing && canManage && moduleCatalog ? (
                                            <div className="relative mt-4 rounded-md border border-line bg-mist/20 p-3 pt-4">
                                                <button
                                                    type="button"
                                                    title="Close"
                                                    aria-label="Close permissions"
                                                    onClick={() => setEditingMemberId(null)}
                                                    className="absolute right-2 top-2 inline-flex h-8 w-8 items-center justify-center rounded-md border border-line bg-white text-ink-muted transition hover:border-signal/40 hover:text-ink"
                                                >
                                                    <svg
                                                        viewBox="0 0 20 20"
                                                        fill="currentColor"
                                                        className="h-4 w-4"
                                                        aria-hidden
                                                    >
                                                        <path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z" />
                                                    </svg>
                                                </button>
                                                <div className="mb-3 flex flex-wrap items-start justify-between gap-2 pr-10">
                                                    <div>
                                                        <div className="text-sm font-semibold text-ink">
                                                            Which menus can {member.name} open?
                                                        </div>
                                                        <p className="mt-0.5 text-xs text-ink-muted">
                                                            Off menus sidebar se hat jaayenge aur
                                                            route bhi block ho jaayega.
                                                        </p>
                                                    </div>
                                                    <button
                                                        type="button"
                                                        className="text-[11px] font-semibold uppercase text-signal"
                                                        onClick={() =>
                                                            saveMemberModules(member.id, [], true)
                                                        }
                                                    >
                                                        Give all workspace menus
                                                    </button>
                                                </div>
                                                <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                                    {allowedItems.map((item) => {
                                                        const on = memberKeys.includes(item.key);
                                                        const tone = moduleTone(toneForModule(item));
                                                        return (
                                                            <div
                                                                key={item.key}
                                                                className={
                                                                    'flex items-center justify-between gap-2 rounded-lg border px-2.5 py-2 shadow-sm ' +
                                                                    (on ? tone.card : tone.off)
                                                                }
                                                            >
                                                                <span
                                                                    className={
                                                                        'truncate rounded px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide ' +
                                                                        tone.chip
                                                                    }
                                                                >
                                                                    {item.label}
                                                                </span>
                                                                <div className="flex shrink-0 flex-col items-center gap-0.5">
                                                                    <Toggle
                                                                        checked={on}
                                                                        onChange={(enabled) => {
                                                                            const set = new Set(
                                                                                memberKeys,
                                                                            );
                                                                            if (enabled) {
                                                                                set.add(item.key);
                                                                            } else {
                                                                                set.delete(item.key);
                                                                            }
                                                                            saveMemberModules(
                                                                                member.id,
                                                                                [...set],
                                                                            );
                                                                        }}
                                                                    />
                                                                    <span className="text-[9px] font-semibold uppercase tracking-wide text-ink-muted">
                                                                        {on ? 'Show' : 'Hide'}
                                                                    </span>
                                                                </div>
                                                            </div>
                                                        );
                                                    })}
                                                </div>
                                            </div>
                                        ) : null}
                                    </li>
                                );
                            })}
                        </ul>
                    </div>
                </section>
            ) : null}
        </div>
    );
}
