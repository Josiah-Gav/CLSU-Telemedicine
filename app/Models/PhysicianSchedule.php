<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recurring weekly window of a physician's normal consultation-intake
 * hours, e.g. "Monday 14:00-17:00".
 *
 * This is NOT schedule_slots. ScheduleSlot rows are concrete bookable
 * appointment inventory for one calendar date, consumed when a physician
 * schedules a reviewed consultation. These rows are a weekly pattern, read
 * only to decide whether an intake session opening right now is labelled
 * 'scheduled' or 'overtime'.
 *
 * day_of_week uses 0 = Sunday, 1 = Monday ... 6 = Saturday, matching
 * CarbonImmutable::now()->dayOfWeek so evaluation needs no conversion. Do not
 * change it to ISO-8601 numbering (1 = Monday ... 7 = Sunday): every stored row
 * would silently shift by one day with nothing failing loudly.
 *
 * A physician may hold several windows on the same day (a morning and an
 * evening clinic). Windows must not overlap and must not cross midnight; both
 * are application rules, since neither can be expressed as a constraint.
 */
class PhysicianSchedule extends Model
{
    use HasFactory;

    protected $fillable = [
        'physician_id',
        'day_of_week',
        'start_time',
        'end_time',
        'is_active',
    ];

    /**
     * start_time/end_time are deliberately left uncast, exactly as
     * ScheduleSlot leaves its own time columns: they are local wall-clock
     * strings ('14:00:00') compared against now()->format('H:i:s'), and
     * casting them to datetime would attach a meaningless date.
     */
    protected $casts = [
        'day_of_week' => 'integer',
        'is_active' => 'boolean',
    ];

    public function physician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'physician_id', 'user_id');
    }
}
