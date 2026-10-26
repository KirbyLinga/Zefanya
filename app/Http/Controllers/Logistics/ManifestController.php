<?php

namespace App\Http\Controllers\Logistics;

use App\Enums\ManifestStatus;
use App\Http\Controllers\Controller;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\Manifest;
use App\Models\Logistics\ManifestItem;
use App\Models\SellerOrder;
use App\Services\ManifestService;
use App\Services\ParcelHandoffVerification;
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
        $status = ManifestStatus::tryFrom((string) $request->query('status'));

        $manifests = Manifest::query()
            ->where('logistics_provider_id', $provider->id)
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

        return view('Logistics.Manifests.index', [
            'provider' => $provider,
            'manifests' => $manifests,
            'status' => $status,
            'statuses' => ManifestStatus::cases(),
        ]);
    }

    public function create(Request $request): View
    {
        return view('Logistics.Manifests.create', [
            'provider' => $this->provider($request),
            'hubs' => $this->hubs($request),
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

        return view('Logistics.Manifests.show', [
            'provider' => $provider,
            'manifest' => $manifest,
            'items' => $items,
            'counts' => $counts,
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

        $this->manifests->dispatch($manifest, $provider, $data['tokens'] ?? []);

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
            'phase' => ['nullable', Rule::in([ManifestService::PHASE_DISPATCH, ManifestService::PHASE_RECEIVE])],
            'method' => ['nullable', Rule::in([
                ParcelHandoffVerification::METHOD_CAMERA,
                ParcelHandoffVerification::METHOD_MANUAL,
            ])],
        ]);

        $phase = $data['phase'] ?? ($manifest->status === ManifestStatus::Dispatched
            ? ManifestService::PHASE_RECEIVE
            : ManifestService::PHASE_DISPATCH);

        try {
            return response()->json($this->manifests->verifyScan(
                $manifest,
                $provider,
                (string) $data['tracking_number'],
                (string) $phase,
                (string) ($data['method'] ?? ParcelHandoffVerification::METHOD_MANUAL),
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
        ]);

        $result = $this->manifests->receive(
            $manifest,
            $provider,
            $item,
            (string) $data['verification_token'],
        );

        return redirect()
            ->route('logistics.manifests.receive.index', $manifest)
            ->with(
                'success',
                $result['completed']
                    ? 'All parcels received. Manifest closed.'
                    : 'Parcel received. The manifest stays open for the remaining parcels.',
            );
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
        return $manifest->items()
            ->where(function ($q): void {
                $q->whereNotNull('received_at')->orWhereNull('released_at');
            })
            ->with([
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
}
