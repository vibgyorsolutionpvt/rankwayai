<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_chatbots', function (Blueprint $table) {
            $table->string('mode', 20)->default('custom')->after('enabled');
        });

        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            $table->json('bot_state')->nullable()->after('opted_out_until');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_chatbots', function (Blueprint $table) {
            $table->dropColumn('mode');
        });

        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            $table->dropColumn('bot_state');
        });
    }
};
