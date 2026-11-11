<?php

namespace App\Notifications\Incident;

use App\Models\Logistics\ParcelLossCase;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Route;

/**
 * Admin confirmed a parcel lost. One notice per audience, each saying only
 * what that person needs: the Buyer was not charged, the Seller is owed the
 * merchandise value, the Logistics provider is told what it owes.
 */
class ParcelLostConfirmed extends Notification
{
    public const BUYER = 'buyer';

    public const SELLER = 'seller';

    public const LOGISTICS = 'logistics';

    public function __construct(
        public ParcelLossCase $case,
        public string $audience,
        public string $tracking,
        public ?int $orderId = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $amount = '₱'.number_format($this->case->merchandise_minor / 100, 2);
        $review = $this->case->compensation_status === ParcelLossCase::REVIEW_REQUIRED;

        [$title, $message, $url] = match ($this->audience) {
            self::BUYER => [
                'Your parcel was lost in transit',
                "Parcel {$this->tracking} was confirmed lost while moving between hubs. You paid nothing: this was cash on delivery and no cash was collected. The seller has been told.",
                $this->orderId !== null && Route::has('buyer.orders.show') ? route('buyer.orders.show', $this->orderId) : null,
            ],
            self::SELLER => [
                'A parcel of yours was confirmed lost',
                $review
                    ? "Parcel {$this->tracking} was confirmed lost. Zefanya is working out which Logistics company is responsible. The merchandise value ({$amount}) will be owed to you once that is decided."
                    : "Parcel {$this->tracking} was confirmed lost. Its merchandise value ({$amount}) is owed to you by the responsible Logistics company. Shipping and commission are not included. You will be told when the payment is recorded.",
                Route::has('seller.earnings.index') ? route('seller.earnings.index').'#lost-parcels' : null,
            ],
            default => [
                'A parcel was confirmed lost',
                $review
                    ? "Admin confirmed parcel {$this->tracking} lost. Responsibility is under review; you may be asked for the {$amount} merchandise value."
                    : "Admin confirmed parcel {$this->tracking} lost while in your custody. You owe the seller {$amount}, its merchandise value. It is paid and recorded manually.",
                Route::has('logistics.lost-parcels.index') ? route('logistics.lost-parcels.index') : null,
            ],
        };

        return [
            'type' => 'parcel_lost',
            'title' => $title,
            'message' => $message,
            'url' => $url,
            'case_id' => $this->case->id,
            'seller_order_id' => $this->case->seller_order_id,
        ];
    }
}
