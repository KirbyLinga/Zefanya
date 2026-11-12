<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Models\Logistics\DeliveryDispute;
use App\Models\Shared\SellerOrder;
use App\Services\Buyer\DeliveryDisputeService;
use App\Support\DeliveryProofImage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DeliveryDisputeController extends Controller
{
    public function index(Request $request): View
    {
        $admin = $request->routeIs('admin.*');
        abort_unless(DeliveryDisputeService::installed(), 404);
        $cases = DeliveryDispute::query()->when(! $admin, fn ($query) => $query->where('logistics_provider_id', $request->user('logistics')->id))
            ->with(['sellerOrder.deliveryProof', 'sellerOrder.payment', 'sellerOrder.statusHistories', 'sellerOrder.incidentSet'])
            ->latest('id')->paginate(15);

        return view('Logistics.DeliveryMonitoring.disputes', compact('cases', 'admin'));
    }

    public function review(Request $request, DeliveryDispute $dispute, DeliveryDisputeService $service): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:investigate,escalate,upheld,denied,release_hold'],
            'note' => ['required', 'string', 'max:1000'],
            'investigator' => ['nullable', 'string', 'max:120'],
        ]);
        $service->review($dispute, $request->routeIs('admin.*') ? $request->user('admin') : $request->user('logistics'),
            $data['action'], $data['note'], $data['investigator'] ?? null);

        return back()->with('success', 'Investigation updated.');
    }

    public function openException(Request $request, DeliveryDisputeService $service): RedirectResponse
    {
        $data = $request->validate([
            'shipment_id' => ['required', 'integer', 'min:1'],
            'explanation' => ['required', 'string', 'max:1000'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);
        $shipment = SellerOrder::query()->with('order.buyer')->findOrFail($data['shipment_id']);
        $service->open($shipment, $shipment->order->buyer, $data['explanation'], exceptionAdmin: $request->user('admin'), exceptionReason: $data['reason']);

        return back()->with('success', 'Authorized non-receipt report recorded.');
    }

    public function proof(Request $request, DeliveryDispute $dispute): StreamedResponse
    {
        abort_unless($request->routeIs('admin.*') || (int) $dispute->logistics_provider_id === (int) $request->user('logistics')->id, 404);
        $proof = $dispute->sellerOrder->deliveryProof;
        abort_if($proof === null, 404);

        return DeliveryProofImage::response($proof->photo_path);
    }

    public function evidence(Request $request, DeliveryDispute $dispute): StreamedResponse
    {
        abort_unless($request->routeIs('admin.*') || (int) $dispute->logistics_provider_id === (int) $request->user('logistics')->id, 404);
        abort_if($dispute->evidence_path === null, 404);

        return DeliveryProofImage::response($dispute->evidence_path);
    }
}
