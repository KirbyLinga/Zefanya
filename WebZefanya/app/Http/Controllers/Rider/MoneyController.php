<?php

namespace App\Http\Controllers\Rider;

use App\Http\Controllers\Controller;
use App\Models\Rider\RiderEarning;
use App\Models\Rider\RiderPayout;
use App\Services\Logistics\LogisticsRemittanceService;
use App\Services\Rider\RiderCashService;
use App\Services\Rider\RiderEarningService;
use App\Services\Rider\RiderOperationsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/** Money page: cash the rider holds (and can mark as handed over), cash confirmed, and pay earned. */
class MoneyController extends Controller
{
    public function index(Request $request, RiderOperationsService $operations, RiderCashService $cash, RiderEarningService $pay): View
    {
        $rider = $request->user('rider');
        $courier = $operations->operationalCourier($rider, allowWindDown: true);
        $rider->loadMissing('provider');

        return view('Rider.Money.index', [
            'rider' => $rider,
            'cash' => $cash->summaryFor($courier),
            'holding' => $cash->holdingFor($courier),
            'handedOver' => $cash->handedOverFor($courier),
            'cutoff' => $cash->overdueCutoff(),
            'overdueDays' => LogisticsRemittanceService::OVERDUE_AFTER_DAYS,
            'pay' => $pay->summaryFor($courier),
            'earnings' => RiderEarning::query()->where('courier_id', $courier->getKey())
                ->with(['sellerOrder:id,tracking_number', 'payout:id,reference,paid_on'])
                ->latest('id')->paginate(10, ['*'], 'earnings_page')->withQueryString(),
            'payouts' => RiderPayout::query()->where('courier_id', $courier->getKey())
                ->with('reversal')->latest('id')->limit(10)->get(),
            'handoverSignal' => $cash->handoverSignalAvailable(),
        ]);
    }

    /** Rider marks selected cash as handed over. A signal only; Logistics confirms the amount. */
    public function declareHandover(Request $request, RiderOperationsService $operations, RiderCashService $cash): RedirectResponse
    {
        $data = $request->validate([
            'remittances' => ['required', 'array', 'min:1', 'max:100'],
            'remittances.*' => ['integer', 'min:1'],
        ], [
            'remittances.required' => 'Choose the cash you handed over.',
        ]);

        $courier = $operations->operationalCourier($request->user('rider'), allowWindDown: true);
        $marked = $cash->declareHandover($courier, $data['remittances']);
        $company = $request->user('rider')->provider?->business_name ?? 'your Logistics company';

        return back()->with($marked > 0 ? 'success' : 'warning', $marked > 0
            ? "Marked {$marked} ".Str::plural('parcel', $marked)." as handed over. {$company} will confirm the amount they received."
            : 'Nothing to mark. The cash may already be marked or confirmed.');
    }
}
