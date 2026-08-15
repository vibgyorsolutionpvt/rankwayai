<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seo_blog_posts', function (Blueprint $table) {
            $table->timestamp('verba_published_at')->nullable()->after('last_shared_at');
            $table->string('verba_published_url', 2048)->nullable()->after('verba_published_at');
        });
    }

    public function down(): void
    {
        Schema::table('seo_blog_posts', function (Blueprint $table) {
            $table->dropColumn(['verba_published_at', 'verba_published_url']);
        });
    }
};
