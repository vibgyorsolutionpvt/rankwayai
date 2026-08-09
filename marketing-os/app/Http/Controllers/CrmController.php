<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesWorkspace;
use App\Models\CrmLead;
use App\Models\CrmLeadAttachment;
use App\Models\WhatsappConversation;
use App\Services\WhatsApp\WhatsAppConversationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Inertia\Inertia;
use Inertia\Response;

class CrmController extends Controller
{
    use ResolvesWorkspace;

    public function index(Request $request): Response
    {
        $workspace = $this->workspace($request);

        $leads = CrmLead::query()
            ->where('workspace_id', $workspace->id)
            ->latest()
            ->limit(200)
            ->get();

        $byStage = collect(['new', 'contacted', 'qualified', 'won', 'lost'])
            ->mapWithKeys(fn ($stage) => [
                $stage => $leads->where('stage', $stage)->values(),
            ]);

        return Inertia::render('Crm/Index', [
            'workspace' => ['id' => $workspace->id, 'name' => $workspace->name],
            'leads' => $leads,
            'byStage' => $byStage,
            'counts' => [
                'total' => $leads->count(),
                'pipeline_value' => $leads->whereIn('stage', ['new', 'contacted', 'qualified'])->sum('value_cents'),
                'won' => $leads->where('stage', 'won')->count(),
            ],
        ]);
    }

    public function show(Request $request, CrmLead $lead): Response
    {
        $workspace = $this->workspace($request);
        abort_unless($lead->workspace_id === $workspace->id, 404);

        $activities = $lead->activities()
            ->with('user:id,name')
            ->limit(100)
            ->get()
            ->map(fn ($a) => $a->toClientArray());

        $conversations = WhatsappConversation::query()
            ->where('workspace_id', $workspace->id)
            ->where('crm_lead_id', $lead->id)
            ->orderByDesc('last_message_at')
            ->limit(20)
            ->get()
            ->map(fn (WhatsappConversation $c) => $c->toClientArray());

        $attachments = $lead->attachments()
            ->with('uploader:id,name')
            ->limit(60)
            ->get()
            ->map(fn (CrmLeadAttachment $a) => $a->toClientArray());

        return Inertia::render('Crm/Show', [
            'workspace' => ['id' => $workspace->id, 'name' => $workspace->name],
            'lead' => $lead->toClientArray(),
            'activities' => $activities,
            'conversations' => $conversations,
            'attachments' => $attachments,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:160'],
            'phone' => ['nullable', 'string', 'max:32'],
            'company' => ['nullable', 'string', 'max:120'],
            'stage' => ['nullable', 'in:new,contacted,qualified,won,lost'],
            'source' => ['nullable', 'string', 'max:60'],
            'value_cents' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $lead = CrmLead::query()->create([
            'workspace_id' => $workspace->id,
            'name' => $data['name'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'company' => $data['company'] ?? null,
            'stage' => $data['stage'] ?? 'new',
            'source' => $data['source'] ?? 'manual',
            'value_cents' => $data['value_cents'] ?? 0,
            'notes' => $data['notes'] ?? null,
        ]);

        $lead->logActivity(
            'created',
            'Lead created'.($data['notes'] ?? null ? ': '.$data['notes'] : ''),
            $request->user(),
            ['source' => $lead->source]
        );

        return redirect()->route('crm.show', $lead)->with('success', 'Lead added');
    }

    public function update(Request $request, CrmLead $lead): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);
        abort_unless($lead->workspace_id === $workspace->id, 404);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:160'],
            'phone' => ['nullable', 'string', 'max:32'],
            'company' => ['nullable', 'string', 'max:120'],
            'stage' => ['sometimes', 'in:new,contacted,qualified,won,lost'],
            'source' => ['nullable', 'string', 'max:60'],
            'value_cents' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $fromStage = $lead->stage;
        $lead->update($data);

        if (isset($data['stage']) && $data['stage'] !== $fromStage) {
            $lead->logActivity(
                'stage_change',
                'Stage moved from '.ucfirst($fromStage).' → '.ucfirst($data['stage']),
                $request->user(),
                ['from' => $fromStage, 'to' => $data['stage']]
            );

            if (in_array($data['stage'], ['contacted', 'qualified', 'won'], true)) {
                $lead->forceFill(['last_contacted_at' => now()])->save();
            }
        }

        return back()->with('success', 'Lead updated');
    }

    public function destroy(Request $request, CrmLead $lead): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);
        abort_unless($lead->workspace_id === $workspace->id, 404);

        $lead->delete();

        return redirect()->route('crm.index')->with('success', 'Lead removed');
    }

    public function storeNote(Request $request, CrmLead $lead): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);
        abort_unless($lead->workspace_id === $workspace->id, 404);

        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:5000'],
            'files' => ['nullable', 'array', 'max:10'],
            'files.*' => [
                'file',
                'max:10240',
                'mimes:pdf,xls,xlsx,csv,jpg,jpeg,png,gif,webp',
            ],
        ]);

        $body = trim((string) ($data['body'] ?? ''));
        $files = $request->file('files', []) ?: [];

        if ($body === '' && $files === []) {
            return back()->with('error', 'Add a note or attach at least one file.');
        }

        $saved = $this->storeLeadFiles($workspace->id, $lead, $files, $request->user()?->id);
        $attachmentMeta = collect($saved)->map(fn (CrmLeadAttachment $a) => [
            'id' => $a->id,
            'name' => $a->original_name,
            'kind' => $a->kind,
            'url' => $a->url(),
            'size' => $a->size,
        ])->all();

        if ($body === '') {
            $names = collect($saved)->pluck('original_name')->implode(', ');
            $body = count($saved) === 1
                ? 'Attached '.$names
                : 'Attached '.count($saved).' files: '.$names;
        }

        $lead->logActivity(
            'note',
            $body,
            $request->user(),
            $attachmentMeta === [] ? null : ['attachments' => $attachmentMeta]
        );
        $lead->forceFill(['last_contacted_at' => now()])->save();

        return back()->with('success', $attachmentMeta === [] ? 'Note added' : 'Note saved with files');
    }

    public function openWhatsApp(Request $request, CrmLead $lead, WhatsAppConversationService $conversations): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);
        abort_unless($lead->workspace_id === $workspace->id, 404);

        if (blank($lead->phone)) {
            return back()->with('error', 'Add a phone number on this lead first.');
        }

        $conversation = $conversations->findOrCreate(
            $workspace,
            $lead->phone,
            $lead->name,
            $lead->id
        );

        if ($conversation->crm_lead_id !== $lead->id) {
            $conversation->forceFill(['crm_lead_id' => $lead->id])->save();
        }

        $lead->logActivity(
            'whatsapp',
            'Opened WhatsApp conversation with '.$conversation->phone,
            $request->user(),
            ['conversation_id' => $conversation->id]
        );

        return redirect()->route('whatsapp.index', [
            'view' => 'conversations',
            'conversation' => $conversation->id,
        ]);
    }

    public function downloadAttachment(Request $request, CrmLead $lead, CrmLeadAttachment $attachment): StreamedResponse
    {
        $workspace = $this->workspace($request);
        abort_unless($lead->workspace_id === $workspace->id, 404);
        abort_unless($attachment->workspace_id === $workspace->id && $attachment->crm_lead_id === $lead->id, 404);
        abort_unless(Storage::disk($attachment->disk)->exists($attachment->path), 404);

        return Storage::disk($attachment->disk)->download(
            $attachment->path,
            $attachment->original_name
        );
    }

    public function destroyAttachment(Request $request, CrmLead $lead, CrmLeadAttachment $attachment): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);
        abort_unless($lead->workspace_id === $workspace->id, 404);
        abort_unless($attachment->workspace_id === $workspace->id && $attachment->crm_lead_id === $lead->id, 404);

        $name = $attachment->original_name;
        $kind = $attachment->kind;
        Storage::disk($attachment->disk)->delete($attachment->path);
        $attachment->delete();

        $lead->logActivity(
            'file',
            'Removed '.$name,
            $request->user(),
            ['kind' => $kind]
        );

        return back()->with('success', 'File removed');
    }

    /**
     * @param  array<int, \Illuminate\Http\UploadedFile>  $files
     * @return list<CrmLeadAttachment>
     */
    private function storeLeadFiles(int $workspaceId, CrmLead $lead, array $files, ?int $userId): array
    {
        if ($files === []) {
            return [];
        }

        $disk = config('media.disk', 'public');
        $saved = [];

        foreach ($files as $file) {
            $path = $file->store('crm/'.$workspaceId.'/'.$lead->id, $disk);
            $extension = strtolower($file->getClientOriginalExtension() ?: '');
            $mime = $file->getMimeType();
            $kind = CrmLeadAttachment::kindFromMime($mime, $extension);

            $saved[] = CrmLeadAttachment::query()->create([
                'workspace_id' => $workspaceId,
                'crm_lead_id' => $lead->id,
                'uploaded_by' => $userId,
                'disk' => $disk,
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $mime,
                'size' => $file->getSize() ?: 0,
                'kind' => $kind,
            ]);
        }

        return $saved;
    }
}
