<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seo_sites', function (Blueprint $table) {
            $table->boolean('gsc_connected')->default(false)->after('status');
            $table->string('gsc_property')->nullable()->after('gsc_connected');
            $table->string('ga4_property')->nullable()->after('gsc_property');
            $table->text('gsc_token')->nullable()->after('ga4_property');
            $table->string('crawl_frequency')->default('daily')->after('gsc_token'); // daily, weekly, manual
            $table->timestamp('next_crawl_at')->nullable()->after('crawl_frequency');
            $table->string('crawl_status')->default('idle')->after('next_crawl_at'); // idle, queued, crawling, failed
            $table->text('last_crawl_error')->nullable()->after('crawl_status');
        });

        Schema::table('seo_pages', function (Blueprint $table) {
            $table->string('h1')->nullable()->after('meta_description');
            $table->string('canonical')->nullable()->after('h1');
            $table->boolean('indexable')->default(true)->after('canonical');
            $table->boolean('has_schema')->default(false)->after('indexable');
            $table->unsignedSmallInteger('images_missing_alt')->default(0)->after('has_schema');
            $table->unsignedSmallInteger('internal_links')->default(0)->after('images_missing_alt');
            $table->string('redirect_to')->nullable()->after('internal_links');
            $table->unsignedInteger('word_count')->default(0)->after('redirect_to');
            $table->json('audit_meta')->nullable()->after('word_count');
        });

        Schema::table('seo_keywords', function (Blueprint $table) {
            $table->boolean('is_local')->default(false)->after('group_name');
            $table->string('location')->nullable()->after('is_local');
            $table->timestamp('last_checked_at')->nullable()->after('position_change');
        });

        Schema::table('seo_tasks', function (Blueprint $table) {
            $table->foreignId('seo_site_id')->nullable()->after('workspace_id')->constrained('seo_sites')->nullOnDelete();
            $table->foreignId('seo_issue_id')->nullable()->after('seo_site_id')->constrained('seo_issues')->nullOnDelete();
            $table->json('ai_suggestion')->nullable()->after('source');
        });

        Schema::create('seo_keyword_ranks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seo_keyword_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->nullable();
            $table->timestamp('checked_at');
            $table->timestamps();
            $table->index(['seo_keyword_id', 'checked_at']);
        });

        Schema::create('seo_competitors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('domain');
            $table->unsignedSmallInteger('overlap_score')->default(0);
            $table->json('shared_keywords')->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'domain']);
        });

        Schema::create('seo_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seo_site_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type'); // meta, faq, internal_links, blog_topic, outline
            $table->string('title');
            $table->text('body');
            $table->string('status')->default('open'); // open, applied, dismissed
            $table->timestamps();
        });

        Schema::create('seo_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seo_site_id')->nullable()->constrained()->nullOnDelete();
            $table->string('period'); // weekly, monthly
            $table->date('period_start');
            $table->date('period_end');
            $table->json('summary');
            $table->string('status')->default('ready');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_reports');
        Schema::dropIfExists('seo_suggestions');
        Schema::dropIfExists('seo_competitors');
        Schema::dropIfExists('seo_keyword_ranks');

        Schema::table('seo_tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('seo_site_id');
            $table->dropConstrainedForeignId('seo_issue_id');
            $table->dropColumn('ai_suggestion');
        });

        Schema::table('seo_keywords', function (Blueprint $table) {
            $table->dropColumn(['is_local', 'location', 'last_checked_at']);
        });

        Schema::table('seo_pages', function (Blueprint $table) {
            $table->dropColumn([
                'h1', 'canonical', 'indexable', 'has_schema',
                'images_missing_alt', 'internal_links', 'redirect_to', 'word_count', 'audit_meta',
            ]);
        });

        Schema::table('seo_sites', function (Blueprint $table) {
            $table->dropColumn([
                'gsc_connected', 'gsc_property', 'ga4_property', 'gsc_token',
                'crawl_frequency', 'next_crawl_at', 'crawl_status', 'last_crawl_error',
            ]);
        });
    }
};
