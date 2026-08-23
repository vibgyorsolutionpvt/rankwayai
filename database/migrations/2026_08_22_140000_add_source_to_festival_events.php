<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('festival_events')) {
            return;
        }

        Schema::table('festival_events', function (Blueprint $table) {
            if (! Schema::hasColumn('festival_events', 'source')) {
                $table->string('source', 20)->default('config')->after('category');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('festival_events')) {
            return;
        }

        Schema::table('festival_events', function (Blueprint $table) {
            if (Schema::hasColumn('festival_events', 'source')) {
                $table->dropColumn('source');
            }
        });
    }
};
