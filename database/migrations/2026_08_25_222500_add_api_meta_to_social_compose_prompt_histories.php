<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_compose_prompt_histories', function (Blueprint $table) {
            $table->string('provider', 40)->nullable()->after('offer');
            $table->string('api_url', 500)->nullable()->after('provider');
            $table->string('model', 120)->nullable()->after('api_url');
            $table->unsignedSmallInteger('http_status')->nullable()->after('model');
            $table->unsignedInteger('tokens')->default(0)->after('http_status');
            $table->boolean('ok')->default(true)->after('tokens');
            $table->string('error', 500)->nullable()->after('ok');
            $table->json('request_payload')->nullable()->after('error');
            $table->json('response_payload')->nullable()->after('request_payload');
            $table->longText('response_text')->nullable()->after('response_payload');
            $table->json('attempts')->nullable()->after('response_text');
            $table->json('draft')->nullable()->after('attempts');
        });
    }

    public function down(): void
    {
        Schema::table('social_compose_prompt_histories', function (Blueprint $table) {
            $table->dropColumn([
                'provider',
                'api_url',
                'model',
                'http_status',
                'tokens',
                'ok',
                'error',
                'request_payload',
                'response_payload',
                'response_text',
                'attempts',
                'draft',
            ]);
        });
    }
};
