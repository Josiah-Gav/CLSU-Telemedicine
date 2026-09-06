<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One continuous period during which a physician deliberately accepted new
     * consultation requests.
     *
     * This is not presence. users.online_status answers "is this person
     * connected?" and is written automatically by TrackUserPresence on every
     * authenticated request; it can never express intent. This table answers
     * "is this physician intentionally accepting new requests?" and is written
     * only by an explicit action, by logout, or by expiry.
     *
     * It has no foreign key to consultation_requests, consultations or
     * schedule_slots, and that absence is the point: there is no path from an
     * availability session into a consultation, so ending availability cannot
     * reach an active consultation.
     */
    public function up(): void
    {
        Schema::create('physician_availability_sessions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('physician_id')
                ->constrained('users', 'user_id')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            // dateTime, not timestamp, for all three. MySQL/MariaDB gives the
            // first TIMESTAMP column in a table an implicit
            // DEFAULT CURRENT_TIMESTAMP and every later NOT NULL TIMESTAMP an
            // implicit '0000-00-00 00:00:00', which strict mode then rejects
            // ("Invalid default value for 'last_seen_at'"). SQLite has no such
            // rule, so the test suite cannot catch it. DATETIME carries no
            // implicit default and behaves identically through the model's
            // datetime casts. consultation_video_sessions already uses
            // dateTime() for the same reason.

            // The domain fact — when this physician chose to accept
            // consultations. Kept alongside created_at for the same reason
            // consultations carries assigned_at/started_at/completed_at next to
            // its timestamps(): audit columns and domain facts stay separate.
            $table->dateTime('started_at');

            // Bumped by the existing 60-second presence heartbeat. This is the
            // staleness clock for intake, deliberately separate from
            // users.last_seen_at, which any page load in any role refreshes.
            $table->dateTime('last_seen_at');

            // Null while the session is open.
            $table->dateTime('ended_at')->nullable();

            // 'closed' = the physician stopped, or logged out. 'expired' = the
            // heartbeat went stale. Both are terminal; reopening always inserts
            // a new row, so a session is an immutable record of one period.
            //
            // Every value is declared here, in the CREATE, on purpose. The
            // project's two ALTER ... MODIFY COLUMN enum migrations return
            // early on SQLite (see CLAUDE.md), so values added by a later ALTER
            // exist in MySQL but never in the test schema. A Schema::create()
            // enum is applied by both drivers, so this table never needs an
            // ALTER and never inherits that trap.
            $table->enum('status', ['open', 'closed', 'expired'])->default('open');

            // Stamped once by the server when the session opens, from the
            // physician's recurring schedule. Never recalculated: a session
            // opened at 16:55 inside a 14:00-17:00 window stays 'scheduled'
            // afterwards.
            $table->enum('mode', ['scheduled', 'overtime']);

            $table->timestamps();

            // Serves the service-level availability read (open sessions still
            // fresh) and the expiry scan, which are the two hot paths.
            $table->index(['status', 'last_seen_at']);

            // Serves "does this physician already have an open session?" on
            // start, stop and heartbeat.
            $table->index(['physician_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('physician_availability_sessions');
    }
};
