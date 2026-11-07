<?php

namespace App\Http\Controllers\Rider;

use App\Http\Controllers\Controller;
use App\Models\Logistics\ParcelReturn;
use App\Services\Logistics\ParcelReturnService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReturnPickupController extends Controller
{
    public function index(Request $request, ParcelReturnService $service): View
    {
        $returns = $service->pendingBuyerCollectionsFor($request->user('rider'));

        return view('Rider.ReturnPickups.index', compact('returns'));
    }

    public function collect(Request $request, ParcelReturn $return, ParcelReturnService $service): RedirectResponse
    {
        $data = $request->validate(['tracking_number' => ['required', 'string', 'max:64']]);

        $service->confirmBuyerCollection($return, $request->user('rider'), $data['tracking_number']);

        return back()->with('success', 'Parcel collected. Bring it to your Station Hub.');
    }
}
