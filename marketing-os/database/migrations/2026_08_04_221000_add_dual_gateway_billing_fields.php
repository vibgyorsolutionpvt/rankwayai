<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_subscriptions', function (Blueprint $table) {
            $table->string('billing_market', 16)->default('in')->after('billing_provider');
            $table->string('billing_currency', 8)->default('INR')->after('billing_market');
            $table->decimal('mrr_amount', 10, 2)->default(0)->after('mrr_usd');
            $table->string('razorpay_customer_id')->nullable()->after('stripe_checkout_session_id');
            $table->string('razorpay_subscription_id')->nullable()->after('razorpay_customer_id');
            $table->string('razorpay_payment_link_id')->nullable()->after('razorpay_subscription_id');
        });
    }

    public function down(): void
    {
        Schema::table('workspace_subscriptions', function (Blueprint $table) {
            $table->dropColumn([
                'billing_market',
                'billing_currency',
                'mrr_amount',
                'razorpay_customer_id',
                'razorpay_subscription_id',
                'razorpay_payment_link_id',
            ]);
        });
    }
};
