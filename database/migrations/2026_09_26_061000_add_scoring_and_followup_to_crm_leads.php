<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_leads', function (Blueprint $table) {
            $table->unsignedTinyInteger('score')->nullable()->after('notes');
            $table->string('score_band', 16)->nullable()->after('score'); // cold, warm, hot
            $table->text('score_reason')->nullable()->after('score_band');
            $table->string('score_source', 20)->nullable()->after('score_reason'); // heuristic, ai
            $table->timestamp('scored_at')->nullable()->after('score_source');
            $table->string('next_action', 160)->nullable()->after('scored_at');
            $table->text('follow_up_suggestion')->nullable()->after('next_action');
            $table->timestamp('follow_up_due_at')->nullable()->after('follow_up_suggestion');
        });
    }

    public function down(): void
    {
        Schema::table('crm_leads', function (Blueprint $table) {
            $table->dropColumn([
                'score',
                'score_band',
                'score_reason',
                'score_source',
                'scored_at',
                'next_action',
                'follow_up_suggestion',
                'follow_up_due_at',
            ]);
        });
    }
};
