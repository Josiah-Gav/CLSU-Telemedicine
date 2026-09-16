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
 * Emailed to the patient once their upcoming consultation falls inside the
 * 24-hour reminder window (App\Console\Commands\SendConsultationReminders).
 * The copy deliberately avoids "tomorrow" — the window is "within the next
 * 24 hours", not a fixed T-minus-24:00:00 mark, so a slot picked up only a
 * few hours out still gets an accurate message.
 */
class ConsultationReminder extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Consultation $consultation,
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
            ->subject('Reminder: Your Telemedicine Consultation Is Coming Up')
            ->greeting('Hello '.$this->consultation->patient?->first_name.',')
            ->line("This is a reminder that your telemedicine consultation is scheduled for {$when}.")
            ->line('Please be ready to join a few minutes early.')
            ->action('View My Consultation', route('consultations.show', $this->consultation->request_id));
    }

    private function formattedWhen(): string
    {
        $date = optional($this->slot->slot_date)->format('F j, Y') ?? (string) $this->slot->slot_date;
        $time = Carbon::createFromFormat('H:i:s', $this->slot->start_time)->format('g:i A');

        return "{$date} at {$time}";
    }
}
