<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

/**
 * Append-only audit of every call Telemed makes to CHIS: THAT data was sent or
 * viewed, by whom, for whom, and when — never WHAT (RA 10173).
 *
 * Written only through record(), by the callers (they know the actor, session
 * and source; ChisClient does not).
 *
 * Limitation: append-only is enforced on the model only; query-builder
 * update()/delete() and raw SQL bypass model events. Real immutability needs
 * DB permissions (no UPDATE/DELETE grant on this table) if it ever matters.
 */
class ChisAuditLog extends Model
{
    const UPDATED_AT = null;

    public const ACTION_LABELS = [
        'encounter_push' => 'Summary sent',
        'patient_lookup' => 'Patient record viewed',
    ];

    public const OUTCOMES = ['success', 'not_found', 'rejected', 'unavailable', 'identity_mismatch'];

    /** The only context record() accepts; anything else is dropped. */
    private const CONTEXT_KEYS = [
        'patient_user_id', 'actor_user_id', 'consultation_session_id', 'clsu_id',
        'http_status', 'reason', 'chis_reference', 'duration_ms',
    ];

    protected $fillable = ['action', 'outcome', 'source', ...self::CONTEXT_KEYS];

    protected $casts = ['created_at' => 'datetime'];

    protected static function booted(): void
    {
        $refuse = fn () => throw new LogicException('The CHIS audit log is append-only.');

        static::updating($refuse);
        static::deleting($refuse);
    }

    /**
     * Never throws: a failed audit write must not break or change the CHIS
     * flow. Only the exception class is logged. Returns whether the row was
     * written, so a caller that must fail closed (viewing a medical record:
     * "no audit, no access") can check it; pushes ignore it.
     */
    public static function record(string $action, string $outcome, string $source, array $context = []): bool
    {
        try {
            static::create([
                'action' => $action,
                'outcome' => $outcome,
                'source' => $source,
                ...Arr::only($context, self::CONTEXT_KEYS),
            ]);

            return true;
        } catch (Throwable $e) {
            Log::warning('CHIS audit log write failed.', ['exception' => $e::class]);

            return false;
        }
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'patient_user_id', 'user_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id', 'user_id');
    }
}
