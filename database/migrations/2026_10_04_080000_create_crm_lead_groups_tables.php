<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_lead_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 120);
            $table->string('status', 24)->default('processing');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->string('error_message')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'status']);
        });

        Schema::create('crm_lead_group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('crm_lead_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('crm_lead_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['crm_lead_group_id', 'crm_lead_id']);
            $table->index('crm_lead_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_lead_group_members');
        Schema::dropIfExists('crm_lead_groups');
    }
};
