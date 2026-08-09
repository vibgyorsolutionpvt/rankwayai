<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_menus', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::table('workspaces', function (Blueprint $table) {
            $table->json('enabled_modules')->nullable()->after('slug');
        });

        Schema::table('workspace_user', function (Blueprint $table) {
            $table->json('enabled_modules')->nullable()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('workspace_user', function (Blueprint $table) {
            $table->dropColumn('enabled_modules');
        });

        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn('enabled_modules');
        });

        Schema::dropIfExists('platform_menus');
    }
};
