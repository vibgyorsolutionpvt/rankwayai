<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_provider_monthly_usages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('provider', 40);
            $table->char('credential_hash', 64);
            $table->char('usage_month', 7);
            $table->unsignedInteger('reserved_count')->default(0);
            $table->timestamps();
            $table->unique(['provider', 'credential_hash', 'usage_month'], 'email_usage_monthly_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_provider_monthly_usages');
    }
};
