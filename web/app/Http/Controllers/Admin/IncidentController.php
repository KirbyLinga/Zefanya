<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ParcelIncidentStatus;
use App\Http\Controllers\Controller;
use App\Models\Shared\Incidents;
use App\Services\Logistics\ParcelIncidentService;
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
            ->where('status', ParcelIncidentStatus::Open->value)
            ->whereIn('type', ['lost', 'damaged', 'LOST', 'DAMAGED'])
            ->with(['sellerOrder.seller', 'sellerOrder.order.buyer', 'events'])
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        return view('Admin.Incidents.index', ['incidents' => $incidents]);
    }

    public function show(Request $request, Incidents $incident): View
    {
        $this->authorizeForUser($request->user('admin'), 'view', $incident);

        return view('Admin.Incidents.detail', [
            'incident' => $incident->loadMissing(['sellerOrder.seller', 'sellerOrder.order.buyer', 'events']),
            'eventCounts' => $incident->eventCounts(),
        ]);
    }

    public function resolve(Request $request, Incidents $incident): RedirectResponse
    {
        $this->authorizeForUser($request->user('admin'), 'resolve', [$incident]);

        $this->incident->resolve($incident, $request->user('admin'), [
            'event' => $request->input('resolution'),
            'reason' => $request->input('resolution_note'),
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
