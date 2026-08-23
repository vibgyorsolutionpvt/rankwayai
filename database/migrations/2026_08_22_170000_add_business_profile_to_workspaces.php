<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->string('industry', 80)->nullable()->after('name');
            $table->string('city', 80)->nullable()->after('industry');
        });

        if (! Schema::hasTable('workspace_ai_settings')) {
            return;
        }

        DB::table('workspace_ai_settings')
            ->orderBy('id')
            ->lazyById()
            ->each(function (object $row): void {
                $industry = trim((string) ($row->industry ?? ''));
                $location = trim((string) ($row->location ?? ''));

                if ($industry === '' || $industry === 'local business') {
                    return;
                }

                if ($location === '' || $location === 'India') {
                    return;
                }

                DB::table('workspaces')
                    ->where('id', $row->workspace_id)
                    ->whereNull('industry')
                    ->whereNull('city')
                    ->update([
                        'industry' => $industry,
                        'city' => $location,
                    ]);
            });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn(['industry', 'city']);
        });
    }
};
