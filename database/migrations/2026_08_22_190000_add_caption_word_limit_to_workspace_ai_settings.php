<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_ai_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('caption_word_limit')
                ->default(50)
                ->after('tone');
        });
    }

    public function down(): void
    {
        Schema::table('workspace_ai_settings', function (Blueprint $table) {
            $table->dropColumn('caption_word_limit');
        });
    }
};
