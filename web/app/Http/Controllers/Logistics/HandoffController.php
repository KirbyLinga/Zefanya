<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\LogisticsProviderLink;
use App\Models\Logistics\ParcelHandoff;
use App\Models\Logistics\ParcelHandoffException;
use App\Models\SellerOrder;
use App\Services\ParcelHandoffService;
use App\Services\ParcelHandoffVerification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Cross-provider parcel handoffs.
 *
 * Acceptance reserves a destination, dispatch proves departure, and a separate
 * destination scan is the only event that transfers custody. No action here
 * writes a SellerOrder status or changes the tracking number.
 */
class HandoffController extends Controller
{
    public function __construct(private readonly ParcelHandoffService $handoffs) {}

    public function index(Request $request): View
    {
        $provider = $this->provider($request);

        $outgoing = ParcelHandoff::query()
            ->where('origin_provider_id', $provider->id)
            ->with(['sellerOrder.order', 'sellerOrder.custodyProvider', 'sellerOrder.carrierProvider', 'destinationProvider', 'sendingHub', 'receivingHub'])
            ->latest('id')
            ->paginate(10, ['*'], 'outgoing_page')
            ->withQueryString();

        $incoming = ParcelHandoff::query()
            ->where('destination_provider_id', $provider->id)
            ->with(['sellerOrder.order', 'sellerOrder.custodyProvider', 'sellerOrder.carrierProvider', 'originProvider', 'sendingHub', 'receivingHub'])
            ->latest('id')
            ->paginate(10, ['*'], 'incoming_page')
            ->withQueryString();

        $eligibleParcels = $this->handoffs->eligibleParcels($provider);
        $eligibleLinks = $eligibleParcels->mapWithKeys(fn (SellerOrder $parcel): array => [
            $parcel->id => $this->handoffs->eligibleLinks($parcel, $provider),
        ]);

        $exceptions = ParcelHandoffException::query()
            ->where('origin_provider_id', $provider->id)
            ->where('status', ParcelHandoffException::OPEN)
            ->with('sellerOrder.order', 'sellerOrder.custodyProvider', 'sellerOrder.carrierProvider')
            ->latest('id')
            ->limit(10)
            ->get();

        return view('Logistics.Handoffs.index', [
            'provider' => $provider,
            'outgoing' => $outgoing,
            'incoming' => $incoming,
            'eligibleParcels' => $eligibleParcels,
            'eligibleLinks' => $eligibleLinks,
            'exceptions' => $exceptions,
            'counts' => [
                'requested' => ParcelHandoff::query()->where('destination_provider_id', $provider->id)->where('status', 'REQUESTED')->count(),
                'accepted' => ParcelHandoff::query()->where('origin_provider_id', $provider->id)->where('status', 'ACCEPTED')->count(),
                'in_transit' => ParcelHandoff::query()
                    ->where(fn ($query) => $query->where('origin_provider_id', $provider->id)->orWhere('destination_provider_id', $provider->id))
                    ->where('status', 'IN_TRANSIT')->count(),
                'received' => ParcelHandoff::query()
                    ->where(fn ($query) => $query->where('origin_provider_id', $provider->id)->orWhere('destination_provider_id', $provider->id))
                    ->where('status', 'RECEIVED')->count(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $provider = $this->provider($request);

        $data = $request->validate([
            'seller_order_id' => ['required', 'integer', 'exists:seller_orders,id'],
            'logistics_provider_link_id' => ['nullable', 'integer', 'exists:logistics_provider_links,id'],
        ]);

        $parcel = SellerOrder::query()->findOrFail((int) $data['seller_order_id']);
        $link = isset($data['logistics_provider_link_id'])
            ? LogisticsProviderLink::query()->find((int) $data['logistics_provider_link_id'])
            : null;

        try {
            $handoff = $this->handoffs->request($parcel, $provider, $link);
        } catch (ValidationException $exception) {
            return back()->withInput()->withErrors($exception->errors());
        }

        return redirect()
            ->route('logistics.handoffs.show', $handoff)
            ->with('success', 'Handoff requested from '.$handoff->destinationProvider?->business_name.'. The parcel stays in your custody until the destination accepts and both scans complete.');
    }

    public function show(Request $request, ParcelHandoff $handoff): View
    {
        $provider = $this->provider($request);
        $this->participant($provider, $handoff);

        $handoff->load([
            'sellerOrder.order.buyer',
            'sellerOrder.custodyProvider',
            'sellerOrder.carrierProvider',
            'sellerOrder.seller',
            'sellerOrder.orderItems',
            'link',
            'originProvider',
            'destinationProvider',
            'sendingHub',
            'receivingHub',
        ]);

        return view('Logistics.Handoffs.show', [
            'provider' => $provider,
            'handoff' => $handoff,
            'isOrigin' => (int) $handoff->origin_provider_id === (int) $provider->id,
            'isDestination' => (int) $handoff->destination_provider_id === (int) $provider->id,
        ]);
    }

    public function accept(Request $request, ParcelHandoff $handoff): RedirectResponse
    {
        $provider = $this->provider($request);

        try {
            $this->handoffs->accept($handoff, $provider);
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        return back()->with('success', 'Handoff accepted. The origin can now dispatch after scanning the parcel.');
    }

    public function reject(Request $request, ParcelHandoff $handoff): RedirectResponse
    {
        $provider = $this->provider($request);

        $data = $request->validate([
            'rejection_reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $this->handoffs->reject($handoff, $provider, $data['rejection_reason']);
        } catch (ValidationException $exception) {
            return back()->withInput()->withErrors($exception->errors());
        }

        $next = ParcelHandoff::query()
            ->where('seller_order_id', $handoff->seller_order_id)
            ->where('status', 'REQUESTED')
            ->whereKeyNot($handoff->id)
            ->latest('id')
            ->first();

        return back()->with(
            'success',
            $next !== null
                ? 'Handoff rejected. The parcel remains in origin custody and was offered to the next eligible partner.'
                : 'Handoff rejected. The parcel remains in origin custody and Admin has been notified that no eligible partner is available.'
        );
    }

    public function verifyScan(Request $request, ParcelHandoff $handoff): JsonResponse
    {
        $provider = $this->provider($request);

        $data = $request->validate([
            'tracking_number' => ['required', 'string', 'max:64'],
            'phase' => ['nullable', Rule::in([ParcelHandoffService::PHASE_DISPATCH, ParcelHandoffService::PHASE_RECEIVE])],
            'method' => ['nullable', Rule::in([
                ParcelHandoffVerification::METHOD_CAMERA,
                ParcelHandoffVerification::METHOD_MANUAL,
            ])],
        ]);

        $phase = $data['phase'] ?? ((int) $handoff->destination_provider_id === (int) $provider->id
            ? ParcelHandoffService::PHASE_RECEIVE
            : ParcelHandoffService::PHASE_DISPATCH);

        try {
            return response()->json($this->handoffs->verifyScan(
                $handoff,
                $provider,
                (string) $data['tracking_number'],
                (string) $phase,
                (string) ($data['method'] ?? ParcelHandoffVerification::METHOD_MANUAL),
            ));
        } catch (ValidationException $exception) {
            return response()->json([
                'message' => collect($exception->errors())->flatten()->first() ?? 'Verification failed.',
                'errors' => $exception->errors(),
            ], 422);
        }
    }

    public function dispatch(Request $request, ParcelHandoff $handoff): RedirectResponse
    {
        $provider = $this->provider($request);

        $data = $request->validate([
            'verification_token' => ['required', 'string', 'max:128'],
        ]);

        try {
            $this->handoffs->dispatch($handoff, $provider, (string) $data['verification_token']);
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        return back()->with('success', 'Handoff dispatched. Custody remains with your provider until the destination passes its own receiving scan.');
    }

    public function receive(Request $request, ParcelHandoff $handoff): RedirectResponse
    {
        $provider = $this->provider($request);

        $data = $request->validate([
            'verification_token' => ['required', 'string', 'max:128'],
        ]);

        try {
            $this->handoffs->receive($handoff, $provider, (string) $data['verification_token']);
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        return back()->with('success', 'Parcel received. Custody is now with your provider; delivery status and tracking were not changed.');
    }

    private function provider(Request $request): LogisticsProvider
    {
        return $request->user('logistics');
    }

    private function participant(LogisticsProvider $provider, ParcelHandoff $handoff): void
    {
        abort_unless(
            in_array((int) $provider->id, [(int) $handoff->origin_provider_id, (int) $handoff->destination_provider_id], true),
            404,
        );
    }
}
