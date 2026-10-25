<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('email_provider_daily_usages')) {
            $hasUnique = collect(Schema::getIndexes('email_provider_daily_usages'))
                ->contains(fn (array $index) => $index['unique']
                    && $index['columns'] === ['provider', 'credential_hash', 'usage_date']);
            if (! $hasUnique) {
                Schema::table('email_provider_daily_usages', function (Blueprint $table) {
                    $table->unique(['provider', 'credential_hash', 'usage_date'], 'email_usage_daily_unique');
                });
            }

            return;
        }

        Schema::create('email_provider_daily_usages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('provider', 40);
            $table->char('credential_hash', 64);
            $table->date('usage_date');
            $table->unsignedInteger('reserved_count')->default(0);
            $table->timestamps();
            $table->unique(['provider', 'credential_hash', 'usage_date'], 'email_usage_daily_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_provider_daily_usages');
    }
};
