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
 * Emailed to the patient when a physician books (or re-books) a schedule
 * slot for their consultation. Deliberately queued, unlike
 * StaffAccountInvitation: there is no plaintext secret in the payload, so
 * serializing it into the 'database' queue's jobs table carries none of that
 * notification's risk.
 */
class ConsultationScheduled extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Consultation $consultation,
        private readonly ScheduleSlot $slot,
        private readonly bool $rescheduled = false,
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

        $subject = $this->rescheduled
            ? 'Your Telemedicine Consultation Has Been Rescheduled'
            : 'Your Telemedicine Consultation Has Been Scheduled';

        $line = $this->rescheduled
            ? "Your telemedicine consultation has been rescheduled to {$when}."
            : "Your telemedicine consultation is scheduled for {$when}.";

        return (new MailMessage)
            ->subject($subject)
            ->greeting('Hello '.$this->consultation->patient?->first_name.',')
            ->line($line)
            ->line('Please be ready to join a few minutes early.')
            ->action('View My Consultation', route('consultations.show', $this->consultation->request_id))
            ->line('If you did not expect this change, please contact the infirmary.');
    }

    private function formattedWhen(): string
    {
        $date = optional($this->slot->slot_date)->format('F j, Y') ?? (string) $this->slot->slot_date;
        $time = Carbon::createFromFormat('H:i:s', $this->slot->start_time)->format('g:i A');

        return "{$date} at {$time}";
    }
}
