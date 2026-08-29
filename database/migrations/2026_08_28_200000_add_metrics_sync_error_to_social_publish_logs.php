<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_publish_logs', function (Blueprint $table) {
            $table->string('metrics_sync_error', 500)->nullable()->after('metrics_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('social_publish_logs', function (Blueprint $table) {
            $table->dropColumn('metrics_sync_error');
        });
    }
};
