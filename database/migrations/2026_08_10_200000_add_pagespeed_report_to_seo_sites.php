<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seo_sites', function (Blueprint $table) {
            $table->string('pagespeed_strategy', 16)->nullable()->after('pagespeed_score');
            $table->json('pagespeed_report')->nullable()->after('pagespeed_issues');
        });
    }

    public function down(): void
    {
        Schema::table('seo_sites', function (Blueprint $table) {
            $table->dropColumn(['pagespeed_strategy', 'pagespeed_report']);
        });
    }
};
