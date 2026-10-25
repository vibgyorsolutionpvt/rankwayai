<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seo_sites', function (Blueprint $table) {
            $table->boolean('gbp_connected')->default(false)->after('gsc_last_error');
            $table->text('gbp_token')->nullable()->after('gbp_connected');
            $table->string('gbp_account_name')->nullable()->after('gbp_token');
            $table->string('gbp_location_name')->nullable()->after('gbp_account_name');
            $table->string('gbp_location_title')->nullable()->after('gbp_location_name');
            $table->json('gbp_locations')->nullable()->after('gbp_location_title');
            $table->json('gbp_profile')->nullable()->after('gbp_locations');
            $table->json('gbp_reviews')->nullable()->after('gbp_profile');
            $table->text('gbp_last_error')->nullable()->after('gbp_reviews');
        });
    }

    public function down(): void
    {
        Schema::table('seo_sites', function (Blueprint $table) {
            $table->dropColumn([
                'gbp_connected',
                'gbp_token',
                'gbp_account_name',
                'gbp_location_name',
                'gbp_location_title',
                'gbp_locations',
                'gbp_profile',
                'gbp_reviews',
                'gbp_last_error',
            ]);
        });
    }
};
