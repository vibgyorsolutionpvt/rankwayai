<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_campaigns', function (Blueprint $table) {
            $table->unsignedInteger('credits_charged')->default(0)->after('failed_count');
        });
    }

    public function down(): void
    {
        Schema::table('channel_campaigns', function (Blueprint $table) {
            $table->dropColumn('credits_charged');
        });
    }
};
