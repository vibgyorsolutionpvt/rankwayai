<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('seo_sites')) {
            return;
        }

        if (! Schema::hasColumn('seo_sites', 'pagespeed_issues')) {
            Schema::table('seo_sites', function (Blueprint $table) {
                $table->json('pagespeed_issues')->nullable()->after('pagespeed_error');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('seo_sites') && Schema::hasColumn('seo_sites', 'pagespeed_issues')) {
            Schema::table('seo_sites', function (Blueprint $table) {
                $table->dropColumn('pagespeed_issues');
            });
        }
    }
};
