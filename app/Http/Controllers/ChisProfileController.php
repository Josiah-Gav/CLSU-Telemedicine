<?php

namespace App\Http\Controllers;

use App\Exceptions\Chis\ChisRequestException;
use App\Exceptions\Chis\ChisUnavailableException;
use App\Models\ChisAuditLog;
use App\Models\ConsultationSession;
use App\Models\User;
use App\Services\ChisClient;
use App\Services\ChisIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;

/**
 * The patient's CHIS medical profile, for the assigned physician and, read-only,
 * for the patient themself (right of access, RA 10173 Sec. 16(c)): from a
 * consultation (show) or from the patient's profile page (showOwn). Fetched
 * lazily (never during page render) so a slow or down CHIS cannot delay the page.
 *
 * Gates, in order: assigned physician or owning patient only → CLSU ID → CHIS
 * lookup (audited, source consultation_view or patient_self_view; a profile-page
 * view has no session id) → identity check. No patient consent gate: the lawful basis is medical treatment
 * (RA 10173 Sec. 13). Both viewers get the same minimised profile. Nothing from
 * CHIS is stored or cached, and if the audit row cannot be written the profile
 * is withheld: no audit, no access.
 */
class ChisProfileController extends Controller
{
    /** Contract fields kept per list; anything else CHIS sends is dropped. */
    private const LISTS = [
        'allergies' => ['substance', 'reaction', 'severity'],
        'chronic_conditions' => ['name', 'diagnosed_on', 'status'],
        'current_medications' => ['name', 'dose', 'frequency'],
        'immunizations' => ['vaccine', 'dose', 'date_administered'],
        'family_history' => ['relation', 'condition'],
    ];

    public function show(ConsultationSession $session, ChisClient $chis): JsonResponse
    {
        $this->authorize('viewMessaging', $session);

        $viewer = Auth::user();
        $patient = $session->request?->patient;
        abort_unless($patient, 404);

        // viewMessaging already admits only these two; restated so the CHIS gate
        // never widens if that policy does.
        $isAssignedPhysician = $viewer->role === 'physician' && (int) $session->physician_id === (int) $viewer->user_id;
        $isOwnPatient = $viewer->role === 'patient' && (int) $patient->user_id === (int) $viewer->user_id;
        abort_unless($isAssignedPhysician || $isOwnPatient, 403, 'Only the assigned physician or the patient can view the CHIS record.');

        return $this->lookup($chis, $viewer, $patient, $session->id, $isOwnPatient ? 'patient_self_view' : 'consultation_view');
    }

    /** The signed-in patient's own record, from the profile page; no consultation needed. */
    public function showOwn(ChisClient $chis): JsonResponse
    {
        $patient = Auth::user();
        abort_unless($patient->role === 'patient', 403, 'Only patients have a CHIS record here.');

        return $this->lookup($chis, $patient, $patient, null, 'patient_self_view');
    }

    private function lookup(ChisClient $chis, User $viewer, User $patient, ?int $sessionId, string $source): JsonResponse
    {
        if (blank($patient->clsu_id)) {
            return $this->state('no_clsu_id');
        }

        $start = hrtime(true);
        $audit = fn (string $outcome, array $context = []) => ChisAuditLog::record('patient_lookup', $outcome, $source, [
            'actor_user_id' => $viewer->user_id,
            'patient_user_id' => $patient->user_id,
            'consultation_session_id' => $sessionId,
            'clsu_id' => $patient->clsu_id,
            'duration_ms' => intdiv(hrtime(true) - $start, 1_000_000),
            ...$context,
        ]);

        try {
            $record = $chis->findPatient($patient->clsu_id);
        } catch (ChisUnavailableException $e) {
            $audit('unavailable', ['http_status' => $e->status, 'reason' => $e->reason]);

            return $this->state('unavailable');
        } catch (ChisRequestException $e) {
            $audit('rejected', ['http_status' => $e->status, 'reason' => 'http_'.$e->status.($e->chisCode ? ':'.$e->chisCode : '')]);

            return $this->state('error');
        }

        if ($record === null) {
            $audit('not_found', ['http_status' => 404]);

            return $this->state('not_found', ['clsu_id' => $patient->clsu_id]);
        }

        if (! ChisIdentity::matches($patient, $record)) {
            // No data and no CHIS name: don't reveal who that CLSU ID belongs to.
            $audit('identity_mismatch', ['http_status' => 200, 'reason' => 'identity_mismatch']);

            return $this->state('identity_mismatch');
        }

        // Fail closed: no audit row, no access.
        if (! $audit('success', ['http_status' => 200])) {
            return $this->state('unavailable');
        }

        return $this->state('ok', ['profile' => $this->minimise($record)]);
    }

    /** Only what the physician needs (the patient sees the same); middle name, college etc. are not returned. */
    private function minimise(array $record): array
    {
        $lists = [];
        foreach (self::LISTS as $list => $fields) {
            $lists[$list] = collect($record['medical_profile'][$list] ?? [])
                ->filter(fn ($item) => is_array($item))
                ->map(fn (array $item) => Arr::only($item, $fields))
                ->values()
                ->all();
        }

        return [
            'blood_type' => $record['blood_type'] ?? null,
            'sex' => $record['sex'] ?? null,
            'birth_date' => $record['birth_date'] ?? null,
            'record_updated_at' => $record['record_updated_at'] ?? null,
            'medical_profile' => $lists,
        ];
    }

    private function state(string $state, array $extra = []): JsonResponse
    {
        return response()->json(['state' => $state, ...$extra])->header('Cache-Control', 'no-store, private');
    }
}
