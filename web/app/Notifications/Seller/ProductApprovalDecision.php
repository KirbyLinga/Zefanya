<?php

namespace App\Notifications\Seller;

use App\Models\Catalog\Product;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ProductApprovalDecision extends Notification
{
    public function __construct(
        public Product $product,
        public string $decision,
        public ?string $reason = null,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toDatabase(object $notifiable): array
    {
        $approved = $this->decision === 'approved';

        return [
            'type' => 'product_approval',
            'title' => $approved ? 'Product approved' : 'Product needs changes',
            'message' => $approved
                ? $this->product->name.' is approved and can be shown to buyers.'
                : $this->product->name.' was not approved.'.($this->reason ? ' Reason: '.$this->reason : ''),
            'product_id' => $this->product->id,
            'decision' => $this->decision,
            'reason' => $this->reason,
            'url' => route('seller.products.index'),
        ];
    }

    public function toArray(object $notifiable): array
    {
        return $this->toDatabase($notifiable);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject($this->decision === 'approved'
                ? 'Your product was approved'
                : 'Your product was not approved');

        if ($this->decision === 'approved') {
            return $message
                ->line('"'.$this->product->name.'" is approved.')
                ->line('Buyers can see it while it is published and meets the listing rules.')
                ->action('View your products', route('seller.products.index'));
        }

        return $message
            ->line('"'.$this->product->name.'" was not approved.')
            ->when(filled($this->reason), fn (MailMessage $mail) => $mail->line('Reason: '.$this->reason))
            ->line('Edit the product and submit it again.')
            ->action('Edit and resubmit', route('seller.products.index', ['edit' => $this->product->id]));
    }
}
