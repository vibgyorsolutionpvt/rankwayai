<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_ai_settings', function (Blueprint $table) {
            $table->unsignedInteger('topup_credits')->default(0)->after('spent_usd');
        });

        Schema::create('credit_recharges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('pack_id');
            $table->unsignedInteger('credits');
            $table->decimal('amount', 10, 2);
            $table->string('currency', 8);
            $table->string('billing_market', 16)->default('in');
            $table->string('status')->default('pending'); // pending, paid, failed
            $table->string('provider')->nullable(); // razorpay, stripe, manual
            $table->string('provider_ref')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'status']);
            $table->index('provider_ref');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_recharges');
        Schema::table('workspace_ai_settings', function (Blueprint $table) {
            $table->dropColumn('topup_credits');
        });
    }
};
