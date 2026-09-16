<?php

namespace App\Services;

use App\Models\Consultation;
use App\Models\ConsultationSession;
use App\Models\FollowUpRequest;
use App\Models\ScheduleSlot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ConsultationOwnershipService
{
    /**
     * Physician Takeover grace period, in minutes after a consultation's
     * scheduled slot start. A consultation scheduled for 2:00 PM whose
     * assigned physician has not started it becomes claimable at 2:10 PM.
     *
     * Deliberately a constant rather than config: this codebase already
     * expresses its one other scheduling window (the 15-minute early-start
     * allowance below) the same way, and a single value does not earn the
     * project's first config file.
     */
    public const TAKEOVER_GRACE_MINUTES = 10;

    public function claimByNurse(int $consultationRequestId, int $nurseId, string $priorityLevel): Consultation
    {
        return DB::transaction(function () use ($consultationRequestId, $nurseId, $priorityLevel) {
            $consultation = Consultation::query()
                ->where('request_id', $consultationRequestId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($consultation->request_status !== 'pending') {
                throw new \RuntimeException('Only pending consultations can be approved.');
            }

            if ($consultation->assigned_nurse_id && (int) $consultation->assigned_nurse_id !== $nurseId) {
                throw new \RuntimeException('This consultation is already being handled by another nurse.');
            }

            $consultation->update([
                'request_status' => 'reviewed',
                'assigned_nurse_id' => $nurseId,
                'priority_level' => $priorityLevel,
            ]);

            return $consultation->fresh();
        });
    }

    /**
     * $nurseId is recorded on the request, exactly as claimByNurse() records a
     * claim and rejectReviewedByPhysician() records the rejecting physician. A
     * rejection used to leave assigned_nurse_id null, so a rejected request had
     * no attributable reviewer at all. Role is still the caller's
     * responsibility — see this class's design note in CLAUDE.md.
     */
    public function rejectByNurse(int $consultationRequestId, int $nurseId, string $rejectionReason): Consultation
    {
        return DB::transaction(function () use ($consultationRequestId, $nurseId, $rejectionReason) {
            $consultation = Consultation::query()
                ->where('request_id', $consultationRequestId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($consultation->request_status !== 'pending') {
                throw new \RuntimeException('Only pending consultations can be rejected.');
            }

            // Same guard claimByNurse() applies: a request another nurse has
            // already taken is not this nurse's to conclude.
            if ($consultation->assigned_nurse_id && (int) $consultation->assigned_nurse_id !== $nurseId) {
                throw new \RuntimeException('This consultation is already being handled by another nurse.');
            }

            $consultation->update([
                'request_status' => 'rejected',
                'rejection_reason' => $rejectionReason,
                'assigned_nurse_id' => $nurseId,
            ]);

            return $consultation->fresh();
        });
    }

    public function cancelByPatient(int $consultationRequestId, int $patientId): Consultation
    {
        return DB::transaction(function () use ($consultationRequestId, $patientId) {
            $consultation = Consultation::query()
                ->where('request_id', $consultationRequestId)
                ->where('patient_id', $patientId)
                ->lockForUpdate()
                ->firstOrFail();

            if (!in_array($consultation->request_status, ['pending', 'reviewed'], true)) {
                throw new \RuntimeException('Only pending or reviewed consultations can be cancelled.');
            }

            $consultation->update([
                'request_status' => 'cancelled',
            ]);

            return $consultation->fresh();
        });
    }

    public function startByPhysician(int $consultationRequestId, int $physicianId): array
    {
        return DB::transaction(function () use ($consultationRequestId, $physicianId) {
            $consultation = Consultation::query()
                ->where('request_id', $consultationRequestId)
                ->lockForUpdate()
                ->firstOrFail();

            if (!in_array($consultation->request_status, ['reviewed', 'assigned', 'scheduled'], true)) {
                throw new \RuntimeException('Only reviewed, assigned, or scheduled consultations can be started.');
            }

            if ($consultation->assigned_physician_id && (int) $consultation->assigned_physician_id !== $physicianId) {
                throw new \RuntimeException('This consultation is already being handled by another physician.');
            }

            $shouldApplyScheduledGate = $consultation->request_status === 'scheduled';

            $session = ConsultationSession::query()
                ->where('request_id', $consultation->request_id)
                ->lockForUpdate()
                ->first();

            if (!$session) {
                $session = ConsultationSession::create([
                    'request_id' => $consultation->request_id,
                    'physician_id' => $physicianId,
                    'consultation_status' => $shouldApplyScheduledGate ? 'scheduled' : 'active',
                    'assessment' => 'Initial assessment pending.',
                    'plan' => 'Plan to be documented during consultation.',
                    'recommendations' => 'Recommendations to follow after evaluation.',
                    'assigned_at' => now(),
                    'started_at' => $shouldApplyScheduledGate ? null : now(),
                ]);

                $session = ConsultationSession::query()
                    ->whereKey($session->id)
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            if ($shouldApplyScheduledGate) {
                if (!$session->slot_id) {
                    throw new \RuntimeException('This consultation has no assigned schedule slot yet.');
                }

                $slot = ScheduleSlot::query()
                    ->where('slot_id', $session->slot_id)
                    ->lockForUpdate()
                    ->first();

                // The slot's physician_id is never rewritten by a takeover — it
                // records who the consultation was *scheduled* to. So the slot is
                // matched either by owning it outright (the ordinary case) or by
                // having claimed this consultation through Physician Takeover.
                $claimedByTakeover = (int) $session->taken_over_by_physician_id === $physicianId;
                $ownsSlot = $slot && (int) $slot->physician_id === $physicianId;

                if (!$slot || !($ownsSlot || $claimedByTakeover) || !in_array($slot->status, ['booked', 'missed'], true)) {
                    throw new \RuntimeException('The assigned schedule slot is not ready to start.');
                }

                // A missed slot is by definition already past its window, so the
                // physician can start immediately instead of being blocked by the
                // time-window checks below (which only make sense while booked).
                //
                // A taken-over slot is skipped for the same reason: takeOverByPhysician
                // only hands the consultation over once slot start + the grace period
                // has passed, so the "too early"/"window ended" guards — which exist to
                // hold the *scheduled* physician to their slot — no longer apply.
                if ($slot->status === 'booked' && !$claimedByTakeover) {
                    $slotDate = $slot->slot_date?->format('Y-m-d') ?? (string) $slot->slot_date;
                    $slotStart = CarbonImmutable::parse($slotDate . ' ' . $slot->start_time);
                    $slotEnd = CarbonImmutable::parse($slotDate . ' ' . $slot->end_time);
                    $canStartAt = $slotStart->subMinutes(15);
                    $now = CarbonImmutable::now();

                    if ($now->greaterThan($slotEnd)) {
                        throw new \RuntimeException('This schedule slot window has already ended. Please reschedule this consultation to an available slot.');
                    }

                    if ($now->lessThan($canStartAt)) {
                        throw new \RuntimeException('This consultation cannot be started yet.');
                    }
                }
            }

            $consultation->update([
                'request_status' => 'active',
                'assigned_physician_id' => $physicianId,
            ]);

            $session->update([
                'physician_id' => $physicianId,
                'consultation_status' => 'active',
                'started_at' => now(),
            ]);

            return [
                'consultation' => $consultation->fresh(),
                'session' => $session->fresh(),
            ];
        });
    }

    /**
     * The moment a scheduled consultation becomes claimable by another
     * physician: its slot's start time plus the grace period.
     *
     * Exposed publicly (and derived from nothing but the slot) so the
     * physician inbox can render the same boundary the transaction below
     * enforces — the two can never drift apart.
     */
    public static function takeoverEligibleAt(ScheduleSlot $slot): CarbonImmutable
    {
        $slotDate = $slot->slot_date?->format('Y-m-d') ?? (string) $slot->slot_date;

        return CarbonImmutable::parse($slotDate . ' ' . $slot->start_time)
            ->addMinutes(self::TAKEOVER_GRACE_MINUTES);
    }

    /**
     * Physician Takeover: another physician claims a scheduled consultation
     * whose assigned physician has not started it within the grace period.
     *
     * No status changes — the consultation stays 'scheduled' on both tables
     * and simply changes hands. What moves is the assignment (written to both
     * consultation_requests.assigned_physician_id and consultations.
     * physician_id in this one transaction, so they can never disagree) plus
     * the three takeover audit columns. The schedule slot is deliberately left
     * completely untouched: its physician_id keeps recording who the
     * consultation was originally scheduled to.
     *
     * Concurrency: the consultation_requests row is locked before the
     * assignment is read, so two physicians claiming simultaneously serialise
     * here. The loser re-reads after the winner commits, sees taken_over_at
     * already set, and is refused — V1 allows exactly one claim per
     * consultation, which is also what stops a second takeover overwriting
     * original_physician_id with the first claimant.
     */
    public function takeOverByPhysician(int $consultationRequestId, int $claimingPhysicianId): array
    {
        return DB::transaction(function () use ($consultationRequestId, $claimingPhysicianId) {
            $consultation = Consultation::query()
                ->where('request_id', $consultationRequestId)
                ->lockForUpdate()
                ->firstOrFail();

            // Excludes active, completed, cancelled and rejected in one check:
            // only a still-scheduled consultation can be taken over.
            if ($consultation->request_status !== 'scheduled') {
                throw new \RuntimeException('Only scheduled consultations that have not started can be taken over.');
            }

            $session = ConsultationSession::query()
                ->where('request_id', $consultation->request_id)
                ->lockForUpdate()
                ->first();

            if (!$session) {
                throw new \RuntimeException('This consultation has no session to take over.');
            }

            // Checked before the "is it already mine" test below so that both
            // sides of a concurrent claim get the same honest answer.
            if ($session->taken_over_at !== null) {
                throw new \RuntimeException('This consultation has already been claimed by another physician.');
            }

            $originalPhysicianId = (int) ($consultation->assigned_physician_id ?? 0);

            if ($originalPhysicianId <= 0) {
                throw new \RuntimeException('This consultation has no assigned physician to take over from.');
            }

            if ($originalPhysicianId === $claimingPhysicianId) {
                throw new \RuntimeException('This consultation is already assigned to you.');
            }

            if ($session->consultation_status !== 'scheduled' || $session->started_at !== null) {
                throw new \RuntimeException('This consultation has already been started and can no longer be taken over.');
            }

            if (!$session->slot_id) {
                throw new \RuntimeException('This consultation has no assigned schedule slot yet.');
            }

            $slot = ScheduleSlot::query()
                ->where('slot_id', $session->slot_id)
                ->lockForUpdate()
                ->first();

            if (!$slot || !in_array($slot->status, ['booked', 'missed'], true)) {
                throw new \RuntimeException('The scheduled slot for this consultation is no longer valid.');
            }

            $eligibleAt = self::takeoverEligibleAt($slot);

            if (CarbonImmutable::now()->lessThan($eligibleAt)) {
                throw new \RuntimeException(
                    'This consultation cannot be taken over until ' . $eligibleAt->format('M d, Y h:i A') . '.'
                );
            }

            $consultation->update([
                'assigned_physician_id' => $claimingPhysicianId,
            ]);

            $session->update([
                'physician_id' => $claimingPhysicianId,
                // Never overwritten on a later takeover — the guard above makes
                // a second claim impossible, and the ?? keeps that intent explicit.
                'original_physician_id' => $session->original_physician_id ?? $originalPhysicianId,
                'taken_over_by_physician_id' => $claimingPhysicianId,
                'taken_over_at' => now(),
            ]);

            return [
                'consultation' => $consultation->fresh(),
                'session' => $session->fresh(),
                'slot' => $slot->fresh(),
                'original_physician_id' => $originalPhysicianId,
            ];
        });
    }

    public function rejectReviewedByPhysician(int $consultationRequestId, int $physicianId, string $rejectionReason): Consultation
    {
        return DB::transaction(function () use ($consultationRequestId, $physicianId, $rejectionReason) {
            $consultation = Consultation::query()
                ->where('request_id', $consultationRequestId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($consultation->request_status !== 'reviewed') {
                throw new \RuntimeException('Only reviewed consultations can be rejected.');
            }

            if ($consultation->assigned_physician_id && (int) $consultation->assigned_physician_id !== $physicianId) {
                throw new \RuntimeException('This consultation is already being handled by another physician.');
            }

            $consultation->update([
                'request_status' => 'rejected',
                'rejection_reason' => $rejectionReason,
                'assigned_physician_id' => $physicianId,
            ]);

            return $consultation->fresh();
        });
    }

    public function scheduleByPhysician(int $consultationRequestId, int $physicianId, int $selectedSlotId): array
    {
        return DB::transaction(function () use ($consultationRequestId, $physicianId, $selectedSlotId) {
            $consultation = Consultation::query()
                ->where('request_id', $consultationRequestId)
                ->lockForUpdate()
                ->firstOrFail();

            if (!in_array($consultation->request_status, ['reviewed', 'assigned', 'scheduled'], true)) {
                throw new \RuntimeException('Only reviewed, assigned, or scheduled consultations can be scheduled.');
            }

            if ($consultation->assigned_physician_id && (int) $consultation->assigned_physician_id !== $physicianId) {
                throw new \RuntimeException('This consultation is already being handled by another physician.');
            }

            $slot = ScheduleSlot::query()
                ->where('slot_id', $selectedSlotId)
                ->where('physician_id', $physicianId)
                ->lockForUpdate()
                ->first();

            if (!$slot || $slot->status !== 'available') {
                throw new \RuntimeException('Selected slot is no longer available.');
            }

            if ($this->isScheduleSlotInPast($slot)) {
                throw new \RuntimeException('Selected slot is already in the past. Please choose a future slot.');
            }

            $session = ConsultationSession::query()
                ->where('request_id', $consultation->request_id)
                ->lockForUpdate()
                ->first();

            if (!$session) {
                $session = ConsultationSession::create([
                    'request_id' => $consultation->request_id,
                    'physician_id' => $physicianId,
                    'consultation_status' => 'scheduled',
                    'assessment' => 'Initial assessment pending.',
                    'plan' => 'Plan to be documented during consultation.',
                    'recommendations' => 'Recommendations to follow after evaluation.',
                    'assigned_at' => now(),
                ]);

                $session = ConsultationSession::query()
                    ->whereKey($session->id)
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            $previousSlotId = (int) ($session->slot_id ?? 0);

            if ($previousSlotId > 0 && $previousSlotId !== (int) $slot->slot_id) {
                $previousSlot = ScheduleSlot::query()
                    ->where('slot_id', $previousSlotId)
                    ->where('physician_id', $physicianId)
                    ->lockForUpdate()
                    ->first();

                if ($previousSlot && $previousSlot->status === 'booked') {
                    // Reset the reminder flag too: this slot row goes back into the
                    // pool and may be booked again for a different consultation,
                    // which needs its own reminder later.
                    $previousSlot->update(['status' => 'available', 'reminder_sent_at' => null]);
                }
            }

            $slot->update(['status' => 'booked']);

            $consultation->update([
                'request_status' => 'scheduled',
                'assigned_physician_id' => $physicianId,
            ]);

            $session->update([
                'physician_id' => $physicianId,
                'consultation_status' => 'scheduled',
                'slot_id' => $slot->slot_id,
            ]);

            return [
                'consultation' => $consultation->fresh(),
                'session' => $session->fresh(),
                'slot' => $slot->fresh(),
                'rescheduled' => $previousSlotId > 0,
            ];
        });
    }

    public function forwardFollowUpByNurse(int $followUpRequestId, int $nurseId, ?string $decisionNotes): FollowUpRequest
    {
        return DB::transaction(function () use ($followUpRequestId, $nurseId, $decisionNotes) {
            $followUpRequest = FollowUpRequest::query()
                ->whereKey($followUpRequestId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($followUpRequest->status !== 'pending') {
                throw new \RuntimeException('Only pending follow-up requests can be forwarded.');
            }

            $followUpRequest->update([
                'status' => 'forwarded',
                'reviewed_by_nurse_id' => $nurseId,
                'reviewed_at' => now(),
                'decision_notes' => $decisionNotes,
            ]);

            return $followUpRequest->fresh();
        });
    }

    public function rejectFollowUpByNurse(int $followUpRequestId, int $nurseId, string $decisionNotes): FollowUpRequest
    {
        return DB::transaction(function () use ($followUpRequestId, $nurseId, $decisionNotes) {
            $followUpRequest = FollowUpRequest::query()
                ->whereKey($followUpRequestId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($followUpRequest->status !== 'pending') {
                throw new \RuntimeException('Only pending follow-up requests can be rejected.');
            }

            $followUpRequest->update([
                'status' => 'rejected',
                'reviewed_by_nurse_id' => $nurseId,
                'reviewed_at' => now(),
                'decision_notes' => $decisionNotes,
            ]);

            return $followUpRequest->fresh();
        });
    }

    public function cancelFollowUpByPatient(int $followUpRequestId, int $patientId): FollowUpRequest
    {
        return DB::transaction(function () use ($followUpRequestId, $patientId) {
            $followUpRequest = FollowUpRequest::query()
                ->whereKey($followUpRequestId)
                ->where('patient_id', $patientId)
                ->lockForUpdate()
                ->firstOrFail();

            if (!in_array($followUpRequest->status, ['pending', 'forwarded'], true)) {
                throw new \RuntimeException('Only pending or forwarded follow-up requests can be cancelled.');
            }

            $followUpRequest->update([
                'status' => 'cancelled',
                'decision_notes' => $followUpRequest->decision_notes ?? 'Cancelled by patient.',
            ]);

            return $followUpRequest->fresh();
        });
    }

    public function decideFollowUpByPhysician(
        int $followUpRequestId,
        int $physicianId,
        string $decision,
        ?string $mode,
        ?int $slotId,
        ?string $decisionNotes
    ): FollowUpRequest {
        return DB::transaction(function () use ($followUpRequestId, $physicianId, $decision, $mode, $slotId, $decisionNotes) {
            $followUpRequest = FollowUpRequest::query()
                ->whereKey($followUpRequestId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($followUpRequest->status !== 'forwarded') {
                throw new \RuntimeException('Only forwarded follow-up requests can be decided.');
            }

            if ($decision === 'rejected') {
                $followUpRequest->update([
                    'status' => 'rejected',
                    'decided_by_physician_id' => $physicianId,
                    'decided_at' => now(),
                    'decision_notes' => $decisionNotes,
                ]);

                return $followUpRequest->fresh();
            }

            if (!in_array((string) $mode, ['immediate', 'scheduled'], true)) {
                throw new \RuntimeException('Invalid follow-up approval mode.');
            }

            $sourceSession = ConsultationSession::query()
                ->whereKey($followUpRequest->consultation_id)
                ->lockForUpdate()
                ->first();

            if (!$sourceSession) {
                throw new \RuntimeException('Source consultation was not found.');
            }

            $sourceSession->loadMissing('request');

            if (!$sourceSession->request) {
                throw new \RuntimeException('Source consultation request was not found.');
            }

            $existingActiveFollowUp = Consultation::query()
                ->where('type', 'follow_up')
                ->where('parent_consultation_id', $sourceSession->id)
                ->whereIn('request_status', ['pending', 'scheduled', 'active'])
                ->lockForUpdate()
                ->exists();

            if ($existingActiveFollowUp) {
                throw new \RuntimeException('An active follow-up consultation already exists for this consultation.');
            }

            $newRequestStatus = $mode === 'immediate' ? 'active' : 'scheduled';

            $newConsultation = Consultation::create([
                'patient_id' => $sourceSession->request->patient_id,
                'assigned_physician_id' => $physicianId,
                'assigned_nurse_id' => $sourceSession->request->assigned_nurse_id,
                'type' => 'follow_up',
                'parent_consultation_id' => $sourceSession->id,
                'concern_category' => $sourceSession->request->concern_category,
                'symptoms_desc' => $sourceSession->request->symptoms_desc,
                'online_reason' => $sourceSession->request->online_reason,
                'request_status' => $newRequestStatus,
                'priority_level' => $sourceSession->request->priority_level,
                'file_attachments' => $sourceSession->request->file_attachments,
            ]);

            $newSessionData = [
                'request_id' => $newConsultation->request_id,
                'physician_id' => $physicianId,
                'follow_up_request_id' => $followUpRequest->id,
                'consultation_status' => $mode === 'immediate' ? 'active' : 'scheduled',
                'assessment' => 'Initial assessment pending.',
                'plan' => 'Plan to be documented during consultation.',
                'recommendations' => 'Recommendations to follow after evaluation.',
                'assigned_at' => now(),
                'started_at' => $mode === 'immediate' ? now() : null,
            ];

            if ($mode === 'scheduled') {
                if (!$slotId) {
                    throw new \RuntimeException('A schedule slot is required when approving a scheduled follow-up.');
                }

                $slot = ScheduleSlot::query()
                    ->where('slot_id', $slotId)
                    ->where('physician_id', $physicianId)
                    ->lockForUpdate()
                    ->first();

                if (!$slot || $slot->status !== 'available') {
                    throw new \RuntimeException('Selected slot is no longer available.');
                }

                if ($this->isScheduleSlotInPast($slot)) {
                    throw new \RuntimeException('Selected slot is already in the past. Please choose a future slot.');
                }

                $slot->update(['status' => 'booked']);
                $newSessionData['slot_id'] = $slot->slot_id;
            }

            ConsultationSession::create($newSessionData);

            $followUpRequest->update([
                'status' => 'approved',
                'decided_by_physician_id' => $physicianId,
                'decided_at' => now(),
                'decision_notes' => $decisionNotes,
            ]);

            return $followUpRequest->fresh();
        });
    }

    private function isScheduleSlotInPast(ScheduleSlot $slot): bool
    {
        $slotDate = $slot->slot_date?->format('Y-m-d') ?? (string) $slot->slot_date;
        $slotStart = CarbonImmutable::parse($slotDate . ' ' . $slot->start_time);

        return CarbonImmutable::now()->greaterThanOrEqualTo($slotStart);
    }
}
