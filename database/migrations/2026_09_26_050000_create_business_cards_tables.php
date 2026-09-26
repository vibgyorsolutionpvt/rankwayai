<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_cards', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('brand_kit_id')->nullable()->constrained('brand_kits')->nullOnDelete();
            $table->string('title');
            $table->string('template_key', 40)->default('classic');
            $table->string('status', 20)->default('draft'); // draft|published
            $table->string('person_name')->nullable();
            $table->string('person_title')->nullable();
            $table->string('person_photo_path')->nullable();
            $table->string('company_name')->nullable();
            $table->string('tagline')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('whatsapp', 40)->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->string('address', 255)->nullable();
            $table->json('social_links')->nullable();
            $table->string('share_token', 64)->unique();
            $table->boolean('is_public')->default(true);
            $table->unsignedInteger('views_count')->default(0);
            $table->unsignedInteger('phone_clicks')->default(0);
            $table->unsignedInteger('whatsapp_clicks')->default(0);
            $table->unsignedInteger('email_clicks')->default(0);
            $table->unsignedInteger('website_clicks')->default(0);
            $table->timestamps();

            $table->index(['workspace_id', 'status']);
        });

        Schema::create('business_card_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_card_id')->constrained('business_cards')->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('event', 40); // view|phone|whatsapp|email|website
            $table->string('ip_hash', 64)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();

            $table->index(['business_card_id', 'event']);
            $table->index(['workspace_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_card_events');
        Schema::dropIfExists('business_cards');
    }
};
