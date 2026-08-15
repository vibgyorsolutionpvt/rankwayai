<?php

use App\Http\Controllers\AiStudioController;
use App\Http\Controllers\BlogController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\BillingWebhookController;
use App\Http\Controllers\BrandKitController;
use App\Http\Controllers\ChannelsController;
use App\Http\Controllers\CrmController;
use App\Http\Controllers\FunnelController;
use App\Http\Controllers\IntegrationsController;
use App\Http\Controllers\MediaLibraryController;
use App\Http\Controllers\MarketingController;
use App\Http\Controllers\MarketingSeoController;
use App\Http\Controllers\PlatformAdminController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SeoController;
use App\Http\Controllers\SeoV2Controller;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SocialController;
use App\Http\Controllers\TodayController;
use App\Http\Controllers\WhatsAppController;
use App\Http\Controllers\WorkspacePageController;
use App\Http\Middleware\EnsureSuperAdmin;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    $marketing = config('seo.marketing');
    $publicBase = rtrim((string) ($marketing['public_url'] ?? config('app.url')), '/');

    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'seo' => [
            'title' => $marketing['title'],
            'description' => $marketing['description'],
            'keywords' => $marketing['keywords'],
            'canonical' => $publicBase.'/',
            'image' => filled($marketing['og_image'] ?? null)
                ? (str_starts_with($marketing['og_image'], 'http')
                    ? $marketing['og_image']
                    : $publicBase.$marketing['og_image'])
                : null,
        ],
    ]);
})->name('welcome');

Route::get('/about', [MarketingController::class, 'about'])->name('about');
Route::get('/pricing', [MarketingController::class, 'pricing'])->name('pricing');
Route::get('/contact', [MarketingController::class, 'contact'])->name('contact');
Route::post('/contact', [MarketingController::class, 'contactStore'])
    ->middleware('throttle:10,1')
    ->name('contact.store');

Route::get('/robots.txt', [MarketingSeoController::class, 'robots'])->name('robots');
Route::get('/sitemap.xml', [MarketingSeoController::class, 'sitemap'])->name('sitemap');

Route::get('/f/{slug}', [FunnelController::class, 'showPublic'])->name('funnels.public');
Route::post('/f/{slug}/lead', [FunnelController::class, 'captureLead'])
    ->middleware('throttle:30,1')
    ->name('funnels.lead');

Route::post('/webhooks/stripe', [BillingWebhookController::class, 'stripe'])
    ->middleware('throttle:120,1')
    ->name('webhooks.stripe');
Route::post('/webhooks/razorpay', [BillingWebhookController::class, 'razorpay'])
    ->middleware('throttle:120,1')
    ->name('webhooks.razorpay');
Route::post('/webhooks/cashfree', [BillingWebhookController::class, 'cashfree'])
    ->middleware('throttle:180,1')
    ->name('webhooks.cashfree');
Route::post('/webhooks/zavu/{workspace}', \App\Http\Controllers\ZavuWebhookController::class)
    ->middleware('throttle:180,1')
    ->name('webhooks.zavu');
Route::get('/webhooks/meta/whatsapp/{workspace}', [\App\Http\Controllers\MetaWhatsAppWebhookController::class, 'verify'])
    ->middleware('throttle:60,1')
    ->name('webhooks.meta.whatsapp.verify');
Route::post('/webhooks/meta/whatsapp/{workspace}', [\App\Http\Controllers\MetaWhatsAppWebhookController::class, 'receive'])
    ->middleware('throttle:180,1')
    ->name('webhooks.meta.whatsapp');

Route::middleware(['auth', 'verified', 'module'])->group(function () {
    Route::get('/home', [PlatformAdminController::class, 'home'])->name('home');

    Route::middleware(EnsureSuperAdmin::class)->prefix('admin')->name('admin.')->group(function () {
        Route::get('/', [PlatformAdminController::class, 'dashboard'])->name('dashboard');
        Route::get('/users', [PlatformAdminController::class, 'users'])->name('users');
        Route::post('/users', [PlatformAdminController::class, 'storeUser'])->name('users.store');
        Route::patch('/users/{user}', [PlatformAdminController::class, 'updateUser'])->name('users.update');
        Route::get('/workspaces', [PlatformAdminController::class, 'workspaces'])->name('workspaces');
        Route::patch('/workspaces/{workspace}', [PlatformAdminController::class, 'updateWorkspace'])->name('workspaces.update');
        Route::post('/workspaces/{workspace}/enter', [PlatformAdminController::class, 'enterWorkspace'])->name('workspaces.enter');
        Route::post('/leave-workspace', [PlatformAdminController::class, 'leaveWorkspace'])->name('leave-workspace');
        Route::get('/billing', [PlatformAdminController::class, 'billing'])->name('billing');
        Route::get('/activity', [PlatformAdminController::class, 'activity'])->name('activity');
        Route::get('/system', [PlatformAdminController::class, 'system'])->name('system');
        Route::patch('/system', [PlatformAdminController::class, 'updateSystem'])->name('system.update');
        Route::get('/jobs', [PlatformAdminController::class, 'jobs'])->name('jobs');
        Route::post('/jobs/failed/{uuid}/retry', [PlatformAdminController::class, 'retryFailedJob'])->name('jobs.retry');
        Route::post('/jobs/failed/flush', [PlatformAdminController::class, 'flushFailedJobs'])->name('jobs.flush');
        Route::patch('/menus/{key}', [PlatformAdminController::class, 'updateMenu'])->name('menus.update');
    });

    // Client Marketing OS
    Route::get('/today', TodayController::class)->name('today');
    Route::get('/brand', [BrandKitController::class, 'edit'])->name('brand.edit');
    Route::post('/brand', [BrandKitController::class, 'store'])->name('brand.store');
    Route::post('/brand/{brand}', [BrandKitController::class, 'update'])->name('brand.update');
    Route::post('/brand/{brand}/activate', [BrandKitController::class, 'activate'])->name('brand.activate');
    Route::delete('/brand/{brand}', [BrandKitController::class, 'destroy'])->name('brand.destroy');
    Route::delete('/brand/{brand}/logo', [BrandKitController::class, 'destroyLogo'])->name('brand.logo.destroy');

    Route::get('/media', [MediaLibraryController::class, 'index'])->name('media.index');
    Route::post('/media', [MediaLibraryController::class, 'store'])->name('media.store');
    Route::patch('/media/{media}', [MediaLibraryController::class, 'update'])->name('media.update');
    Route::delete('/media/{media}', [MediaLibraryController::class, 'destroy'])->name('media.destroy');

    Route::get('/social', [SocialController::class, 'index'])->name('social.index');
    Route::post('/social/posts', [SocialController::class, 'store'])->name('social.posts.store');
    Route::patch('/social/posts/{post}', [SocialController::class, 'update'])->name('social.posts.update');
    Route::post('/social/posts/{post}/approve', [SocialController::class, 'approve'])->name('social.posts.approve');
    Route::post('/social/posts/{post}/publish', [SocialController::class, 'publishNow'])->name('social.posts.publish');
    Route::post('/social/posts/{post}/retry', [SocialController::class, 'retry'])->name('social.posts.retry');
    Route::post('/social/posts/{post}/posters', [SocialController::class, 'generatePosters'])->name('social.posts.posters');
    Route::delete('/social/posts/{post}', [SocialController::class, 'destroyPost'])->name('social.posts.destroy');
    Route::post('/social/accounts', [SocialController::class, 'connectStub'])->name('social.accounts.store');
    Route::get('/social/oauth/{platform}/callback', [SocialController::class, 'oauthCallback'])->name('social.oauth.callback');
    Route::post('/social/accounts/{account}/reconnect', [SocialController::class, 'reconnect'])->name('social.accounts.reconnect');
    Route::delete('/social/accounts/{account}', [SocialController::class, 'disconnect'])->name('social.accounts.destroy');

    Route::get('/seo', [SeoController::class, 'index'])->name('seo.index');
    Route::post('/seo/sites', [SeoController::class, 'storeSite'])->name('seo.sites.store');
    Route::post('/seo/sites/{site}/crawl', [SeoController::class, 'crawlNow'])->name('seo.sites.crawl');
    Route::get('/seo/sites/{site}/gsc', [SeoController::class, 'connectGsc'])
        ->middleware('plan:seo_apis')
        ->name('seo.sites.gsc');
    Route::post('/seo/sites/{site}/gsc/sync', [SeoController::class, 'syncGsc'])
        ->middleware(['plan:seo_apis', 'throttle:3,60'])
        ->name('seo.sites.gsc.sync');
    Route::delete('/seo/sites/{site}/gsc', [SeoController::class, 'disconnectGsc'])
        ->name('seo.sites.gsc.disconnect');
    Route::get('/seo/gsc/callback', [SeoController::class, 'gscCallback'])->name('seo.gsc.callback');
    Route::post('/seo/sites/{site}/pagespeed', [SeoController::class, 'runPageSpeed'])
        ->middleware(['plan:seo_apis', 'throttle:240,1'])
        ->name('seo.sites.pagespeed');
    Route::patch('/seo/sites/{site}/crawl-settings', [SeoController::class, 'updateCrawlSettings'])->name('seo.sites.crawl-settings');
    Route::post('/seo/keywords', [SeoController::class, 'storeKeyword'])->name('seo.keywords.store');
    Route::post('/seo/keywords/research', [SeoController::class, 'researchKeywords'])
        ->middleware('throttle:20,1')
        ->name('seo.keywords.research');
    Route::post('/seo/keywords/track', [SeoController::class, 'trackRanks'])
        ->middleware('plan:seo_metrics')
        ->name('seo.keywords.track');
    Route::post('/seo/keywords/metrics', [SeoController::class, 'refreshMetrics'])
        ->middleware('plan:seo_metrics')
        ->name('seo.keywords.metrics');
    Route::post('/seo/competitors', [SeoController::class, 'storeCompetitor'])->name('seo.competitors.store');
    Route::post('/seo/tasks/generate', [SeoController::class, 'generateTasks'])->name('seo.tasks.generate');
    Route::post('/seo/tasks/{task}/complete', [SeoController::class, 'completeTask'])->name('seo.tasks.complete');
    Route::post('/seo/issues/{issue}/resolve', [SeoController::class, 'resolveIssue'])->name('seo.issues.resolve');
    Route::post('/seo/reports/weekly', [SeoController::class, 'weeklyReport'])->name('seo.reports.weekly');
    Route::get('/seo/reports/{report}/download/{format}', [SeoController::class, 'downloadReport'])
        ->whereIn('format', ['pdf', 'excel', 'xls', 'xlsx'])
        ->name('seo.reports.download');
    Route::get('/seo/export/{type}', [SeoController::class, 'exportTab'])
        ->whereIn('type', ['issues', 'fix', 'keywords', 'tasks', 'todos'])
        ->name('seo.export');
    Route::post('/seo/suggestions/{suggestion}/dismiss', [SeoController::class, 'dismissSuggestion'])->name('seo.suggestions.dismiss');

    Route::post('/seo/sites/{site}/backlinks', [SeoV2Controller::class, 'syncBacklinks'])
        ->middleware('plan:seo_backlinks')
        ->name('seo.sites.backlinks');
    Route::post('/seo/sites/{site}/crawl-mode', [SeoV2Controller::class, 'setCrawlMode'])->name('seo.sites.crawl-mode');
    Route::post('/seo/local-targets', [SeoV2Controller::class, 'storeLocalTarget'])
        ->middleware('plan:seo_local')
        ->name('seo.local.store');
    Route::post('/seo/local-targets/{target}/track', [SeoV2Controller::class, 'trackLocal'])
        ->middleware('plan:seo_local')
        ->name('seo.local.track');
    Route::get('/blog', [BlogController::class, 'index'])->name('blog.index');
    Route::post('/blog/verba/connect', [BlogController::class, 'storeVerbaConnection'])
        ->middleware('plan:seo_cms')
        ->name('blog.verba.connect');
    Route::post('/blog/verba/disconnect', [BlogController::class, 'disconnectVerba'])
        ->middleware('plan:seo_cms')
        ->name('blog.verba.disconnect');
    Route::post('/blog/posts/{post}/verba', [BlogController::class, 'publishBlogToVerba'])
        ->middleware('plan:seo_cms')
        ->name('blog.posts.verba');
    Route::post('/blog/cms/connections', [BlogController::class, 'storeCmsConnection'])
        ->middleware('plan:seo_cms')
        ->name('blog.cms.store');
    Route::post('/blog/content-drafts', [BlogController::class, 'createContentDraft'])
        ->middleware('plan:seo_cms')
        ->name('blog.content.store');
    Route::post('/blog/content-drafts/{draft}/approve', [BlogController::class, 'approveDraft'])
        ->middleware('plan:seo_cms')
        ->name('blog.content.approve');
    Route::post('/blog/content-drafts/{draft}/publish', [BlogController::class, 'publishDraft'])
        ->middleware('plan:seo_cms')
        ->name('blog.content.publish');
    Route::post('/blog/sites/{site}/sync', [BlogController::class, 'syncBlogPosts'])
        ->name('blog.posts.sync');
    Route::post('/blog/posts/{post}/share', [BlogController::class, 'shareBlogPost'])
        ->name('blog.posts.share');

    Route::get('/ai', [AiStudioController::class, 'index'])->name('ai.index');
    Route::post('/ai/settings', [AiStudioController::class, 'updateSettings'])->name('ai.settings');
    Route::post('/ai/generate-today', [AiStudioController::class, 'generateToday'])
        ->middleware(['throttle:20,1', 'plan:ai'])
        ->name('ai.generate-today');
    Route::post('/ai/blog-outline', [AiStudioController::class, 'blogOutline'])
        ->middleware(['throttle:30,1', 'plan:ai'])
        ->name('ai.blog-outline');
    Route::post('/ai/seo-metas', [AiStudioController::class, 'seoMetas'])
        ->middleware(['throttle:30,1', 'plan:ai'])
        ->name('ai.seo-metas');

    Route::get('/channels', [ChannelsController::class, 'index'])->name('channels.index');
    Route::post('/channels', [ChannelsController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('channels.store');
    Route::post('/channels/templates', [ChannelsController::class, 'storeTemplate'])
        ->middleware('throttle:30,1')
        ->name('channels.templates.store');
    Route::patch('/channels/templates/{template}', [ChannelsController::class, 'updateTemplate'])
        ->name('channels.templates.update');
    Route::delete('/channels/templates/{template}', [ChannelsController::class, 'destroyTemplate'])
        ->name('channels.templates.destroy');
    Route::post('/channels/{campaign}/send', [ChannelsController::class, 'send'])
        ->middleware(['throttle:20,1', 'plan:channel_send'])
        ->name('channels.send');
    Route::delete('/channels/{campaign}', [ChannelsController::class, 'destroy'])->name('channels.destroy');

    Route::get('/whatsapp', [WhatsAppController::class, 'index'])->name('whatsapp.index');
    Route::post('/whatsapp/conversations', [WhatsAppController::class, 'start'])
        ->middleware('throttle:40,1')
        ->name('whatsapp.conversations.start');
    Route::post('/whatsapp/conversations/{conversation}/reply', [WhatsAppController::class, 'reply'])
        ->middleware('throttle:60,1')
        ->name('whatsapp.conversations.reply');
    Route::post('/whatsapp/conversations/{conversation}/close', [WhatsAppController::class, 'close'])
        ->name('whatsapp.conversations.close');
    Route::post('/whatsapp/templates', [WhatsAppController::class, 'storeTemplate'])
        ->middleware('throttle:30,1')
        ->name('whatsapp.templates.store');
    Route::patch('/whatsapp/templates/{template}', [WhatsAppController::class, 'updateTemplate'])
        ->name('whatsapp.templates.update');
    Route::delete('/whatsapp/templates/{template}', [WhatsAppController::class, 'destroyTemplate'])
        ->name('whatsapp.templates.destroy');

    Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::redirect('/integrations', '/settings?tab=providers')->name('integrations.index');
    Route::put('/integrations/{provider}', [IntegrationsController::class, 'update'])->name('integrations.update');
    Route::post('/integrations/{provider}/disconnect', [IntegrationsController::class, 'disconnect'])->name('integrations.disconnect');
    Route::delete('/integrations/{provider}', [IntegrationsController::class, 'destroy'])->name('integrations.destroy');

    Route::get('/crm', [CrmController::class, 'index'])->name('crm.index');
    Route::post('/crm', [CrmController::class, 'store'])->name('crm.store');
    Route::get('/crm/{lead}', [CrmController::class, 'show'])->name('crm.show');
    Route::patch('/crm/{lead}', [CrmController::class, 'update'])->name('crm.update');
    Route::delete('/crm/{lead}', [CrmController::class, 'destroy'])->name('crm.destroy');
    Route::post('/crm/{lead}/notes', [CrmController::class, 'storeNote'])->name('crm.notes.store');
    Route::post('/crm/{lead}/whatsapp', [CrmController::class, 'openWhatsApp'])->name('crm.whatsapp.open');
    Route::get('/crm/{lead}/attachments/{attachment}/download', [CrmController::class, 'downloadAttachment'])->name('crm.attachments.download');
    Route::delete('/crm/{lead}/attachments/{attachment}', [CrmController::class, 'destroyAttachment'])->name('crm.attachments.destroy');

    Route::get('/billing', [BillingController::class, 'index'])->name('billing.index');
    Route::post('/billing/plan', [BillingController::class, 'updatePlan'])->name('billing.plan');
    Route::post('/billing/credits/recharge', [BillingController::class, 'recharge'])->name('billing.credits.recharge');
    Route::post('/billing/cancel', [BillingController::class, 'cancel'])->name('billing.cancel');

    Route::get('/funnels', [FunnelController::class, 'index'])->name('funnels.index');
    Route::post('/funnels', [FunnelController::class, 'store'])->name('funnels.store');
    Route::patch('/funnels/{funnel}', [FunnelController::class, 'update'])->name('funnels.update');
    Route::delete('/funnels/{funnel}', [FunnelController::class, 'destroy'])->name('funnels.destroy');

    Route::get('/workspaces', [WorkspacePageController::class, 'index'])->name('workspaces.index');
    Route::post('/workspaces', [WorkspacePageController::class, 'store'])->name('workspaces.store');
    Route::post('/workspaces/{workspace}/switch', [WorkspacePageController::class, 'switch'])->name('workspaces.switch');
    Route::post('/workspaces/{workspace}/members', [WorkspacePageController::class, 'storeMember'])->name('workspaces.members.store');
    Route::patch('/workspaces/{workspace}/members/{userId}', [WorkspacePageController::class, 'updateMember'])->name('workspaces.members.update');
    Route::delete('/workspaces/{workspace}/members/{userId}', [WorkspacePageController::class, 'destroyMember'])->name('workspaces.members.destroy');
    Route::put('/workspaces/{workspace}/modules', [WorkspacePageController::class, 'updateModules'])->name('workspaces.modules.update');
    Route::put('/workspaces/{workspace}/members/{userId}/modules', [WorkspacePageController::class, 'updateMemberModules'])->name('workspaces.members.modules.update');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
