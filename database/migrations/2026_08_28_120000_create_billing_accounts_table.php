<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete()->unique();
            $table->foreignId('billing_workspace_id')->nullable()->constrained('workspaces')->nullOnDelete();
            $table->string('plan')->default('free');
            $table->string('status')->default('active');
            $table->string('billing_provider')->default('manual');
            $table->string('billing_market', 16)->default('in');
            $table->string('billing_currency', 8)->default('INR');
            $table->string('billing_interval', 8)->default('month');
            $table->unsignedInteger('seats')->default(1);
            $table->decimal('mrr_usd', 10, 2)->default(0);
            $table->decimal('mrr_amount', 12, 2)->default(0);
            $table->decimal('spent_usd', 10, 4)->default(0);
            $table->unsignedInteger('topup_credits')->default(0);
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_ends_at')->nullable();
            $table->json('limits')->nullable();
            $table->string('razorpay_customer_id')->nullable();
            $table->string('razorpay_subscription_id')->nullable();
            $table->string('razorpay_payment_link_id')->nullable();
            $table->timestamps();
        });

        Schema::create('billing_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 32); // plan_checkout, credit_recharge
            $table->string('plan', 32)->nullable();
            $table->string('pack_id', 40)->nullable();
            $table->unsignedInteger('credits')->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 8);
            $table->string('status', 24)->default('paid');
            $table->string('provider', 32)->nullable();
            $table->string('provider_ref')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['billing_account_id', 'created_at']);
            $table->index('provider_ref');
        });

        Schema::table('credit_recharges', function (Blueprint $table) {
            $table->foreignId('billing_account_id')
                ->nullable()
                ->after('user_id')
                ->constrained()
                ->nullOnDelete();
            $table->index('billing_account_id');
        });
    }

    public function down(): void
    {
        Schema::table('credit_recharges', function (Blueprint $table) {
            $table->dropConstrainedForeignId('billing_account_id');
        });
        Schema::dropIfExists('billing_transactions');
        Schema::dropIfExists('billing_accounts');
    }
};
