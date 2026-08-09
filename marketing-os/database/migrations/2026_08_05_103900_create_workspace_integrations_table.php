<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('category', 32); // social, messaging, rcs
            $table->string('provider', 40); // meta, linkedin, x, zavu, jio, airtel, vi
            $table->boolean('enabled')->default(true);
            $table->string('status', 32)->default('disconnected'); // disconnected, connected, error
            $table->text('credentials')->nullable(); // encrypted JSON
            $table->json('meta')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'provider']);
            $table->index(['workspace_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_integrations');
    }
};
