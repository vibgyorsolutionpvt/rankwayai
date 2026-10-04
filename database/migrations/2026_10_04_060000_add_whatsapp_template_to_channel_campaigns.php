<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_campaigns', function (Blueprint $table) {
            $table->unsignedBigInteger('whatsapp_template_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('channel_campaigns', function (Blueprint $table) {
            $table->dropIndex(['whatsapp_template_id']);
            $table->dropColumn('whatsapp_template_id');
        });
    }
};
