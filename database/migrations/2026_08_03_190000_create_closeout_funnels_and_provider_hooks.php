<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->string('account_type')->default('page')->after('platform'); // page, profile
            $table->string('connection_mode')->default('sandbox')->after('account_type'); // sandbox, oauth
        });

        Schema::table('seo_sites', function (Blueprint $table) {
            $table->string('gsc_connection_mode')->default('none')->after('gsc_connected'); // none, sandbox, oauth
            $table->decimal('cwv_lcp', 8, 2)->nullable()->after('last_crawl_error');
            $table->decimal('cwv_cls', 8, 3)->nullable();
            $table->decimal('cwv_inp', 8, 2)->nullable();
            $table->unsignedSmallInteger('pagespeed_score')->nullable();
            $table->timestamp('pagespeed_checked_at')->nullable();
            $table->string('pagespeed_error')->nullable();
        });

        Schema::table('workspace_subscriptions', function (Blueprint $table) {
            $table->string('stripe_customer_id')->nullable()->after('billing_provider');
            $table->string('stripe_subscription_id')->nullable()->after('stripe_customer_id');
            $table->string('stripe_checkout_session_id')->nullable()->after('stripe_subscription_id');
        });

        Schema::create('funnels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('status')->default('draft'); // draft, published
            $table->string('headline')->nullable();
            $table->text('subheadline')->nullable();
            $table->string('cta_label')->nullable();
            $table->string('cta_url')->nullable();
            $table->text('body_html')->nullable();
            $table->string('primary_color', 20)->nullable();
            $table->unsignedInteger('views')->default(0);
            $table->unsignedInteger('leads')->default(0);
            $table->timestamps();
            $table->unique(['workspace_id', 'slug']);
        });

        Schema::create('funnel_leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('funnel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('email');
            $table->string('phone')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('funnel_leads');
        Schema::dropIfExists('funnels');

        Schema::table('workspace_subscriptions', function (Blueprint $table) {
            $table->dropColumn(['stripe_customer_id', 'stripe_subscription_id', 'stripe_checkout_session_id']);
        });

        Schema::table('seo_sites', function (Blueprint $table) {
            $table->dropColumn([
                'gsc_connection_mode',
                'cwv_lcp',
                'cwv_cls',
                'cwv_inp',
                'pagespeed_score',
                'pagespeed_checked_at',
                'pagespeed_error',
            ]);
        });

        Schema::table('social_accounts', function (Blueprint $table) {
            $table->dropColumn(['account_type', 'connection_mode']);
        });
    }
};
