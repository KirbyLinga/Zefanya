<?php

namespace App\Http\Controllers\Logistics;

use App\Enums\ManifestStatus;
use App\Http\Controllers\Controller;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\Manifest;
use App\Models\Logistics\ManifestItem;
use App\Models\Shared\SellerOrder;
use App\Services\Logistics\ManifestService;
use App\Services\Logistics\ParcelHandoffVerification;
use App\Services\Logistics\ParcelLossService;
use App\Services\Logistics\TransferCourierService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Internal hub-to-hub manifests.
 *
 * A manifest is an audit record of physical custody. It never writes a
 * SellerOrder status: dispatch/receipt only prove that the parcel moved between
 * the provider's own hubs.
 */
class ManifestController extends Controller
{
    public function __construct(private readonly ManifestService $manifests) {}

    public function index(Request $request): View
    {
        $provider = $this->provider($request);
        $tab = (string) $request->query('tab', 'outgoing');  // 'outgoing' or 'incoming'
        $status = ManifestStatus::tryFrom((string) $request->query('status'));

        if ($tab === 'incoming') {
            // Incoming: manifests this provider is receiving at (destination_hub_id)
            $manifests = Manifest::query()
                ->where('destination_hub_id', function ($query) use ($provider): void {
                    $query->select('id')
                        ->from('logistics_hubs')
                        ->where('logistics_provider_id', $provider->id)
                        ->limit(1);
                })
                ->where('status', ManifestStatus::Dispatched->value)  // Only show dispatched for incoming
                ->with(['originHub:id,name,hub_type', 'destinationHub:id,name,hub_type', 'provider:id,business_name'])
                ->withCount([
                    'items as total_items_count' => fn ($q) => $q->where(function ($inner): void {
                        $inner->whereNull('released_at')->orWhereNotNull('received_at');
                    }),
                    'items as received_items_count' => fn ($q) => $q->whereNotNull('received_at'),
                ])
                ->latest('id')
                ->paginate(15)
                ->withQueryString();
        } else {
            // Outgoing: manifests this provider is sending from (origin_hub_id)
            $manifests = Manifest::query()
                ->where('origin_hub_id', function ($query) use ($provider): void {
                    $query->select('id')
                        ->from('logistics_hubs')
                        ->where('logistics_provider_id', $provider->id)
                        ->limit(1);
                })
                ->with(['originHub:id,name,hub_type', 'destinationHub:id,name,hub_type'])
                ->withCount([
                    'items as total_items_count' => fn ($q) => $q->where(function ($inner): void {
                        $inner->whereNull('released_at')->orWhereNotNull('received_at');
                    }),
                    'items as received_items_count' => fn ($q) => $q->whereNotNull('received_at'),
                ])
                ->when($status !== null, fn ($q) => $q->where('status', $status->value))
                ->latest('id')
                ->paginate(15)
                ->withQueryString();
        }

        return view('Logistics.Manifests.index', [
            'provider' => $provider,
            'manifests' => $manifests,
            'status' => $status,
            'tab' => $tab,
            'statuses' => ManifestStatus::cases(),
        ]);
    }

    public function create(Request $request): View
    {
        $provider = $this->provider($request);
        $hubs = $this->hubs($request);

        // If an origin hub is being retained (from form validation error or query param),
        // suggest recent destinations for that origin.
        $originHubId = (int) $request->query('origin_hub_id', $request->old('origin_hub_id', 0));
        $recentDestinations = $originHubId > 0
            ? $this->recentDestinations($provider, $originHubId)
            : collect();

        return view('Logistics.Manifests.create', [
            'provider' => $provider,
            'hubs' => $hubs,
            'recentDestinations' => $recentDestinations,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $provider = $this->provider($request);

        $data = $request->validate([
            'origin_hub_id' => ['required', 'integer'],
            'destination_hub_id' => ['required', 'integer', 'different:origin_hub_id'],
        ]);

        $manifest = $this->manifests->create(
            $provider,
            (int) $data['origin_hub_id'],
            (int) $data['destination_hub_id'],
        );

        return redirect()
            ->route('logistics.manifests.show', $manifest)
            ->with('success', 'Manifest created. Add parcels while it is still a draft.');
    }

    public function show(Request $request, Manifest $manifest): View
    {
        $provider = $this->provider($request);
        $this->ownedManifest($provider->id, $manifest);

        $manifest->load(['originHub', 'destinationHub', 'provider']);

        $items = $this->visibleItems($manifest);
        $counts = $this->manifests->counts($manifest);
        $needsRecovery = $manifest->status === ManifestStatus::Cancelled && $manifest->dispatched_at !== null;
        $recoveryHubs = $needsRecovery ? $this->manifests->recoveryHubs($manifest) : collect();
        $recoveryHub = $recoveryHubs->firstWhere('id', $request->integer('hub')) ?? $recoveryHubs->first();

        return view('Logistics.Manifests.show', [
            'provider' => $provider,
            'manifest' => $manifest,
            'items' => $items,
            'counts' => $counts,
            'recoveryHubs' => $recoveryHubs,
            'recoveryHub' => $recoveryHub,
            'awaitingRecovery' => $needsRecovery ? $items->filter->awaitsRecovery()->count() : 0,
            'transportAvailable' => TransferCourierService::available(),
            'transportCourier' => TransferCourierService::available() ? $manifest->transferCourier()->first() : null,
            'transportCandidates' => $manifest->status === ManifestStatus::Draft
                ? app(TransferCourierService::class)->couriersFor($provider, activeOnly: true)
                : collect(),
            'transportHistory' => app(TransferCourierService::class)->historyFor($manifest),
            'eligibleParcels' => $manifest->status === ManifestStatus::Draft
                ? $this->manifests->eligibleParcels($manifest)
                : collect(),
            'hubs' => $this->hubs($request),
        ]);
    }

    public function addItem(Request $request, Manifest $manifest): RedirectResponse
    {
        $provider = $this->provider($request);
        $this->ownedManifest($provider->id, $manifest);

        $data = $request->validate([
            'seller_order_id' => ['required', 'integer'],
        ]);

        $parcel = SellerOrder::query()->findOrFail((int) $data['seller_order_id']);
        $this->manifests->addParcel($manifest, $parcel);

        return redirect()
            ->route('logistics.manifests.show', $manifest)
            ->with('success', 'Parcel added to the draft manifest.');
    }

    public function removeItem(Request $request, Manifest $manifest, ManifestItem $item): RedirectResponse
    {
        $provider = $this->provider($request);
        $this->ownedManifest($provider->id, $manifest);
        abort_unless((int) $item->manifest_id === (int) $manifest->id, 404);

        $this->manifests->removeParcel($manifest, $item);

        return redirect()
            ->route('logistics.manifests.show', $manifest)
            ->with('success', 'Parcel removed from the draft manifest.');
    }

    public function dispatch(Request $request, Manifest $manifest): RedirectResponse
    {
        $provider = $this->provider($request);
        $this->ownedManifest($provider->id, $manifest);

        $data = $request->validate([
            'tokens' => ['nullable', 'array'],
            'tokens.*' => ['nullable', 'string', 'max:128'],
        ]);

        $this->manifests->dispatch($manifest, $provider, $data['tokens'] ?? [], $request);

        return redirect()
            ->route('logistics.manifests.show', $manifest)
            ->with('success', 'Manifest dispatched. Custody remains with the origin hub until the destination verifies receipt.');
    }

    public function verifyScan(Request $request, Manifest $manifest): JsonResponse
    {
        $provider = $this->provider($request);
        $this->ownedManifest($provider->id, $manifest);

        $data = $request->validate([
            'tracking_number' => ['required', 'string', 'max:64'],
            'phase' => ['nullable', Rule::in([ManifestService::PHASE_DISPATCH, ManifestService::PHASE_RECEIVE, ManifestService::PHASE_RECOVER])],
            'hub' => ['nullable', 'integer'],
            'method' => ['nullable', Rule::in([
                ParcelHandoffVerification::METHOD_CAMERA,
                ParcelHandoffVerification::METHOD_MANUAL,
            ])],
        ]);

        $phase = $data['phase'] ?? match ($manifest->status) {
            ManifestStatus::Dispatched => ManifestService::PHASE_RECEIVE,
            ManifestStatus::Cancelled => ManifestService::PHASE_RECOVER,
            default => ManifestService::PHASE_DISPATCH,
        };

        try {
            return response()->json($this->manifests->verifyScan(
                $manifest,
                $provider,
                (string) $data['tracking_number'],
                (string) $phase,
                (string) ($data['method'] ?? ParcelHandoffVerification::METHOD_MANUAL),
                isset($data['hub']) ? (int) $data['hub'] : null,
            ));
        } catch (ValidationException $e) {
            return response()->json([
                'message' => collect($e->errors())->flatten()->first() ?? 'Verification failed.',
                'errors' => $e->errors(),
            ], 422);
        }
    }

    public function receiveIndex(Request $request, Manifest $manifest): View
    {
        $provider = $this->provider($request);
        $this->ownedManifest($provider->id, $manifest);

        $manifest->load(['originHub', 'destinationHub', 'provider']);

        return view('Logistics.Manifests.receive', [
            'provider' => $provider,
            'manifest' => $manifest,
            'items' => $this->visibleItems($manifest),
            'counts' => $this->manifests->counts($manifest),
        ]);
    }

    public function receiveStore(Request $request, Manifest $manifest, ManifestItem $item): RedirectResponse
    {
        $provider = $this->provider($request);
        $this->ownedManifest($provider->id, $manifest);
        abort_unless((int) $item->manifest_id === (int) $manifest->id, 404);

        $data = $request->validate([
            'verification_token' => ['required', 'string', 'max:128'],
            'issue_type' => ['nullable', 'string', 'in:missing,damaged'],
            'issue_note' => ['nullable', 'string', 'max:500'],
        ]);

        $issueType = $data['issue_type'] ?? null;

        if ($issueType !== null) {
            // Report issue first
            $this->manifests->reportIssue(
                $manifest,
                $item,
                (string) $issueType,
                (string) ($data['issue_note'] ?? ''),
                'logistics',
                (int) $provider->id,
                (string) $data['verification_token'],
            );

            $issueMessage = $issueType === 'missing'
                ? 'parcel marked as missing'
                : 'parcel marked as damaged and received';

            return redirect()
                ->route('logistics.manifests.receive.index', $manifest)
                ->with('success', 'Issue reported: '.$issueMessage)
                ->with('nextStep', 'The issue has been recorded for investigation. Parcel status updated accordingly.');
        }

        // Normal receive
        $result = $this->manifests->receive(
            $manifest,
            $provider,
            $item,
            (string) $data['verification_token'],
            $request,
        );

        $nextStepMessage = $this->manifests->nextStepMessage(
            $result['manifest'],
            $result['item'],
            $result['completed'],
        );

        return redirect()
            ->route('logistics.manifests.receive.index', $manifest)
            ->with(
                'success',
                $result['completed']
                    ? 'All parcels received. Manifest closed.'
                    : 'Parcel received. The manifest stays open for the remaining parcels.',
            )
            ->with('nextStep', $nextStepMessage);
    }

    public function destroy(Request $request, Manifest $manifest): RedirectResponse
    {
        $provider = $this->provider($request);
        $this->ownedManifest($provider->id, $manifest);

        $this->manifests->deleteManifest($manifest, $provider, $request);

        return redirect()
            ->route('logistics.manifests.index')
            ->with('success', 'Draft batch #'.$manifest->id.' deleted. It never left the hub, so its parcels are back in the sending hub\'s queue.');
    }

    public function cancel(Request $request, Manifest $manifest): RedirectResponse
    {
        $provider = $this->provider($request);
        $this->ownedManifest($provider->id, $manifest);

        $data = $request->validate([
            'cancellation_reason' => ['required', 'string', 'max:1000'],
        ]);

        $this->manifests->cancelManifest($manifest, $provider, (string) $data['cancellation_reason'], $request);

        return redirect()
            ->route('logistics.manifests.show', $manifest)
            ->with('success', 'Batch cancelled. Its parcels had already left the hub, so each one stays held as an exception until it is scanned at a hub below.');
    }

    /** A verified recovery scan for one parcel of a batch cancelled after dispatch. */
    public function recover(Request $request, Manifest $manifest, ManifestItem $item): RedirectResponse|JsonResponse
    {
        $provider = $this->provider($request);
        $this->ownedManifest($provider->id, $manifest);
        abort_unless((int) $item->manifest_id === (int) $manifest->id, 404);

        $data = $request->validate([
            'verification_token' => ['required', 'string', 'max:128'],
            'hub' => ['required', 'integer'],
        ]);

        $recovered = $this->manifests->recoverItem($manifest, $item, $provider, (string) $data['verification_token'], (int) $data['hub'], $request);
        $message = $recovered->tracking_number.' recorded at '.($recovered->recoveredHub?->name ?? 'the hub').'. Resolve its exception to resume or reroute it.';

        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'redirect' => route('logistics.manifests.show', $manifest)]);
        }

        return redirect()->route('logistics.manifests.show', $manifest)->with('success', $message);
    }

    /**
     * The sending company reports several parcels of a dispatched batch missing
     * together. Each becomes its own exception, all or none. Custody does not change.
     */
    public function reportMissing(Request $request, Manifest $manifest): RedirectResponse
    {
        $provider = $this->provider($request);
        $this->ownedManifest($provider->id, $manifest);
        $data = $request->validate([
            'seller_order_ids' => ['required', 'array', 'min:1', 'max:'.ParcelLossService::MAX_BULK],
            'seller_order_ids.*' => ['integer'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $incidents = app(ParcelLossService::class)->reportMissingTogether('manifest', (int) $manifest->id, $provider, $data['seller_order_ids'], $data['reason']);

        return redirect()->route('logistics.manifests.show', $manifest)->with('success',
            $incidents->count().' '.str('parcel')->plural($incidents->count()).' reported missing together. Admin reviews each one. They stay in your custody until a verified scan or an Admin decision.');
    }

    /** The sending company names which of its Couriers carries this draft batch. */
    public function assignTransport(Request $request, Manifest $manifest): RedirectResponse
    {
        $provider = $this->provider($request);
        $this->ownedManifest($provider->id, $manifest);

        $data = $request->validate([
            'transfer_courier_id' => ['required', 'integer'],
            'transport_vehicle_type' => ['nullable', 'string', 'max:20'],
            'transport_plate_number' => ['nullable', 'string', 'max:20'],
        ]);

        $updated = app(TransferCourierService::class)->assignToManifest(
            $manifest, $provider, (int) $data['transfer_courier_id'],
            $data['transport_vehicle_type'] ?? null, $data['transport_plate_number'] ?? null, $request,
        );
        $name = $updated->transferCourier?->name ?? 'The Courier';

        return redirect()
            ->route('logistics.manifests.show', $manifest)
            ->with('success', $name.' will carry this batch. Custody still moves only when the receiving hub scans each parcel.');
    }

    private function provider(Request $request): LogisticsProvider
    {
        return $request->user('logistics');
    }

    private function ownedManifest(int $providerId, Manifest $manifest): void
    {
        abort_unless((int) $manifest->logistics_provider_id === $providerId, 404);
    }

    /**
     * @return Collection<int, ManifestItem>
     */
    private function visibleItems(Manifest $manifest)
    {
        // A batch cancelled after dispatch keeps showing every parcel that left
        // with it, because each one still needs a recovery scan.
        $leftTheHub = $manifest->status === ManifestStatus::Cancelled && $manifest->dispatched_at !== null;

        return $manifest->items()
            ->unless($leftTheHub, fn ($items) => $items->where(function ($q): void {
                $q->whereNotNull('received_at')->orWhereNull('released_at');
            }))
            ->with([
                'manifest',
                'recoveredHub',
                'sellerOrder.order.buyer',
                'sellerOrder.seller',
                'sellerOrder.orderItems',
            ])
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, LogisticsHub>
     */
    private function hubs(Request $request)
    {
        $provider = $this->provider($request);

        return LogisticsHub::query()
            ->where('logistics_provider_id', $provider->id)
            ->operational()
            ->orderByRaw('case when hub_type = ? then 0 else 1 end', [LogisticsHub::PROVINCE])
            ->orderBy('name')
            ->get();
    }

    /**
     * Fetch recent destination hubs for the given origin, sorted by most recent usage.
     * Used by AJAX to populate suggestions as user changes origin hub.
     *
     * @return Collection<int, LogisticsHub>
     */
    public function suggestDestinations(Request $request): JsonResponse
    {
        $provider = $this->provider($request);

        $originHubId = (int) $request->query('origin_hub_id');
        if ($originHubId <= 0) {
            return response()->json([]);
        }

        // Validate the origin hub belongs to this provider and is operational
        $originHub = LogisticsHub::query()
            ->where('id', $originHubId)
            ->where('logistics_provider_id', $provider->id)
            ->operational()
            ->first();

        if (! $originHub) {
            return response()->json([]);
        }

        $recentDestinations = $this->recentDestinations($provider, $originHubId);

        return response()->json([
            'suggestions' => $recentDestinations->map(fn (LogisticsHub $hub) => [
                'id' => $hub->id,
                'name' => $hub->name,
                'hub_type' => $hub->hub_type,
                'type_label' => $hub->hub_type === LogisticsHub::PROVINCE ? 'Province Hub' : 'Station Hub',
            ]),
        ]);
    }

    /**
     * Fetch recent destination hubs for the given origin, sorted by most recent usage.
     *
     * @return Collection<int, LogisticsHub>
     */
    private function recentDestinations(LogisticsProvider $provider, int $originHubId, int $limit = 5)
    {
        $destinationHubIds = Manifest::query()
            ->where('origin_hub_id', $originHubId)
            ->where('logistics_provider_id', $provider->id)
            ->where('status', '!=', ManifestStatus::Cancelled->value)
            ->select('destination_hub_id')
            ->distinct()
            ->orderByRaw('MAX(created_at) DESC')
            ->groupBy('destination_hub_id')
            ->limit($limit)
            ->pluck('destination_hub_id');

        return LogisticsHub::query()
            ->whereIn('id', $destinationHubIds)
            ->where('logistics_provider_id', $provider->id)
            ->operational()
            ->get()
            ->sortByDesc(function (LogisticsHub $hub) use ($destinationHubIds): int {
                return $destinationHubIds->search((int) $hub->id);
            });
    }
}
