<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesWorkspace;
use App\Models\ChannelCampaign;
use App\Models\ChannelMessageTemplate;
use App\Models\CrmLead;
use App\Models\WhatsappConversation;
use App\Services\Billing\PlanAccess;
use App\Services\Channels\ChannelCampaignService;
use App\Services\Channels\ChannelTemplateService;
use App\Services\WhatsApp\WhatsAppConversationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WhatsAppController extends Controller
{
    use ResolvesWorkspace;

    public function index(
        Request $request,
        WhatsAppConversationService $conversations,
        ChannelCampaignService $channels,
        ChannelTemplateService $templates,
        PlanAccess $plans
    ): Response {
        $workspace = $this->workspace($request);
        $view = in_array($request->query('view'), ['conversations', 'templates', 'campaigns'], true)
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
            'body' => ['required', 'string', 'max:4000'],
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

        $body = $template?->body ?? $data['body'];
        $result = $conversations->sendOutbound(
            $workspace,
            $conversation,
            $body,
            $request->user(),
            $template,
            $request->boolean('as_template')
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

    public function storeTemplate(Request $request): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:4000'],
            'category' => ['nullable', 'in:marketing,utility,authentication'],
            'language' => ['nullable', 'string', 'max:16'],
            'wa_status' => ['nullable', 'in:draft,ready'],
        ]);

        ChannelMessageTemplate::query()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $request->user()->id,
            'name' => $data['name'],
            'channel' => 'whatsapp',
            'category' => $data['category'] ?? 'utility',
            'language' => $data['language'] ?? 'en',
            'wa_status' => $data['wa_status'] ?? 'draft',
            'body' => $data['body'],
        ]);

        return redirect()
            ->route('whatsapp.index', ['view' => 'templates'])
            ->with('success', 'WhatsApp template saved.');
    }

    public function updateTemplate(Request $request, ChannelMessageTemplate $template): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);
        abort_unless($template->workspace_id === $workspace->id && $template->channel === 'whatsapp', 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:4000'],
            'category' => ['nullable', 'in:marketing,utility,authentication'],
            'language' => ['nullable', 'string', 'max:16'],
            'wa_status' => ['nullable', 'in:draft,ready'],
        ]);

        $template->update([
            'name' => $data['name'],
            'body' => $data['body'],
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
}
