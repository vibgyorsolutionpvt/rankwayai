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
            $table->string('phone', 30)->nullable()->after('city');
            $table->string('email', 120)->nullable()->after('phone');
            $table->string('website', 200)->nullable()->after('email');
        });

        if (! Schema::hasTable('brand_kits')) {
            return;
        }

        DB::table('workspaces')
            ->orderBy('id')
            ->lazyById()
            ->each(function (object $workspace): void {
                $kit = DB::table('brand_kits')
                    ->where('workspace_id', $workspace->id)
                    ->orderByDesc('is_active')
                    ->orderBy('id')
                    ->first();

                if (! $kit) {
                    return;
                }

                DB::table('workspaces')
                    ->where('id', $workspace->id)
                    ->update([
                        'phone' => $workspace->phone ?? $kit->phone,
                        'email' => $workspace->email ?? $kit->email,
                        'website' => $workspace->website ?? $kit->website_url,
                    ]);
            });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn(['phone', 'email', 'website']);
        });
    }
};
