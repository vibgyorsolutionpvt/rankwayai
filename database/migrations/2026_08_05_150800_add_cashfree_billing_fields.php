<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_subscriptions', function (Blueprint $table) {
            $table->string('cashfree_order_id')->nullable()->after('razorpay_payment_link_id');
            $table->string('cashfree_payment_link_id')->nullable()->after('cashfree_order_id');
            $table->string('cashfree_customer_id')->nullable()->after('cashfree_payment_link_id');
        });
    }

    public function down(): void
    {
        Schema::table('workspace_subscriptions', function (Blueprint $table) {
            $table->dropColumn([
                'cashfree_order_id',
                'cashfree_payment_link_id',
                'cashfree_customer_id',
            ]);
        });
    }
};
