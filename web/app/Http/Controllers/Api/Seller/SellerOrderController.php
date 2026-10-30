<?php

namespace App\Http\Controllers\Api\Seller;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\SellerOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/seller/orders
 * PATCH /api/v1/seller/orders/{sellerOrder}/confirm
 * PATCH /api/v1/seller/orders/{sellerOrder}/ready
 *
 * Minimal seller order handling for the mobile courier/marketplace:
 * the signed-in seller lists their own orders, confirms a placed order,
 * and marks it ready for pickup with a tracking number.
 */
class SellerOrderController extends Controller
{
    /** List the seller's own orders. */
    public function index(Request $request): JsonResponse
    {
        $seller = auth('seller')->user();
        abort_unless($seller !== null, 403, 'Seller authentication required.');

        $orders = SellerOrder::where('seller_id', $seller->id)
            ->with(['order', 'orderItems', 'payment'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'orders' => $orders,
        ]);
    }

    /** Confirm a placed order (move PLACED -> CONFIRMED). */
    public function confirm(Request $request, SellerOrder $sellerOrder): JsonResponse
    {
        $seller = auth('seller')->user();
        abort_unless(
            $seller !== null && (int) $sellerOrder->seller_id === (int) $seller->id,
            403,
            'This order belongs to another seller.',
        );

        abort_unless(
            $sellerOrder->status === OrderStatus::Placed,
            422,
            'Only a placed order can be confirmed.',
        );

        $sellerOrder->confirm();

        return response()->json([
            'ok' => true,
            'status' => $sellerOrder->status->value,
        ]);
    }

    /** Mark the order ready for pickup with a tracking number. */
    public function ready(Request $request, SellerOrder $sellerOrder): JsonResponse
    {
        $seller = auth('seller')->user();
        abort_unless(
            $seller !== null && (int) $sellerOrder->seller_id === (int) $seller->id,
            403,
            'This order belongs to another seller.',
        );

        abort_unless(
            $sellerOrder->status === OrderStatus::Confirmed,
            422,
            'Only a confirmed order can be marked ready for pickup.',
        );

        $tracking = $request->input('tracking_number');
        $sellerOrder->markReadyForPickup($tracking);

        return response()->json([
            'ok' => true,
            'status' => $sellerOrder->status->value,
            'tracking_number' => $sellerOrder->tracking_number,
        ]);
    }
}