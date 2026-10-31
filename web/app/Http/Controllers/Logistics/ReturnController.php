<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\ParcelReturn;
use App\Models\Rider\Courier;
use App\Models\Rider\Rider;
use App\Services\Logistics\ParcelReturnService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReturnController extends Controller
{
    public function index(Request $request)
    {
        $provider = $request->user('logistics');
        $returns = ParcelReturn::query()->visibleToProvider((int) $provider->id)
            ->with(['sellerOrder.originStation', 'sellerOrder.custodyHub', 'sellerOrder.seller', 'originHub', 'originProvider'])->latest('id')->paginate(25)->withQueryString();

        return view('Logistics.Returns.index', compact('returns'));
    }

    public function show(Request $request, ParcelReturn $return, ParcelReturnService $service)
    {
        $return->load(['sellerOrder.originStation', 'sellerOrder.custodyHub', 'sellerOrder.seller', 'originHub', 'originProvider', 'events', 'sellerOrder.manifestItems.manifest', 'sellerOrder.parcelHandoffs', 'sellerOrder.parcelHandoffExceptions']);
        $provider = $request->user('logistics');
        abort_unless(ParcelReturn::query()->whereKey($return->id)->visibleToProvider((int) $provider->id)->exists(), 404);
        $canOperate = $service->custodyProviderId($return->sellerOrder) === (int) $provider->id && $return->isActive() && ! $return->inDispute();
        $hubs = LogisticsHub::query()->where('logistics_provider_id', $provider->id)->operational()->orderBy('name')->get();
        $collectionCouriers = Courier::query()->where('logistics_provider_id', $provider->id)
            ->whereHas('rider', fn ($rider) => $rider->whereIn('status', Rider::ASSIGNABLE_STATUSES))
            ->with('rider')->get();

        return view('Logistics.Returns.show', compact('return', 'provider', 'canOperate', 'hubs', 'collectionCouriers'));
    }

    public function assignCollection(Request $request, ParcelReturn $return, ParcelReturnService $service)
    {
        $data = $request->validate(['courier_id' => ['required', 'integer']]);
        $service->assignBuyerCollection($return, $request->user('logistics'), (int) $data['courier_id']);

        return back()->with('success', 'Rider assigned to collect this return from the buyer.');
    }

    public function scan(Request $request, ParcelReturn $return, ParcelReturnService $service)
    {
        $data = $request->validate(['tracking_number' => ['required', 'string', 'max:64'], 'phase' => ['required', Rule::in(['origin', 'handover'])]]);

        return response()->json($service->scan($return, $request->user('logistics'), $data['tracking_number'], $data['phase']));
    }

    public function origin(Request $request, ParcelReturn $return, ParcelReturnService $service)
    {
        $data = $request->validate(['verification_token' => ['required', 'string', 'max:128']]);
        $service->confirmOrigin($return, $request->user('logistics'), $data['verification_token']);

        return back()->with('success', 'Physical return received at origin. Seller receipt is still required.');
    }

    public function handover(Request $request, ParcelReturn $return, ParcelReturnService $service)
    {
        $data = $request->validate(['verification_token' => ['required', 'string', 'max:128']]);
        $code = $service->prepareHandover($return, $request->user('logistics'), $data['verification_token']);

        return back()->with('handover_code', $code)->with('success', 'Give this one-time code to the owning Seller only during physical handover. It expires in ten minutes.');
    }

    public function manifest(Request $request, ParcelReturn $return, ParcelReturnService $service)
    {
        $data = $request->validate(['destination_hub_id' => ['required', 'integer']]);
        $manifest = $service->reverseManifest($return, $request->user('logistics'), (int) $data['destination_hub_id']);

        return to_route('logistics.manifests.show', $manifest);
    }

    public function handoff(Request $request, ParcelReturn $return, ParcelReturnService $service)
    {
        $handoff = $service->reverseHandoff($return, $request->user('logistics'));

        return to_route('logistics.handoffs.show', $handoff);
    }
}
