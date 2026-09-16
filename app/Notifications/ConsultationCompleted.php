<?php

namespace App\Notifications;

use App\Models\Consultation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emailed to the patient once their physician closes out the consultation.
 * Deliberately carries no clinical detail — the message points them back
 * into the app to read the assessment, plan, and any prescription.
 */
class ConsultationCompleted extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Consultation $consultation,
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
        return (new MailMessage)
            ->subject('Your Telemedicine Consultation Is Complete')
            ->greeting('Hello '.$this->consultation->patient?->first_name.',')
            ->line('Your telemedicine consultation has been completed.')
            ->line('You can view the summary and, if one was issued, your prescription in your consultation history.')
            ->action('View Consultation History', route('consultations.show', $this->consultation->request_id));
    }
}
