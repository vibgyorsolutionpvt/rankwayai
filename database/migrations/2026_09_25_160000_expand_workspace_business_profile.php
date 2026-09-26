<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->string('business_type', 40)->nullable()->after('name');
            $table->string('tagline', 160)->nullable();
            $table->text('description')->nullable();
            $table->string('whatsapp', 30)->nullable()->after('phone');
            $table->string('address', 255)->nullable();
            $table->string('state', 80)->nullable()->after('city');
            $table->string('country', 80)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->json('services')->nullable();
            $table->json('products')->nullable();
            $table->text('target_audience')->nullable();
            $table->string('working_hours', 120)->nullable();
            $table->json('social_links')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn([
                'business_type',
                'tagline',
                'description',
                'whatsapp',
                'address',
                'state',
                'country',
                'postal_code',
                'services',
                'products',
                'target_audience',
                'working_hours',
                'social_links',
            ]);
        });
    }
};
