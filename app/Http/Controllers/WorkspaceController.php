<?php

namespace App\Http\Controllers;

use App\Enums\WorkspaceRole;
use App\Http\Requests\Workspace\StoreWorkspaceRequest;
use App\Http\Requests\Workspace\UpdateWorkspaceRequest;
use App\Http\Resources\WorkspaceMemberResource;
use App\Http\Resources\WorkspaceResource;
use App\Models\ActivityLog;
use App\Models\Workspace;
use App\Services\Billing\PlanAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class WorkspaceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Workspace::class);

        $workspaces = $request->user()
            ->workspaces()
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => WorkspaceResource::collection($workspaces),
        ]);
    }

    public function store(StoreWorkspaceRequest $request): JsonResponse
    {
        $this->authorize('create', Workspace::class);

        $plans = app(PlanAccess::class);
        if (! $plans->canCreateWorkspace($request->user())) {
            throw ValidationException::withMessages([
                'name' => $plans->denyCreateWorkspaceMessage($request->user()),
            ]);
        }

        $workspace = Workspace::create([
            'name' => $request->validated('name'),
        ]);

        $workspace->users()->attach($request->user()->id, [
            'role' => WorkspaceRole::Owner->value,
        ]);

        ActivityLog::record($workspace, $request->user(), 'workspace.created', [
            'name' => $workspace->name,
        ]);

        $workspace = $request->user()->workspaces()->where('workspaces.id', $workspace->id)->firstOrFail();

        return response()->json([
            'data' => new WorkspaceResource($workspace),
        ], 201);
    }

    public function show(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorize('view', $workspace);

        $membership = $request->user()->workspaces()->where('workspaces.id', $workspace->id)->firstOrFail();

        return response()->json([
            'data' => new WorkspaceResource($membership),
            'meta' => [
                'members' => WorkspaceMemberResource::collection($workspace->users()->orderBy('name')->get()),
            ],
        ]);
    }

    public function update(UpdateWorkspaceRequest $request, Workspace $workspace): JsonResponse
    {
        $this->authorize('update', $workspace);

        $workspace->update([
            'name' => $request->validated('name'),
        ]);

        ActivityLog::record($workspace, $request->user(), 'workspace.updated', [
            'name' => $workspace->name,
        ]);

        $membership = $request->user()->workspaces()->where('workspaces.id', $workspace->id)->firstOrFail();

        return response()->json([
            'data' => new WorkspaceResource($membership),
        ]);
    }

    public function destroy(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorize('delete', $workspace);

        ActivityLog::record($workspace, $request->user(), 'workspace.deleted', [
            'name' => $workspace->name,
        ]);

        $workspace->delete();

        return response()->json(null, 204);
    }
}
