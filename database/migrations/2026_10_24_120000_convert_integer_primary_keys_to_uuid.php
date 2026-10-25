<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Existing MySQL databases still have bigint primary keys. Fresh installs
 * already create UUID keys, so this migration only rewrites integer ids and
 * the foreign keys that point at them. The queue `jobs` and `failed_jobs`
 * tables stay numeric because Laravel's database queue expects that.
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $skipTables = ['jobs', 'failed_jobs', 'migrations', 'job_batches', 'cache', 'cache_locks'];

    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        $pkTables = $this->integerPrimaryKeyTables();
        if ($pkTables === []) {
            return;
        }
        $foreignKeys = $this->foreignKeys();
        $columns = $this->columnsToConvert($pkTables, $foreignKeys);

        DB::statement('DROP TEMPORARY TABLE IF EXISTS uuid_id_map');
        Schema::disableForeignKeyConstraints();

        $this->dropForeignKeys($foreignKeys);
        $indexes = $this->pullIndexes($columns);

        DB::statement('CREATE TEMPORARY TABLE uuid_id_map (table_name VARCHAR(64) NOT NULL, old_id BIGINT UNSIGNED NOT NULL, new_id CHAR(36) NOT NULL, PRIMARY KEY (table_name, old_id))');
        foreach ($pkTables as $table) {
            $this->rememberIds($table);
        }

        foreach ($pkTables as $table) {
            $this->swapPrimaryKey($table);
        }

        foreach ($columns as $column) {
            if ($column['column'] === 'id') {
                continue;
            }
            $this->swapForeignKey($column['table'], $column['column'], $column['references'], $column['nullable']);
        }

        $this->restoreIndexes($indexes);
        $this->restoreForeignKeys($foreignKeys);

        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
        // Integer ids are not restored. Take a database backup before migrating production.
    }

    /**
     * @return list<string>
     */
    private function integerPrimaryKeyTables(): array
    {
        $rows = DB::select('
            SELECT TABLE_NAME
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND COLUMN_NAME = "id"
              AND DATA_TYPE IN ("bigint", "int")
              AND COLUMN_KEY = "PRI"
        ');

        return collect($rows)
            ->map(fn ($row) => $row->TABLE_NAME)
            ->reject(fn (string $table) => in_array($table, $this->skipTables, true) || ! $this->safeName($table))
            ->values()
            ->all();
    }

    /**
     * @return list<array{name:string,table:string,column:string,references:string,on_delete:string,on_update:string}>
     */
    private function foreignKeys(): array
    {
        $rows = DB::select('
            SELECT k.CONSTRAINT_NAME, k.TABLE_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME,
                   r.DELETE_RULE, r.UPDATE_RULE
            FROM information_schema.KEY_COLUMN_USAGE k
            JOIN information_schema.REFERENTIAL_CONSTRAINTS r
              ON r.CONSTRAINT_SCHEMA = k.TABLE_SCHEMA
             AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
             AND r.TABLE_NAME = k.TABLE_NAME
            WHERE k.TABLE_SCHEMA = DATABASE()
              AND k.REFERENCED_TABLE_NAME IS NOT NULL
        ');

        return collect($rows)->map(fn ($row) => [
            'name' => $row->CONSTRAINT_NAME,
            'table' => $row->TABLE_NAME,
            'column' => $row->COLUMN_NAME,
            'references' => $row->REFERENCED_TABLE_NAME,
            'on_delete' => $row->DELETE_RULE,
            'on_update' => $row->UPDATE_RULE,
        ])->all();
    }

    /**
     * @param  list<string>  $pkTables
     * @param  list<array{name:string,table:string,column:string,references:string,on_delete:string,on_update:string}>  $foreignKeys
     * @return list<array{table:string,column:string,references:string,nullable:bool}>
     */
    private function columnsToConvert(array $pkTables, array $foreignKeys): array
    {
        $columns = [];
        foreach ($pkTables as $table) {
            $columns[$table.'.id'] = [
                'table' => $table,
                'column' => 'id',
                'references' => $table,
                'nullable' => false,
            ];
        }

        foreach ($foreignKeys as $foreignKey) {
            if (! in_array($foreignKey['references'], $pkTables, true)) {
                continue;
            }
            $type = strtolower((string) $this->columnType($foreignKey['table'], $foreignKey['column']));
            if (! str_contains($type, 'int')) {
                continue;
            }
            $columns[$foreignKey['table'].'.'.$foreignKey['column']] = [
                'table' => $foreignKey['table'],
                'column' => $foreignKey['column'],
                'references' => $foreignKey['references'],
                'nullable' => $this->isNullable($foreignKey['table'], $foreignKey['column']),
            ];
        }

        foreach ([
            ['sessions', 'user_id', 'users'],
            ['channel_campaigns', 'whatsapp_template_id', 'channel_message_templates'],
            ['personal_access_tokens', 'tokenable_id', 'users'],
        ] as [$table, $column, $references]) {
            if (! Schema::hasTable($table) || isset($columns[$table.'.'.$column])) {
                continue;
            }
            $type = strtolower((string) $this->columnType($table, $column));
            if (! str_contains($type, 'int')) {
                continue;
            }
            $columns[$table.'.'.$column] = [
                'table' => $table,
                'column' => $column,
                'references' => $references,
                'nullable' => $this->isNullable($table, $column),
            ];
        }

        return array_values($columns);
    }

    /**
     * @param  list<array{name:string,table:string,column:string,references:string,on_delete:string,on_update:string}>  $foreignKeys
     */
    private function dropForeignKeys(array $foreignKeys): void
    {
        $dropped = [];
        foreach ($foreignKeys as $foreignKey) {
            if (isset($dropped[$foreignKey['table'].'.'.$foreignKey['name']]) || ! $this->safeName($foreignKey['table']) || ! $this->safeName($foreignKey['name'])) {
                continue;
            }
            DB::statement("ALTER TABLE `{$foreignKey['table']}` DROP FOREIGN KEY `{$foreignKey['name']}`");
            $dropped[$foreignKey['table'].'.'.$foreignKey['name']] = true;
        }
    }

    /**
     * @param  list<array{table:string,column:string,references:string,nullable:bool}>  $columns
     * @return list<array{table:string,name:string,unique:bool,columns:list<string>}>
     */
    private function pullIndexes(array $columns): array
    {
        $watched = [];
        foreach ($columns as $column) {
            $watched[$column['table']][$column['column']] = true;
        }

        $rows = DB::select('
            SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND INDEX_NAME <> "PRIMARY"
            ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX
        ');

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row->TABLE_NAME.'.'.$row->INDEX_NAME]['table'] = $row->TABLE_NAME;
            $grouped[$row->TABLE_NAME.'.'.$row->INDEX_NAME]['name'] = $row->INDEX_NAME;
            $grouped[$row->TABLE_NAME.'.'.$row->INDEX_NAME]['unique'] = (int) $row->NON_UNIQUE === 0;
            $grouped[$row->TABLE_NAME.'.'.$row->INDEX_NAME]['columns'][] = $row->COLUMN_NAME;
        }

        $pulled = [];
        foreach ($grouped as $index) {
            $hit = false;
            foreach ($index['columns'] as $column) {
                if (isset($watched[$index['table']][$column])) {
                    $hit = true;
                    break;
                }
            }
            if (! $hit || ! $this->safeName($index['table']) || ! $this->safeName($index['name'])) {
                continue;
            }
            DB::statement("ALTER TABLE `{$index['table']}` DROP INDEX `{$index['name']}`");
            $pulled[] = $index;
        }

        return $pulled;
    }

    private function rememberIds(string $table): void
    {
        $ids = DB::table($table)->pluck('id');
        foreach ($ids->chunk(200) as $chunk) {
            $placeholders = implode(',', array_fill(0, $chunk->count(), '(?,?,?)'));
            $bindings = [];
            foreach ($chunk as $id) {
                $bindings[] = $table;
                $bindings[] = $id;
                $bindings[] = (string) Str::uuid();
            }
            DB::insert("INSERT INTO uuid_id_map (table_name, old_id, new_id) VALUES {$placeholders}", $bindings);
        }
    }

    private function swapPrimaryKey(string $table): void
    {
        DB::statement("ALTER TABLE `{$table}` ADD COLUMN `uuid_id` CHAR(36) NULL");
        DB::update("UPDATE `{$table}` t INNER JOIN uuid_id_map m ON m.table_name = ? AND m.old_id = t.id SET t.uuid_id = m.new_id", [$table]);
        DB::statement("ALTER TABLE `{$table}` MODIFY `id` BIGINT UNSIGNED NOT NULL");
        DB::statement("ALTER TABLE `{$table}` DROP PRIMARY KEY");
        DB::statement("ALTER TABLE `{$table}` DROP COLUMN `id`");
        DB::statement("ALTER TABLE `{$table}` CHANGE `uuid_id` `id` CHAR(36) NOT NULL FIRST");
        DB::statement("ALTER TABLE `{$table}` ADD PRIMARY KEY (`id`)");
    }

    private function swapForeignKey(string $table, string $column, string $references, bool $nullable): void
    {
        if (! $this->safeName($table) || ! $this->safeName($column)) {
            return;
        }

        DB::statement("ALTER TABLE `{$table}` ADD COLUMN `uuid_fk` CHAR(36) NULL");
        if ($table === 'personal_access_tokens' && $column === 'tokenable_id') {
            DB::update("UPDATE `{$table}` t INNER JOIN uuid_id_map m ON m.table_name = ? AND m.old_id = t.`{$column}` SET t.uuid_fk = m.new_id WHERE t.tokenable_type LIKE ?", [$references, '%User']);
            DB::delete("DELETE FROM `{$table}` WHERE uuid_fk IS NULL");
        } else {
            DB::update("UPDATE `{$table}` t INNER JOIN uuid_id_map m ON m.table_name = ? AND m.old_id = t.`{$column}` SET t.uuid_fk = m.new_id", [$references]);
        }
        if (! $nullable) {
            DB::delete("DELETE FROM `{$table}` WHERE `uuid_fk` IS NULL");
        }
        DB::statement("ALTER TABLE `{$table}` DROP COLUMN `{$column}`");
        $null = $nullable ? 'NULL' : 'NOT NULL';
        DB::statement("ALTER TABLE `{$table}` CHANGE `uuid_fk` `{$column}` CHAR(36) {$null}");
    }

    /**
     * @param  list<array{table:string,name:string,unique:bool,columns:list<string>}>  $indexes
     */
    private function restoreIndexes(array $indexes): void
    {
        foreach ($indexes as $index) {
            $columns = implode(', ', array_map(fn (string $column) => "`{$column}`", $index['columns']));
            $unique = $index['unique'] ? 'UNIQUE ' : '';
            DB::statement("CREATE {$unique}INDEX `{$index['name']}` ON `{$index['table']}` ({$columns})");
        }
    }

    /**
     * @param  list<array{name:string,table:string,column:string,references:string,on_delete:string,on_update:string}>  $foreignKeys
     */
    private function restoreForeignKeys(array $foreignKeys): void
    {
        foreach ($foreignKeys as $foreignKey) {
            if (! $this->safeName($foreignKey['table']) || ! $this->safeName($foreignKey['name']) || ! $this->safeName($foreignKey['column']) || ! $this->safeName($foreignKey['references'])) {
                continue;
            }
            $onDelete = $this->referentialAction($foreignKey['on_delete']);
            $onUpdate = $this->referentialAction($foreignKey['on_update']);
            DB::statement("ALTER TABLE `{$foreignKey['table']}` ADD CONSTRAINT `{$foreignKey['name']}` FOREIGN KEY (`{$foreignKey['column']}`) REFERENCES `{$foreignKey['references']}` (`id`) ON DELETE {$onDelete} ON UPDATE {$onUpdate}");
        }
    }

    private function referentialAction(string $rule): string
    {
        return match (strtoupper($rule)) {
            'CASCADE' => 'CASCADE',
            'SET NULL' => 'SET NULL',
            'SET DEFAULT' => 'SET DEFAULT',
            'RESTRICT' => 'RESTRICT',
            default => 'NO ACTION',
        };
    }

    private function columnType(string $table, string $column): ?string
    {
        $row = DB::selectOne('
            SELECT COLUMN_TYPE
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
        ', [$table, $column]);

        return $row->COLUMN_TYPE ?? null;
    }

    private function isNullable(string $table, string $column): bool
    {
        $row = DB::selectOne('
            SELECT IS_NULLABLE
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
        ', [$table, $column]);

        return ($row->IS_NULLABLE ?? 'YES') === 'YES';
    }

    private function safeName(string $name): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_]+$/', $name);
    }
};
