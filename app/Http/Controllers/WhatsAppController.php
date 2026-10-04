<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesWorkspace;
use App\Jobs\ImportCrmLeadGroupJob;
use App\Models\ChannelCampaign;
use App\Models\ChannelMessageTemplate;
use App\Models\CrmLead;
use App\Models\CrmLeadGroup;
use App\Models\WhatsappConversation;
use App\Models\Workspace;
use App\Services\Billing\PlanAccess;
use App\Services\Channels\ChannelCampaignService;
use App\Services\Channels\ChannelTemplateService;
use App\Services\Integrations\IntegrationCatalog;
use App\Services\Integrations\WorkspaceIntegrationService;
use App\Services\WhatsApp\MetaEmbeddedSignupService;
use App\Services\WhatsApp\MetaWhatsAppCloudService;
use App\Services\WhatsApp\WhatsAppConversationService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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
        'app_id',
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
            : ($integrations->whatsappConnected($workspace) ? 'conversations' : 'setup');
        $activeId = (int) $request->query('conversation', 0);
        $inbox = in_array($request->query('inbox'), ['mine', 'unassigned'], true)
            ? $request->query('inbox')
            : 'all';

        $user = $request->user();
        $visible = fn () => WhatsappConversation::query()
            ->where('workspace_id', $workspace->id)
            ->visibleTo($user, $workspace);

        $seesAll = WhatsappConversation::seesAllFor($user, $workspace);
        $memberFilter = $seesAll ? max(0, (int) $request->query('member', 0)) : 0;
        $campaignFilter = $seesAll ? max(0, (int) $request->query('campaign', 0)) : 0;
        $search = Str::limit(trim((string) $request->query('q', '')), 80, '');
        $filtered = fn () => $visible()
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $digits = preg_replace('/\D+/', '', $search);
                $q->where(fn ($w) => $w
                    ->where('contact_name', 'like', $like)
                    ->orWhere('last_message_preview', 'like', $like)
                    ->when($digits !== '', fn ($p) => $p->orWhere('phone', 'like', '%'.$digits.'%')));
            })
            ->when($inbox === 'mine', fn ($q) => $q->where('assigned_user_id', $user->id))
            ->when($inbox === 'unassigned', fn ($q) => $q->whereNull('assigned_user_id'))
            ->when($memberFilter, fn ($q) => $q->where('assigned_user_id', $memberFilter))
            ->when($campaignFilter, fn ($q) => $q->whereHas(
                'messages',
                fn ($m) => $m->where('channel_campaign_id', $campaignFilter)
            ));

        $threadList = $filtered()
            ->with('assignee:id,name')
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->limit(80)
            ->get()
            ->map(fn (WhatsappConversation $c) => $c->toClientArray());

        $active = null;
        $messages = [];
        if ($activeId > 0) {
            $activeModel = $visible()->whereKey($activeId)->first();
            if ($activeModel) {
                $conversations->markRead($activeModel);
                $active = $activeModel->fresh()->toClientArray();
                $messages = $activeModel->messages()
                    ->with('user:id,name')
                    ->orderByDesc('id')
                    ->limit(500)
                    ->get()
                    ->reverse()
                    ->values()
                    ->map(fn ($m) => $m->toClientArray());
            }
        }

        $importedGroupId = (int) session()->pull('whatsapp_imported_group_id', 0);

        return Inertia::render('WhatsApp/Index', [
            'workspace' => ['id' => $workspace->id, 'name' => $workspace->name],
            'view' => $view,
            'provider' => $channels->provider($workspace, 'whatsapp'),
            'plan' => $plans->summary($workspace),
            'meta_setup' => $this->metaSetupPayload($workspace, $integrations, $request->user()),
            'conversations' => $threadList,
            'inbox' => $inbox,
            'inboxFilters' => [
                'member' => $memberFilter ?: null,
                'campaign' => $campaignFilter ?: null,
                'q' => $search,
            ],
            'inboxTotal' => $filtered()->count(),
            'currentUserId' => $user->id,
            'seesAllConversations' => $seesAll,
            'teamMembers' => $workspace->users()
                ->orderBy('name')
                ->get(['users.id', 'users.name'])
                ->map(fn ($user) => ['id' => $user->id, 'name' => $user->name])
                ->values(),
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
                ->with(['whatsappTemplate:id,name,wa_status', 'crmLeadGroup:id,name'])
                ->latest()
                ->limit(40)
                ->get(),
            'importedGroupId' => $importedGroupId,
            'leadGroups' => CrmLeadGroup::query()
                ->where('workspace_id', $workspace->id)
                ->withCount('leads')
                ->latest()
                ->limit(100)
                ->get([
                    'id',
                    'name',
                    'status',
                    'total_rows',
                    'created_count',
                    'updated_count',
                    'skipped_count',
                    'error_message',
                    'created_at',
                ]),
            'leadImportFields' => collect($workspace->crm_lead_custom_fields ?? [])
                ->map(fn (array $field) => ['key' => $field['key'], 'label' => $field['label']])
                ->values(),
            'audienceOptions' => $this->audienceOptions($workspace),
            'leads' => CrmLead::query()
                ->where('workspace_id', $workspace->id)
                ->whereNotNull('phone')
                ->orderByDesc('id')
                ->limit(1000)
                ->get(['id', 'name', 'phone', 'email', 'stage']),
            'placeholders' => [
                ['token' => '{{name}}', 'label' => 'Lead name'],
                ['token' => '{{brand}}', 'label' => 'Brand'],
                ['token' => '{{cta}}', 'label' => 'CTA label'],
                ['token' => '{{cta_url}}', 'label' => 'CTA URL'],
                ['token' => '{{phone}}', 'label' => 'Brand phone'],
                ...collect($workspace->crm_lead_custom_fields ?? [])
                    ->map(fn (array $field) => [
                        'token' => '{{'.$field['key'].'}}',
                        'label' => $field['label'],
                        'custom' => true,
                    ])
                    ->all(),
            ],
            'brand_tokens' => $templates->tokens($workspace),
            'counts' => [
                'conversations' => $visible()->count(),
                'unread' => (int) $visible()->sum('unread_count'),
                'templates' => ChannelMessageTemplate::query()->where('workspace_id', $workspace->id)->where('channel', 'whatsapp')->count(),
                'campaigns' => ChannelCampaign::query()->where('workspace_id', $workspace->id)->where('channel', 'whatsapp')->count(),
            ],
        ]);
    }

    public function storeCampaignLeadGroup(Request $request): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
        ]);
        $name = trim($data['name']);
        if (CrmLeadGroup::query()->where('workspace_id', $workspace->id)->where('name', $name)->exists()) {
            throw ValidationException::withMessages(['name' => 'A contact group with this name already exists.']);
        }

        $group = CrmLeadGroup::query()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $request->user()->id,
            'name' => $name,
            'status' => 'completed',
        ]);
        session()->flash('whatsapp_imported_group_id', $group->id);

        return redirect()
            ->route('whatsapp.index', ['view' => 'campaigns'])
            ->with('success', "Contact group “{$group->name}” created. Import a CSV or Excel file to add contacts.");
    }

    public function importCampaignLeads(Request $request, CrmLeadGroup $group): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);
        abort_unless($group->workspace_id === $workspace->id, 404);

        if ($group->status === 'processing') {
            return back()->with('error', "“{$group->name}” is still importing. Wait for it to finish before importing another file.");
        }

        $data = $request->validate([
            'csv_file' => ['required', 'file', 'extensions:csv,txt,xls,xlsx', 'max:25600'],
        ], [
            'csv_file.extensions' => 'Upload a CSV or Excel file (.csv, .xls, .xlsx).',
        ]);
        $extension = strtolower($data['csv_file']->getClientOriginalExtension());
        $path = $data['csv_file']->storeAs('crm/lead-group-imports', Str::uuid()->toString().'.'.$extension, 'local');
        if (! is_string($path) || $path === '') {
            return back()->with('error', 'Unable to store the uploaded CSV file. Please retry.');
        }

        $group->update([
            'status' => 'processing',
            'error_message' => null,
            'total_rows' => 0,
            'created_count' => 0,
            'updated_count' => 0,
            'skipped_count' => 0,
        ]);
        ImportCrmLeadGroupJob::dispatch($group->id, $path);
        session()->flash('whatsapp_imported_group_id', $group->id);

        return redirect()
            ->route('whatsapp.index', ['view' => 'campaigns'])
            ->with('success', "Importing contacts into “{$group->name}”. Existing contacts in the group stay as they are.");
    }

    public function audiencePreview(Request $request, ChannelCampaignService $channels): JsonResponse
    {
        $workspace = $this->workspace($request);

        $data = $request->validate([
            'recipient_mode' => ['required', 'in:all,selected,group'],
            'lead_ids' => ['nullable', 'array', 'max:5000'],
            'lead_ids.*' => ['integer'],
            'crm_lead_group_id' => ['nullable', 'integer'],
            ...ChannelsController::audienceFilterRules(),
        ]);

        $empty = ['matched' => 0, 'recipients' => 0, 'duplicates' => 0, 'opted_out' => 0];
        $groupId = null;
        $leadIds = null;
        if ($data['recipient_mode'] === 'group') {
            $groupId = CrmLeadGroup::query()
                ->where('workspace_id', $workspace->id)
                ->whereKey($data['crm_lead_group_id'] ?? 0)
                ->value('id');
            if (! $groupId) {
                return response()->json($empty);
            }
        } elseif ($data['recipient_mode'] === 'selected') {
            $leadIds = array_values(array_unique($data['lead_ids'] ?? []));
            if ($leadIds === []) {
                return response()->json($empty);
            }
        }

        return response()->json($channels->audienceSummary(
            $workspace->id,
            'whatsapp',
            $groupId,
            $leadIds,
            $data['recipient_mode'] === 'all' ? ChannelsController::audienceFilters($data) : [],
        ));
    }

    public function destroyCampaignLeadGroup(Request $request, CrmLeadGroup $group): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);
        abort_unless($group->workspace_id === $workspace->id, 404);

        if ($group->status === 'processing') {
            return back()->with('error', 'Wait for the contact group import to finish before deleting it.');
        }

        $group->delete();

        return back()->with('success', 'Contact group deleted. CRM contacts remain available.');
    }

    public function start(
        Request $request,
        WhatsAppConversationService $conversations,
        PlanAccess $plans,
        WorkspaceIntegrationService $integrations,
    ): RedirectResponse {
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

        if (! $integrations->whatsappConnected($workspace)) {
            return redirect()
                ->route('whatsapp.index', ['view' => 'setup'])
                ->with('error', WorkspaceIntegrationService::whatsappNotConnectedMessage());
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
        if (! $conversation->isVisibleTo($request->user(), $workspace)) {
            return back()->with('error', 'This contact is assigned to '.($conversation->assignee?->name ?? 'another team member').'.');
        }
        $conversations->assignIfUnassigned($conversation, $request->user());

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
        abort_unless($conversation->isVisibleTo($request->user(), $workspace), 404);

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
        abort_unless($conversation->isVisibleTo($request->user(), $workspace), 404);

        $conversation->update(['status' => 'closed']);

        return back()->with('success', 'Conversation closed.');
    }

    public function assign(Request $request, WhatsappConversation $conversation): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);
        abort_unless($conversation->workspace_id === $workspace->id, 404);
        $user = $request->user();
        abort_unless($conversation->isVisibleTo($user, $workspace), 404);

        $data = $request->validate([
            'user_id' => ['nullable', 'integer'],
        ]);

        $userId = isset($data['user_id']) ? (int) $data['user_id'] : null;
        if ($userId && ! $workspace->users()->whereKey($userId)->exists()) {
            throw ValidationException::withMessages(['user_id' => 'Choose a member of this workspace.']);
        }

        $conversation->update(['assigned_user_id' => $userId]);
        $name = $userId ? $conversation->fresh('assignee')->assignee?->name : null;
        $message = $name ? "Conversation assigned to {$name}." : 'Conversation unassigned.';

        if (! $conversation->fresh()->isVisibleTo($user, $workspace)) {
            return redirect()
                ->route('whatsapp.index', ['view' => 'conversations'])
                ->with('success', $message);
        }

        return back()->with('success', $message);
    }

    public function storeTemplate(Request $request, MetaWhatsAppCloudService $meta): RedirectResponse
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
        ] + $this->templateComponentRules($request));

        $prepared = $this->prepareTemplateComponents($request, $workspace, $meta);
        if ($prepared['error']) {
            return back()->withInput()->with('error', $prepared['error']);
        }

        $name = $meta->normalizeTemplateName($data['name']);

        $template = ChannelMessageTemplate::query()->create([
            'workspace_id' => $workspace->id,
            'channel' => 'whatsapp',
            'name' => $name,
            'body' => $data['body'],
            'category' => $data['category'],
            'language' => $data['language'],
            'wa_status' => $data['wa_status'],
            'components' => $prepared['components'],
        ]);

        if ($request->boolean('submit_to_meta')) {
            $result = $meta->createMessageTemplate(
                $workspace,
                $name,
                $data['body'],
                $data['category'],
                $data['language'],
                $prepared['components'],
                $prepared['header_handle'],
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
        MetaWhatsAppCloudService $meta,
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

    public function updateTemplate(
        Request $request,
        ChannelMessageTemplate $template,
        MetaWhatsAppCloudService $meta,
    ): RedirectResponse {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);
        abort_unless($template->workspace_id === $workspace->id && $template->channel === 'whatsapp', 404);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'body' => ['sometimes', 'string', 'max:4000'],
            'category' => ['sometimes', 'in:utility,marketing,authentication'],
            'language' => ['sometimes', 'string', 'max:12'],
            'wa_status' => ['sometimes', 'in:draft,ready'],
        ] + $this->templateComponentRules($request, $template));

        $prepared = $this->prepareTemplateComponents($request, $workspace, $meta, $template);
        if ($prepared['error']) {
            return back()->withInput()->with('error', $prepared['error']);
        }

        $oldPath = $template->components['header']['media_path'] ?? null;
        $template->update([
            'name' => $data['name'] ?? $template->name,
            'body' => $data['body'] ?? $template->body,
            'category' => $data['category'] ?? $template->category,
            'language' => $data['language'] ?? $template->language,
            'wa_status' => $data['wa_status'] ?? $template->wa_status,
            'components' => $prepared['components'],
        ]);

        $newPath = $prepared['components']['header']['media_path'] ?? null;
        if (is_string($oldPath) && $oldPath !== $newPath) {
            Storage::disk('local')->delete($oldPath);
        }

        return redirect()
            ->route('whatsapp.index', ['view' => 'templates'])
            ->with('success', 'Template updated.');
    }

    public function templateMedia(Request $request, ChannelMessageTemplate $template): BinaryFileResponse
    {
        $workspace = $this->workspace($request);
        abort_unless($template->workspace_id === $workspace->id && $template->channel === 'whatsapp', 404);

        $header = $template->components['header'] ?? [];
        $path = $header['media_path'] ?? null;
        abort_unless(
            ($header['format'] ?? null) === 'IMAGE'
                && is_string($path)
                && Storage::disk('local')->exists($path),
            404,
        );

        return response()->file(Storage::disk('local')->path($path), [
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function destroyTemplate(Request $request, ChannelMessageTemplate $template): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);
        abort_unless($template->workspace_id === $workspace->id && $template->channel === 'whatsapp', 404);

        $mediaPath = $template->components['header']['media_path'] ?? null;
        $template->delete();
        if (is_string($mediaPath) && $mediaPath !== '') {
            Storage::disk('local')->delete($mediaPath);
        }

        return redirect()
            ->route('whatsapp.index', ['view' => 'templates'])
            ->with('success', 'Template removed.');
    }

    /**
     * @return array<string, mixed>
     */
    private function templateComponentRules(Request $request, ?ChannelMessageTemplate $existing = null): array
    {
        $format = strtoupper((string) $request->input('header_format', 'NONE'));
        $mediaRequired = in_array($format, ['IMAGE', 'DOCUMENT'], true)
            && ! (($existing?->components['header']['format'] ?? null) === $format
                && filled($existing?->components['header']['media_path'] ?? null));
        $mediaRules = ['nullable', 'file'];
        if ($mediaRequired) {
            $mediaRules[] = 'required';
        }
        if ($format === 'IMAGE') {
            array_push($mediaRules, 'mimes:jpg,jpeg,png', 'max:5120');
        } elseif ($format === 'DOCUMENT') {
            array_push($mediaRules, 'mimes:pdf', 'max:102400');
        }

        return [
            'header_format' => ['sometimes', 'in:NONE,TEXT,IMAGE,DOCUMENT'],
            'header_text' => ['nullable', 'required_if:header_format,TEXT', 'string', 'max:60'],
            'header_media' => $mediaRules,
            'footer' => ['nullable', 'string', 'max:60'],
            'buttons' => ['nullable', 'array', 'max:5'],
            'buttons.*.type' => ['required', 'in:URL,PHONE_NUMBER,QUICK_REPLY'],
            'buttons.*.text' => ['required', 'string', 'max:25'],
            'buttons.*.url' => ['nullable', 'url', 'max:2000'],
            'buttons.*.phone_number' => ['nullable', 'regex:/^\\+[1-9]\\d{6,14}$/'],
        ];
    }

    /**
     * @return array{components:array<string, mixed>,header_handle:?string,error:?string}
     */
    private function prepareTemplateComponents(
        Request $request,
        Workspace $workspace,
        MetaWhatsAppCloudService $meta,
        ?ChannelMessageTemplate $existing = null,
    ): array {
        $format = strtoupper((string) $request->input(
            'header_format',
            $existing?->components['header']['format'] ?? 'NONE',
        ));
        $header = ['format' => $format];
        $headerHandle = null;

        if ($format === 'TEXT') {
            $header['text'] = trim((string) $request->input(
                'header_text',
                $existing?->components['header']['text'] ?? '',
            ));
        } elseif (in_array($format, ['IMAGE', 'DOCUMENT'], true)) {
            $file = $request->file('header_media');
            $existingHeader = $existing?->components['header'] ?? [];
            if ($file instanceof UploadedFile) {
                $upload = $meta->uploadTemplateMedia($workspace, $file);
                if (! $upload['ok']) {
                    return ['components' => [], 'header_handle' => null, 'error' => $upload['error']];
                }

                $extension = $file->guessExtension() ?: $file->getClientOriginalExtension();
                $path = $file->storeAs(
                    "whatsapp/templates/{$workspace->id}",
                    Str::uuid().($extension ? '.'.$extension : ''),
                    'local',
                );
                if (! is_string($path) || $path === '') {
                    return [
                        'components' => [],
                        'header_handle' => null,
                        'error' => 'Unable to save the uploaded header media. Please try again.',
                    ];
                }

                $header = [
                    'format' => $format,
                    'media_id' => $upload['id'],
                    'media_path' => $path,
                    'filename' => $file->getClientOriginalName(),
                ];
                $headerHandle = $upload['handle'];
            } elseif (
                ($existingHeader['format'] ?? null) === $format
                && filled($existingHeader['media_path'] ?? null)
                && Storage::disk('local')->exists($existingHeader['media_path'])
            ) {
                $header = $existingHeader;
            } else {
                return [
                    'components' => [],
                    'header_handle' => null,
                    'error' => 'Select a header media file before saving this template.',
                ];
            }
        }

        $buttons = array_values($request->input('buttons', []));
        $ctaCount = count(array_filter($buttons, fn ($button) => in_array($button['type'] ?? '', ['URL', 'PHONE_NUMBER'], true)));
        $quickReplyCount = count(array_filter($buttons, fn ($button) => ($button['type'] ?? '') === 'QUICK_REPLY'));
        foreach ($buttons as $index => $button) {
            if (($button['type'] ?? '') === 'URL' && blank($button['url'] ?? null)) {
                throw ValidationException::withMessages(["buttons.{$index}.url" => 'Add a URL for this button.']);
            }
            if (($button['type'] ?? '') === 'PHONE_NUMBER' && blank($button['phone_number'] ?? null)) {
                throw ValidationException::withMessages(["buttons.{$index}.phone_number" => 'Add an international phone number for this button.']);
            }
        }
        if ($ctaCount > 2 || $quickReplyCount > 3) {
            throw ValidationException::withMessages([
                'buttons' => 'WhatsApp supports up to 2 call-to-action buttons or 3 quick replies.',
            ]);
        }

        $components = [
            'header' => $header,
            'footer' => trim((string) $request->input('footer'))
                ?: trim((string) ($existing?->components['footer'] ?? ''))
                ?: 'Reply STOP to opt out',
            'buttons' => $buttons,
        ];

        return ['components' => $components, 'header_handle' => $headerHandle, 'error' => null];
    }

    /**
     * Each workspace connects its own Meta WhatsApp Cloud API number.
     * Nothing is sent from a shared/platform number.
     */
    public function saveSetup(
        Request $request,
        WorkspaceIntegrationService $integrations,
        MetaWhatsAppCloudService $meta,
    ): RedirectResponse {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);

        $data = $request->validate([
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
            'credentials.phone_number_id' => ['nullable', 'string', 'regex:/^\d{6,32}$/'],
            'credentials.waba_id' => ['nullable', 'string', 'regex:/^\d{6,32}$/'],
            'credentials.app_id' => ['nullable', 'string', 'regex:/^\d{5,32}$/'],
            'credentials.access_token' => ['nullable', 'string', 'max:4000'],
            'credentials.app_secret' => ['nullable', 'string', 'max:4000'],
            'credentials.verify_token' => ['nullable', 'string', 'max:255'],
            'credentials.api_version' => ['nullable', 'string', 'regex:/^v\d{1,3}\.\d{1,2}$/'],
        ], [
            'credentials.phone_number_id.regex' => 'Phone number ID should be digits only (Meta → WhatsApp → API Setup).',
            'credentials.waba_id.regex' => 'WhatsApp Business Account ID should be digits only.',
            'credentials.app_id.regex' => 'Meta App ID should be digits only.',
            'credentials.api_version.regex' => 'API version looks like v21.0.',
        ]);
        $input = $data['credentials'] ?? [];

        $creds = [];
        foreach ([...self::BUSINESS_SETUP_KEYS, ...self::META_SETUP_KEYS] as $key) {
            if (array_key_exists($key, $input)) {
                $creds[$key] = $input[$key];
            }
        }

        if (filled($creds['phone_number_id'] ?? null)
            && $integrations->whatsappPhoneIdUsedElsewhere($workspace, (string) $creds['phone_number_id'])) {
            return back()->withInput()->with('error', 'This WhatsApp number is already connected to another workspace.');
        }

        $existing = $integrations->getRecord($workspace, 'whatsapp_meta');
        if (blank($creds['verify_token'] ?? null) && blank($existing?->credential('verify_token'))) {
            $creds['verify_token'] = Str::random(32);
        }

        $enabled = $request->boolean('enabled', true);

        try {
            $row = $integrations->upsert($workspace, 'whatsapp_meta', $creds, $enabled);
        } catch (\InvalidArgumentException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $phoneId = (string) ($row->credential('phone_number_id') ?: '');
        $token = (string) ($row->credential('access_token') ?: '');

        if ($phoneId === '' || $token === '') {
            $row->update([
                'credentials' => array_merge($row->credentials ?? [], ['onboarding_status' => 'incomplete']),
                'status' => 'disconnected',
                'last_error' => 'Phone number ID and access token are required to send messages.',
            ]);

            return redirect()
                ->route('whatsapp.index', ['view' => 'setup'])
                ->with('success', 'Business details saved. Connect your WhatsApp number to start sending — no WhatsApp message goes out until then.');
        }

        if ($existing?->credential('onboarding_status') === 'needs_pin') {
            $row->update([
                'credentials' => array_merge($row->credentials ?? [], ['onboarding_status' => 'needs_pin']),
                'status' => 'error',
                'connected_at' => null,
            ]);

            return redirect()
                ->route('whatsapp.index', ['view' => 'setup'])
                ->with('success', 'Profile saved. Enter your two-step PIN to activate the number before sending.');
        }

        if (! $enabled) {
            return redirect()
                ->route('whatsapp.index', ['view' => 'setup'])
                ->with('success', 'WhatsApp is paused for this workspace. No messages will be sent.');
        }

        $check = $meta->verifyCredentials($phoneId, $token, (string) ($row->credential('api_version') ?: 'v21.0'));

        if (! $check['ok'] && $check['reachable']) {
            $row->update([
                'credentials' => array_merge($row->credentials ?? [], ['onboarding_status' => 'error']),
                'status' => 'error',
                'last_error' => $check['error'],
                'connected_at' => null,
            ]);

            return redirect()
                ->route('whatsapp.index', ['view' => 'setup'])
                ->with('error', 'Meta rejected these credentials: '.$check['error']);
        }

        $row->update([
            'credentials' => array_merge($row->credentials ?? [], array_filter([
                'onboarding_status' => 'connected',
                'verified_phone' => $check['display_phone_number'] ?? null,
                'verified_name' => $check['verified_name'] ?? null,
            ], fn ($v) => filled($v))),
            'status' => 'connected',
            'last_error' => null,
            'connected_at' => $row->connected_at ?: now(),
        ]);

        $label = trim(($check['verified_name'] ?? '').' '.($check['display_phone_number'] ?? ''));

        return redirect()
            ->route('whatsapp.index', ['view' => 'setup'])
            ->with('success', $check['ok']
                ? 'WhatsApp connected'.($label !== '' ? " ({$label})" : '').'. Messages from this workspace now go out from your own number.'
                : 'Credentials saved, but Meta could not be reached to verify them right now. Send a test message to confirm.');
    }

    public function embeddedSignup(Request $request, MetaEmbeddedSignupService $signup): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:2048'],
            'waba_id' => ['nullable', 'string', 'regex:/^\d{6,32}$/'],
            'phone_number_id' => ['nullable', 'string', 'regex:/^\d{6,32}$/'],
            'pin' => ['nullable', 'digits:6'],
        ]);

        try {
            $result = $signup->complete(
                $workspace,
                $data['code'],
                $data['waba_id'] ?? null,
                $data['phone_number_id'] ?? null,
                $data['pin'] ?? null,
            );
        } catch (ConnectionException $e) {
            return redirect()
                ->route('whatsapp.index', ['view' => 'setup'])
                ->with('error', 'Could not reach Meta right now. Please try Connect WhatsApp again in a minute.');
        }

        if (! $result['ok']) {
            return redirect()
                ->route('whatsapp.index', ['view' => 'setup'])
                ->with('error', $result['error']);
        }

        $label = trim(($result['verified_name'] ?? '').' '.($result['display_phone_number'] ?? ''));

        return redirect()
            ->route('whatsapp.index', ['view' => 'setup'])
            ->with('success', 'WhatsApp connected'.($label !== '' ? " ({$label})" : '').'. Submit a template next — campaigns can start once Meta approves it.');
    }

    public function registerNumber(Request $request, MetaEmbeddedSignupService $signup): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);

        $data = $request->validate([
            'pin' => ['required', 'digits:6'],
        ]);

        try {
            $result = $signup->register($workspace, $data['pin']);
        } catch (ConnectionException $e) {
            return back()->with('error', 'Could not reach Meta right now. Please try again in a minute.');
        }

        return redirect()
            ->route('whatsapp.index', ['view' => 'setup'])
            ->with(
                $result['ok'] ? 'success' : 'error',
                $result['ok'] ? 'Number activated. WhatsApp is ready to send.' : 'Activation failed: '.$result['error']
            );
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
        if ($connected) {
            $onboarding = 'connected';
        } elseif ($onboarding === 'connected' || $onboarding === 'pending_platform') {
            $onboarding = $row && ! $row->enabled ? 'paused' : 'incomplete';
        }
        if ($onboarding === '') {
            $onboarding = $row ? 'incomplete' : 'not_started';
        }

        return [
            'connected' => $connected,
            'enabled' => $row ? (bool) $row->enabled : true,
            'onboarding_status' => $onboarding,
            'can_manage_meta' => $user !== null && $user->can('update', $workspace),
            'last_error' => $row?->last_error,
            'verified_phone' => $row ? ($row->credential('verified_phone') ?: null) : null,
            'verified_name' => $row ? ($row->credential('verified_name') ?: null) : null,
            'connected_via' => $row ? ($row->credential('connected_via') ?: 'manual') : null,
            'embedded_signup' => app(MetaEmbeddedSignupService::class)->clientConfig(),
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
    /**
     * @return array<string, mixed>
     */
    private function audienceOptions(Workspace $workspace): array
    {
        $withPhone = fn () => CrmLead::query()
            ->where('workspace_id', $workspace->id)
            ->whereNotNull('phone')
            ->where('phone', '!=', '');

        $stageCounts = $withPhone()->selectRaw('stage, count(*) as total')->groupBy('stage')->pluck('total', 'stage');

        return [
            'stages' => collect(ChannelCampaignService::LEAD_STAGES)
                ->map(fn (string $stage) => [
                    'value' => $stage,
                    'label' => ucfirst($stage),
                    'count' => (int) ($stageCounts[$stage] ?? 0),
                ])
                ->values(),
            'default_stages' => ChannelCampaignService::DEFAULT_AUDIENCE_STAGES,
            'sources' => $withPhone()
                ->whereNotNull('source')
                ->where('source', '!=', '')
                ->selectRaw('source, count(*) as total')
                ->groupBy('source')
                ->orderByDesc('total')
                ->limit(30)
                ->get()
                ->map(fn ($row) => ['value' => (string) $row->source, 'count' => (int) $row->total])
                ->values(),
            'custom_fields' => collect($workspace->crm_lead_custom_fields ?? [])
                ->map(fn (array $field) => ['key' => $field['key'], 'label' => $field['label']])
                ->values(),
        ];
    }

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
