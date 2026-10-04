<?php

namespace App\Http\Resources;

use App\Models\OrderItem;
use App\Models\SellerOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * One **seller order**, in the shape the Flutter app's `Order` wants.
 *
 * ## Why a seller order, and not an order
 *
 * A marketplace order can hold goods from several shops, and its `seller_orders`
 * are the per-shop fulfilment units: exactly one seller ships a parcel, and the
 * buyer's history is a list of parcels. That is the split the app already models —
 * `OrderState.placeOrders` groups a cart by seller and the mock mints one order per
 * seller — which is why this resource is keyed on the seller order: one row here is
 * one row there, and the app needs no change of shape.
 *
 * ## The money is centavos, and the status is the server's own vocabulary
 *
 * `subtotal_minor` and each line's `price_minor` are centavos, like every amount
 * the app reads (`money.dart` owns the only conversion). The status is sent as the
 * server's string (`PLACED`, `SORTED`, `OUT_FOR_DELIVERY`, …) rather than being
 * pre-mapped into the app's four display stages: thirteen is not four, and the
 * mapping belongs on the side that *chose* to simplify. Pre-chewing it here would
 * throw away the fact that a parcel is `SORTED` rather than merely in transit, which
 * is the detail a tracker will want the day it starts showing real progress.
 *
 * ## `product_ids` is why this endpoint is worth having
 *
 * The app's joins — "stop showing me what I already bought", the wishlist's IN CART
 * tag, the recently-viewed filter — key on product ids, and an order history that
 * cannot produce them leaves every one of those surfaces unable to reference a
 * purchase. A line whose product row has since been removed keeps its snapshot
 * (`product_name`, `price_minor`) and simply contributes no id, which is the same
 * "contents unknown" rule the app already documents for old orders.
 *
 * @mixin SellerOrder
 */
class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Collection<int, OrderItem> $items */
        $items = $this->orderItems;

        return [
            // A string, for the same reason the catalogue's ids are: the app keys
            // its order list, its tracker and its review joins on string ids that
            // came from the mock ('ORD-10246'), so a live id has to arrive in the
            // same type or every set-membership test needs to know the mode.
            'id' => (string) $this->id,
            // The human-facing number a person reads back to support ("ORD-" +
            // date + six characters). Carried because the app's confirmation and
            // history both print it, and re-deriving it is not possible from an id.
            'reference' => (string) ($this->order?->reference ?? ''),
            // The seller order's own status — it is the parcel's state that moves
            // through the pipeline, not the checkout's.
            'status' => $this->status->value,
            'shop' => $this->seller?->business_name ?? '',
            'subtotal_minor' => (int) $this->subtotal_minor,
            'items' => $items
                ->map(fn (OrderItem $item): array => [
                    'name' => $item->product_name,
                    'quantity' => (int) $item->quantity,
                    'price_minor' => (int) $item->price_minor,
                    // Null for a line whose product was removed: the line survives
                    // as an audit record, and the app renders the snapshot.
                    'product_id' => $item->product_id === null ? null : (string) $item->product_id,
                ])
                ->values()
                ->all(),
            // Only the ids that still resolve, in line order. The app's `productIds`
            // means "the catalogue items this order contains"; a removed product is
            // no longer one of them, and a null in that list would be an id that
            // matches nothing anywhere.
            'product_ids' => $items
                ->pluck('product_id')
                ->filter(fn (?int $id): bool => $id !== null)
                ->map(fn (int $id): string => (string) $id)
                ->values()
                ->all(),
        ];
    }
}
