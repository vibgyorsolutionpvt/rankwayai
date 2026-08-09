<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('crm_lead_id')->nullable()->constrained('crm_leads')->nullOnDelete();
            $table->string('phone', 32);
            $table->string('contact_name')->nullable();
            $table->string('external_conversation_id')->nullable();
            $table->string('status', 32)->default('open'); // open, closed
            $table->unsignedInteger('unread_count')->default(0);
            $table->text('last_message_preview')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('window_expires_at')->nullable(); // 24h customer care window
            $table->timestamps();

            $table->unique(['workspace_id', 'phone']);
            $table->index(['workspace_id', 'last_message_at']);
        });

        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('direction', 16); // inbound, outbound
            $table->text('body');
            $table->string('status', 32)->default('sent'); // queued, sent, delivered, failed, received
            $table->string('provider_message_id')->nullable();
            $table->string('template_name')->nullable();
            $table->json('meta')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['whatsapp_conversation_id', 'created_at']);
            $table->index('provider_message_id');
        });

        Schema::table('channel_message_templates', function (Blueprint $table) {
            $table->string('category', 40)->nullable()->after('channel'); // marketing, utility, auth
            $table->string('language', 16)->nullable()->after('category'); // en, hi, en_US
            $table->string('wa_status', 24)->default('draft')->after('language'); // draft, ready
        });
    }

    public function down(): void
    {
        Schema::table('channel_message_templates', function (Blueprint $table) {
            $table->dropColumn(['category', 'language', 'wa_status']);
        });

        Schema::dropIfExists('whatsapp_messages');
        Schema::dropIfExists('whatsapp_conversations');
    }
};
