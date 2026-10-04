<?php

namespace App\Http\Controllers\Api\Order;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Buyer\Buyer;
use App\Models\SellerOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The buyer's own order history: `GET /api/orders`.
 *
 * ## The first authenticated read on this API
 *
 * Everything else here either precedes a token (registration, sign-in) or is public
 * because it is a shop window (the catalogue). An order history is per-person by
 * definition, so it sits behind `auth:sanctum` — and it is the reason `/me` exists
 * beside it: a token that lists orders has to be checkable.
 *
 * ## The query starts at the join, so there is nothing to tamper with
 *
 * It begins at `seller_orders` because the unit the app lists is a parcel (see
 * `OrderResource`), and it is scoped through `orders.buyer_id` rather than through
 * an id from the request. That is deliberate: a request cannot name another buyer's
 * order, so there is no ownership check to forget, and a `404` is not even needed to
 * hide one.
 *
 * ## Newest first, by the checkout's own timestamp
 *
 * The parent order's `created_at`, not the seller order's. One checkout writes all
 * of its parcels in a single transaction, so ordering by the child's own timestamp
 * would reshuffle the parcels of one checkout against each other — technically
 * newer, and confusing to read.
 */
class OrderController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $buyer = $request->user();

        // `auth:sanctum` accepts any tokenable; only buyers can hold one today.
        // This is the guard for the day that stops being true.
        abort_unless(
            $buyer instanceof Buyer,
            403,
            'This token belongs to a different kind of account.',
        );

        $orders = SellerOrder::query()
            ->join('orders', 'orders.id', '=', 'seller_orders.order_id')
            ->where('orders.buyer_id', $buyer->id)
            ->orderByDesc('orders.created_at')
            ->orderBy('seller_orders.id')
            ->select('seller_orders.*')
            // Eager-loaded because the resource reads all three; without it a
            // history of N parcels would issue 3N queries.
            ->with(['seller', 'orderItems', 'order:id,reference'])
            ->get();

        return OrderResource::collection($orders);
    }
}
