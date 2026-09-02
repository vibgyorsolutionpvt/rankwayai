<?php

namespace App\Services\Audit;

use App\Models\User;
use App\Models\UserLoginLog;
use Illuminate\Http\Request;

class UserLoginLogger
{
    public function recordLogin(
        User $user,
        Request $request,
        string $channel = 'web',
        bool $simulated = false,
        ?int $simulatedBy = null
    ): UserLoginLog {
        $log = UserLoginLog::query()->create([
            'user_id' => $user->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'channel' => $channel,
            'simulated' => $simulated,
            'simulated_by' => $simulatedBy,
            'logged_in_at' => now(),
        ]);

        $request->session()->put('login_log_id', $log->id);

        return $log;
    }

    public function recordLogout(Request $request): void
    {
        $logId = (int) $request->session()->get('login_log_id');
        if ($logId <= 0) {
            return;
        }

        UserLoginLog::query()
            ->where('id', $logId)
            ->whereNull('logged_out_at')
            ->update(['logged_out_at' => now()]);

        $request->session()->forget('login_log_id');
    }
}
