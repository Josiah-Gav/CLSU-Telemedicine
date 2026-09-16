<?php

namespace App\Console\Commands;

use App\Models\Consultation;
use App\Models\ConsultationSession;
use App\Models\ScheduleSlot;
use App\Notifications\ConsultationReminder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Emails the patient once their booked consultation falls inside a 24-hour
 * reminder window. Follows the same lock-then-recheck shape as
 * MarkMissedScheduleSlots, because this spans the same three tables
 * (session, request, slot) and needs the same protection against a slot
 * changing state between being listed as a candidate and being written.
 *
 * De-duplication relies on schedule_slots.reminder_sent_at rather than
 * NotificationService::sendUnique(), because an emailed reminder — unlike an
 * in-app notification — leaves no row in the notifications table to check
 * against. The flag is claimed (set) inside the same transaction that
 * re-verifies the slot is still eligible, then the email is sent after the
 * transaction commits, mirroring StaffAccountInvitation's rule that mail is
 * never sent from inside the transaction that guards its precondition. A
 * transport failure after the flag is claimed is logged and not retried —
 * an acceptable trade-off for a best-effort reminder, unlike the invitation
 * flow this pattern is borrowed from.
 */
class SendConsultationReminders extends Command
{
    protected $signature = 'consultations:send-reminders';

    protected $description = 'Email patients a reminder once their scheduled consultation is within 24 hours.';

    public function handle(): int
    {
        $windowEnd = CarbonImmutable::now()->addHours(24);

        $candidateSessionIds = ConsultationSession::query()
            ->where('consultation_status', 'scheduled')
            ->whereNotNull('slot_id')
            ->whereHas('request', function ($query) {
                $query->where('request_status', 'scheduled');
            })
            ->whereHas('slot', function ($query) {
                $query->where('status', 'booked')->whereNull('reminder_sent_at');
            })
            ->pluck('id');

        if ($candidateSessionIds->isEmpty()) {
            $this->info('No upcoming consultations need a reminder.');

            return self::SUCCESS;
        }

        $toEmail = [];

        foreach ($candidateSessionIds as $sessionId) {
            DB::transaction(function () use ($sessionId, $windowEnd, &$toEmail) {
                $session = ConsultationSession::query()
                    ->whereKey($sessionId)
                    ->lockForUpdate()
                    ->first();

                if (! $session || $session->consultation_status !== 'scheduled' || ! $session->slot_id) {
                    return;
                }

                $request = $session->request()->lockForUpdate()->first();
                if (! $request || $request->request_status !== 'scheduled' || ! $request->patient_id) {
                    return;
                }

                $slot = ScheduleSlot::query()
                    ->where('slot_id', $session->slot_id)
                    ->lockForUpdate()
                    ->first();

                if (! $slot || $slot->status !== 'booked' || $slot->reminder_sent_at !== null) {
                    return;
                }

                $slotDate = $slot->slot_date?->format('Y-m-d') ?? (string) $slot->slot_date;
                $slotStartsAt = CarbonImmutable::parse($slotDate.' '.$slot->start_time);

                // Not yet inside the 24-hour window, or the slot has already
                // started — the latter is left for MarkMissedScheduleSlots to
                // resolve instead of emailing a reminder for a missed window.
                if ($slotStartsAt->greaterThan($windowEnd) || $slotStartsAt->lessThanOrEqualTo(CarbonImmutable::now())) {
                    return;
                }

                $slot->update(['reminder_sent_at' => now()]);

                $toEmail[] = [
                    'patient_id' => $request->patient_id,
                    'request_id' => $request->request_id,
                    'slot_id' => $slot->slot_id,
                ];
            });
        }

        $sentCount = 0;

        foreach ($toEmail as $entry) {
            $request = Consultation::with('patient')->find($entry['request_id']);
            $slot = ScheduleSlot::find($entry['slot_id']);

            if (! $request || ! $request->patient || ! $slot) {
                continue;
            }

            try {
                $request->patient->notify(new ConsultationReminder($request, $slot));
                $sentCount++;
            } catch (Throwable $exception) {
                Log::error('Consultation reminder email could not be sent.', [
                    'request_id' => $entry['request_id'],
                    'exception' => $exception::class,
                ]);
            }
        }

        $this->info('Sent '.$sentCount.' consultation reminder(s).');

        return self::SUCCESS;
    }
}
