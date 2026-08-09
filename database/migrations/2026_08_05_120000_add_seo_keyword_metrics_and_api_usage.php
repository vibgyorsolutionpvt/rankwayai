<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seo_keywords', function (Blueprint $table) {
            $table->unsignedInteger('search_volume')->nullable()->after('location');
            $table->unsignedTinyInteger('keyword_difficulty')->nullable()->after('search_volume');
            $table->decimal('cpc', 10, 4)->nullable()->after('keyword_difficulty');
            $table->decimal('competition', 8, 4)->nullable()->after('cpc');
            $table->string('metrics_provider', 40)->nullable()->after('competition');
            $table->timestamp('metrics_fetched_at')->nullable()->after('metrics_provider');
            $table->unsignedInteger('local_pack_position')->nullable()->after('position_change');
            $table->string('rank_provider', 40)->nullable()->after('local_pack_position');
        });

        Schema::create('seo_api_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 40);
            $table->string('operation', 60);
            $table->unsignedInteger('units')->default(1);
            $table->decimal('cost_usd', 10, 6)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_api_usage_logs');

        Schema::table('seo_keywords', function (Blueprint $table) {
            $table->dropColumn([
                'search_volume',
                'keyword_difficulty',
                'cpc',
                'competition',
                'metrics_provider',
                'metrics_fetched_at',
                'local_pack_position',
                'rank_provider',
            ]);
        });
    }
};
