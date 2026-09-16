<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tracks whether the 24-hours-before reminder email has already gone out
     * for this booking, so `consultations:send-reminders` never double-sends.
     * Reset to null whenever a slot is released back to 'available' (see
     * ConsultationOwnershipService::scheduleByPhysician's reschedule branch),
     * so the same slot row can be reminded again the next time it is booked.
     */
    public function up(): void
    {
        Schema::table('schedule_slots', function (Blueprint $table) {
            $table->timestamp('reminder_sent_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('schedule_slots', function (Blueprint $table) {
            $table->dropColumn('reminder_sent_at');
        });
    }
};
