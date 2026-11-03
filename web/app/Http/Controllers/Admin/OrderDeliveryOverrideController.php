<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\DeliveryHandoverException;
use App\Http\Controllers\Controller;
use App\Models\Admin\Admin;
use App\Models\SellerOrder;
use App\Services\OrderDeliveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The only administrator path into DELIVERED.
 *
 * Normal handover is the courier's: code or signed pass, plus a proof photo.
 * This exists for the cases the buyer cannot produce a pass (lost phone,
 * unreachable buyer at the door) and is deliberately narrow: an admin-role
 * account, a written reason, a proof photo, and an audit row. It never serves
 * a courier or buyer token.
 */
class OrderDeliveryOverrideController extends Controller
{
    public function store(
        Request $request,
        SellerOrder $sellerOrder,
        OrderDeliveryService $delivery,
    ): JsonResponse {
        $admin = Auth::guard('admin')->user();

        abort_unless($admin instanceof Admin, 403);
        abort_unless($admin->role === 'admin', 403, 'Only an administrator may override a delivery.');

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            'proof_photo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        try {
            $job = $delivery->adminOverride(
                $sellerOrder,
                $admin,
                $data['reason'],
                $request->file('proof_photo'),
                $request->ip(),
            );
        } catch (DeliveryHandoverException $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], $e->statusCode);
        }

        return response()->json([
            'ok' => true,
            'milestone' => $job->status->value,
            'delivered_at' => $job->delivered_at?->toIso8601String(),
        ]);
    }
}
