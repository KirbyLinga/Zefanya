<?php

namespace App\Http\Controllers\Seller;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Shared\SellerOrder;
use App\Services\Logistics\WaybillQrCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Seller shipments: the parcels this shop is responsible for moving out, plus
 * a printable waybill/label.
 *
 * The tracking number is assigned when the seller_order is created (see
 * CheckoutController::placeOrder), so a label can be printed BEFORE the rider
 * picks the parcel up — which is what the ERP requires.
 */
class ShipmentController extends Controller
{
    public function __construct(
        private readonly WaybillQrCode $waybillQr,
    ) {}

    /**
     * Parcels in the outbound pipeline (anything from a fresh order through to
     * delivered). The list is shop-scoped and read-only apart from the label.
     */
    public function index(Request $request): View
    {
        $seller = Auth::guard('seller')->user();
        $statuses = collect(OrderStatus::cases())
            ->reject(fn (OrderStatus $status) => in_array($status, [OrderStatus::Returned, OrderStatus::Cancelled], true));
        $selectedStatus = $statuses->first(
            fn (OrderStatus $status) => $status->value === $request->query('status')
        );

        $shipments = SellerOrder::query()
            ->where('seller_id', $seller->id)
            ->whereNotIn('status', [OrderStatus::Returned, OrderStatus::Cancelled])
            ->when($selectedStatus, fn ($query) => $query->where('status', $selectedStatus))
            ->with([
                'order' => fn ($q) => $q->with('buyer'),
                'orderItems',
                'courier',
                'deliveryCourier',
            ])
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('Seller.Shipments.index', [
            'shipments' => $shipments,
            'statuses' => $statuses,
            'selectedStatus' => $selectedStatus,
        ]);
    }

    /**
     * Printable waybill for one parcel. Available from PLACED onwards — the
     * seller prints it, sticks it on the parcel, and hands it to the rider.
     */
    public function label(SellerOrder $sellerOrder): View
    {
        $this->authorizeForUser(Auth::guard('seller')->user(), 'view', $sellerOrder);

        $sellerOrder->load([
            'order.buyer',
            'orderItems',
            'seller',
            'courier',
            'payment',
        ]);

        $tracking = $sellerOrder->tracking_number;

        return view('Seller.Shipments.label', [
            'sellerOrder' => $sellerOrder,
            'qrSvg' => $this->waybillQr->svg($tracking),
            'qrPayload' => $this->waybillQr->payload($tracking),
        ]);
    }
}
