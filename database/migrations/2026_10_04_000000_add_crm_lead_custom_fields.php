<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->json('crm_lead_custom_fields')->nullable();
        });

        Schema::table('crm_leads', function (Blueprint $table) {
            $table->json('custom_fields')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('crm_leads', function (Blueprint $table) {
            $table->dropColumn('custom_fields');
        });

        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn('crm_lead_custom_fields');
        });
    }
};
