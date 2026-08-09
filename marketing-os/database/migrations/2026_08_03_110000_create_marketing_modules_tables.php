<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_kits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('logo_path')->nullable();
            $table->string('primary_color', 7)->default('#0E9F90');
            $table->string('secondary_color', 7)->default('#0B1220');
            $table->string('font_family')->default('Plus Jakarta Sans');
            $table->string('website_url')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->json('social_links')->nullable();
            $table->string('default_cta_label')->nullable();
            $table->string('default_cta_url')->nullable();
            $table->timestamps();
        });

        Schema::create('media_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('disk')->default('public');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('folder')->nullable()->index();
            $table->json('tags')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['workspace_id', 'created_at']);
        });

        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('platform'); // facebook, instagram, linkedin, x
            $table->string('account_name');
            $table->string('status')->default('disconnected'); // connected, disconnected, error
            $table->text('external_id')->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'platform', 'account_name']);
        });

        Schema::create('social_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title')->nullable();
            $table->text('body');
            $table->json('platforms')->nullable();
            $table->foreignId('media_asset_id')->nullable()->constrained('media_assets')->nullOnDelete();
            $table->string('status')->default('draft'); // draft, scheduled, publishing, published, failed
            $table->timestamp('scheduled_at')->nullable()->index();
            $table->timestamp('published_at')->nullable();
            $table->string('failure_reason')->nullable();
            $table->boolean('requires_approval')->default(false);
            $table->timestamps();
            $table->index(['workspace_id', 'status']);
        });

        Schema::create('seo_sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('domain');
            $table->string('sitemap_url')->nullable();
            $table->string('status')->default('connected');
            $table->timestamp('last_crawled_at')->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'domain']);
        });

        Schema::create('seo_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seo_site_id')->constrained()->cascadeOnDelete();
            $table->string('url');
            $table->string('title')->nullable();
            $table->text('meta_description')->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'seo_site_id']);
        });

        Schema::create('seo_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seo_site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seo_page_id')->nullable()->constrained()->nullOnDelete();
            $table->string('severity'); // critical, warning, info
            $table->string('code');
            $table->string('message');
            $table->string('suggestion')->nullable();
            $table->string('status')->default('open'); // open, done, ignored
            $table->timestamps();
            $table->index(['workspace_id', 'status', 'severity']);
        });

        Schema::create('seo_keywords', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('keyword');
            $table->string('group_name')->nullable();
            $table->unsignedInteger('position')->nullable();
            $table->integer('position_change')->default(0);
            $table->timestamps();
            $table->index(['workspace_id', 'keyword']);
        });

        Schema::create('seo_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('priority')->default('medium'); // high, medium, low
            $table->string('status')->default('open'); // open, done
            $table->date('due_on')->nullable()->index();
            $table->string('source')->nullable(); // audit, keyword, manual
            $table->timestamps();
            $table->index(['workspace_id', 'status', 'due_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_tasks');
        Schema::dropIfExists('seo_keywords');
        Schema::dropIfExists('seo_issues');
        Schema::dropIfExists('seo_pages');
        Schema::dropIfExists('seo_sites');
        Schema::dropIfExists('social_posts');
        Schema::dropIfExists('social_accounts');
        Schema::dropIfExists('media_assets');
        Schema::dropIfExists('brand_kits');
    }
};
