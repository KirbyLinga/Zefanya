<?php

namespace App\Notifications\Logistics;

use App\Models\Shared\SellerOrder;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Operational update sent to the Logistics company when one of its riders
 * performs a parcel action (pickup, delivery, failed attempt, exception).
 *
 * Tracking and buyer/seller messaging stay in SellerOrderStatusService — this
 * notification only keeps the dispatcher informed of rider activity.
 */
class RiderParcelActivity extends Notification
{
    public const PICKUP = 'pickup';

    public const OUT_FOR_DELIVERY = 'out_for_delivery';

    public const DELIVERED = 'delivered';

    public const DELIVERY_FAILED = 'delivery_failed';

    public const PICKUP_FAILURE = 'pickup_failure';

    public function __construct(
        public SellerOrder $sellerOrder,
        public string $event,
        public ?string $detail = null,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'rider_parcel_activity',
            'title' => $this->label(),
            'message' => ($this->sellerOrder->tracking_number ?? "Parcel #{$this->sellerOrder->id}").': '.($this->sellerOrder->status?->label() ?? 'unknown').($this->detail ? '. '.$this->detail : ''),
            'seller_order_id' => $this->sellerOrder->id,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $parcel = $this->sellerOrder;
        $tracking = $parcel->tracking_number ?? "parcel #{$parcel->id}";

        $mail = (new MailMessage)
            ->subject('Rider update — '.$this->label().' ('.$tracking.')')
            ->greeting('Rider parcel update')
            ->line("{$this->label()} for {$tracking}.")
            ->line('Status: '.($parcel->status?->label() ?? 'unknown'));

        if ($this->detail !== null && $this->detail !== '') {
            $mail->line($this->detail);
        }

        return $mail->line('Open the Rider and Delivery Monitoring screens to review the assignment.');
    }

    private function label(): string
    {
        return match ($this->event) {
            self::PICKUP => 'Parcel collected from seller',
            self::OUT_FOR_DELIVERY => 'Delivery started',
            self::DELIVERED => 'Parcel delivered',
            self::DELIVERY_FAILED => 'Delivery attempt failed',
            self::PICKUP_FAILURE => 'Pickup failed',
            default => 'Rider parcel activity',
        };
    }
}
