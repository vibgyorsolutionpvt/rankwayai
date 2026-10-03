<?php

use App\Models\WorkspaceIntegration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_integrations', function (Blueprint $table) {
            // Plain lookup key (e.g. WhatsApp phone_number_id) since credentials are encrypted.
            $table->string('external_id', 64)->nullable()->after('provider');
            $table->index(['provider', 'external_id']);
        });

        WorkspaceIntegration::query()
            ->where('provider', 'whatsapp_meta')
            ->each(function (WorkspaceIntegration $row) {
                $phoneId = trim((string) ($row->credentials['phone_number_id'] ?? ''));
                $row->external_id = $phoneId !== '' ? $phoneId : null;
                $row->saveQuietly();
            });
    }

    public function down(): void
    {
        Schema::table('workspace_integrations', function (Blueprint $table) {
            $table->dropIndex(['provider', 'external_id']);
            $table->dropColumn('external_id');
        });
    }
};
