<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One continuous period during which a physician deliberately accepted new
 * consultation requests.
 *
 * Intake is not presence. users.online_status/last_seen_at answer "is this
 * person connected?" and are written automatically by TrackUserPresence on
 * every authenticated request. This model answers "is this physician
 * intentionally accepting new requests?" and changes only through an explicit
 * start/stop, logout, or expiry.
 *
 * Lifecycle: a row is created 'open' when the physician starts intake, its
 * last_seen_at is bumped by the existing presence heartbeat, and it ends as
 * 'closed' (stopped, or logged out) or 'expired' (heartbeat went stale). Both
 * end states are terminal — starting intake again inserts a new row rather
 * than reopening this one, so each row records exactly one period.
 *
 * At most one row per physician may be 'open'. That invariant is held in the
 * service layer by a pessimistic lock on the physician's users row (the same
 * approach the staff-invitation resend uses), because a partial unique index
 * is unsupported on MySQL and a plain unique on (physician_id, status) would
 * wrongly forbid a physician from ever having two closed sessions.
 *
 * There is deliberately no relationship to Consultation, ConsultationSession
 * or ScheduleSlot. Availability must have no path into consultation lifecycle
 * records, so that ending availability cannot reach an active consultation.
 */
class PhysicianAvailabilitySession extends Model
{
    use HasFactory;

    /*
     * Every value end_reason may hold. A plain string column validated here
     * rather than an enum, so adding a reason never needs an ALTER (see the
     * SQLite enum gotcha in CLAUDE.md). Null means still open, or a row from
     * before the column existed.
     */
    public const END_MANUAL_CLOSE = 'manual_close';

    public const END_LOGOUT = 'logout';

    public const END_STALE_EXPIRY = 'stale_expiry';

    public const END_CONTINUED_AS_OVERTIME = 'continued_as_overtime';

    public const END_SCHEDULE_ENDED = 'schedule_ended';

    public const END_OVERTIME_LIMIT_REACHED = 'overtime_limit_reached';

    public const END_REASONS = [
        self::END_MANUAL_CLOSE,
        self::END_LOGOUT,
        self::END_STALE_EXPIRY,
        self::END_CONTINUED_AS_OVERTIME,
        self::END_SCHEDULE_ENDED,
        self::END_OVERTIME_LIMIT_REACHED,
    ];

    protected $fillable = [
        'physician_id',
        'started_at',
        'last_seen_at',
        'ended_at',
        'status',
        'mode',
        'planned_end_at',
        'end_reason',
        'end_warning_sent_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'ended_at' => 'datetime',
        'planned_end_at' => 'datetime',
        'end_warning_sent_at' => 'datetime',
    ];

    public function physician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'physician_id', 'user_id');
    }
}
