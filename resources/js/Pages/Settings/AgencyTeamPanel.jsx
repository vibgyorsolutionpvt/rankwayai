import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SelectMenu from '@/Components/SelectMenu';
import TextInput from '@/Components/TextInput';
import { router, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';

export default function AgencyTeamPanel({ agencyTeam, roles = [] }) {
    const ownedWorkspaces = agencyTeam?.owned_workspaces || [];
    const members = agencyTeam?.members || [];
    const defaultWorkspaceIds = useMemo(
        () => ownedWorkspaces.map((w) => w.id),
        [ownedWorkspaces],
    );

    const inviteForm = useForm({
        name: '',
        email: '',
        role: 'editor',
        workspace_ids: defaultWorkspaceIds,
    });

    const [selectedIds, setSelectedIds] = useState(() =>
        Object.fromEntries(
            members.map((m) => [m.id, m.workspaces.map((w) => w.id)]),
        ),
    );

    const inviteRoles = roles.filter((r) => r !== 'owner');

    const toggleInviteWorkspace = (id, on) => {
        const set = new Set(inviteForm.data.workspace_ids);
        if (on) {
            set.add(id);
        } else {
            set.delete(id);
        }
        inviteForm.setData('workspace_ids', [...set]);
    };

    const saveMemberAccess = (memberId, workspaceIds) => {
        router.put(
            route('account.team.workspaces', memberId),
            { workspace_ids: workspaceIds },
            { preserveScroll: true },
        );
    };

    const toggleMemberWorkspace = (memberId, workspaceId, on) => {
        const current = selectedIds[memberId] || [];
        const next = on
            ? [...new Set([...current, workspaceId])]
            : current.filter((id) => id !== workspaceId);
        setSelectedIds((prev) => ({ ...prev, [memberId]: next }));
        saveMemberAccess(memberId, next);
    };

    if (ownedWorkspaces.length === 0) {
        return null;
    }

    return (
        <section className="atlas-panel overflow-hidden">
            <div className="border-b border-line px-4 py-3.5">
                <h3 className="font-display text-base font-bold text-ink">Agency team</h3>
                <p className="mt-0.5 text-sm text-ink-muted">
                    Invite once, assign any owned workspaces. On each brand they can use every
                    module you enabled for that workspace (and for that person) — SEO, SMM, Blog,
                    CRM, WhatsApp, and more. Same shared data the owner set up.
                </p>
            </div>

            <div className="space-y-4 p-4">
                <form
                    className="space-y-3 rounded-md border border-line bg-mist/40 p-3"
                    onSubmit={(e) => {
                        e.preventDefault();
                        inviteForm.post(route('account.team.invite'), {
                            preserveScroll: true,
                            onSuccess: () => {
                                inviteForm.reset('email', 'name');
                                inviteForm.setData('workspace_ids', defaultWorkspaceIds);
                            },
                        });
                    }}
                >
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-[1fr_1.2fr_9rem_auto] lg:items-end">
                        <div>
                            <InputLabel htmlFor="agency-member-name" value="Name (optional)" />
                            <TextInput
                                id="agency-member-name"
                                className="mt-1.5 block w-full"
                                value={inviteForm.data.name}
                                onChange={(e) => inviteForm.setData('name', e.target.value)}
                            />
                        </div>
                        <div>
                            <InputLabel htmlFor="agency-member-email" value="Email" />
                            <TextInput
                                id="agency-member-email"
                                type="email"
                                className="mt-1.5 block w-full"
                                value={inviteForm.data.email}
                                onChange={(e) => inviteForm.setData('email', e.target.value)}
                                required
                            />
                        </div>
                        <div>
                            <InputLabel value="Default role" />
                            <div className="mt-1.5">
                                <SelectMenu
                                    value={inviteForm.data.role}
                                    onChange={(role) => inviteForm.setData('role', role)}
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
                            Add to team
                        </PrimaryButton>
                    </div>

                    <div>
                        <InputLabel value="Workspace access" />
                        <div className="mt-2 flex flex-wrap gap-2">
                            {ownedWorkspaces.map((ws) => {
                                const on = inviteForm.data.workspace_ids.includes(ws.id);
                                return (
                                    <label
                                        key={ws.id}
                                        className={
                                            'inline-flex cursor-pointer items-center gap-2 rounded-md border px-2.5 py-1.5 text-xs font-semibold ' +
                                            (on
                                                ? 'border-signal/40 bg-signal-soft text-signal-strong'
                                                : 'border-line bg-white text-ink-muted')
                                        }
                                    >
                                        <input
                                            type="checkbox"
                                            className="rounded border-line text-signal-strong"
                                            checked={on}
                                            onChange={(e) =>
                                                toggleInviteWorkspace(ws.id, e.target.checked)
                                            }
                                        />
                                        {ws.name}
                                    </label>
                                );
                            })}
                        </div>
                    </div>

                    <InputError message={inviteForm.errors.email} />
                    <InputError message={inviteForm.errors.workspace_ids} />
                </form>

                {members.length === 0 ? (
                    <p className="text-sm text-ink-muted">
                        No team members yet. Add someone above — they can work on every workspace
                        you tick.
                    </p>
                ) : (
                    <ul className="space-y-3">
                        {members.map((member) => {
                            const accessIds =
                                selectedIds[member.id] ?? member.workspaces.map((w) => w.id);

                            return (
                                <li
                                    key={member.id}
                                    className="rounded-md border border-line bg-white p-3 sm:p-4"
                                >
                                    <div className="min-w-0">
                                        <div className="font-semibold text-ink">{member.name}</div>
                                        <div className="text-xs text-ink-muted">{member.email}</div>
                                    </div>
                                    <div className="mt-3">
                                        <div className="text-[10px] font-bold uppercase tracking-wide text-ink-muted">
                                            Workspace access
                                        </div>
                                        <div className="mt-2 flex flex-wrap gap-2">
                                            {ownedWorkspaces.map((ws) => {
                                                const on = accessIds.includes(ws.id);
                                                return (
                                                    <label
                                                        key={ws.id}
                                                        className={
                                                            'inline-flex cursor-pointer items-center gap-2 rounded-md border px-2.5 py-1.5 text-xs font-semibold ' +
                                                            (on
                                                                ? 'border-signal/40 bg-signal-soft text-signal-strong'
                                                                : 'border-line bg-mist/30 text-ink-muted')
                                                        }
                                                    >
                                                        <input
                                                            type="checkbox"
                                                            className="rounded border-line text-signal-strong"
                                                            checked={on}
                                                            onChange={(e) =>
                                                                toggleMemberWorkspace(
                                                                    member.id,
                                                                    ws.id,
                                                                    e.target.checked,
                                                                )
                                                            }
                                                        />
                                                        {ws.name}
                                                    </label>
                                                );
                                            })}
                                        </div>
                                    </div>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </div>
        </section>
    );
}
