<?php

namespace App\Notifications\Admin;

use App\Models\Catalog\Product;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ProductSubmitted extends Notification
{
    public function __construct(public Product $product) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'product_submitted',
            'title' => 'Product awaiting approval',
            'message' => $this->product->name.' was submitted by '.($this->product->seller?->business_name ?? 'a seller').'.',
            'product_id' => $this->product->id,
            'url' => route('admin.products.pending'),
        ];
    }

    public function toArray(object $notifiable): array
    {
        return $this->toDatabase($notifiable);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Product awaiting approval — '.$this->product->name)
            ->line(($this->product->seller?->business_name ?? 'A seller').' submitted "'.$this->product->name.'" for approval.')
            ->action('Review pending products', route('admin.products.pending'));
    }
}
