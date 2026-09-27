<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Gives every intake session a planned end, so an open tab can no longer
     * keep the clinic accepting requests after the physician has left.
     *
     * All three columns are nullable and nothing is backfilled: rows created
     * before this migration keep planned_end_at = null, which the service reads
     * as "no planned end" — they are only ever closed manually, on logout, or
     * when the heartbeat goes stale, exactly as before.
     *
     * dateTime rather than timestamp for the same MySQL implicit-default reason
     * given in the create migration. end_reason is a plain string validated in
     * application code (PhysicianAvailabilitySession::END_REASONS) rather than
     * an enum, so it never needs the driver-specific ALTER that CLAUDE.md warns
     * about; status stays open/closed/expired.
     */
    public function up(): void
    {
        Schema::table('physician_availability_sessions', function (Blueprint $table) {
            // Fixed when the session opens, like mode. Never moved afterwards.
            $table->dateTime('planned_end_at')->nullable()->after('ended_at');

            // Why the session ended. Null while open, and on legacy rows.
            $table->string('end_reason', 32)->nullable()->after('planned_end_at');

            // Exactly-once guard for the end-of-hours warning notification.
            $table->dateTime('end_warning_sent_at')->nullable()->after('end_reason');
        });
    }

    public function down(): void
    {
        Schema::table('physician_availability_sessions', function (Blueprint $table) {
            $table->dropColumn(['planned_end_at', 'end_reason', 'end_warning_sent_at']);
        });
    }
};
