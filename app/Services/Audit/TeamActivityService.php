<?php

namespace App\Services\Audit;

use App\Models\ActivityLog;
use App\Models\UserLoginLog;
use App\Models\Workspace;
use App\Support\ActivityLabels;

class TeamActivityService
{
    /** @var list<string> */
    private const HIDDEN_ACTIONS = [
        'admin.simulate_user',
        'admin.impersonate',
    ];

    /**
     * @return array{
     *   login_logs:list<array<string,mixed>>,
     *   action_logs:list<array<string,mixed>>
     * }
     */
    public function forWorkspace(Workspace $workspace, ?int $userId = null, int $limit = 40): array
    {
        $memberIds = $workspace->users()->pluck('users.id');

        if ($memberIds->isEmpty()) {
            return ['login_logs' => [], 'action_logs' => []];
        }

        if ($userId !== null && ! $memberIds->contains($userId)) {
            $userId = null;
        }

        $loginLogs = UserLoginLog::query()
            ->with('user:id,name,email')
            ->whereIn('user_id', $memberIds)
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->orderByDesc('logged_in_at')
            ->limit($limit)
            ->get()
            ->map(fn (UserLoginLog $log) => $this->loginPayload($log))
            ->all();

        $actionLogs = ActivityLog::query()
            ->with('user:id,name,email')
            ->where('workspace_id', $workspace->id)
            ->whereIn('user_id', $memberIds)
            ->whereNotIn('action', self::HIDDEN_ACTIONS)
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (ActivityLog $log) => $this->actionPayload($log))
            ->all();

        return [
            'login_logs' => $loginLogs,
            'action_logs' => $actionLogs,
        ];
    }

    /** @return array<string, mixed> */
    private function loginPayload(UserLoginLog $log): array
    {
        return [
            'id' => $log->id,
            'user_id' => $log->user_id,
            'user' => $log->user?->name,
            'email' => $log->user?->email,
            'ip_address' => $log->ip_address,
            'logged_in_at' => $log->logged_in_at?->timezone(config('app.timezone'))->format('d M Y, g:i A'),
            'logged_out_at' => $log->logged_out_at?->timezone(config('app.timezone'))->format('d M Y, g:i A'),
        ];
    }

    /** @return array<string, mixed> */
    private function actionPayload(ActivityLog $log): array
    {
        return [
            'id' => $log->id,
            'user_id' => $log->user_id,
            'user' => $log->user?->name,
            'email' => $log->user?->email,
            'action' => $log->action,
            'label' => ActivityLabels::forAction($log->action),
            'meta' => $log->meta,
            'created_at' => $log->created_at?->timezone(config('app.timezone'))->format('d M Y, g:i A'),
        ];
    }
}
