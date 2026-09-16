<?php

namespace App\Console\Commands;

use App\Enums\NotificationType;
use App\Models\ConsultationSession;
use App\Models\ScheduleSlot;
use App\Services\NotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MarkMissedScheduleSlots extends Command
{
    protected $signature = 'consultations:mark-missed-slots';

    protected $description = 'Mark scheduled consultation slots as missed once slot end time has passed without consultation start.';

    public function handle(): int
    {
        $candidateSessionIds = ConsultationSession::query()
            ->where('consultation_status', 'scheduled')
            ->whereNotNull('slot_id')
            ->whereHas('request', function ($query) {
                $query->where('request_status', 'scheduled');
            })
            ->whereHas('slot', function ($query) {
                $query->where('status', 'booked');
            })
            ->pluck('id');

        if ($candidateSessionIds->isEmpty()) {
            $this->info('No scheduled booked slots to evaluate.');

            return self::SUCCESS;
        }

        $markedAsMissedCount = 0;
        // Notifications are sent after the loop, outside every lock — matches
        // syncMissedSlotsForPhysician, and means the two mechanisms notify
        // identically regardless of which one actually marks the slot missed.
        $toNotify = [];

        foreach ($candidateSessionIds as $sessionId) {
            DB::transaction(function () use ($sessionId, &$markedAsMissedCount, &$toNotify) {
                $session = ConsultationSession::query()
                    ->whereKey($sessionId)
                    ->lockForUpdate()
                    ->first();

                if (! $session || $session->consultation_status !== 'scheduled' || ! $session->slot_id) {
                    return;
                }

                $request = $session->request()->lockForUpdate()->first();
                if (! $request || $request->request_status !== 'scheduled') {
                    return;
                }

                $slot = ScheduleSlot::query()
                    ->where('slot_id', $session->slot_id)
                    ->lockForUpdate()
                    ->first();

                if (! $slot || $slot->status !== 'booked') {
                    return;
                }

                $slotDate = $slot->slot_date?->format('Y-m-d') ?? (string) $slot->slot_date;
                $slotEndsAt = CarbonImmutable::parse($slotDate.' '.$slot->end_time);

                if (CarbonImmutable::now()->lessThanOrEqualTo($slotEndsAt)) {
                    return;
                }

                $slot->update([
                    'status' => 'missed',
                ]);

                $markedAsMissedCount++;

                $toNotify[] = [
                    'patient_id' => $request->patient_id,
                    'physician_id' => $session->physician_id,
                    'request_id' => $request->request_id,
                    'slot_id' => $slot->slot_id,
                ];
            });
        }

        foreach ($toNotify as $entry) {
            if ($entry['patient_id']) {
                NotificationService::sendUnique(
                    $entry['patient_id'],
                    NotificationType::CONSULTATION_MISSED,
                    'Consultation Missed',
                    'Your scheduled consultation was missed. Please contact the infirmary to reschedule.',
                    [
                        'consultation_id' => $entry['request_id'],
                        'request_id' => $entry['request_id'],
                        'schedule_slot_id' => $entry['slot_id'],
                    ]
                );
            }

            if ($entry['physician_id']) {
                NotificationService::sendUnique(
                    $entry['physician_id'],
                    NotificationType::CONSULTATION_MISSED,
                    'Consultation Missed',
                    'A scheduled consultation slot was missed. Please reschedule the consultation.',
                    [
                        'consultation_id' => $entry['request_id'],
                        'request_id' => $entry['request_id'],
                        'schedule_slot_id' => $entry['slot_id'],
                    ]
                );
            }
        }

        $this->info('Marked '.$markedAsMissedCount.' slot(s) as missed.');

        return self::SUCCESS;
    }
}
