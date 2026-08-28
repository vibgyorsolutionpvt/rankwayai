<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_publish_logs', function (Blueprint $table) {
            $table->string('external_post_id', 120)->nullable()->after('permalink');
            $table->json('metrics')->nullable()->after('external_post_id');
            $table->timestamp('metrics_synced_at')->nullable()->after('metrics');
            $table->index('external_post_id', 'soc_pub_log_ext_post_idx');
        });
    }

    public function down(): void
    {
        Schema::table('social_publish_logs', function (Blueprint $table) {
            $table->dropIndex('soc_pub_log_ext_post_idx');
            $table->dropColumn(['external_post_id', 'metrics', 'metrics_synced_at']);
        });
    }
};
