<?php

namespace App\Http\Controllers\Rider;

use App\Enums\ParcelReturnStatus;
use App\Http\Controllers\Controller;
use App\Models\Logistics\ParcelReturn;
use App\Services\Logistics\ParcelReturnService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReturnPickupController extends Controller
{
    public function index(Request $request): View
    {
        $returns = ParcelReturn::query()
            ->where('status', ParcelReturnStatus::Requested->value)
            ->whereNull('collected_at')
            ->whereHas('collectionCourier', fn ($courier) => $courier->where('rider_id', $request->user('rider')->id))
            ->with(['sellerOrder.order.buyer', 'sellerOrder.orderItems'])
            ->oldest('collection_assigned_at')
            ->get();

        return view('Rider.ReturnPickups.index', compact('returns'));
    }

    public function collect(Request $request, ParcelReturn $return, ParcelReturnService $service): RedirectResponse
    {
        $data = $request->validate(['tracking_number' => ['required', 'string', 'max:64']]);

        $service->confirmBuyerCollection($return, $request->user('rider'), $data['tracking_number']);

        return back()->with('success', 'Parcel collected. Bring it to your Station Hub.');
    }
}
