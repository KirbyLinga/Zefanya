<?php

namespace App\Notifications\Logistics;

use App\Models\Logistics\LogisticsHub;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LogisticsHubDecisionNotification extends Notification
{
    public function __construct(
        public LogisticsHub $hub,
        public string $decision,
        public ?string $reason = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'hub_decision',
            'title' => $this->decision === 'approved' ? 'Hub approved' : 'Hub application rejected',
            'message' => $this->hub->name.($this->reason ? ': '.$this->reason : ''),
            'hub_id' => $this->hub->id,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $hubTypeLabel = $this->hub->hub_type === LogisticsHub::PROVINCE ? 'Province Hub' : 'Station Hub';

        $message = (new MailMessage)
            ->subject($this->decision === 'approved'
                ? "Your {$hubTypeLabel} has been approved: {$this->hub->name}"
                : "Your {$hubTypeLabel} application was not approved: {$this->hub->name}");

        $recipientName = method_exists($notifiable, 'fullName') ? $notifiable->fullName() : ($notifiable->business_name ?? 'Logistics Partner');

        if ($this->decision === 'approved') {
            $nextStep = $this->hub->hub_type === LogisticsHub::STATION && ! $this->hub->is_active
                ? 'Activate it from Hubs and Stations to make it available for municipality coverage and rider assignments.'
                : 'This hub is now ready to participate in your delivery network operations.';

            return $message
                ->greeting("Hello {$recipientName},")
                ->line("Your application for {$hubTypeLabel} \"{$this->hub->name}\" has been approved.")
                ->line("Facility address: {$this->hub->fullAddress()}")
                ->line($nextStep)
                ->action('View Hubs and Stations', route('logistics.hubs.index'));
        }

        return $message
            ->greeting("Hello {$recipientName},")
            ->line("Your application for {$hubTypeLabel} \"{$this->hub->name}\" was rejected.")
            ->when($this->reason, fn ($msg) => $msg->line("Reason: {$this->reason}"))
            ->line('You may review your details and submit a corrected hub application.')
            ->action('View Hubs and Stations', route('logistics.hubs.index'));
    }
}
