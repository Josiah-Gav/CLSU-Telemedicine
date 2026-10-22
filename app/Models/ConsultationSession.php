<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class ConsultationSession extends Model
{
    use HasFactory;

    protected $table = 'consultations';

    protected $fillable = [
        'request_id',
        'physician_id',
        'original_physician_id',
        'taken_over_by_physician_id',
        'taken_over_at',
        'slot_id',
        'follow_up_request_id',
        'consultation_status',
        'assessment',
        'plan',
        'recommendations',
        'prescription_file_name',
        'prescription_file_path',
        'prescription_mime_type',
        'prescription_file_size',
        'diagnosis',
        'cancellation_reason',
        'follow_up_required',
        'follow_up_date',
        'assigned_at',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'follow_up_required' => 'boolean',
        'follow_up_date' => 'date',
        'prescription_file_size' => 'integer',
        'assigned_at' => 'datetime',
        'taken_over_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        // CHIS sync columns are written only by ChisEncounterSync and
        // SyncEncounterToChis (forceFill / query updates), never mass-assigned.
        'chis_synced_at' => 'datetime',
        'chis_attempts' => 'integer',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(Consultation::class, 'request_id', 'request_id');
    }

    public function physician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'physician_id', 'user_id');
    }

    /**
     * The physician this consultation was scheduled to before any takeover.
     * Null while no takeover has happened — physician() is the only assignment
     * that ever matters in that case.
     */
    public function originalPhysician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'original_physician_id', 'user_id');
    }

    /** The physician who claimed this consultation via Physician Takeover. */
    public function takenOverByPhysician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'taken_over_by_physician_id', 'user_id');
    }

    /**
     * V1 allows a consultation to be claimed once only, so this doubles as the
     * "already claimed" guard in ConsultationOwnershipService::takeOverByPhysician.
     */
    public function wasTakenOver(): bool
    {
        return $this->taken_over_at !== null;
    }

    public function slot(): BelongsTo
    {
        return $this->belongsTo(ScheduleSlot::class, 'slot_id', 'slot_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'consultation_id', 'id')->orderBy('created_at', 'asc');
    }

    public function followUpRequests(): HasMany
    {
        return $this->hasMany(FollowUpRequest::class, 'consultation_id', 'id');
    }

    public function followUpRequest(): BelongsTo
    {
        return $this->belongsTo(FollowUpRequest::class, 'follow_up_request_id', 'id');
    }

    /**
     * Every video session ever opened for this consultation, newest first.
     */
    public function videoSessions(): HasMany
    {
        return $this->hasMany(ConsultationVideoSession::class, 'consultation_id', 'id')
            ->orderByDesc('created_at');
    }

    /**
     * The single video session that has not been ended yet, if any.
     */
    public function activeVideoSession(): HasOne
    {
        return $this->hasOne(ConsultationVideoSession::class, 'consultation_id', 'id')
            ->whereNull('ended_at');
    }

    public function hasMeaningfulAssessment(): bool
    {
        return $this->hasMeaningfulText($this->assessment, [
            'initial assessment pending.',
        ]);
    }

    public function hasMeaningfulPlan(): bool
    {
        return $this->hasMeaningfulText($this->plan, [
            'plan to be documented during consultation.',
        ]);
    }

    public function hasMeaningfulRecommendations(): bool
    {
        return $this->hasMeaningfulText($this->recommendations, [
            'recommendations to follow after evaluation.',
        ]);
    }

    public function hasDiagnosis(): bool
    {
        return filled(trim((string) $this->diagnosis));
    }

    public function hasPrescription(): bool
    {
        return filled($this->prescription_file_path);
    }

    public function hasClinicalDocumentation(): bool
    {
        return $this->hasMeaningfulAssessment()
            || $this->hasMeaningfulPlan()
            || $this->hasMeaningfulRecommendations()
            || $this->hasDiagnosis();
    }

    private function hasMeaningfulText(?string $value, array $placeholders = []): bool
    {
        $normalizedValue = Str::lower(trim((string) $value));

        if ($normalizedValue === '') {
            return false;
        }

        $normalizedPlaceholders = array_map(
            static fn (string $placeholder) => Str::lower(trim($placeholder)),
            $placeholders
        );

        return ! in_array($normalizedValue, $normalizedPlaceholders, true);
    }
}
