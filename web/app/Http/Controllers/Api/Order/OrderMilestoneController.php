<?php

namespace App\Http\Controllers\Api\Order;

use App\Http\Controllers\Controller;
use App\Models\Buyer\Buyer;
use App\Models\SellerOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/orders/{sellerOrder}/milestone
 *
 * The current pipeline status of one of the buyer's parcels.
 *
 * ## Why this exists separately from the order history
 *
 * `GET /api/orders` returns the full order history, including status. But
 * the buyer's tracker screen wants to *poll* a single parcel's status while
 * the screen is open — it should not re-fetch the entire history on each
 * tick. A single-field endpoint is lightweight enough to poll every 30 s.
 *
 * ## Scoping
 *
 * The seller order must belong to the signed-in buyer. A token cannot read
 * another buyer's parcel status.
 *
 * ## What it returns
 *
 *   { "milestone": "OUT_FOR_DELIVERY" }
 *
 * One string: the server's own status value. The app's 13→4 mapping lives
 * in `remote_status.dart` on the mobile side; this endpoint sends the raw
 * value so a future app that needs more detail can use it.
 */
class OrderMilestoneController extends Controller
{
    public function show(Request $request, SellerOrder $sellerOrder): JsonResponse
    {
        $buyer = $request->user();

        abort_unless(
            $buyer instanceof Buyer,
            403,
            'This token belongs to a different kind of account.',
        );

        // Ownership: the parent order must belong to this buyer.
        abort_unless(
            $sellerOrder->order?->buyer_id === $buyer->id,
            403,
            'This order does not belong to your account.',
        );

        return response()->json([
            'milestone' => $sellerOrder->status instanceof \App\Enums\OrderStatus
                ? $sellerOrder->status->value
                : (string) $sellerOrder->status,
        ]);
    }
}
