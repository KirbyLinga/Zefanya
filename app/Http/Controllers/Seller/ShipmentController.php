<?php

namespace App\Http\Controllers\Seller;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\SellerOrder;
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
    /**
     * Parcels in the outbound pipeline (anything from a fresh order through to
     * delivered). The list is shop-scoped and read-only apart from the label.
     */
    public function index(): View
    {
        $seller = Auth::guard('seller')->user();

        $shipments = SellerOrder::query()
            ->where('seller_id', $seller->id)
            ->whereNotIn('status', [OrderStatus::Returned])
            ->with([
                'order' => fn ($q) => $q->with('buyer'),
                'orderItems',
                'courier',
                'deliveryCourier',
            ])
            ->latest('id')
            ->paginate(20);

        return view('Seller.Shipments.index', [
            'shipments' => $shipments,
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

        return view('Seller.Shipments.label', [
            'sellerOrder' => $sellerOrder,
        ]);
    }
}
