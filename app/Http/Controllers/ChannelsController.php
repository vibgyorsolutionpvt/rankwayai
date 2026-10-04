<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesWorkspace;
use App\Jobs\SendChannelCampaignJob;
use App\Models\ChannelCampaign;
use App\Models\ChannelMessageTemplate;
use App\Models\CrmLead;
use App\Models\CrmLeadGroup;
use App\Services\Billing\PlanAccess;
use App\Services\Channels\ChannelCampaignService;
use App\Services\Channels\ChannelTemplateService;
use App\Services\Channels\Rcs\RcsProviderCatalog;
use App\Services\Integrations\WorkspaceIntegrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
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
            'recipient_mode' => ['sometimes', 'in:all,selected,group'],
            'crm_lead_group_id' => ['nullable', 'integer'],
            'whatsapp_template_id' => ['nullable', 'integer'],
            'delivery' => ['required', 'in:now,schedule,draft'],
            ...self::audienceFilterRules(),
        ]);
        $data['audience'] = ($data['recipient_mode'] ?? 'all') === 'all' ? self::audienceFilters($data) : [];

        $recipientGroup = null;
        if (($data['recipient_mode'] ?? null) === 'group') {
            if ($data['channel'] !== 'whatsapp' || blank($data['crm_lead_group_id'] ?? null)) {
                throw ValidationException::withMessages([
                    'crm_lead_group_id' => 'Choose a contact group for this WhatsApp campaign.',
                ]);
            }
            $recipientGroup = CrmLeadGroup::query()
                ->where('workspace_id', $workspace->id)
                ->where('status', 'completed')
                ->find($data['crm_lead_group_id']);
            if (
                ! $recipientGroup
                || ! $recipientGroup->leads()
                    ->whereNotNull('phone')
                    ->where('phone', '!=', '')
                    ->exists()
            ) {
                throw ValidationException::withMessages([
                    'crm_lead_group_id' => 'Choose a completed contact group that has phone numbers.',
                ]);
            }
            $data['crm_lead_group_id'] = $recipientGroup->id;
        }

        if (($data['recipient_mode'] ?? null) === 'selected') {
            $selectedLeadIds = $data['lead_ids'] ?? [];
            $validSelectedCount = CrmLead::query()
                ->where('workspace_id', $workspace->id)
                ->whereNotNull('phone')
                ->whereIn('id', $selectedLeadIds)
                ->count();
            if ($selectedLeadIds === [] || $validSelectedCount !== count(array_unique($selectedLeadIds))) {
                throw ValidationException::withMessages([
                    'lead_ids' => 'Select at least one valid CRM contact with a phone number from this workspace.',
                ]);
            }
        }

        if (filled($data['whatsapp_template_id'] ?? null)) {
            if ($data['channel'] !== 'whatsapp') {
                throw ValidationException::withMessages([
                    'whatsapp_template_id' => 'WhatsApp templates can only be used for WhatsApp campaigns.',
                ]);
            }

            $whatsappTemplate = ChannelMessageTemplate::query()
                ->where('workspace_id', $workspace->id)
                ->where('channel', 'whatsapp')
                ->where('wa_status', 'approved')
                ->find($data['whatsapp_template_id']);

            if (! $whatsappTemplate) {
                throw ValidationException::withMessages([
                    'whatsapp_template_id' => 'Choose an approved WhatsApp template from this workspace.',
                ]);
            }

            if (
                in_array($data['delivery'], ['now', 'schedule'], true)
                && $channels->provider($workspace, 'whatsapp') !== 'meta'
            ) {
                return back()->with('error', 'Approved WhatsApp template campaigns require the Meta Cloud API provider.');
            }

            $data['body'] = $whatsappTemplate->body;
        }

        if ($data['channel'] === 'rcs') {
            $allowed = RcsProviderCatalog::ids();
            if (! empty($data['rcs_provider']) && ! in_array($data['rcs_provider'], $allowed, true)) {
                return back()->with('error', 'Invalid RCS provider.');
            }
        }

        if (in_array($data['delivery'], ['now', 'schedule'], true) && ! $plans->allows($workspace, 'channel_send')) {
            return back()->with('error', $plans->denyMessage('channel_send'));
        }

        if (
            $data['channel'] === 'whatsapp'
            && in_array($data['delivery'], ['now', 'schedule'], true)
            && $channels->provider($workspace, 'whatsapp') === 'none'
        ) {
            return back()->with('error', WorkspaceIntegrationService::whatsappNotConnectedMessage());
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

        if (in_array($data['delivery'], ['now', 'schedule'], true)) {
            $audience = $channels->audienceSummary(
                $workspace->id,
                $data['channel'],
                $recipientGroup?->id,
                ($data['recipient_mode'] ?? null) === 'selected' ? ($data['lead_ids'] ?? []) : null,
                $data['audience'],
            );
            if ($audience['recipients'] === 0) {
                return back()->with('error', 'No recipients match this audience. Change the filters or choose other contacts.');
            }
        }

        $campaign = $channels->create(
            $workspace,
            $request->user()->id,
            $data,
            ($data['recipient_mode'] ?? null) === 'selected'
                ? ($data['lead_ids'] ?? [])
                : (($data['recipient_mode'] ?? null) === 'group'
                    ? null
                    : ($data['lead_ids'] ?? null))
        );

        if ($data['delivery'] === 'now') {
            if ($campaign->crm_lead_group_id) {
                $campaign->update(['status' => 'sending']);
                SendChannelCampaignJob::dispatch($campaign->id);

                return back()->with('success', 'Large contact-group campaign queued. Progress will update as message batches are sent.');
            }

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

    /**
     * @return array<string, list<string>>
     */
    public static function audienceFilterRules(): array
    {
        return [
            'stages' => ['sometimes', 'array'],
            'stages.*' => ['string', 'in:'.implode(',', ChannelCampaignService::LEAD_STAGES)],
            'sources' => ['sometimes', 'array', 'max:50'],
            'sources.*' => ['string', 'max:60'],
            'custom_field' => ['nullable', 'string', 'regex:/^[a-z0-9_]{1,60}$/'],
            'custom_value' => ['nullable', 'string', 'max:120'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{stages?:list<string>, sources?:list<string>, custom_field?:?string, custom_value?:?string}
     */
    public static function audienceFilters(array $data): array
    {
        return array_filter([
            'stages' => array_key_exists('stages', $data) ? array_values($data['stages'] ?? []) : null,
            'sources' => array_values($data['sources'] ?? []),
            'custom_field' => $data['custom_field'] ?? null,
            'custom_value' => $data['custom_value'] ?? null,
        ], fn ($value) => $value !== null);
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

    public function send(Request $request, ChannelCampaign $campaign, ChannelCampaignService $channels): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);
        abort_unless($campaign->workspace_id === $workspace->id, 404);

        if ($campaign->channel === 'whatsapp' && $channels->provider($workspace, 'whatsapp') === 'none') {
            return back()->with('error', WorkspaceIntegrationService::whatsappNotConnectedMessage());
        }

        if ($campaign->crm_lead_group_id) {
            $campaign->update(['status' => 'sending']);
            SendChannelCampaignJob::dispatch($campaign->id);

            return back()->with('success', 'Contact-group campaign queued for batched sending.');
        }

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
