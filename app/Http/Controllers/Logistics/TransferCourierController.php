<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\TransferCourier;
use App\Models\Logistics\TransferCourierAssignment;
use App\Services\Logistics\TransferCourierService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Courier Management: the transport personnel records a Logistics company
 * keeps for transfer batches and handoffs. Records only (no accounts).
 */
class TransferCourierController extends Controller
{
    public function __construct(private readonly TransferCourierService $couriers) {}

    public function index(Request $request): View
    {
        $provider = $this->provider($request);
        abort_unless(TransferCourierService::available(), 404);

        return view('Logistics.TransferCouriers.index', [
            'couriers' => $this->couriers->couriersFor($provider),
            'recent' => TransferCourierAssignment::query()->where('logistics_provider_id', $provider->id)
                ->with(['courier', 'manifest.originHub', 'manifest.destinationHub', 'handoff.sendingHub', 'handoff.receivingHub'])
                ->latest('assigned_at')->latest('id')->limit(15)->get(),
            'editing' => $request->integer('edit') ?: null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $courier = $this->couriers->save($this->provider($request), $request->only(['name', 'contact_no', 'vehicle_type', 'plate_number']));

        return redirect()->route('logistics.transfer-couriers.index')->with('success', $courier->name.' added to your Couriers.');
    }

    public function update(Request $request, TransferCourier $transferCourier): RedirectResponse
    {
        $courier = $this->couriers->save($this->provider($request), $request->only(['name', 'contact_no', 'vehicle_type', 'plate_number']), $transferCourier);

        return redirect()->route('logistics.transfer-couriers.index')->with('success', $courier->name.' updated.');
    }

    public function status(Request $request, TransferCourier $transferCourier): RedirectResponse
    {
        $data = $request->validate(['active' => ['required', 'boolean']]);
        $courier = $this->couriers->setActive($this->provider($request), $transferCourier, (bool) $data['active']);

        return redirect()->route('logistics.transfer-couriers.index')
            ->with('success', $courier->name.($courier->isActive() ? ' is active again.' : ' is now inactive and cannot be assigned.'));
    }

    private function provider(Request $request): LogisticsProvider
    {
        return $request->user('logistics');
    }
}
