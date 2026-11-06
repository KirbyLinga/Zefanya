<?php

namespace App\Notifications\Admin;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent immediately when a scheduled command exits non-zero. Not queued. */
class ScheduledTaskFailed extends Notification
{
    public function __construct(public string $task) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Zefanya scheduled task failed')
            ->line('A scheduled task failed: '.$this->task.'.')
            ->line('Buyer auto-receipt, return approval, and pickup-window rolling depend on the scheduler. Check the application log and failed_jobs, then run the command again.')
            ->line('The scheduler must be cron (`php artisan schedule:run` every minute). When QUEUE_CONNECTION=database, a worker (`php artisan queue:work --tries=3`) must be running as well.');
    }
}
