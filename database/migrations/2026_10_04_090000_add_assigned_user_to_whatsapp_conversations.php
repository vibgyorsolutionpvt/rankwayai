<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            $table->foreignId('assigned_user_id')->nullable()->after('crm_lead_id')->constrained('users')->nullOnDelete();
            $table->index(['workspace_id', 'assigned_user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            $table->dropIndex(['workspace_id', 'assigned_user_id']);
            $table->dropConstrainedForeignId('assigned_user_id');
        });
    }
};
