<?php

namespace App\Notifications\Seller;

use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use Illuminate\Notifications\Notification;

class LowStockAlert extends Notification
{
    public function __construct(
        public Product $product,
        public int $quantity,
        public int $inventoryTransactionId,
        public ?ProductVariant $variant = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $variantLabel = $this->variant?->label();
        $identifiesVariant = $variantLabel !== null && $variantLabel !== 'Default';

        return [
            'type' => 'low_stock',
            'title' => 'Low stock',
            'product_id' => $this->product->id,
            'variant_id' => $this->variant?->id,
            'variant_label' => $variantLabel,
            'quantity' => $this->quantity,
            'inventory_transaction_id' => $this->inventoryTransactionId,
            'message' => $this->product->name
                .($identifiesVariant ? ' ('.$variantLabel.')' : '')
                .' has '.$this->quantity.' units left.',
        ];
    }
}
