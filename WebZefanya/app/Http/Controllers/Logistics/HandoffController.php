<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\LogisticsProviderLink;
use App\Models\Logistics\ParcelHandoff;
use App\Models\Logistics\ParcelHandoffException;
use App\Models\Shared\SellerOrder;
use App\Services\Logistics\ParcelHandoffService;
use App\Services\Logistics\ParcelHandoffVerification;
use App\Services\Logistics\ParcelLossService;
use App\Services\Logistics\TransferCourierService;
use App\Support\RuntimeSchema;
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

        // Summary-card filter: each card narrows the lists to exactly what it counts.
        $filters = [
            'requested' => ['status' => 'REQUESTED', 'incoming' => true, 'outgoing' => false],
            'accepted' => ['status' => 'ACCEPTED', 'incoming' => false, 'outgoing' => true],
            'in_transit' => ['status' => 'IN_TRANSIT', 'incoming' => true, 'outgoing' => true],
            'received' => ['status' => 'RECEIVED', 'incoming' => true, 'outgoing' => true],
        ];
        $filter = $filters[(string) $request->query('status')] ?? null;
        $statusFilter = $filter !== null ? (string) $request->query('status') : null;

        $outgoing = ParcelHandoff::query()
            ->where('origin_provider_id', $provider->id)
            ->when($filter, fn ($query) => $query->where('status', $filter['status']))
            ->with(['sellerOrder.order', 'sellerOrder.custodyProvider', 'sellerOrder.carrierProvider', 'destinationProvider', 'sendingHub', 'receivingHub'])
            ->latest('id')
            ->paginate(10, ['*'], 'outgoing_page')
            ->withQueryString();

        $incoming = ParcelHandoff::query()
            ->where('destination_provider_id', $provider->id)
            ->when($filter, fn ($query) => $query->where('status', $filter['status']))
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
            'statusFilter' => $statusFilter,
            'showIncoming' => $filter['incoming'] ?? true,
            'showOutgoing' => $filter['outgoing'] ?? true,
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

        $isOrigin = (int) $handoff->origin_provider_id === (int) $provider->id;
        $couriers = app(TransferCourierService::class);

        return view('Logistics.Handoffs.show', [
            'provider' => $provider,
            'handoff' => $handoff,
            'isOrigin' => $isOrigin,
            'isDestination' => (int) $handoff->destination_provider_id === (int) $provider->id,
            'transportAvailable' => TransferCourierService::available(),
            'transportCourier' => TransferCourierService::available() ? $handoff->transferCourier()->first() : null,
            'transportCandidates' => $isOrigin ? $couriers->couriersFor($provider, activeOnly: true) : collect(),
            'transportHistory' => $isOrigin ? $couriers->historyFor($handoff) : collect(),
            'cancellationAvailable' => RuntimeSchema::hasColumn('parcel_handoffs', 'cancelled_at'),
        ]);
    }

    /** The sending company reports the parcel of an in-transit handoff missing. */
    public function reportMissing(Request $request, ParcelHandoff $handoff): RedirectResponse
    {
        $provider = $this->provider($request);
        $this->participant($provider, $handoff);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        app(ParcelLossService::class)->reportMissingTogether('handoff', (int) $handoff->id, $provider, [(int) $handoff->seller_order_id], $data['reason']);

        return redirect()->route('logistics.handoffs.show', $handoff)->with('success',
            'Parcel reported missing. Admin will review it. It stays in your custody until a verified scan or an Admin decision.');
    }

    /** The sending company cancels a handoff it has not seen received, with a reason. */
    public function cancel(Request $request, ParcelHandoff $handoff): RedirectResponse
    {
        $provider = $this->provider($request);
        $this->participant($provider, $handoff);
        $data = $request->validate(['cancellation_reason' => ['required', 'string', 'max:1000']]);
        $leftTheHub = $handoff->fresh()->dispatched_at !== null;

        $this->handoffs->cancel($handoff, $provider, (string) $data['cancellation_reason']);

        return redirect()->route('logistics.handoffs.show', $handoff)->with('success', $leftTheHub
            ? 'Handoff cancelled. The parcel had already left your hub, so it stays held as an exception until you scan it back at the sending hub.'
            : 'Handoff cancelled. The parcel never left your hub and is back in your handoff queue.');
    }

    /** Verified scan of a cancelled in-transit handoff's parcel back at the sending hub. */
    public function recover(Request $request, ParcelHandoff $handoff): RedirectResponse|JsonResponse
    {
        $provider = $this->provider($request);
        $this->participant($provider, $handoff);
        $data = $request->validate(['verification_token' => ['required', 'string', 'max:128']]);

        $recovered = $this->handoffs->recover($handoff, $provider, (string) $data['verification_token']);
        $message = 'Parcel recorded back at '.($recovered->sendingHub?->name ?? 'the sending hub').'. Resolve its exception to resume or reroute it.';

        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'redirect' => route('logistics.handoffs.show', $handoff)]);
        }

        return redirect()->route('logistics.handoffs.show', $handoff)->with('success', $message);
    }

    /** The sending company names which of its Couriers carries this handoff. */
    public function assignCourier(Request $request, ParcelHandoff $handoff): RedirectResponse
    {
        $provider = $this->provider($request);
        $this->participant($provider, $handoff);

        $data = $request->validate([
            'transfer_courier_id' => ['required', 'integer'],
            'transport_vehicle_type' => ['nullable', 'string', 'max:20'],
            'transport_plate_number' => ['nullable', 'string', 'max:20'],
        ]);

        $updated = app(TransferCourierService::class)->assignToHandoff(
            $handoff, $provider, (int) $data['transfer_courier_id'],
            $data['transport_vehicle_type'] ?? null, $data['transport_plate_number'] ?? null,
        );

        return back()->with('success', ($updated->transferCourier?->name ?? 'The Courier').' will carry this handoff. Custody moves only when the receiving company scans it.');
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
            'phase' => ['nullable', Rule::in([ParcelHandoffService::PHASE_DISPATCH, ParcelHandoffService::PHASE_RECEIVE, ParcelHandoffService::PHASE_RECOVER])],
            'method' => ['nullable', Rule::in([
                ParcelHandoffVerification::METHOD_CAMERA,
                ParcelHandoffVerification::METHOD_MANUAL,
            ])],
        ]);

        $phase = $data['phase'] ?? match (true) {
            (int) $handoff->destination_provider_id === (int) $provider->id => ParcelHandoffService::PHASE_RECEIVE,
            $handoff->awaitsRecovery() => ParcelHandoffService::PHASE_RECOVER,
            default => ParcelHandoffService::PHASE_DISPATCH,
        };

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
