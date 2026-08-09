<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesWorkspace;
use App\Jobs\SendChannelCampaignJob;
use App\Models\ChannelCampaign;
use App\Models\ChannelMessageTemplate;
use App\Models\CrmLead;
use App\Services\Billing\PlanAccess;
use App\Services\Channels\ChannelCampaignService;
use App\Services\Channels\ChannelTemplateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ChannelsController extends Controller
{
    use ResolvesWorkspace;

    public function index(Request $request, ChannelCampaignService $channels, ChannelTemplateService $templates, PlanAccess $plans): Response
    {
        $workspace = $this->workspace($request);
        $brand = $workspace->resolveBrandKit();
        $plan = $plans->summary($workspace);

        return Inertia::render('Channels/Index', [
            'workspace' => ['id' => $workspace->id, 'name' => $workspace->name],
            'provider' => $channels->provider($workspace),
            'providers' => $channels->messagingProviders($workspace),
            'rcs_providers' => $channels->rcsProviders($workspace),
            'plan' => $plan,
            'campaigns' => ChannelCampaign::query()
                ->where('workspace_id', $workspace->id)
                ->latest()
                ->limit(40)
                ->get(),
            'templates' => ChannelMessageTemplate::query()
                ->where('workspace_id', $workspace->id)
                ->latest()
                ->get()
                ->map->toArrayBrief()
                ->values(),
            'placeholders' => [
                ['token' => '{{name}}', 'label' => 'Lead name (auto on send)'],
                ['token' => '{{brand}}', 'label' => 'Business name'],
                ['token' => '{{cta}}', 'label' => 'Brand button text'],
                ['token' => '{{cta_url}}', 'label' => 'Brand button link'],
                ['token' => '{{phone}}', 'label' => 'Brand phone'],
                ['token' => '{{email}}', 'label' => 'Brand email'],
                ['token' => '{{website}}', 'label' => 'Brand website'],
            ],
            'brand_tokens' => $templates->tokens($workspace, $brand),
            'leads' => CrmLead::query()
                ->where('workspace_id', $workspace->id)
                ->orderBy('name')
                ->limit(100)
                ->get(['id', 'name', 'email', 'phone', 'stage']),
            'counts' => [
                'whatsapp' => ChannelCampaign::query()->where('workspace_id', $workspace->id)->where('channel', 'whatsapp')->count(),
                'email' => ChannelCampaign::query()->where('workspace_id', $workspace->id)->where('channel', 'email')->count(),
                'rcs' => ChannelCampaign::query()->where('workspace_id', $workspace->id)->where('channel', 'rcs')->count(),
                'templates' => ChannelMessageTemplate::query()->where('workspace_id', $workspace->id)->count(),
                'leads_with_phone' => CrmLead::query()->where('workspace_id', $workspace->id)->whereNotNull('phone')->where('phone', '!=', '')->count(),
                'leads_with_email' => CrmLead::query()->where('workspace_id', $workspace->id)->whereNotNull('email')->where('email', '!=', '')->count(),
            ],
        ]);
    }

    public function store(Request $request, ChannelCampaignService $channels, PlanAccess $plans): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'channel' => ['required', 'in:whatsapp,email,rcs'],
            'rcs_provider' => ['nullable', 'string', 'max:40'],
            'subject' => ['nullable', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:4000'],
            'scheduled_at' => ['nullable', 'date'],
            'lead_ids' => ['nullable', 'array'],
            'lead_ids.*' => ['integer'],
            'delivery' => ['required', 'in:now,schedule,draft'],
        ]);

        if ($data['channel'] === 'rcs') {
            $allowed = \App\Services\Channels\Rcs\RcsProviderCatalog::ids();
            if (! empty($data['rcs_provider']) && ! in_array($data['rcs_provider'], $allowed, true)) {
                return back()->with('error', 'Invalid RCS provider.');
            }
        }

        if (in_array($data['delivery'], ['now', 'schedule'], true) && ! $plans->allows($workspace, 'channel_send')) {
            return back()->with('error', $plans->denyMessage('channel_send'));
        }

        if ($data['channel'] === 'email' && blank($data['subject'] ?? null)) {
            return back()->with('error', 'Email campaigns need a subject.');
        }

        if (blank($data['scheduled_at'] ?? null)) {
            $data['scheduled_at'] = null;
        }

        if ($data['delivery'] === 'schedule') {
            $request->validate([
                'scheduled_at' => ['required', 'date', 'after:now'],
            ], [
                'scheduled_at.required' => 'Pick a future date & time to schedule.',
                'scheduled_at.after' => 'Schedule time must be in the future.',
            ]);
        } else {
            $data['scheduled_at'] = null;
        }

        $campaign = $channels->create(
            $workspace,
            $request->user()->id,
            $data,
            $data['lead_ids'] ?? null
        );

        if ($data['delivery'] === 'now') {
            SendChannelCampaignJob::dispatchSync($campaign->id);

            return back()->with('success', 'Campaign sent now.');
        }

        if ($data['delivery'] === 'schedule') {
            return back()->with(
                'success',
                'Campaign scheduled for '.$campaign->fresh()->scheduled_at?->timezone(config('app.timezone'))->format('d M Y, g:i A').'.'
            );
        }

        return back()->with('success', 'Campaign saved as draft.');
    }

    public function storeTemplate(Request $request): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'channel' => ['required', 'in:whatsapp,email,rcs'],
            'subject' => ['nullable', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:4000'],
        ]);

        if ($data['channel'] === 'email' && blank($data['subject'] ?? null)) {
            return back()->with('error', 'Email templates need a subject.');
        }

        ChannelMessageTemplate::query()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $request->user()->id,
            'name' => $data['name'],
            'channel' => $data['channel'],
            'subject' => $data['subject'] ?? null,
            'body' => $data['body'],
        ]);

        return back()->with('success', 'Template saved.');
    }

    public function updateTemplate(Request $request, ChannelMessageTemplate $template): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);
        abort_unless($template->workspace_id === $workspace->id, 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'channel' => ['required', 'in:whatsapp,email,rcs'],
            'subject' => ['nullable', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:4000'],
        ]);

        if ($data['channel'] === 'email' && blank($data['subject'] ?? null)) {
            return back()->with('error', 'Email templates need a subject.');
        }

        $template->update([
            'name' => $data['name'],
            'channel' => $data['channel'],
            'subject' => $data['subject'] ?? null,
            'body' => $data['body'],
        ]);

        return back()->with('success', 'Template updated.');
    }

    public function destroyTemplate(Request $request, ChannelMessageTemplate $template): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);
        abort_unless($template->workspace_id === $workspace->id, 404);

        $template->delete();

        return back()->with('success', 'Template deleted.');
    }

    public function send(Request $request, ChannelCampaign $campaign): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);
        abort_unless($campaign->workspace_id === $workspace->id, 404);

        SendChannelCampaignJob::dispatchSync($campaign->id);

        return back()->with('success', 'Campaign send finished');
    }

    public function destroy(Request $request, ChannelCampaign $campaign): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);
        abort_unless($campaign->workspace_id === $workspace->id, 404);

        if (in_array($campaign->status, ['sending', 'sent'], true)) {
            return back()->with('error', 'Cannot delete a sent/sending campaign.');
        }

        $campaign->delete();

        return back()->with('success', 'Campaign deleted');
    }
}
