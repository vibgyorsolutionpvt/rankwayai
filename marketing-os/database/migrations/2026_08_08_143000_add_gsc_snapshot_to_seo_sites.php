<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seo_sites', function (Blueprint $table) {
            $table->timestamp('gsc_synced_at')->nullable()->after('gsc_token');
            $table->json('gsc_summary')->nullable()->after('gsc_synced_at');
            $table->json('gsc_queries')->nullable()->after('gsc_summary');
            $table->string('gsc_last_error')->nullable()->after('gsc_queries');
        });
    }

    public function down(): void
    {
        Schema::table('seo_sites', function (Blueprint $table) {
            $table->dropColumn(['gsc_synced_at', 'gsc_summary', 'gsc_queries', 'gsc_last_error']);
        });
    }
};
