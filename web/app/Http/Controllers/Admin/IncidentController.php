<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ParcelIncidentStatus;
use App\Enums\ParcelIncidentType;
use App\Http\Controllers\Controller;
use App\Models\Logistics\ParcelRouteRereoute;
use App\Models\Shared\Incidents;
use App\Services\Logistics\HubDelayService;
use App\Services\Logistics\ParcelIncidentService;
use App\Services\Logistics\ParcelLegService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class IncidentController extends Controller
{
    public function __construct(
        private readonly ParcelIncidentService $incident = new ParcelIncidentService,
    ) {}

    public function index(Request $request): View
    {
        $this->authorizeForUser($request->user('admin'), 'viewAny', Incidents::class);

        $incidents = Incidents::query()
            ->whereIn('status', [
                ParcelIncidentStatus::Open->value,
                ParcelIncidentStatus::UnderReview->value,
            ])
            ->whereIn('type', ParcelIncidentService::QUEUE_TYPES)
            ->with(['sellerOrder.seller', 'sellerOrder.order.buyer', 'events', 'sellerOrder.parcelRoute.destinationHub'])
            ->withExists(['events as recovery_escalated' => fn ($query) => $query->where('event', 'recovery_escalated')])
            ->withExists(['events as sla_assessment_due' => fn ($query) => $query
                ->where('event', 'hub_delay_level')->where('metadata->hours', 168)])
            ->orderByDesc('recovery_escalated')
            ->orderByDesc('sla_assessment_due')
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        return view('Admin.Incidents.index', ['incidents' => $incidents]);
    }

    public function show(Request $request, Incidents $incident): View
    {
        $this->authorizeForUser($request->user('admin'), 'view', $incident);

        $incident = $incident->loadMissing([
            'sellerOrder.seller',
            'sellerOrder.order.buyer',
            'events',
            'sellerOrder.parcelRoute.destinationHub',
            'sellerOrder.parcelRoute.originHub',
        ]);

        return view('Admin.Incidents.detail', [
            'incident' => $incident,
            'eventCounts' => $incident->eventCounts(),
            'lastVerifiedHub' => app(ParcelLegService::class)
                ->verifiedLocation($incident->sellerOrder),
            'rereoutes' => ParcelRouteRereoute::query()
                ->where('seller_order_id', $incident->incident_seller_order_id)
                ->latest('id')->get(),
        ]);
    }

    public function resolve(Request $request, Incidents $incident): RedirectResponse
    {
        $this->authorizeForUser($request->user('admin'), 'resolve', [$incident]);
        if ($incident->type === ParcelIncidentType::HubDelay->value) {
            $data = $request->validate([
                'action_owner' => ['required', 'string', 'max:120'],
                'review_note' => ['required', 'string', 'max:1000'],
            ]);
            app(HubDelayService::class)->recordReview($incident, $request->user('admin'), $data['action_owner'], $data['review_note']);

            return back()->with('success', 'Hub delay review and action owner recorded.');
        }

        $this->incident->resolve($incident, $request->user('admin'), [
            'event' => $request->input('resolution'),
            'reason' => $request->input('resolution_note'),
            'context' => is_array($request->input('resolution_context')) ? $request->input('resolution_context') : [],
        ]);

        return back()->with('success', 'Incident resolution recorded.');
    }

    public function reject(Request $request, Incidents $incident): RedirectResponse
    {
        $this->authorizeForUser($request->user('admin'), 'reject', [$incident]);

        $this->incident->resolve($incident, $request->user('admin'), [
            'event' => 'RESUME',
            'reason' => $request->input('resolution_note'),
        ]);

        return back()->with('success', 'Incident rejected. The delivery is unlocked.');
    }

    public function destroy(Request $request, Incidents $incident): RedirectResponse
    {
        $this->authorizeForUser($request->user('admin'), 'delete', [$incident]);

        abort(403, 'Incident history must be preserved.');

    }
}
