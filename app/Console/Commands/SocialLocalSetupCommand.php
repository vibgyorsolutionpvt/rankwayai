<?php

namespace App\Console\Commands;

use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\PlanAccess;
use App\Services\Integrations\WorkspaceIntegrationService;
use App\Services\Social\SocialConnectionService;
use App\Services\Social\SocialPublisherService;
use Illuminate\Console\Command;

class SocialLocalSetupCommand extends Command
{
    protected $signature = 'social:local-setup
                            {--workspace=7 : Workspace id (default: Vibgyor Holidays)}
                            {--publish : After checks, publish a text-only test post to Facebook}
                            {--force : Skip confirmations}';

    protected $description = 'Prepare & verify local Meta (Facebook/IG) connect + publish testing';

    public function handle(
        WorkspaceIntegrationService $integrations,
        SocialConnectionService $connections,
        PlanAccess $plans,
        SocialPublisherService $publisher,
    ): int {
        $workspaceId = (int) $this->option('workspace');
        $workspace = Workspace::query()->find($workspaceId);

        if (! $workspace) {
            $this->error("Workspace #{$workspaceId} not found.");

            return self::FAILURE;
        }

        $this->info('Local social test setup');
        $this->line('Workspace: #'.$workspace->id.' — '.$workspace->name);
        $this->line('APP_URL: '.config('app.url'));
        $this->newLine();

        $meta = $integrations->get($workspace, 'meta');
        $appId = (string) data_get($meta?->credentials ?? [], 'app_id', '');
        $hasSecret = filled(data_get($meta?->credentials ?? [], 'app_secret'));
        $modes = $connections->modes($workspace);

        $this->table(['Check', 'Status'], [
            ['Meta App ID on workspace', $appId !== '' ? $appId : 'MISSING — Settings → Providers → Meta'],
            ['Meta App Secret', $hasSecret ? 'set' : 'MISSING'],
            ['Facebook mode', $modes['facebook'] ?? '?'],
            ['Instagram mode', $modes['instagram'] ?? '?'],
            ['Plan allows publish', $plans->allows($workspace, 'social_publish') ? 'yes' : 'NO — '.$plans->denyMessage('social_publish')],
            ['Queue (publish-now uses sync)', config('queue.default')],
        ]);

        $this->newLine();
        $this->warn('Add BOTH of these Exact URIs in Meta Developer Console → Facebook Login → Valid OAuth Redirect URIs:');
        $this->line('  '.route('social.oauth.callback', ['platform' => 'facebook']));
        $this->line('  '.route('social.oauth.callback', ['platform' => 'instagram']));
        $this->newLine();
        $this->line('Also set App Domains to include localhost (or leave blank for Localhost testing).');
        $this->line('Open the app in browser as: '.rtrim((string) config('app.url'), '/').'/social');
        $this->line('Switch workspace to: '.$workspace->name);
        $this->newLine();

        $accounts = SocialAccount::query()
            ->where('workspace_id', $workspace->id)
            ->orderBy('platform')
            ->get();

        if ($accounts->isEmpty()) {
            $this->comment('No social accounts yet. In UI: Social → Connect Facebook (oauth).');
        } else {
            $this->info('Connected accounts:');
            $this->table(
                ['id', 'platform', 'name', 'mode', 'status', 'external_id'],
                $accounts->map(fn (SocialAccount $a) => [
                    $a->id,
                    $a->platform,
                    $a->account_name,
                    $a->connection_mode,
                    $a->status,
                    $a->external_id ?: '—',
                ])->all()
            );

            $stubs = $accounts->where('connection_mode', 'sandbox');
            if ($stubs->isNotEmpty()) {
                $this->warn('Sandbox rows cannot publish live. Disconnect them in UI, then Connect again with Meta OAuth.');
            }
        }

        if (! $this->option('publish')) {
            $this->newLine();
            $this->info('Next:');
            $this->line('1) Meta console me redirect URIs add karo (upar wali).');
            $this->line('2) Browser: switch to this workspace → Social → Connect Facebook.');
            $this->line('3) Text-only post → Publish now (local images Meta fetch nahi kar sakta).');
            $this->line('4) Or CLI: php artisan social:local-setup --workspace='.$workspace->id.' --publish');

            return ($appId !== '' && $hasSecret && $plans->allows($workspace, 'social_publish'))
                ? self::SUCCESS
                : self::FAILURE;
        }

        $fb = SocialAccount::query()
            ->where('workspace_id', $workspace->id)
            ->where('platform', 'facebook')
            ->where('status', 'connected')
            ->where('connection_mode', 'oauth')
            ->whereNotNull('access_token')
            ->whereNotNull('external_id')
            ->orderByDesc('connected_at')
            ->first();

        if (! $fb) {
            $this->error('No oauth Facebook account with page token. Connect Facebook in the UI first.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm('Publish a text test post to Facebook page "'.$fb->account_name.'"?', true)) {
            return self::SUCCESS;
        }

        $user = $workspace->users()->first() ?? User::query()->first();
        if (! $user) {
            $this->error('No user found to own the post.');

            return self::FAILURE;
        }

        $post = SocialPost::query()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $user->id,
            'title' => 'Local test',
            'body' => 'RankwayAI local publish test @ '.now()->toDateTimeString(),
            'platforms' => ['facebook'],
            'status' => 'publishing',
            'approved_at' => now(),
            'approved_by' => $user->id,
        ]);

        $this->line('Publishing social_post #'.$post->id.' …');
        $result = $publisher->publish($post->fresh());
        $post->refresh();

        if ($result['ok'] ?? false) {
            $this->info('OK — published.');
            $this->line('Permalink: '.($post->permalinks['facebook'] ?? '(none)'));

            return self::SUCCESS;
        }

        $this->error('Publish failed: '.($post->failure_reason ?: json_encode($result['errors'] ?? [])));

        return self::FAILURE;
    }
}
