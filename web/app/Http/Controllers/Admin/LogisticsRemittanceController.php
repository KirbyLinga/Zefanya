<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Logistics\LogisticsRemittance;
use App\Models\Seller\SellerPayoutRequest;
use App\Services\Logistics\LogisticsRemittanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LogisticsRemittanceController extends Controller
{
    public function index(LogisticsRemittanceService $remittances): View
    {
        return view('Admin.LogisticsRemittances.index', [
            'waiting' => LogisticsRemittance::query()->where('status', LogisticsRemittance::SUBMITTED)
                ->with(['provider:id,business_name', 'codRemittances.sellerOrder:id,tracking_number,shipping_fee_minor', 'codRemittances.sellerOrder.parcelReturn'])
                ->orderBy('id')->get(),
            'unsent' => $remittances->unsentByProvider(),
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
        ], [
            'received_amount.required' => 'Enter the amount that actually arrived.',
            'received_amount.regex' => 'Enter a peso amount with at most two decimal places.',
        ]);

        // Pesos to centavos using integers, matching COD remittances.
        [$pesos, $centavos] = array_pad(explode('.', $data['received_amount'], 2), 2, '0');
        $receivedMinor = ((int) $pesos) * 100 + (int) str_pad($centavos, 2, '0');

        $confirmed = $remittances->confirm($remittance, $request->user('admin'), $receivedMinor);

        return redirect()->route('admin.logistics-remittances.index')->with(
            'success',
            $confirmed ? 'Payment confirmed. Sellers on these parcels can now be paid.' : 'This payment was already confirmed.',
        );
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
