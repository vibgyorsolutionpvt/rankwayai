<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesWorkspace;
use App\Services\Integrations\IntegrationCatalog;
use App\Services\Integrations\WorkspaceIntegrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class IntegrationsController extends Controller
{
    use ResolvesWorkspace;

    public function update(Request $request, string $provider, WorkspaceIntegrationService $integrations): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);

        $def = IntegrationCatalog::find($provider);
        if (! $def) {
            return back()->with('error', 'Unknown provider.');
        }

        $rules = [
            'enabled' => ['sometimes', 'boolean'],
        ];
        foreach ($def['fields'] as $field) {
            if (($field['type'] ?? null) === 'select') {
                $allowed = array_column($field['options'] ?? [], 'value');
                $rules['credentials.'.$field['key']] = ['nullable', 'string', 'in:'.implode(',', $allowed)];
            } else {
                $rules['credentials.'.$field['key']] = ['nullable', 'string', 'max:500'];
            }
        }

        $data = $request->validate($rules);
        $creds = $data['credentials'] ?? [];

        try {
            $integrations->upsert(
                $workspace,
                $provider,
                $creds,
                $request->boolean('enabled', true)
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $def['label'].' saved.');
    }

    public function disconnect(Request $request, string $provider, WorkspaceIntegrationService $integrations): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);

        if (! IntegrationCatalog::find($provider)) {
            return back()->with('error', 'Unknown provider.');
        }

        $integrations->disconnect($workspace, $provider);

        return back()->with('success', 'Provider disconnected.');
    }

    public function destroy(Request $request, string $provider, WorkspaceIntegrationService $integrations): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);

        if (! IntegrationCatalog::find($provider)) {
            return back()->with('error', 'Unknown provider.');
        }

        $integrations->forget($workspace, $provider);

        return back()->with('success', 'Provider credentials removed.');
    }
}
