<?php

namespace App\Notifications\Admin;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Route;

/** A transfer stayed overdue long enough that Admin is told. It changes nothing by itself. */
class TransferEscalated extends Notification
{
    public function __construct(public string $title, public string $message) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject($this->title)->greeting('A transfer needs attention')->line($this->message)
            ->line('Overdue does not mean lost. A parcel is only closed when you confirm it lost.');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'transfer_escalated',
            'title' => $this->title,
            'message' => $this->message,
            'url' => Route::has('admin.transport.index') ? route('admin.transport.index') : null,
        ];
    }
}
