<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rankway_domains', function (Blueprint $table) {
            $table->id();
            $table->string('domain', 255)->unique();
            $table->string('url', 2048)->nullable();
            $table->string('title')->nullable();
            $table->string('category')->nullable();
            $table->string('country', 8)->nullable()->default('IN');
            $table->unsignedTinyInteger('rankway_score')->nullable();
            $table->unsignedInteger('global_rank')->nullable();
            $table->unsignedInteger('country_rank')->nullable();
            $table->unsignedInteger('category_rank')->nullable();
            $table->string('status', 32)->default('pending'); // pending|analyzing|ready|failed
            $table->text('last_error')->nullable();
            $table->timestamp('last_analyzed_at')->nullable();
            $table->timestamps();

            $table->index(['rankway_score', 'global_rank']);
            $table->index('status');
        });

        Schema::create('rankway_domain_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rankway_domain_id')->constrained('rankway_domains')->cascadeOnDelete();
            $table->unsignedInteger('organic_traffic')->nullable();
            $table->unsignedInteger('organic_keywords')->nullable();
            $table->unsignedInteger('backlinks')->nullable();
            $table->unsignedInteger('referring_domains')->nullable();
            $table->unsignedTinyInteger('authority_score')->nullable();
            $table->unsignedTinyInteger('visibility_score')->nullable();
            $table->unsignedTinyInteger('keyword_score')->nullable();
            $table->unsignedTinyInteger('backlink_score')->nullable();
            $table->unsignedTinyInteger('referring_score')->nullable();
            $table->unsignedTinyInteger('technical_score')->nullable();
            $table->unsignedTinyInteger('performance_score')->nullable();
            $table->unsignedTinyInteger('content_score')->nullable();
            $table->unsignedTinyInteger('growth_score')->nullable();
            $table->json('probe')->nullable();
            $table->json('breakdown')->nullable();
            $table->string('provider', 40)->nullable();
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->index(['rankway_domain_id', 'recorded_at']);
        });

        Schema::create('rankway_domain_rank_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rankway_domain_id')->constrained('rankway_domains')->cascadeOnDelete();
            $table->unsignedTinyInteger('rankway_score')->nullable();
            $table->unsignedInteger('global_rank')->nullable();
            $table->unsignedInteger('country_rank')->nullable();
            $table->unsignedInteger('category_rank')->nullable();
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->index(['rankway_domain_id', 'recorded_at']);
        });

        Schema::table('seo_sites', function (Blueprint $table) {
            $table->foreignId('rankway_domain_id')
                ->nullable()
                ->after('domain')
                ->constrained('rankway_domains')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('seo_sites', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rankway_domain_id');
        });
        Schema::dropIfExists('rankway_domain_rank_history');
        Schema::dropIfExists('rankway_domain_metrics');
        Schema::dropIfExists('rankway_domains');
    }
};
