<?php

namespace App\Http\Controllers\Logistics;

use App\Enums\ParcelIncidentStatus;
use App\Enums\ParcelIncidentType;
use App\Http\Controllers\Controller;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\ParcelRouteRereoute;
use App\Models\Shared\Incidents;
use App\Models\Shared\SellerOrder;
use App\Services\Logistics\ParcelIncidentService;
use App\Services\Logistics\ParcelLegService;
use App\Services\Logistics\RouteExecutionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Provider-scoped exception queue for the existing logistics panel.
 *
 * Uses the shared incidents/incident_events records and the existing route,
 * manifest and handoff systems. It never changes parcel status, custody, COD or
 * payment records; resolution only authorizes movement or a reroute.
 */
class ExceptionsController extends Controller
{
    public function __construct(
        private readonly ParcelIncidentService $incidents,
        private readonly RouteExecutionService $routes,
    ) {}

    public function index(Request $request): View
    {
        $provider = $this->provider($request);
        $request->validate([
            'status' => ['nullable', Rule::in(['active', 'open', 'under_review', 'resolved', 'all'])],
            'type' => ['nullable', Rule::in(ParcelIncidentService::ACTIVE_TYPES)],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $status = (string) $request->query('status', 'active');
        $search = trim((string) $request->query('search', ''));

        $exceptions = Incidents::query()
            ->whereIn('type', ParcelIncidentService::ACTIVE_TYPES)
            ->whereHas('sellerOrder', fn ($parcels) => $parcels->visibleToProvider((int) $provider->id))
            ->when($status === 'active', fn ($query) => $query->where(function ($active): void {
                $active->whereIn('status', [ParcelIncidentStatus::Open->value, ParcelIncidentStatus::UnderReview->value])
                    ->orWhere('resolution', ParcelIncidentService::HOLD);
            }))
            ->when(in_array($status, ['open', 'under_review', 'resolved'], true), fn ($query) => $query->where('status', $status))
            ->when($request->filled('type'), fn ($query) => $query->where('type', $request->query('type')))
            ->when($search !== '', function ($query) use ($search): void {
                $query->whereHas('sellerOrder', function ($parcels) use ($search): void {
                    $parcels->whereLike('tracking_number', '%'.$search.'%');
                    if (ctype_digit(ltrim($search, '#'))) {
                        $parcels->orWhereKey((int) ltrim($search, '#'));
                    }
                });
            })
            ->with(['sellerOrder.order.buyer', 'sellerOrder.seller', 'events'])
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('Logistics.Exceptions.index', [
            'exceptions' => $exceptions,
            'status' => $status,
            'types' => ParcelIncidentType::labels(),
        ]);
    }

    public function show(Request $request, Incidents $incident): View
    {
        $provider = $this->provider($request);
        $this->assertVisible($incident, (int) $provider->id);

        $incident->loadMissing([
            'sellerOrder.order.buyer',
            'sellerOrder.seller',
            'sellerOrder.parcelRoute.originHub',
            'sellerOrder.parcelRoute.destinationHub',
            'events',
        ]);

        $sellerOrder = $incident->sellerOrder;
        $route = $sellerOrder->parcelRoute;

        return view('Logistics.Exceptions.show', [
            'incident' => $incident,
            'sellerOrder' => $sellerOrder,
            'route' => $route,
            'lastVerifiedHub' => app(ParcelLegService::class)->verifiedLocation($sellerOrder),
            'rereoutes' => ParcelRouteRereoute::query()
                ->where('seller_order_id', $sellerOrder->id)
                ->latest('id')
                ->get(),
            'reroutePreview' => (int) session('reroute_preview.incident_id') === (int) $incident->id ? session('reroute_preview') : null,
            'custodyHistory' => collect(array_merge($sellerOrder->manifestMovementEvents(), $sellerOrder->trackingEvents()))->sortBy('at')->values(),
            'reportTypes' => [
                ParcelIncidentType::Missing->value => ParcelIncidentType::Missing->label(),
                ParcelIncidentType::Damaged->value => ParcelIncidentType::Damaged->label(),
                ParcelIncidentType::WrongHub->value => ParcelIncidentType::WrongHub->label(),
                ParcelIncidentType::InactiveConnection->value => ParcelIncidentType::InactiveConnection->label(),
                ParcelIncidentType::FailedTransfer->value => ParcelIncidentType::FailedTransfer->label(),
            ],
            'canResolve' => $this->providerCanManage($sellerOrder, (int) $provider->id)
                && ! in_array($incident->type, ['lost', 'damaged'], true),
        ]);
    }

    public function store(Request $request, SellerOrder $sellerOrder): RedirectResponse
    {
        $provider = $this->provider($request);
        $incident = $this->incidents->reportOperational($sellerOrder, $provider, $request->all());

        return redirect()->route('logistics.exceptions.show', $incident)
            ->with('success', 'Exception recorded. Movement is blocked until it is reviewed and resolved.');
    }

    public function review(Request $request, Incidents $incident): RedirectResponse
    {
        $provider = $this->provider($request);
        $this->assertVisible($incident, (int) $provider->id);
        $data = $request->validate([
            'review_note' => ['required', 'string', 'max:1000'],
        ]);

        $this->incidents->review($incident, $provider, $data['review_note']);

        return back()->with('success', 'Exception moved under review. The parcel remains blocked.');
    }

    public function workerRecovery(Request $request, Incidents $incident): RedirectResponse
    {
        $provider = $this->provider($request);
        $this->assertVisible($incident, (int) $provider->id);
        $data = $request->validate([
            'action' => ['required', 'in:contact,escalate,verify,receive,finish'],
            'note' => ['required_if:action,contact,escalate', 'nullable', 'string', 'max:1000'],
            'tracking_number' => ['required_if:action,verify', 'nullable', 'string', 'max:100'],
            'verification_token' => ['required_if:action,receive', 'nullable', 'string', 'max:128'],
            'physically_received' => ['exclude_unless:action,receive', 'required', 'accepted'],
        ]);
        if ($data['action'] === 'verify') {
            return back()->with('worker_receipt_token', [
                'case' => $incident->id,
            ] + $this->incidents->verifyWorkerStationReceipt($incident, $provider, $data['tracking_number']));
        }
        $message = match ($data['action']) {
            'contact', 'escalate' => $this->logWorkerContact($incident, $provider, $data['note'], $data['action'] === 'escalate'),
            'receive' => $this->recordWorkerReceipt($incident, $provider, $data['verification_token']),
            'finish' => $this->releaseWorkerParcel($incident, $provider),
        };

        return back()->with('success', $message);
    }

    private function logWorkerContact(Incidents $incident, LogisticsProvider $provider, string $note, bool $escalate): string
    {
        $this->incidents->recordWorkerContact($incident, $provider, $note, $escalate);

        return $escalate ? 'Escalation recorded for Admin review.' : 'Contact attempt recorded.';
    }

    private function recordWorkerReceipt(Incidents $incident, LogisticsProvider $provider, string $token): string
    {
        $this->incidents->receiveWorkerParcel($incident, $provider, $token);

        return 'Station receipt recorded. The parcel remains on hold until recovery is finished.';
    }

    private function releaseWorkerParcel(Incidents $incident, LogisticsProvider $provider): string
    {
        $this->incidents->finishWorkerRecovery($incident, $provider);

        return 'Worker recovery finished. The shipment shows its current station or Rider assignment.';
    }

    public function resolve(Request $request, Incidents $incident): RedirectResponse
    {
        $provider = $this->provider($request);
        $this->assertVisible($incident, (int) $provider->id);
        $data = $request->validate([
            'resolution' => ['required', Rule::in([
                ParcelIncidentService::RESUME,
                ParcelIncidentService::REROUTE,
                ParcelIncidentService::HOLD,
            ])],
            'resolution_note' => ['required', 'string', 'max:1000'],
        ]);

        try {
            if ($data['resolution'] === ParcelIncidentService::REROUTE) {
                $this->assertAlternativeExists($incident, $provider);
            }

            $this->incidents->resolveForProvider($incident, $provider, [
                'event' => $data['resolution'],
                'reason' => $data['resolution_note'],
            ]);
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors())->withInput();
        }

        $message = match ($data['resolution']) {
            ParcelIncidentService::RESUME => 'Original route resumed. Movement may continue through the saved legs.',
            ParcelIncidentService::REROUTE => 'Reroute authorized from the last verified hub. The destination and custody were not changed.',
            default => 'Parcel kept on hold. Movement remains blocked for this route.',
        };

        return back()->with('success', $message);
    }

    public function preview(Request $request, Incidents $incident): RedirectResponse
    {
        $provider = $this->provider($request);
        $this->assertVisible($incident, (int) $provider->id);

        try {
            $preview = $this->routes->previewReroute($incident->sellerOrder()->firstOrFail(), $provider);
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        return back()->with('reroute_preview', [
            'incident_id' => $incident->id,
            'verified_hub' => $preview['verified_hub']->name,
            'destination' => $preview['destination']->name,
            'description' => $preview['description'],
            'new_path' => $preview['future_path'],
        ]);
    }

    private function provider(Request $request): LogisticsProvider
    {
        $provider = $request->user('logistics');
        abort_unless($provider->isApproved() && $provider->archived_at === null, 403);

        return $provider;
    }

    private function assertAlternativeExists(Incidents $incident, $provider): void
    {
        $this->routes->previewReroute($incident->sellerOrder()->firstOrFail(), $provider);
    }

    private function assertVisible(Incidents $incident, int $providerId): void
    {
        abort_unless(
            Incidents::query()
                ->whereKey($incident->id)
                ->whereHas('sellerOrder', fn ($parcels) => $parcels->visibleToProvider($providerId))
                ->exists(),
            403,
            'This exception belongs to another provider.',
        );
    }

    private function providerCanManage(SellerOrder $order, int $providerId): bool
    {
        $custody = $order->currentCustodyProviderId();

        return ($custody !== null && (int) $custody === $providerId)
            || ($custody === null && (int) $order->carrier_provider_id === $providerId);
    }
}
