<?php

namespace App\Http\Controllers;

use App\Enums\WorkspaceRole;
use App\Http\Requests\Workspace\StoreWorkspaceMemberRequest;
use App\Http\Requests\Workspace\UpdateWorkspaceMemberRequest;
use App\Http\Resources\WorkspaceMemberResource;
use App\Models\ActivityLog;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class WorkspaceMemberController extends Controller
{
    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorize('view', $workspace);

        $members = $workspace->users()->orderBy('name')->get();

        return response()->json([
            'data' => WorkspaceMemberResource::collection($members),
        ]);
    }

    public function store(StoreWorkspaceMemberRequest $request, Workspace $workspace): JsonResponse
    {
        $this->authorize('manageMembers', $workspace);

        $role = WorkspaceRole::from($request->validated('role'));

        if (! $request->user()->can('assignRole', [$workspace, $role])) {
            abort(403, 'You cannot assign this role.');
        }

        $user = User::where('email', $request->validated('email'))->first();

        if (! $user) {
            throw ValidationException::withMessages([
                'email' => ['No user found with this email. They must register first.'],
            ]);
        }

        if ($workspace->hasMember($user)) {
            throw ValidationException::withMessages([
                'email' => ['User is already a member of this workspace.'],
            ]);
        }

        $workspace->users()->attach($user->id, [
            'role' => $role->value,
        ]);

        ActivityLog::record($workspace, $request->user(), 'member.added', [
            'user_id' => $user->id,
            'role' => $role->value,
        ]);

        $member = $workspace->users()->where('user_id', $user->id)->firstOrFail();

        return response()->json([
            'data' => new WorkspaceMemberResource($member),
        ], 201);
    }

    public function update(
        UpdateWorkspaceMemberRequest $request,
        Workspace $workspace,
        int $userId,
    ): JsonResponse {
        $this->authorize('manageMembers', $workspace);

        $member = $workspace->users()->where('user_id', $userId)->firstOrFail();
        $currentRole = WorkspaceRole::from($member->pivot->role);
        $newRole = WorkspaceRole::from($request->validated('role'));

        if (! $request->user()->can('assignRole', [$workspace, $newRole])) {
            abort(403, 'You cannot assign this role.');
        }

        $actorRole = $workspace->roleFor($request->user());

        if ($currentRole === WorkspaceRole::Owner && $actorRole !== WorkspaceRole::Owner) {
            abort(403, 'Only an owner can change another owner.');
        }

        if ($actorRole === WorkspaceRole::Admin && $currentRole->rank() >= WorkspaceRole::Admin->rank()) {
            abort(403, 'Admins cannot change other admins or owners.');
        }

        if (
            $currentRole === WorkspaceRole::Owner
            && $newRole !== WorkspaceRole::Owner
            && $this->ownerCount($workspace) <= 1
        ) {
            throw ValidationException::withMessages([
                'role' => ['Workspace must keep at least one owner.'],
            ]);
        }

        $workspace->users()->updateExistingPivot($userId, [
            'role' => $newRole->value,
        ]);

        ActivityLog::record($workspace, $request->user(), 'member.role_updated', [
            'user_id' => $userId,
            'role' => $newRole->value,
        ]);

        $member = $workspace->users()->where('user_id', $userId)->firstOrFail();

        return response()->json([
            'data' => new WorkspaceMemberResource($member),
        ]);
    }

    public function destroy(Request $request, Workspace $workspace, int $userId): JsonResponse
    {
        $this->authorize('manageMembers', $workspace);

        $member = $workspace->users()->where('user_id', $userId)->firstOrFail();
        $memberRole = WorkspaceRole::from($member->pivot->role);
        $actorRole = $workspace->roleFor($request->user());

        if ($memberRole === WorkspaceRole::Owner && $actorRole !== WorkspaceRole::Owner) {
            abort(403, 'Only an owner can remove another owner.');
        }

        if ($actorRole === WorkspaceRole::Admin && $memberRole->rank() >= WorkspaceRole::Admin->rank()) {
            abort(403, 'Admins cannot remove other admins or owners.');
        }

        if ($memberRole === WorkspaceRole::Owner && $this->ownerCount($workspace) <= 1) {
            throw ValidationException::withMessages([
                'user' => ['Workspace must keep at least one owner.'],
            ]);
        }

        $workspace->users()->detach($userId);

        ActivityLog::record($workspace, $request->user(), 'member.removed', [
            'user_id' => $userId,
        ]);

        return response()->json(null, 204);
    }

    private function ownerCount(Workspace $workspace): int
    {
        return $workspace->users()
            ->wherePivot('role', WorkspaceRole::Owner->value)
            ->count();
    }
}
