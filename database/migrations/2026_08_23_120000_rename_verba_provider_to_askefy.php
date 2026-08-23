<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('cms_connections')
            ->where('provider', 'verba')
            ->update(['provider' => 'askefy']);
    }

    public function down(): void
    {
        DB::table('cms_connections')
            ->where('provider', 'askefy')
            ->update(['provider' => 'verba']);
    }
};
