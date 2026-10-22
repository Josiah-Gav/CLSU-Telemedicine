<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only record THAT Telemed sent or viewed CHIS data (RA 10173:
     * "who accessed whose data, and when") — never WHAT. There are no columns
     * for request/response bodies, clinical text, names or the token.
     */
    public function up(): void
    {
        Schema::create('chis_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('action', 30);   // encounter_push | patient_lookup
            $table->string('outcome', 30);  // success | not_found | rejected | unavailable
            $table->string('source', 30);   // sync_job | health_command (phase 5: consultation_view)

            // Deliberately no foreign keys: audit rows must outlive deleted users/sessions.
            $table->unsignedBigInteger('patient_user_id')->nullable()->index();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->unsignedBigInteger('consultation_session_id')->nullable()->index();

            // Snapshot: an admin can later change a user's CLSU ID.
            $table->string('clsu_id', 50)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('reason', 100)->nullable();
            $table->string('chis_reference')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chis_audit_logs');
    }
};
