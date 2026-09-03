<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('social_compose_prompt_histories')) {
            return;
        }

        Schema::table('social_compose_prompt_histories', function (Blueprint $table) {
            if (! Schema::hasColumn('social_compose_prompt_histories', 'provider')) {
                $table->string('provider', 40)->nullable()->after('offer');
            }
            if (! Schema::hasColumn('social_compose_prompt_histories', 'api_url')) {
                $table->string('api_url', 500)->nullable()->after('provider');
            }
            if (! Schema::hasColumn('social_compose_prompt_histories', 'model')) {
                $table->string('model', 120)->nullable()->after('api_url');
            }
            if (! Schema::hasColumn('social_compose_prompt_histories', 'http_status')) {
                $table->unsignedSmallInteger('http_status')->nullable()->after('model');
            }
            if (! Schema::hasColumn('social_compose_prompt_histories', 'tokens')) {
                $table->unsignedInteger('tokens')->default(0)->after('http_status');
            }
            if (! Schema::hasColumn('social_compose_prompt_histories', 'ok')) {
                $table->boolean('ok')->default(true)->after('tokens');
            }
            if (! Schema::hasColumn('social_compose_prompt_histories', 'error')) {
                $table->string('error', 500)->nullable()->after('ok');
            }
            if (! Schema::hasColumn('social_compose_prompt_histories', 'request_payload')) {
                $table->json('request_payload')->nullable()->after('error');
            }
            if (! Schema::hasColumn('social_compose_prompt_histories', 'response_payload')) {
                $table->json('response_payload')->nullable()->after('request_payload');
            }
            if (! Schema::hasColumn('social_compose_prompt_histories', 'response_text')) {
                $table->longText('response_text')->nullable()->after('response_payload');
            }
            if (! Schema::hasColumn('social_compose_prompt_histories', 'attempts')) {
                $table->json('attempts')->nullable()->after('response_text');
            }
            if (! Schema::hasColumn('social_compose_prompt_histories', 'draft')) {
                $table->json('draft')->nullable()->after('attempts');
            }
        });
    }

    public function down(): void
    {
        // Keep columns — original meta migration owns the rollback.
    }
};
