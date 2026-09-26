<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesWorkspace;
use App\Services\Crm\AnalyticsService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AnalyticsController extends Controller
{
    use ResolvesWorkspace;

    public function index(Request $request, AnalyticsService $analytics): Response
    {
        $workspace = $this->workspace($request);

        return Inertia::render('Analytics/Index', [
            'workspace' => ['id' => $workspace->id, 'name' => $workspace->name],
            'snapshot' => $analytics->workspaceSnapshot($workspace),
        ]);
    }
}
