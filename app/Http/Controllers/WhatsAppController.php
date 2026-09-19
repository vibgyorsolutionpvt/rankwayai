<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesWorkspace;
use App\Models\ChannelCampaign;
use App\Models\ChannelMessageTemplate;
use App\Models\CrmLead;
use App\Models\WhatsappConversation;
use App\Models\Workspace;
use App\Services\Billing\PlanAccess;
use App\Services\Channels\ChannelCampaignService;
use App\Services\Channels\ChannelTemplateService;
use App\Services\Integrations\IntegrationCatalog;
use App\Services\Integrations\WorkspaceIntegrationService;
use App\Services\WhatsApp\WhatsAppConversationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WhatsAppController extends Controller
{
    use ResolvesWorkspace;

    /** @var list<string> */
    private const BUSINESS_SETUP_KEYS = [
        'business_display_name',
        'business_phone',
        'business_category',
        'business_email',
        'business_website',
        'business_address',
        'business_country',
        'business_about',
    ];

    /** @var list<string> */
    private const META_SETUP_KEYS = [
        'phone_number_id',
        'waba_id',
        'access_token',
        'app_secret',
        'verify_token',
        'api_version',
    ];

    public function index(
        Request $request,
        WhatsAppConversationService $conversations,
        ChannelCampaignService $channels,
        ChannelTemplateService $templates,
        PlanAccess $plans,
        WorkspaceIntegrationService $integrations,
    ): Response {
        $workspace = $this->workspace($request);

        $view = in_array($request->query('view'), ['conversations', 'templates', 'campaigns', 'setup'], true)
            ? $request->query('view')
            : 'conversations';
        $activeId = (int) $request->query('conversation', 0);

        $threadList = WhatsappConversation::query()
            ->where('workspace_id', $workspace->id)
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->limit(80)
            ->get()
            ->map(fn (WhatsappConversation $c) => $c->toClientArray());

        $active = null;
        $messages = [];
        if ($activeId > 0) {
            $activeModel = WhatsappConversation::query()
                ->where('workspace_id', $workspace->id)
                ->whereKey($activeId)
                ->first();
            if ($activeModel) {
                $conversations->markRead($activeModel);
                $active = $activeModel->fresh()->toClientArray();
                $messages = $activeModel->messages()
                    ->orderBy('id')
                    ->limit(200)
                    ->get()
                    ->map(fn ($m) => $m->toClientArray());
            }
        }

        return Inertia::render('WhatsApp/Index', [
            'workspace' => ['id' => $workspace->id, 'name' => $workspace->name],
            'view' => $view,
            'provider' => $channels->provider($workspace, 'whatsapp'),
            'plan' => $plans->summary($workspace),
            'meta_setup' => $this->metaSetupPayload($workspace, $integrations, $request->user()),
            'conversations' => $threadList,
            'activeConversation' => $active,
            'messages' => $messages,
            'templates' => ChannelMessageTemplate::query()
                ->where('workspace_id', $workspace->id)
                ->where('channel', 'whatsapp')
                ->latest()
                ->get()
                ->map(fn (ChannelMessageTemplate $t) => $t->toArrayBrief()),
            'campaigns' => ChannelCampaign::query()
                ->where('workspace_id', $workspace->id)
                ->where('channel', 'whatsapp')
                ->latest()
                ->limit(40)
                ->get(),
            'leads' => CrmLead::query()
                ->where('workspace_id', $workspace->id)
                ->whereNotNull('phone')
                ->orderByDesc('id')
                ->limit(100)
                ->get(['id', 'name', 'phone', 'email', 'stage']),
            'placeholders' => [
                ['token' => '{{name}}', 'label' => 'Lead name'],
                ['token' => '{{brand}}', 'label' => 'Brand'],
                ['token' => '{{cta}}', 'label' => 'CTA label'],
                ['token' => '{{cta_url}}', 'label' => 'CTA URL'],
                ['token' => '{{phone}}', 'label' => 'Brand phone'],
            ],
            'brand_tokens' => $templates->tokens($workspace),
            'counts' => [
                'conversations' => WhatsappConversation::query()->where('workspace_id', $workspace->id)->count(),
                'unread' => (int) WhatsappConversation::query()->where('workspace_id', $workspace->id)->sum('unread_count'),
                'templates' => ChannelMessageTemplate::query()->where('workspace_id', $workspace->id)->where('channel', 'whatsapp')->count(),
                'campaigns' => ChannelCampaign::query()->where('workspace_id', $workspace->id)->where('channel', 'whatsapp')->count(),
            ],
        ]);
    }

    public function start(Request $request, WhatsAppConversationService $conversations, PlanAccess $plans): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);

        $data = $request->validate([
            'phone' => ['nullable', 'string', 'max:32'],
            'crm_lead_id' => ['nullable', 'integer'],
            'contact_name' => ['nullable', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:4000'],
            'template_id' => ['nullable', 'integer'],
            'as_template' => ['sometimes', 'boolean'],
        ]);

        if (! $plans->allows($workspace, 'channel_send')) {
            return back()->with('error', $plans->denyMessage('channel_send'));
        }

        $phone = $data['phone'] ?? null;
        $leadId = $data['crm_lead_id'] ?? null;
        if ($leadId) {
            $lead = CrmLead::query()->where('workspace_id', $workspace->id)->whereKey($leadId)->first();
            if (! $lead || blank($lead->phone)) {
                return back()->with('error', 'Lead needs a phone number.');
            }
            $phone = $lead->phone;
            $data['contact_name'] = $data['contact_name'] ?? $lead->name;
        }

        if (blank($phone)) {
            return back()->with('error', 'Phone number is required.');
        }

        $template = null;
        if (! empty($data['template_id'])) {
            $template = ChannelMessageTemplate::query()
                ->where('workspace_id', $workspace->id)
                ->where('channel', 'whatsapp')
                ->whereKey($data['template_id'])
                ->first();
        }

        $conversation = $conversations->findOrCreate(
            $workspace,
            $phone,
            $data['contact_name'] ?? null,
            $leadId
        );

        $result = $conversations->sendOutbound(
            $workspace,
            $conversation,
            $template?->body ?? $data['body'],
            $request->user(),
            $template,
            $request->boolean('as_template')
        );

        if (! $result['ok']) {
            return redirect()
                ->route('whatsapp.index', ['view' => 'conversations', 'conversation' => $conversation->id])
                ->with('error', $result['error'] ?: 'Failed to send.');
        }

        return redirect()
            ->route('whatsapp.index', ['view' => 'conversations', 'conversation' => $conversation->id])
            ->with('success', 'Message sent.');
    }

    public function reply(
        Request $request,
        WhatsappConversation $conversation,
        WhatsAppConversationService $conversations,
        PlanAccess $plans
    ): RedirectResponse {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);
        abort_unless($conversation->workspace_id === $workspace->id, 404);

        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:4000'],
            'template_id' => ['nullable', 'integer'],
            'as_template' => ['sometimes', 'boolean'],
        ]);

        if (! $plans->allows($workspace, 'channel_send')) {
            return back()->with('error', $plans->denyMessage('channel_send'));
        }

        $template = null;
        if (! empty($data['template_id'])) {
            $template = ChannelMessageTemplate::query()
                ->where('workspace_id', $workspace->id)
                ->where('channel', 'whatsapp')
                ->whereKey($data['template_id'])
                ->first();
        }

        $asTemplate = $request->boolean('as_template');
        $body = trim((string) ($data['body'] ?? ''));
        if ($body === '' && ! ($asTemplate && $template)) {
            return back()->with('error', 'Message body is required (or select a template with Send as template ON).');
        }

        $result = $conversations->sendOutbound(
            $workspace,
            $conversation,
            $body !== '' ? $body : (string) ($template?->body ?? ''),
            $request->user(),
            $template,
            $asTemplate
        );

        if (! $result['ok']) {
            return back()->with('error', $result['error'] ?: 'Failed to send.');
        }

        return back()->with('success', 'Reply sent.');
    }

    public function close(Request $request, WhatsappConversation $conversation): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);
        abort_unless($conversation->workspace_id === $workspace->id, 404);

        $conversation->update(['status' => 'closed']);

        return back()->with('success', 'Conversation closed.');
    }

    public function storeTemplate(Request $request, \App\Services\WhatsApp\MetaWhatsAppCloudService $meta): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:4000'],
            'category' => ['required', 'in:utility,marketing,authentication'],
            'language' => ['required', 'string', 'max:12'],
            'wa_status' => ['required', 'in:draft,ready,pending'],
            'submit_to_meta' => ['sometimes', 'boolean'],
        ]);

        $name = $meta->normalizeTemplateName($data['name']);

        $template = ChannelMessageTemplate::query()->create([
            'workspace_id' => $workspace->id,
            'channel' => 'whatsapp',
            'name' => $name,
            'body' => $data['body'],
            'category' => $data['category'],
            'language' => $data['language'],
            'wa_status' => $data['wa_status'],
        ]);

        if ($request->boolean('submit_to_meta')) {
            $result = $meta->createMessageTemplate(
                $workspace,
                $name,
                $data['body'],
                $data['category'],
                $data['language'],
            );

            if (! $result['ok']) {
                return redirect()
                    ->route('whatsapp.index', ['view' => 'templates'])
                    ->with('error', 'Saved locally, but Meta submit failed: '.($result['error'] ?: 'unknown error'));
            }

            $template->update([
                'wa_status' => strtolower((string) ($result['status'] ?: 'pending')),
                'subject' => $result['id'] ? 'meta:'.$result['id'] : $template->subject,
            ]);

            return redirect()
                ->route('whatsapp.index', ['view' => 'templates'])
                ->with('success', 'Template submitted to Meta (status: '.($result['status'] ?: 'PENDING').').');
        }

        return redirect()
            ->route('whatsapp.index', ['view' => 'templates'])
            ->with('success', 'Template saved.');
    }

    public function syncTemplateStatus(
        Request $request,
        ChannelMessageTemplate $template,
        \App\Services\WhatsApp\MetaWhatsAppCloudService $meta,
    ): RedirectResponse {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);
        abort_unless($template->workspace_id === $workspace->id && $template->channel === 'whatsapp', 404);

        $metaId = null;
        if (is_string($template->subject) && str_starts_with($template->subject, 'meta:')) {
            $metaId = substr($template->subject, 5);
        }

        if (blank($metaId)) {
            return back()->with('error', 'Is template pe Meta ID nahi hai. Pehle Submit to Meta karo.');
        }

        $result = $meta->retrieveTemplateStatus($workspace, $metaId);
        if (! $result['ok']) {
            return back()->with('error', 'Meta status fetch failed: '.($result['error'] ?: 'unknown'));
        }

        $status = strtolower((string) ($result['status'] ?: 'pending'));
        $template->update(['wa_status' => $status]);

        $label = strtoupper((string) $result['status']);
        $msg = match ($status) {
            'approved' => "Meta status: APPROVED — ab send kar sakte ho ({$template->name}).",
            'rejected' => "Meta status: REJECTED — template reject ho gaya ({$template->name}).",
            'pending' => "Meta status: PENDING — abhi review mein hai ({$template->name}).",
            default => "Meta status: {$label} ({$template->name}).",
        };

        return redirect()
            ->route('whatsapp.index', ['view' => 'templates'])
            ->with($status === 'rejected' ? 'error' : 'success', $msg);
    }

    public function updateTemplate(Request $request, ChannelMessageTemplate $template): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);
        abort_unless($template->workspace_id === $workspace->id && $template->channel === 'whatsapp', 404);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'body' => ['sometimes', 'string', 'max:4000'],
            'category' => ['sometimes', 'in:utility,marketing,authentication'],
            'language' => ['sometimes', 'string', 'max:12'],
            'wa_status' => ['sometimes', 'in:draft,ready'],
        ]);

        $template->update([
            'name' => $data['name'] ?? $template->name,
            'body' => $data['body'] ?? $template->body,
            'category' => $data['category'] ?? $template->category,
            'language' => $data['language'] ?? $template->language,
            'wa_status' => $data['wa_status'] ?? $template->wa_status,
        ]);

        return redirect()
            ->route('whatsapp.index', ['view' => 'templates'])
            ->with('success', 'Template updated.');
    }

    public function destroyTemplate(Request $request, ChannelMessageTemplate $template): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);
        abort_unless($template->workspace_id === $workspace->id && $template->channel === 'whatsapp', 404);

        $template->delete();

        return redirect()
            ->route('whatsapp.index', ['view' => 'templates'])
            ->with('success', 'Template removed.');
    }

    /**
     * Client onboarding: business number + profile only.
     * Meta Cloud API is completed later by RankwayAI (platform), not by the client.
     */
    public function saveSetup(
        Request $request,
        WorkspaceIntegrationService $integrations,
    ): RedirectResponse {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);

        $canManageMeta = (bool) $request->user()?->is_superadmin;

        $rules = [
            'enabled' => ['sometimes', 'boolean'],
            'credentials' => ['required', 'array'],
            'credentials.business_display_name' => ['required', 'string', 'max:120'],
            'credentials.business_phone' => ['required', 'string', 'max:32'],
            'credentials.business_category' => ['nullable', 'string', 'max:40'],
            'credentials.business_email' => ['nullable', 'email', 'max:190'],
            'credentials.business_website' => ['nullable', 'string', 'max:255'],
            'credentials.business_address' => ['nullable', 'string', 'max:255'],
            'credentials.business_country' => ['nullable', 'string', 'max:8'],
            'credentials.business_about' => ['nullable', 'string', 'max:500'],
        ];

        if ($canManageMeta) {
            $rules['credentials.phone_number_id'] = ['nullable', 'string', 'max:64'];
            $rules['credentials.waba_id'] = ['nullable', 'string', 'max:64'];
            $rules['credentials.access_token'] = ['nullable', 'string', 'max:4000'];
            $rules['credentials.app_secret'] = ['nullable', 'string', 'max:4000'];
            $rules['credentials.verify_token'] = ['nullable', 'string', 'max:255'];
            $rules['credentials.api_version'] = ['nullable', 'string', 'max:16'];
        }

        $data = $request->validate($rules);
        $input = $data['credentials'] ?? [];

        $creds = [];
        foreach (self::BUSINESS_SETUP_KEYS as $key) {
            if (array_key_exists($key, $input)) {
                $creds[$key] = $input[$key];
            }
        }

        // Only platform admins may write Meta Cloud API credentials.
        if ($canManageMeta) {
            foreach (self::META_SETUP_KEYS as $key) {
                if (array_key_exists($key, $input)) {
                    $creds[$key] = $input[$key];
                }
            }
        }

        try {
            $row = $integrations->upsert(
                $workspace,
                'whatsapp_meta',
                $creds,
                $canManageMeta ? $request->boolean('enabled', true) : true
            );
        } catch (\InvalidArgumentException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        // Business submitted without full Meta API → pending (platform connects later).
        if (! $integrations->hasWhatsappMeta($workspace)) {
            $merged = array_merge($row->credentials ?? [], $creds, [
                'onboarding_status' => 'pending_platform',
                'onboarding_submitted_at' => now()->toIso8601String(),
            ]);
            $row->update([
                'credentials' => $merged,
                'status' => 'pending',
                'enabled' => true,
                'last_error' => null,
            ]);

            return redirect()
                ->route('whatsapp.index', ['view' => 'setup'])
                ->with('success', 'Details submitted. RankwayAI will connect Meta WhatsApp for this number — you do not need Developer Console access.');
        }

        $merged = array_merge($row->credentials ?? [], [
            'onboarding_status' => 'connected',
        ]);
        $row->update(['credentials' => $merged]);

        return redirect()
            ->route('whatsapp.index', ['view' => 'setup'])
            ->with('success', 'WhatsApp Business is connected and live for this workspace.');
    }

    /**
     * @return array<string, mixed>
     */
    private function metaSetupPayload(
        Workspace $workspace,
        WorkspaceIntegrationService $integrations,
        mixed $user,
    ): array {
        $def = IntegrationCatalog::find('whatsapp_meta') ?? ['fields' => []];
        $row = $integrations->getRecord($workspace, 'whatsapp_meta');
        $brandProfile = $this->businessDefaultsFromWorkspace($workspace);
        $values = [];
        $secretsSet = [];
        $hasSavedBusiness = false;

        foreach ($def['fields'] as $field) {
            $key = $field['key'];
            $saved = $row ? (string) ($row->credential($key) ?: '') : '';
            if ($saved !== '' && str_starts_with($key, 'business_')) {
                $hasSavedBusiness = true;
            }

            // WABA values: saved setup wins; otherwise seed from Brand so dual entry starts aligned.
            $raw = $saved;
            if ($raw === '' && isset($brandProfile[$key])) {
                $raw = (string) $brandProfile[$key];
            }

            if (! empty($field['secret'])) {
                $secretsSet[$key] = $row ? filled($row->credential($key)) : false;
                $values[$key] = '';
            } else {
                $values[$key] = $raw;
            }
        }

        $path = '/webhooks/meta/whatsapp/'.$workspace->id;
        $connected = $integrations->hasWhatsappMeta($workspace);
        $onboarding = $row ? (string) ($row->credential('onboarding_status') ?: '') : '';
        if ($onboarding === '' && $row) {
            $onboarding = $connected ? 'connected' : (string) $row->status;
        }
        if ($onboarding === '') {
            $onboarding = 'not_started';
        }

        $canManageMeta = (bool) ($user?->is_superadmin);

        return [
            'connected' => $connected,
            'onboarding_status' => $onboarding,
            'can_manage_meta' => $canManageMeta,
            'webhook_url' => rtrim((string) config('app.url'), '/').$path,
            'webhook_path' => $path,
            'fields' => $def['fields'],
            'values' => $values,
            'brand_profile' => $brandProfile,
            'secrets_set' => $secretsSet,
            'business_phone' => $values['business_phone'] ?? '',
            'business_display_name' => $values['business_display_name'] ?? '',
            'autofilled_from' => $hasSavedBusiness ? 'saved_setup' : 'workspace_brand',
        ];
    }

    /**
     * Prefill from Brand / workspace contact fields already on file.
     *
     * @return array<string, string>
     */
    private function businessDefaultsFromWorkspace(Workspace $workspace): array
    {
        $contact = $workspace->contactDetails();
        $industry = strtolower((string) ($workspace->resolvedIndustry() ?? ''));
        $category = match (true) {
            str_contains($industry, 'travel') || str_contains($industry, 'tour') || str_contains($industry, 'holiday') => 'TRAVEL',
            str_contains($industry, 'hotel') || str_contains($industry, 'hospitality') => 'HOTEL',
            str_contains($industry, 'restaurant') || str_contains($industry, 'food') => 'RESTAURANT',
            str_contains($industry, 'health') || str_contains($industry, 'clinic') || str_contains($industry, 'medical') => 'HEALTH',
            str_contains($industry, 'edu') || str_contains($industry, 'school') => 'EDU',
            str_contains($industry, 'beauty') || str_contains($industry, 'salon') => 'BEAUTY',
            str_contains($industry, 'retail') || str_contains($industry, 'shop') => 'RETAIL',
            str_contains($industry, 'finance') || str_contains($industry, 'bank') => 'FINANCE',
            $industry !== '' => 'PROF_SERVICES',
            default => '',
        };

        $city = trim((string) ($workspace->resolvedCity() ?? ''));

        return array_filter([
            'business_display_name' => trim((string) $workspace->name),
            'business_phone' => $contact['phone'] ?? null,
            'business_email' => $contact['email'] ?? null,
            'business_website' => $contact['website'] ?? null,
            'business_category' => $category !== '' ? $category : null,
            'business_address' => $city !== '' ? $city : null,
            'business_country' => 'IN',
            'business_about' => trim((string) $workspace->name) !== ''
                ? trim((string) $workspace->name)
                : null,
        ], fn ($v) => filled($v));
    }
}
