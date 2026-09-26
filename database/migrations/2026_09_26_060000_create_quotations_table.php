<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('itinerary_id')->nullable()->constrained('itineraries')->nullOnDelete();
            $table->foreignId('crm_lead_id')->nullable()->constrained('crm_leads')->nullOnDelete();
            $table->foreignId('brand_kit_id')->nullable()->constrained('brand_kits')->nullOnDelete();
            $table->string('number', 40);
            $table->string('title');
            $table->string('status', 20)->default('draft'); // draft, sent, accepted, declined
            $table->string('customer_name')->nullable();
            $table->string('customer_email')->nullable();
            $table->string('customer_phone', 40)->nullable();
            $table->string('customer_company')->nullable();
            $table->string('trip_title')->nullable();
            $table->string('destination')->nullable();
            $table->unsignedSmallInteger('travellers')->default(1);
            $table->unsignedSmallInteger('duration_days')->nullable();
            $table->string('currency', 8)->default('INR');
            $table->json('line_items');
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_percent', 5, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->text('payment_terms')->nullable();
            $table->date('valid_until')->nullable();
            $table->text('notes')->nullable();
            $table->json('inclusions')->nullable();
            $table->json('exclusions')->nullable();
            $table->string('share_token', 64)->unique();
            $table->boolean('is_public')->default(true);
            $table->unsignedInteger('views_count')->default(0);
            $table->unsignedInteger('downloads_count')->default(0);
            $table->timestamps();

            $table->unique(['workspace_id', 'number']);
            $table->index(['workspace_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotations');
    }
};
