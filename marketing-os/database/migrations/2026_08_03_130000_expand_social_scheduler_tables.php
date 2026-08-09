<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->text('access_token')->nullable()->after('external_id');
            $table->text('refresh_token')->nullable()->after('access_token');
            $table->timestamp('token_expires_at')->nullable()->after('refresh_token');
            $table->timestamp('connected_at')->nullable()->after('token_expires_at');
            $table->string('health')->default('unknown')->after('connected_at'); // healthy, warning, error, unknown
            $table->string('last_error')->nullable()->after('health');
        });

        Schema::table('social_posts', function (Blueprint $table) {
            $table->json('permalinks')->nullable()->after('failure_reason');
            $table->json('publish_log')->nullable()->after('permalinks');
            $table->json('poster_variants')->nullable()->after('publish_log');
            $table->timestamp('approved_at')->nullable()->after('requires_approval');
            $table->foreignId('approved_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
        });

        Schema::create('social_publish_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_post_id')->constrained()->cascadeOnDelete();
            $table->string('platform');
            $table->string('status'); // queued, published, failed
            $table->string('permalink')->nullable();
            $table->string('error')->nullable();
            $table->unsignedTinyInteger('attempt')->default(1);
            $table->timestamps();
            $table->index(['workspace_id', 'social_post_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_publish_logs');

        Schema::table('social_posts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['permalinks', 'publish_log', 'poster_variants', 'approved_at']);
        });

        Schema::table('social_accounts', function (Blueprint $table) {
            $table->dropColumn([
                'access_token',
                'refresh_token',
                'token_expires_at',
                'connected_at',
                'health',
                'last_error',
            ]);
        });
    }
};
