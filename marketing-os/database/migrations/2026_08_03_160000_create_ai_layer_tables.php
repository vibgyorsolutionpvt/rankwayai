<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_ai_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete()->unique();
            $table->decimal('monthly_budget_usd', 8, 2)->default(20);
            $table->decimal('spent_usd', 10, 4)->default(0);
            $table->boolean('template_first')->default(true);
            $table->string('tone')->default('mixed'); // hindi, english, mixed
            $table->string('industry')->nullable();
            $table->string('location')->nullable();
            $table->json('hashtag_packs')->nullable();
            $table->boolean('auto_daily_posts')->default(false);
            $table->timestamps();
        });

        Schema::create('ai_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action'); // captions, blog_outline, seo_meta, festival_pack, generate_today
            $table->string('provider')->default('template'); // template, openai
            $table->unsignedInteger('tokens')->default(0);
            $table->decimal('cost_usd', 10, 6)->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'created_at']);
        });

        Schema::create('festival_events', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->date('occurs_on');
            $table->string('region')->default('IN');
            $table->string('category')->nullable(); // festival, sale, awareness
            $table->json('suggested_angles')->nullable();
            $table->timestamps();
            $table->index(['occurs_on', 'region']);
        });

        Schema::create('ai_generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // caption, blog, seo_meta, today_pack
            $table->string('title')->nullable();
            $table->json('payload');
            $table->string('status')->default('ready'); // ready, used, discarded
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_generations');
        Schema::dropIfExists('festival_events');
        Schema::dropIfExists('ai_usage_logs');
        Schema::dropIfExists('workspace_ai_settings');
    }
};
