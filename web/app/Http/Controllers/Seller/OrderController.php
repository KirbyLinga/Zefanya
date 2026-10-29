<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\SellerOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function confirm(Request $request, SellerOrder $sellerOrder): JsonResponse
    {
        $seller = auth('seller')->user();
        abort_unless(
            $seller !== null && (int) $sellerOrder->seller_id === (int) $seller->id,
            403,
            'This order belongs to another seller.',
        );

        $sellerOrder->confirm();

        return response()->json([
            'ok' => true,
            'status' => $sellerOrder->status->value,
        ]);
    }

    public function ready(Request $request, SellerOrder $sellerOrder): JsonResponse
    {
        $seller = auth('seller')->user();
        abort_unless(
            $seller !== null && (int) $sellerOrder->seller_id === (int) $seller->id,
            403,
            'This order belongs to another seller.',
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
