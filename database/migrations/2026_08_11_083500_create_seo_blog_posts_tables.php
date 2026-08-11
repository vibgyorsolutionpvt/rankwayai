<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_blog_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seo_site_id')->constrained('seo_sites')->cascadeOnDelete();
            $table->string('url', 2048);
            $table->string('url_hash', 64);
            $table->string('title')->nullable();
            $table->text('excerpt')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->string('source', 40)->default('sitemap'); // rss|sitemap|crawl
            $table->unsignedInteger('share_count')->default(0);
            $table->timestamp('last_shared_at')->nullable();
            $table->timestamps();

            $table->unique(['seo_site_id', 'url_hash']);
            $table->index(['seo_site_id', 'published_at']);
        });

        Schema::create('seo_blog_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seo_blog_post_id')->constrained('seo_blog_posts')->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('channel', 40);
            $table->string('share_url', 2048)->nullable();
            $table->string('status', 20)->default('opened'); // opened|done
            $table->timestamps();

            $table->index(['seo_blog_post_id', 'channel']);
        });

        Schema::table('seo_sites', function (Blueprint $table) {
            $table->timestamp('blog_posts_synced_at')->nullable()->after('backlinks_synced_at');
            $table->string('blog_feed_url', 2048)->nullable()->after('sitemap_url');
        });
    }

    public function down(): void
    {
        Schema::table('seo_sites', function (Blueprint $table) {
            $table->dropColumn(['blog_posts_synced_at', 'blog_feed_url']);
        });

        Schema::dropIfExists('seo_blog_shares');
        Schema::dropIfExists('seo_blog_posts');
    }
};
