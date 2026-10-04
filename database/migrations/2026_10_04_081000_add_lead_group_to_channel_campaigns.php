<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_campaigns', function (Blueprint $table) {
            $table->foreignId('crm_lead_group_id')
                ->nullable()
                ->after('whatsapp_template_id')
                ->constrained('crm_lead_groups')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('channel_campaigns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('crm_lead_group_id');
        });
    }
};
