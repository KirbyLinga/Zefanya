<?php

namespace App\Notifications\Logistics;

use App\Models\Logistics\LogisticsRemittance;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LogisticsPaymentDecided extends Notification
{
    public function __construct(public LogisticsRemittance $remittance) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'logistics_payment_decided',
            'title' => $this->title(),
            'message' => $this->message(),
            'url' => route('logistics.earnings.index'),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title())
            ->line($this->message())
            ->action('View earnings', route('logistics.earnings.index'));
    }

    private function title(): string
    {
        return $this->remittance->status === LogisticsRemittance::CONFIRMED
            ? 'Zefanya confirmed your payment'
            : 'Zefanya rejected your payment';
    }

    private function message(): string
    {
        $payment = '₱'.number_format($this->remittance->amount_minor / 100, 2).' (ref '.$this->remittance->reference.')';

        return $this->remittance->status === LogisticsRemittance::CONFIRMED
            ? "Your payment of {$payment} has arrived."
            : "Your payment of {$payment} was rejected: {$this->remittance->rejection_reason}";
    }
}
