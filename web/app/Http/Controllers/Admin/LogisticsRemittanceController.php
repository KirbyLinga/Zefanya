<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Logistics\CodRemittance;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\LogisticsRemittance;
use App\Models\Logistics\OriginSharePayout;
use App\Models\Seller\SellerPayoutRequest;
use App\Models\Seller\StoreSale;
use App\Services\Logistics\LogisticsRemittanceService;
use App\Services\Seller\SellerSettlementService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LogisticsRemittanceController extends Controller
{
    public function index(LogisticsRemittanceService $remittances, SellerSettlementService $settlements): View
    {
        return view('Admin.LogisticsRemittances.index', [
            'waiting' => LogisticsRemittance::query()->where('status', LogisticsRemittance::SUBMITTED)
                ->with(['provider:id,business_name', 'codRemittances.sellerOrder:id,tracking_number,shipping_fee_minor,carrier_provider_id', 'codRemittances.sellerOrder.parcelReturn'])
                ->orderBy('id')->get(),
            'unsent' => $remittances->unsentByProvider(),
            'overdueDays' => LogisticsRemittanceService::OVERDUE_AFTER_DAYS,
            'subsidy' => $settlements->subsidySummary(),
            'historical' => $remittances->historicalToReconcile(),
            'destinationOwed' => $remittances->destinationOwed(),
            'originShares' => $remittances->originShares(),
            'originOwed' => $remittances->originOwedByProvider(),
            'originPayouts' => OriginSharePayout::query()->with(['provider:id,business_name', 'paidBy:id,name', 'reversal'])
                ->latest('id')->paginate(20, ['*'], 'origin_payouts_page')->withQueryString(),
            'payoutsWaiting' => SellerPayoutRequest::query()->where('status', SellerPayoutRequest::PENDING)->count(),
            'history' => LogisticsRemittance::query()->where('status', '!=', LogisticsRemittance::SUBMITTED)
                ->with(['provider:id,business_name', 'decidedBy:id,name'])
                ->latest('id')->paginate(25),
        ]);
    }

    public function confirm(Request $request, LogisticsRemittance $remittance, LogisticsRemittanceService $remittances): RedirectResponse
    {
        $data = $request->validate([
            'received_amount' => ['required', 'string', 'regex:/\A\d{1,9}(?:\.\d{1,2})?\z/'],
            'parcels' => ['nullable', 'array'],
            'parcels.*' => ['integer', 'min:1'],
        ], [
            'received_amount.required' => 'Enter the amount that actually arrived.',
            'received_amount.regex' => 'Enter a peso amount with at most two decimal places.',
        ]);

        // Pesos to centavos using integers, matching COD remittances.
        [$pesos, $centavos] = array_pad(explode('.', $data['received_amount'], 2), 2, '0');
        $receivedMinor = ((int) $pesos) * 100 + (int) str_pad($centavos, 2, '0');

        $accepted = isset($data['parcels']) ? array_map('intval', $data['parcels']) : null;
        $confirmed = $remittances->confirm($remittance, $request->user('admin'), $receivedMinor, $accepted);

        return redirect()->route('admin.logistics-remittances.index')->with(
            'success',
            $confirmed ? 'Payment confirmed. Sellers on the confirmed parcels can now be paid.' : 'This payment was already confirmed.',
        );
    }

    public function reconcileHistorical(Request $request, CodRemittance $parcel, LogisticsRemittanceService $remittances): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:allocated,kept']]);
        $remittances->reconcileHistorical($request->user('admin'), $parcel, $data['decision']);

        return redirect()->route('admin.logistics-remittances.index')->with(
            'success',
            $data['decision'] === LogisticsRemittanceService::RECONCILE_ALLOCATE
                ? 'Fee split 40/60. The origin carrier\'s share can now be paid below.'
                : 'The shipping fee stays with Zefanya.',
        );
    }

    public function payDestination(Request $request, CodRemittance $parcel, LogisticsRemittanceService $remittances): RedirectResponse
    {
        $data = $request->validate([
            'reference' => ['required', 'string', 'max:100'],
            // "Today" is the Philippine date the form defaults to, not the server clock's date.
            'paid_on' => ['required', 'date', 'before_or_equal:'.Carbon::now(StoreSale::TIMEZONE)->toDateString()],
        ], [
            'reference.required' => 'Enter the bank or GCash reference for the money you sent.',
            'paid_on.before_or_equal' => 'The payment date cannot be in the future.',
        ]);
        $remittances->payDestinationShare($request->user('admin'), $parcel, $data['reference'], $data['paid_on']);

        return redirect()->route('admin.logistics-remittances.index')->with('success', 'Payment to the delivering company recorded.');
    }

    public function payOrigin(Request $request, LogisticsProvider $provider, LogisticsRemittanceService $remittances): RedirectResponse
    {
        $data = $request->validate([
            'through_id' => ['required', 'integer', 'min:1'],
            'expected_amount_minor' => ['required', 'integer', 'min:1'],
            'reference' => ['required', 'string', 'max:100'],
            // "Today" is the Philippine date the form defaults to, not the server clock's date.
            'paid_on' => ['required', 'date', 'before_or_equal:'.Carbon::now(StoreSale::TIMEZONE)->toDateString()],
        ], [
            'reference.required' => 'Enter the bank or GCash reference for the money you sent.',
            'paid_on.before_or_equal' => 'The payment date cannot be in the future.',
        ]);

        $payout = $remittances->payOriginShare(
            $request->user('admin'),
            $provider,
            (int) $data['through_id'],
            (int) $data['expected_amount_minor'],
            $data['reference'],
            $data['paid_on'],
        );

        return redirect()->route('admin.logistics-remittances.index')
            ->with('success', 'Recorded ₱'.number_format($payout->amount_minor / 100, 2).' paid to '.$provider->business_name.'.');
    }

    public function reverseOriginPayment(Request $request, OriginSharePayout $payout, LogisticsRemittanceService $remittances): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'confirm_reversal' => ['accepted'],
        ]);
        $remittances->reverseOriginPayout($request->user('admin'), $payout, $data['reason']);

        return to_route('admin.logistics-remittances.index')->with('success', 'Payment record reversed. Its shipping shares are unpaid again; the original payment remains in history.');
    }

    public function reject(Request $request, LogisticsRemittance $remittance, LogisticsRemittanceService $remittances): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ], [
            'reason.required' => 'Say why this payment is being rejected.',
        ]);

        $remittances->reject($remittance, $request->user('admin'), $data['reason']);

        return redirect()->route('admin.logistics-remittances.index')
            ->with('success', 'Payment rejected. Its parcels are due from the company again.');
    }
}
