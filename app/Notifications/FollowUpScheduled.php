<?php

namespace App\Notifications;

use App\Models\Consultation;
use App\Models\ScheduleSlot;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emailed to the patient when a follow-up consultation is booked onto a
 * schedule slot — whether approved from a patient-submitted follow-up
 * request (ConsultationOwnershipService::decideFollowUpByPhysician) or
 * created directly by the physician (PhysicianController::
 * createPhysicianFollowUp). Only the scheduled-mode branch of either path
 * sends this; an immediate follow-up starts right away and has no slot to
 * report.
 */
class FollowUpScheduled extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Consultation $followUpConsultation,
        private readonly ScheduleSlot $slot,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $when = $this->formattedWhen();

        return (new MailMessage)
            ->subject('Your Telemedicine Follow-Up Has Been Scheduled')
            ->greeting('Hello '.$this->followUpConsultation->patient?->first_name.',')
            ->line("Your telemedicine follow-up consultation is scheduled for {$when}.")
            ->line('Please be ready to join a few minutes early.')
            ->action('View My Consultation', route('consultations.show', $this->followUpConsultation->request_id));
    }

    private function formattedWhen(): string
    {
        $date = optional($this->slot->slot_date)->format('F j, Y') ?? (string) $this->slot->slot_date;
        $time = Carbon::createFromFormat('H:i:s', $this->slot->start_time)->format('g:i A');

        return "{$date} at {$time}";
    }
}
