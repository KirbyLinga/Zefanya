<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\ParcelLossCase;
use App\Services\Logistics\ParcelHandoffVerification;
use App\Services\Logistics\ParcelLossService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * A Logistics company's lost parcels: what it owes Sellers for parcels Admin
 * confirmed lost in its custody, and the verified scan that records a lost
 * parcel found at one of its hubs. Recording a find never reopens the order.
 */
class LostParcelController extends Controller
{
    public function __construct(private readonly ParcelLossService $losses) {}

    public function index(Request $request): View
    {
        abort_unless(ParcelLossService::available(), 404);
        $provider = $this->provider($request);
        $cases = $this->losses->forProvider($provider);

        return view('Logistics.LostParcels.index', [
            'cases' => $cases,
            'owedMinor' => (int) $cases->where('compensation_status', '!=', ParcelLossCase::PAID)->sum('merchandise_minor'),
            'paidMinor' => (int) $cases->where('compensation_status', ParcelLossCase::PAID)->sum(fn (ParcelLossCase $c): int => $c->netCompensationMinor()),
            'hubs' => LogisticsHub::query()->where('logistics_provider_id', $provider->id)->operational()->orderBy('name')->get(['id', 'name']),
            'canRecover' => fn (ParcelLossCase $case): bool => $case->recovered_at === null && $this->losses->canRecover($case, $provider),
        ]);
    }

    /** Issues the single-use scan token for recording a found parcel at one of this company's hubs. */
    public function verifyScan(Request $request, ParcelLossCase $case): JsonResponse
    {
        abort_unless(ParcelLossService::available(), 404);
        $provider = $this->provider($request);
        abort_unless($this->losses->canRecover($case, $provider), 404);

        $data = $request->validate([
            'tracking_number' => ['required', 'string', 'max:64'],
            'hub' => ['required', 'integer'],
            'method' => ['nullable', Rule::in([ParcelHandoffVerification::METHOD_CAMERA, ParcelHandoffVerification::METHOD_MANUAL])],
        ]);

        $parcel = $case->sellerOrder()->firstOrFail();
        try {
            if ((string) $parcel->tracking_number !== trim((string) $data['tracking_number'])) {
                throw ValidationException::withMessages(['tracking_number' => 'That tracking number does not match this lost parcel.']);
            }
            $hub = LogisticsHub::query()->whereKey((int) $data['hub'])->where('logistics_provider_id', $provider->id)->first();
            if ($hub === null || ! $hub->isOperational()) {
                throw ValidationException::withMessages(['hub' => 'Choose one of your own operational hubs.']);
            }
            $token = app(ParcelHandoffVerification::class)->issueForLogistics(
                $provider, $parcel, ParcelHandoffVerification::ACTION_LOSS_RECOVER,
                (string) ($data['method'] ?? ParcelHandoffVerification::METHOD_MANUAL), (int) $hub->id,
            );
        } catch (ValidationException $e) {
            return response()->json(['message' => (string) collect($e->errors())->flatten()->first(), 'errors' => $e->errors()], 422);
        }

        return response()->json([
            'context' => 'loss_recover',
            'parcel' => ['id' => $parcel->id, 'tracking_number' => $parcel->tracking_number, 'status' => $parcel->status?->value, 'status_label' => $parcel->status?->label()],
            'actions' => [[
                'key' => ParcelHandoffVerification::ACTION_LOSS_RECOVER,
                'label' => 'Record found at '.$hub->name,
                'method' => 'POST',
                'url' => route('logistics.lost-parcels.recover', ['case' => $case, 'hub' => $hub->id]),
                'confirm_message' => 'Confirm this parcel is physically at '.$hub->name.'? The order stays closed as lost and Admin decides what happens next.',
                'verification_token' => $token,
            ]],
            'verification_token' => $token,
        ]);
    }

    public function recover(Request $request, ParcelLossCase $case): RedirectResponse
    {
        abort_unless(ParcelLossService::available(), 404);
        $provider = $this->provider($request);
        abort_unless($this->losses->canRecover($case, $provider), 404);

        $data = $request->validate([
            'verification_token' => ['required', 'string', 'max:128'],
            'hub' => ['required', 'integer'],
        ]);
        $recovered = $this->losses->recordRecovery($case, $provider, (int) $data['hub'], (string) $data['verification_token']);

        return redirect()->route('logistics.lost-parcels.index')
            ->with('success', 'Recorded: parcel '.($recovered->sellerOrder?->tracking_number ?? '#'.$recovered->seller_order_id).' was found. Its order stays closed as lost. Admin has been told and will decide what happens to it.');
    }

    private function provider(Request $request): LogisticsProvider
    {
        return $request->user('logistics');
    }
}
