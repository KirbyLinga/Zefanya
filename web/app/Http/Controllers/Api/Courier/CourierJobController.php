<?php

namespace App\Http\Controllers\Api\Courier;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\DeliveryJobResource;
use App\Models\Logistics\LogisticsProvider;
use App\Models\SellerOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * GET /api/courier/jobs
 *
 * The parcels assigned to the signed-in courier that are still in the
 * active pipeline — i.e. not yet delivered, completed, or returned.
 *
 * ## Scoping
 *
 * The query begins at `seller_orders.assigned_courier_id = token holder's id`,
 * so a token cannot list another courier's jobs. There is no ownership check
 * to forget: the filter IS the ownership.
 *
 * ## What counts as "active"
 *
 * Statuses that are finished (`DELIVERED`, `COMPLETED`, `RETURNED`) are
 * excluded. A courier's job list is "what I am currently carrying or have
 * been asked to carry", not a full history. The completed history lives in
 * `CourierState`'s device-local ledger for now; a separate endpoint would
 * serve it when needed.
 *
 * ## Why the app keeps milestone state locally
 *
 * The milestone machine (`accepted` → `pickedUp` → `delivered`) is still
 * simulated on the device (`CourierState`). This endpoint gives the courier
 * their jobs; the milestone updates will follow as a separate endpoint when
 * the ERP's dispatcher workflow is built. For now, the job list is the one
 * thing the app cannot produce itself: a dispatcher assigns parcels, and the
 * courier's phone has no way to know about them without asking the server.
 */
class CourierJobController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $courier = $request->user();

        abort_unless(
            $courier instanceof LogisticsProvider,
            403,
            'This token belongs to a different kind of account.',
        );

        // Statuses that are still in flight — the courier has work to do.
        $activeStatuses = [
            OrderStatus::Placed->value,
            OrderStatus::Confirmed->value,
            OrderStatus::Preparing->value,
            OrderStatus::ReadyForPickup->value,
            OrderStatus::PickedUp->value,
            OrderStatus::AtSortingCenter->value,
            OrderStatus::Sorted->value,
            OrderStatus::AssignedToRider->value,
            OrderStatus::OutForDelivery->value,
            OrderStatus::DeliveryFailed->value,
        ];

        $jobs = SellerOrder::query()
            ->where('assigned_courier_id', $courier->id)
            ->whereIn('status', $activeStatuses)
            ->with(['seller', 'order', 'orderItems', 'payment'])
            ->orderBy('id')
            ->get();

        return DeliveryJobResource::collection($jobs);
    }
}
