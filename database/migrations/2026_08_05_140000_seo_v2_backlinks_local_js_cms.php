<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seo_sites', function (Blueprint $table) {
            $table->string('crawl_mode', 20)->default('static')->after('crawl_frequency'); // static|js
            $table->unsignedInteger('backlinks')->nullable()->after('pagespeed_error');
            $table->unsignedInteger('referring_domains')->nullable()->after('backlinks');
            $table->unsignedInteger('dofollow_backlinks')->nullable()->after('referring_domains');
            $table->timestamp('backlinks_synced_at')->nullable()->after('dofollow_backlinks');
            $table->string('backlinks_provider', 40)->nullable()->after('backlinks_synced_at');
            $table->json('backlink_summary')->nullable()->after('backlinks_provider');
        });

        Schema::table('seo_pages', function (Blueprint $table) {
            $table->string('render_mode', 20)->default('static')->after('audit_meta');
            $table->unsignedInteger('depth')->default(0)->after('render_mode');
            $table->unsignedInteger('inlink_count')->default(0)->after('depth');
            $table->unsignedInteger('outlink_count')->default(0)->after('inlink_count');
            $table->boolean('is_orphan')->default(false)->after('outlink_count');
            $table->unsignedInteger('load_time_ms')->nullable()->after('is_orphan');
        });

        Schema::create('seo_backlinks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seo_site_id')->constrained()->cascadeOnDelete();
            $table->string('source_url', 500);
            $table->string('source_domain', 255)->nullable();
            $table->string('target_url', 500)->nullable();
            $table->string('anchor', 500)->nullable();
            $table->boolean('dofollow')->default(true);
            $table->unsignedInteger('domain_rank')->nullable();
            $table->string('status', 20)->default('active'); // active|lost
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->index(['seo_site_id', 'status']);
        });

        Schema::create('seo_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seo_site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_page_id')->nullable()->constrained('seo_pages')->nullOnDelete();
            $table->foreignId('to_page_id')->nullable()->constrained('seo_pages')->nullOnDelete();
            $table->string('to_url', 500)->nullable();
            $table->string('type', 20)->default('a'); // a|redirect
            $table->boolean('is_external')->default(false);
            $table->timestamps();
            $table->index(['seo_site_id', 'from_page_id']);
        });

        Schema::create('seo_local_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seo_site_id')->nullable()->constrained()->nullOnDelete();
            $table->string('keyword');
            $table->string('location_name');
            $table->string('business_name')->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'keyword', 'location_name'], 'seo_local_targets_unique');
        });

        Schema::create('seo_local_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seo_local_target_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('our_rank')->nullable();
            $table->json('pack_json')->nullable();
            $table->timestamp('checked_at');
            $table->string('provider', 40)->nullable();
            $table->timestamps();
            $table->index(['seo_local_target_id', 'checked_at']);
        });

        Schema::create('cms_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 40); // wordpress
            $table->string('label')->nullable();
            $table->string('base_url');
            $table->text('credentials'); // encrypted json
            $table->string('status', 20)->default('active');
            $table->timestamp('last_tested_at')->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'provider', 'base_url'], 'cms_connections_unique');
        });

        Schema::create('seo_content_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seo_keyword_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cms_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('slug')->nullable();
            $table->longText('body_html')->nullable();
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->string('status', 20)->default('draft'); // draft|approved|publishing|published|failed
            $table->string('external_id')->nullable();
            $table->string('published_url')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_content_drafts');
        Schema::dropIfExists('cms_connections');
        Schema::dropIfExists('seo_local_snapshots');
        Schema::dropIfExists('seo_local_targets');
        Schema::dropIfExists('seo_links');
        Schema::dropIfExists('seo_backlinks');

        Schema::table('seo_pages', function (Blueprint $table) {
            $table->dropColumn(['render_mode', 'depth', 'inlink_count', 'outlink_count', 'is_orphan', 'load_time_ms']);
        });

        Schema::table('seo_sites', function (Blueprint $table) {
            $table->dropColumn([
                'crawl_mode', 'backlinks', 'referring_domains', 'dofollow_backlinks',
                'backlinks_synced_at', 'backlinks_provider', 'backlink_summary',
            ]);
        });
    }
};
