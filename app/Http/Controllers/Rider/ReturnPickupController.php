<?php

namespace App\Http\Controllers\Rider;

use App\Enums\ParcelReturnStatus;
use App\Http\Controllers\Controller;
use App\Models\Logistics\ParcelReturn;
use App\Services\Logistics\ParcelReturnService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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

    public function failed(Request $request, ParcelReturn $return, ParcelReturnService $service): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', Rule::in(array_keys(ParcelReturn::COLLECTION_FAILURE_REASONS))],
            'notes' => ['nullable', 'string', 'max:500', 'required_if:reason,other'],
        ], [
            'reason.required' => 'Choose why the parcel could not be collected.',
            'reason.in' => 'Choose why the parcel could not be collected.',
            'notes.required_if' => 'Describe what happened.',
            'notes.max' => 'Keep the notes to 500 characters or fewer.',
        ]);

        $service->reportCollectionFailure($return, $request->user('rider'), $data['reason'], $data['notes'] ?? null);

        return back()->with('success', 'Missed collection recorded. Logistics will arrange the next step; the parcel stays with the buyer.');
    }
}
