<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('itineraries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('brand_kit_id')->nullable()->constrained('brand_kits')->nullOnDelete();
            $table->string('title');
            $table->string('status', 20)->default('draft'); // draft|ready|archived
            $table->string('destination')->nullable();
            $table->string('starting_city')->nullable();
            $table->unsignedTinyInteger('duration_days')->default(3);
            $table->date('travel_start')->nullable();
            $table->date('travel_end')->nullable();
            $table->unsignedSmallInteger('adults')->default(2);
            $table->unsignedSmallInteger('children')->default(0);
            $table->string('budget_band', 40)->nullable(); // economy|standard|premium|luxury
            $table->string('hotel_category', 40)->nullable();
            $table->string('transport', 80)->nullable();
            $table->json('interests')->nullable();
            $table->string('meal_preference', 80)->nullable();
            $table->text('special_requirements')->nullable();
            $table->text('overview')->nullable();
            $table->json('days')->nullable();
            $table->json('inclusions')->nullable();
            $table->json('exclusions')->nullable();
            $table->json('pricing')->nullable();
            $table->string('currency', 3)->default('INR');
            $table->string('generation_source', 40)->nullable(); // ai|template|manual
            $table->string('share_token', 64)->unique();
            $table->boolean('is_public')->default(false);
            $table->timestamps();

            $table->index(['workspace_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('itineraries');
    }
};
