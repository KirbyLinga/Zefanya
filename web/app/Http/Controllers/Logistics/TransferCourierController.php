<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\TransferCourier;
use App\Models\Logistics\TransferCourierAssignment;
use App\Services\Logistics\CourierAccountService;
use App\Services\Logistics\TransferCourierService;
use App\Support\CourierAccess;
use App\Support\CourierAppAccessPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Courier Management: the transport personnel records a Logistics company
 * keeps for transfer batches and handoffs. Records only (no accounts).
 */
class TransferCourierController extends Controller
{
    public function __construct(
        private readonly TransferCourierService $couriers,
        private readonly CourierAccountService $appAccess,
    ) {}

    public function index(Request $request): View
    {
        $provider = $this->provider($request);
        abort_unless(TransferCourierService::available(), 404);

        $couriers = $this->couriers->couriersFor($provider);

        return view('Logistics.TransferCouriers.index', [
            'couriers' => $couriers,
            'recent' => TransferCourierAssignment::query()->where('logistics_provider_id', $provider->id)
                ->with(['courier', 'manifest.originHub', 'manifest.destinationHub', 'handoff.sendingHub', 'handoff.receivingHub'])
                ->latest('assigned_at')->latest('id')->limit(15)->get(),
            'editing' => $request->integer('edit') ?: null,
            // Courier Mobile app access: shown only once its pending table exists.
            'appAccessReady' => CourierAccess::schemaReady(),
            'appAccessEnabled' => CourierAccess::enabled(),
            'appAccess' => CourierAppAccessPresenter::forProvider($provider, $couriers),
        ]);
    }

    /**
     * Issue (or reissue) Courier Mobile app access. The Logistics account
     * re-enters its password first. The login ID and one-time code are shown
     * once on this response and never stored in plain text, the session, a
     * URL or a log.
     */
    public function issueAppAccess(Request $request, TransferCourier $transferCourier): Response
    {
        abort_unless(CourierAccess::enabled(), 404);
        $provider = $this->provider($request);
        abort_unless((int) $transferCourier->logistics_provider_id === (int) $provider->id, 404);
        $request->validate(['current_password' => ['required', 'string', 'current_password:logistics']], [
            'current_password.current_password' => 'The password is incorrect.',
        ]);

        [$account, $code] = $this->appAccess->issue($provider, $transferCourier);

        return response()
            ->view('Logistics.TransferCouriers.access-code', [
                'courier' => $transferCourier->fresh(),
                'loginId' => $account->login_id,
                'code' => trim(chunk_split($code, 5, '-'), '-'),
                'expiresAt' => $account->activation_expires_at,
            ])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, private, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
    }

    /** Revoke Courier Mobile app access now. Allowed while the Courier carries a transfer. */
    public function revokeAppAccess(Request $request, TransferCourier $transferCourier): RedirectResponse
    {
        abort_unless(CourierAccess::schemaReady(), 404);
        $provider = $this->provider($request);

        $revoked = $this->appAccess->revoke($provider, $transferCourier, 'Revoked by Logistics');

        return redirect()->route('logistics.transfer-couriers.index')->with($revoked ? 'success' : 'warning', $revoked
            ? $transferCourier->name.' can no longer sign in to the Courier app. Their transfers are unchanged.'
            : $transferCourier->name.' has no active Courier app access.');
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
