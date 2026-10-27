<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_chatbots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->text('fallback')->nullable();
            $table->json('rules')->nullable();
            $table->timestamps();

            $table->unique('workspace_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_chatbots');
    }
};
