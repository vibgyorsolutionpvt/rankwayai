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

        if (! Schema::hasColumn('seo_sites', 'pagespeed_strategy')) {
            Schema::table('seo_sites', function (Blueprint $table) {
                $table->string('pagespeed_strategy', 16)->nullable()->after('pagespeed_score');
            });
        }

        if (! Schema::hasColumn('seo_sites', 'pagespeed_report')) {
            Schema::table('seo_sites', function (Blueprint $table) {
                $after = Schema::hasColumn('seo_sites', 'pagespeed_issues')
                    ? 'pagespeed_issues'
                    : (Schema::hasColumn('seo_sites', 'pagespeed_strategy')
                        ? 'pagespeed_strategy'
                        : 'pagespeed_score');
                $table->json('pagespeed_report')->nullable()->after($after);
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('seo_sites')) {
            return;
        }

        $drop = array_values(array_filter([
            Schema::hasColumn('seo_sites', 'pagespeed_report') ? 'pagespeed_report' : null,
            Schema::hasColumn('seo_sites', 'pagespeed_strategy') ? 'pagespeed_strategy' : null,
        ]));

        if ($drop !== []) {
            Schema::table('seo_sites', function (Blueprint $table) use ($drop) {
                $table->dropColumn($drop);
            });
        }
    }
};
