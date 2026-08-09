<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE seo_sites MODIFY last_crawl_error TEXT NULL');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE seo_sites ALTER COLUMN last_crawl_error TYPE TEXT');
        } elseif ($driver === 'sqlite') {
            // SQLite affinity is flexible; no-op for tests.
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE seo_sites MODIFY last_crawl_error VARCHAR(255) NULL');
        }
    }
};
