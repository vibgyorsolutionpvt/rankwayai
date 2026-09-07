<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('social_posts', 'publish_to_story')) {
            Schema::table('social_posts', function (Blueprint $table) {
                $table->boolean('publish_to_story')->default(true)->after('requires_approval');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('social_posts', 'publish_to_story')) {
            Schema::table('social_posts', function (Blueprint $table) {
                $table->dropColumn('publish_to_story');
            });
        }
    }
};
