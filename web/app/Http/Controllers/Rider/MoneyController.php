<?php

namespace App\Http\Controllers\Rider;

use App\Http\Controllers\Controller;
use App\Models\Rider\RiderEarning;
use App\Models\Rider\RiderPayout;
use App\Services\Logistics\LogisticsRemittanceService;
use App\Services\Rider\RiderCashService;
use App\Services\Rider\RiderEarningService;
use App\Services\Rider\RiderOperationsService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Read-only money page: cash the rider holds, cash handed over, and pay earned. */
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
        ]);
    }
}
