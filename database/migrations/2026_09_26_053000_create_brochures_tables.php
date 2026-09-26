<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brochures', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('brand_kit_id')->nullable()->constrained('brand_kits')->nullOnDelete();
            $table->string('title');
            $table->string('template_key', 40)->default('agency');
            $table->string('status', 20)->default('draft');
            $table->string('headline')->nullable();
            $table->string('subheadline')->nullable();
            $table->json('sections')->nullable();
            $table->string('share_token', 64)->unique();
            $table->boolean('is_public')->default(true);
            $table->unsignedInteger('views_count')->default(0);
            $table->unsignedInteger('downloads_count')->default(0);
            $table->unsignedInteger('cta_clicks')->default(0);
            $table->timestamps();

            $table->index(['workspace_id', 'status']);
        });

        Schema::create('brochure_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('brochure_id')->constrained('brochures')->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('event', 40); // view|download|cta
            $table->string('ip_hash', 64)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();

            $table->index(['brochure_id', 'event']);
            $table->index(['workspace_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brochure_events');
        Schema::dropIfExists('brochures');
    }
};
