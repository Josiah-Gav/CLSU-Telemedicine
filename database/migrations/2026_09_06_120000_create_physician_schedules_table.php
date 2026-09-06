<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A physician's recurring consultation-intake hours — deliberately a
     * separate table from schedule_slots, which stores concrete bookable
     * appointment inventory tied to one calendar date. This table stores a
     * weekly pattern and is only ever read to label an intake session as
     * 'scheduled' or 'overtime'; it never gates anything, so a physician with
     * no rows here is not blocked from anything.
     */
    public function up(): void
    {
        Schema::create('physician_schedules', function (Blueprint $table) {
            $table->id();

            // Same users FK convention as follow_up_requests: users' primary
            // key is user_id, not id.
            $table->foreignId('physician_id')
                ->constrained('users', 'user_id')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            // 0 = Sunday ... 6 = Saturday, matching CarbonImmutable::dayOfWeek
            // so schedule evaluation needs no conversion. NOT ISO-8601
            // (1 = Monday), which would shift every physician's week by a day.
            // See PhysicianSchedule's class docblock.
            $table->unsignedTinyInteger('day_of_week');

            // Local wall-clock, exactly as schedule_slots.start_time stores it.
            // config/app.php pins the application timezone to Asia/Manila, so
            // these need no conversion layer.
            $table->time('start_time');
            $table->time('end_time');

            // Lets a physician suspend a window (leave, rotation) without
            // losing it and re-entering it later. Removing a window that was
            // simply entered wrongly is a plain delete — the project uses no
            // SoftDeletes anywhere.
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // Mirrors schedule_slots' unique(physician_id, slot_date, start_time).
            // Deliberately NOT unique on (physician_id, day_of_week): a
            // physician may hold several windows in one day, e.g. Monday
            // 14:00-17:00 and 19:00-21:00. Overlap between windows cannot be
            // expressed as a constraint and is enforced in the application.
            $table->unique(['physician_id', 'day_of_week', 'start_time']);

            // Serves the only query shape that exists: this physician's
            // windows for today.
            $table->index(['physician_id', 'day_of_week']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('physician_schedules');
    }
};
