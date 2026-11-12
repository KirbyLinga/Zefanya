<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Http\Middleware\Traits\ApiResponses;
use App\Models\Shared\SellerOrder;
use App\Services\Buyer\DeliveryDisputeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class DeliveryDisputeController extends Controller
{
    use ApiResponses;

    public function show(Request $request, SellerOrder $shipment, DeliveryDisputeService $disputes): JsonResponse
    {
        abort_unless((int) $shipment->order->buyer_id === (int) $request->user()->id, 404);

        return $this->success('Delivery dispute status retrieved.', $disputes->buyerView($shipment));
    }

    public function store(Request $request, SellerOrder $shipment, DeliveryDisputeService $disputes): JsonResponse|RedirectResponse
    {
        $buyer = $request->routeIs('api.buyer.*') ? $request->user() : $request->user('buyer');
        abort_unless((int) $shipment->order->buyer_id === (int) $buyer->id, 404);
        $data = $request->validate([
            'explanation' => ['required', 'string', 'max:1000'],
            'evidence' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);
        $path = $request->file('evidence')?->store('delivery-disputes/'.$shipment->id, 'local');
        if ($path === false) {
            throw ValidationException::withMessages(['evidence' => 'The evidence could not be saved. Try attaching it again.']);
        }
        try {
            $case = $disputes->open($shipment, $buyer, $data['explanation'], $path);
        } catch (Throwable $exception) {
            if ($path !== null) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }
        if ($path !== null && $case->evidence_path !== $path) {
            Storage::disk('local')->delete($path);
        }

        return $request->routeIs('api.buyer.*')
            ? $this->success('Delivery dispute recorded.', $disputes->buyerView($shipment->fresh()))
            : back()->with('success', 'Delivery dispute recorded. Your case reference is shown on the order.');
    }
}
