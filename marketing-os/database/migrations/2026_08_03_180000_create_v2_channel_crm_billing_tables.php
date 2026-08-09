<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('company')->nullable();
            $table->string('stage')->default('new'); // new, contacted, qualified, won, lost
            $table->string('source')->nullable(); // website, whatsapp, email, referral, manual
            $table->unsignedInteger('value_cents')->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('last_contacted_at')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'stage']);
        });

        Schema::create('channel_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('channel'); // whatsapp, email
            $table->string('subject')->nullable(); // email
            $table->text('body');
            $table->string('status')->default('draft'); // draft, scheduled, sending, sent, failed, cancelled
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->unsignedInteger('recipient_count')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->string('provider')->default('sandbox'); // sandbox, zavu
            $table->json('meta')->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'channel', 'status']);
        });

        Schema::create('channel_campaign_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('crm_lead_id')->nullable()->constrained('crm_leads')->nullOnDelete();
            $table->string('to'); // E.164 or email
            $table->string('status')->default('pending'); // pending, sent, failed, skipped
            $table->string('provider_message_id')->nullable();
            $table->string('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['channel_campaign_id', 'status']);
        });

        Schema::create('workspace_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete()->unique();
            $table->string('plan')->default('starter'); // starter, growth, agency
            $table->string('status')->default('trialing'); // trialing, active, past_due, cancelled
            $table->string('billing_provider')->default('manual'); // manual stub — Stripe later
            $table->unsignedInteger('seats')->default(3);
            $table->decimal('mrr_usd', 10, 2)->default(0);
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_ends_at')->nullable();
            $table->json('limits')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_subscriptions');
        Schema::dropIfExists('channel_campaign_recipients');
        Schema::dropIfExists('channel_campaigns');
        Schema::dropIfExists('crm_leads');
    }
};
