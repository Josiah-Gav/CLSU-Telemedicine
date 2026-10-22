<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultations', function (Blueprint $table) {
            // pending | synced | failed | skipped; NULL = not applicable (not
            // completed, or completed before CHIS sync existed). A plain string,
            // not a DB enum: SQLite tests would never enforce an enum anyway.
            $table->string('chis_sync_status', 20)->nullable();
            $table->string('chis_reference')->nullable();
            $table->timestamp('chis_synced_at')->nullable();
            $table->unsignedInteger('chis_attempts')->default(0);
            // A short code-like reason only (e.g. http_422:VALIDATION_FAILED),
            // never patient data, clinical text or a response body.
            $table->string('chis_last_error', 255)->nullable();

            $table->index(['chis_sync_status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::table('consultations', function (Blueprint $table) {
            $table->dropIndex(['chis_sync_status', 'updated_at']);
            $table->dropColumn(['chis_sync_status', 'chis_reference', 'chis_synced_at', 'chis_attempts', 'chis_last_error']);
        });
    }
};
