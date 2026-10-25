<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The UUID rewrite appended id at the end of each table. id belongs in the first column.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        $rows = DB::select('
            SELECT TABLE_NAME, COLUMN_TYPE, IS_NULLABLE
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND COLUMN_NAME = "id"
              AND ORDINAL_POSITION <> 1
        ');

        foreach ($rows as $row) {
            if (! preg_match('/^[A-Za-z0-9_]+$/', $row->TABLE_NAME)) {
                continue;
            }
            if (! preg_match('/^(?:char|varchar)\(\d+\)$/i', $row->COLUMN_TYPE)) {
                continue;
            }

            $null = $row->IS_NULLABLE === 'YES' ? 'NULL' : 'NOT NULL';
            DB::statement("ALTER TABLE `{$row->TABLE_NAME}` MODIFY `id` {$row->COLUMN_TYPE} {$null} FIRST");
        }
    }

    public function down(): void
    {
        // Column order is not restored.
    }
};
