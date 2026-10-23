<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class Consultation extends Model
{
    use HasFactory;

    // 1. Point explicitly to the table shown in your database
    protected $table = 'consultation_requests';

    // 2. Point explicitly to your custom auto-incrementing primary key
    protected $primaryKey = 'request_id';

    /**
     * Every request_status value an actual application code path writes.
     *
     * 'assigned' is a live enum value (see the alter_consultation_requests
     * migration) but no controller or service transition ever sets it —
     * claimByNurse() moves straight to 'reviewed' and the physician flows
     * move straight to 'scheduled'/'active'. Analytics must not render it
     * as a meaningful status category (Phase 0/1 reconnaissance).
     */
    public const MEANINGFUL_STATUSES = [
        'pending',
        'reviewed',
        'scheduled',
        'active',
        'completed',
        'rejected',
        'cancelled',
    ];

    /** The two request_status values that can never move forward again. */
    public const CONCLUDED_STATUSES = ['completed', 'rejected', 'cancelled'];

    /** The four request_status values still moving through the workflow. */
    public const IN_FLIGHT_STATUSES = ['pending', 'reviewed', 'scheduled', 'active'];

    public const PRIORITY_LEVELS = ['High', 'Normal'];

    // 3. Map your custom timestamp column names from the schema
    const CREATED_AT = 'submitted_at';
    const UPDATED_AT = 'updated_at';

    // 4. Define fields allowed for mass assignment via the Controller's create() method
    protected $fillable = [
        'patient_id',
        'assigned_physician_id',
        'assigned_nurse_id',
        'type',
        'parent_consultation_id',
        'concern_category',
        'symptoms_desc',
        'online_reason',
        'additional_information',
        'request_status',
        'priority_level',
        'file_attachments', // Added your new column here
        'rejection_reason',
    ];

    /**
     * Optional Attribute Casting
     * Automatically json_decodes the text column back into an array when read,
     * and json_encodes it when saved to the database.
     */
    protected $casts = [
        'symptoms_desc'    => 'array',
        'file_attachments' => 'array', // Automatically handles converting arrays to JSON and vice-versa
    ];

    public function patient()
    {
        return $this->belongsTo(User::class, 'patient_id', 'user_id');
    }

    public function nurse()
    {
        return $this->belongsTo(User::class, 'assigned_nurse_id', 'user_id');
    }

    public function physician()
    {
        return $this->belongsTo(User::class, 'assigned_physician_id', 'user_id');
    }

    public function messages()
    {
    return $this->hasMany(Message::class, 'consultation_id')->orderBy('created_at', 'asc');
    }

    public function consultationSession(): HasOne
    {
        return $this->hasOne(ConsultationSession::class, 'request_id', 'request_id');
    }

    public function parentConsultation(): BelongsTo
    {
        return $this->belongsTo(ConsultationSession::class, 'parent_consultation_id', 'id');
    }

    /**
     * Both follow-up creation paths produce a type = 'follow_up' request, so
     * the type alone cannot say who started it. The discriminator is the
     * session's follow_up_request_id: the patient-initiated path (patient asks
     * -> nurse forwards -> physician approves, ConsultationOwnershipService::
     * decideFollowUpByPhysician) links the new session back to the originating
     * FollowUpRequest, while a physician acting on their own (PhysicianController::
     * createPhysicianFollowUp -> createFollowUpConsultationFromSource, called
     * without a $followUpRequestId) leaves it null.
     */
    public function isPhysicianInitiatedFollowUp(): bool
    {
        return $this->type === 'follow_up'
            && $this->consultationSession !== null
            && $this->consultationSession->follow_up_request_id === null;
    }

    /**
     * The name the patient dashboard shows on a physician-initiated follow-up
     * ("<name> has arranged this follow-up"), or null for any other request.
     * Expects physician and consultationSession to be loaded.
     */
    public function arrangingPhysicianName(): ?string
    {
        if (! $this->isPhysicianInitiatedFollowUp()) {
            return null;
        }

        return trim(optional($this->physician)->first_name.' '.optional($this->physician)->last_name) ?: 'Your physician';
    }

    /*
    |--------------------------------------------------------------------
    | Analytics scopes
    |--------------------------------------------------------------------
    | Canonical, reusable definitions behind every dashboard metric
    | (Phase 1 analytics blueprint DEF-01/DEF-02). Compose these instead
    | of re-deriving "completed"/"concluded"/etc. in a controller or
    | service — a query built from these always agrees with every other
    | query built from these.
    */

    /**
     * A request is completed when either side of the two-table split says
     * so: request_status = 'completed', OR its ConsultationSession has
     * consultation_status = 'completed'. ConsultationMessageController::
     * complete() writes both together in one transaction, so in practice
     * they agree — the OR is kept because the existing history controllers
     * (ConsultationController::history, PhysicianController::
     * consultationHistory) already rely on it, and this scope must never
     * disagree with those.
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->where('request_status', 'completed')
                ->orWhereHas('consultationSession', function (Builder $sessionQuery) {
                    $sessionQuery->where('consultation_status', 'completed');
                });
        });
    }

    /** Reached a terminal outcome: completed (OR-based), rejected, or cancelled. */
    public function scopeConcluded(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->completed()->orWhereIn('request_status', ['rejected', 'cancelled']);
        });
    }

    /** Still moving through the workflow — the complement of concluded(). */
    public function scopeInFlight(Builder $query): Builder
    {
        return $query->whereIn('request_status', self::IN_FLIGHT_STATUSES);
    }

    /**
     * Not a follow-up. Legacy rows predating the type column's migration
     * have type = NULL and are treated as initial, matching how
     * ConsultationController::history and PhysicianController::
     * consultationHistory already read a NULL type.
     */
    public function scopeInitial(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->where('type', 'initial')->orWhereNull('type');
        });
    }

    public function scopeFollowUp(Builder $query): Builder
    {
        return $query->where('type', 'follow_up');
    }

    /**
     * assigned_nurse_id is set once by ConsultationOwnershipService::
     * claimByNurse() and never cleared, so it is stable across the whole
     * lifecycle — including a follow-up spawned from a request this nurse
     * claimed, which inherits the column verbatim.
     */
    public function scopeForNurse(Builder $query, int $nurseId): Builder
    {
        return $query->where('assigned_nurse_id', $nurseId);
    }

    /**
     * assigned_physician_id, not consultations.physician_id — a request the
     * physician rejected never gets a ConsultationSession, and scoping on
     * the request keeps that rejection in the physician's own numbers.
     */
    public function scopeForPhysician(Builder $query, int $physicianId): Builder
    {
        return $query->where('assigned_physician_id', $physicianId);
    }

    /** Inclusive on both ends. $start/$end may be Carbon instances or date strings. */
    public function scopeSubmittedBetween(Builder $query, Carbon|string $start, Carbon|string $end): Builder
    {
        return $query->whereBetween('submitted_at', [$start, $end]);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('request_status', 'pending');
    }

    /** No nurse has claimed this request yet. */
    public function scopeUnclaimed(Builder $query): Builder
    {
        return $query->whereNull('assigned_nurse_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('request_status', 'active');
    }

    public function scopeHighPriority(Builder $query): Builder
    {
        return $query->where('priority_level', 'High');
    }
}