<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_compose_prompt_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('prompt');
            $table->string('offer', 200)->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'user_id', 'created_at'], 'scp_hist_ws_user_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_compose_prompt_histories');
    }
};
