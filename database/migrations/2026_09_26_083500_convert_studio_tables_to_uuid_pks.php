<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Studio create-migrations were edited to UUID after tables already existed as
 * bigint auto-increment. Convert those PKs/FKs in place.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Fresh installs already create UUID PKs; this only repairs MySQL DBs
        // that ran the older bigint create migrations.
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        if (! Schema::hasTable('business_cards')) {
            return;
        }

        $type = $this->columnType('business_cards', 'id');
        if ($type && ! str_contains(strtolower($type), 'int')) {
            return;
        }

        Schema::disableForeignKeyConstraints();

        $this->dropForeign('business_card_events', 'business_card_id');
        $this->dropForeign('brochure_events', 'brochure_id');
        $this->dropForeign('quotations', 'itinerary_id');

        $cardMap = $this->swapPrimaryToUuid('business_cards');
        $this->swapPrimaryToUuid('business_card_events');
        $this->swapForeignToUuid('business_card_events', 'business_card_id', $cardMap, false);

        $brochureMap = $this->swapPrimaryToUuid('brochures');
        $this->swapPrimaryToUuid('brochure_events');
        $this->swapForeignToUuid('brochure_events', 'brochure_id', $brochureMap, false);

        $itineraryMap = $this->swapPrimaryToUuid('itineraries');
        $this->swapPrimaryToUuid('quotations');
        $this->swapForeignToUuid('quotations', 'itinerary_id', $itineraryMap, true);

        DB::statement('ALTER TABLE `business_card_events` ADD CONSTRAINT `business_card_events_business_card_id_foreign` FOREIGN KEY (`business_card_id`) REFERENCES `business_cards` (`id`) ON DELETE CASCADE');
        DB::statement('ALTER TABLE `brochure_events` ADD CONSTRAINT `brochure_events_brochure_id_foreign` FOREIGN KEY (`brochure_id`) REFERENCES `brochures` (`id`) ON DELETE CASCADE');
        DB::statement('ALTER TABLE `quotations` ADD CONSTRAINT `quotations_itinerary_id_foreign` FOREIGN KEY (`itinerary_id`) REFERENCES `itineraries` (`id`) ON DELETE SET NULL');

        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
        // Irreversible.
    }

    private function columnType(string $table, string $column): ?string
    {
        $row = collect(DB::select(
            'SHOW COLUMNS FROM `'.$table.'` WHERE Field = ?',
            [$column]
        ))->first();

        return $row->Type ?? null;
    }

    private function dropForeign(string $table, string $column): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $rows = DB::select("
            SELECT CONSTRAINT_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
              AND REFERENCED_TABLE_NAME IS NOT NULL
        ", [$table, $column]);

        foreach ($rows as $row) {
            DB::statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$row->CONSTRAINT_NAME}`");
        }
    }

    /** @return array<string, string> */
    private function swapPrimaryToUuid(string $table): array
    {
        if (! Schema::hasTable($table)) {
            return [];
        }

        $type = strtolower((string) $this->columnType($table, 'id'));
        if (! str_contains($type, 'int')) {
            return [];
        }

        $hasUuidCol = collect(DB::select("SHOW COLUMNS FROM `{$table}` WHERE Field = 'uuid_id'"))->isNotEmpty();
        if (! $hasUuidCol) {
            DB::statement("ALTER TABLE `{$table}` ADD COLUMN `uuid_id` CHAR(36) NULL AFTER `id`");
        }

        $map = [];
        foreach (DB::table($table)->select('id', 'uuid_id')->orderBy('id')->get() as $row) {
            $uuid = $row->uuid_id ?: (string) Str::uuid();
            $map[(string) $row->id] = $uuid;
            if (! $row->uuid_id) {
                DB::table($table)->where('id', $row->id)->update(['uuid_id' => $uuid]);
            }
        }

        // MySQL: cannot DROP PRIMARY KEY while AUTO_INCREMENT remains.
        DB::statement("ALTER TABLE `{$table}` MODIFY `id` BIGINT UNSIGNED NOT NULL");
        DB::statement("ALTER TABLE `{$table}` DROP PRIMARY KEY");
        DB::statement("ALTER TABLE `{$table}` DROP COLUMN `id`");
        DB::statement("ALTER TABLE `{$table}` CHANGE `uuid_id` `id` CHAR(36) NOT NULL");
        DB::statement("ALTER TABLE `{$table}` ADD PRIMARY KEY (`id`)");

        return $map;
    }

    /** @param  array<string, string>  $map */
    private function swapForeignToUuid(string $table, string $column, array $map, bool $nullable): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $type = strtolower((string) $this->columnType($table, $column));
        if ($type && ! str_contains($type, 'int')) {
            return;
        }

        DB::statement("ALTER TABLE `{$table}` ADD COLUMN `uuid_fk` CHAR(36) NULL AFTER `{$column}`");

        foreach (DB::table($table)->select('id', $column)->get() as $row) {
            $old = $row->{$column};
            if ($old === null) {
                continue;
            }
            $new = $map[(string) $old] ?? null;
            if ($new) {
                DB::table($table)->where('id', $row->id)->update(['uuid_fk' => $new]);
            }
        }

        DB::statement("ALTER TABLE `{$table}` DROP COLUMN `{$column}`");
        $null = $nullable ? 'NULL' : 'NOT NULL';
        DB::statement("ALTER TABLE `{$table}` CHANGE `uuid_fk` `{$column}` CHAR(36) {$null}");
    }
};
