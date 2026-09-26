<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brand_kits', function (Blueprint $table) {
            $table->string('secondary_logo_path')->nullable()->after('logo_path');
            $table->string('favicon_path')->nullable()->after('secondary_logo_path');
            $table->string('accent_color', 7)->nullable()->after('secondary_color');
            $table->string('heading_font')->nullable()->after('font_family');
            $table->string('brand_tone', 40)->nullable()->after('heading_font');
            $table->text('default_header')->nullable()->after('default_cta_url');
            $table->text('default_footer')->nullable()->after('default_header');
        });
    }

    public function down(): void
    {
        Schema::table('brand_kits', function (Blueprint $table) {
            $table->dropColumn([
                'secondary_logo_path',
                'favicon_path',
                'accent_color',
                'heading_font',
                'brand_tone',
                'default_header',
                'default_footer',
            ]);
        });
    }
};
