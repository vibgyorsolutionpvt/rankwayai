<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brand_kits', function (Blueprint $table) {
            $table->dropForeign(['workspace_id']);
        });

        Schema::table('brand_kits', function (Blueprint $table) {
            $table->dropUnique(['workspace_id']);
        });

        Schema::table('brand_kits', function (Blueprint $table) {
            $table->string('name')->default('Default')->after('workspace_id');
            $table->boolean('is_active')->default(false)->after('name');
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->index(['workspace_id', 'is_active']);
        });

        DB::table('brand_kits')->update([
            'name' => 'Default',
            'is_active' => true,
        ]);
    }

    public function down(): void
    {
        $workspaceIds = DB::table('brand_kits')->distinct()->pluck('workspace_id');
        foreach ($workspaceIds as $workspaceId) {
            $keepId = DB::table('brand_kits')
                ->where('workspace_id', $workspaceId)
                ->orderByDesc('is_active')
                ->orderBy('id')
                ->value('id');

            DB::table('brand_kits')
                ->where('workspace_id', $workspaceId)
                ->where('id', '!=', $keepId)
                ->delete();
        }

        Schema::table('brand_kits', function (Blueprint $table) {
            $table->dropForeign(['workspace_id']);
            $table->dropIndex(['workspace_id', 'is_active']);
            $table->dropColumn(['name', 'is_active']);
        });

        Schema::table('brand_kits', function (Blueprint $table) {
            $table->unique('workspace_id');
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
        });
    }
};
