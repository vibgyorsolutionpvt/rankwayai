<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_message_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('channel'); // whatsapp, email
            $table->string('subject')->nullable();
            $table->text('body');
            $table->timestamps();
            $table->index(['workspace_id', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_message_templates');
    }
};
