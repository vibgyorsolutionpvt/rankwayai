<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_lead_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('crm_lead_id')->constrained('crm_leads')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 40); // note, stage_change, created, whatsapp, quotation
            $table->text('body');
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['crm_lead_id', 'created_at']);
        });

        Schema::create('crm_quotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('crm_lead_id')->constrained('crm_leads')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('number', 40);
            $table->string('title');
            $table->string('status', 20)->default('draft'); // draft, sent, accepted, declined
            $table->string('currency', 8)->default('USD');
            $table->json('line_items');
            $table->unsignedInteger('subtotal_cents')->default(0);
            $table->decimal('tax_percent', 5, 2)->default(0);
            $table->unsignedInteger('tax_cents')->default(0);
            $table->unsignedInteger('total_cents')->default(0);
            $table->text('notes')->nullable();
            $table->date('valid_until')->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'number']);
            $table->index(['crm_lead_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_quotations');
        Schema::dropIfExists('crm_lead_activities');
    }
};
