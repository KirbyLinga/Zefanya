<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Models\Admin\PlatformSetting;
use App\Models\Logistics\CodRemittance;
use App\Models\Logistics\LogisticsRemittance;
use App\Models\Logistics\LogisticsReturnCharge;
use App\Models\Rider\Courier;
use App\Models\Rider\RiderPayout;
use App\Models\Seller\StoreSale;
use App\Services\Logistics\LogisticsFinanceService;
use App\Services\Logistics\LogisticsRemittanceService;
use App\Services\Logistics\ShippingRateService;
use App\Services\Rider\RiderEarningService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class EarningsController extends Controller
{
    public function index(Request $request, LogisticsRemittanceService $remittances, RiderEarningService $riderPay, LogisticsFinanceService $finance, ShippingRateService $rates): View
    {
        $provider = $request->user('logistics');
        $tab = $request->query('tab') === 'shipping-rates' ? 'shipping-rates' : 'overview';
        $parcels = CodRemittance::query()->where('cod_remittances.logistics_provider_id', $provider->getKey());

        return view('Logistics.Earnings.index', [
            'tab' => $tab,
            'zones' => $tab === 'shipping-rates' ? $rates->zonesFor($provider) : collect(),
            'totals' => $remittances->totals($provider),
            'statement' => $finance->statement($provider),
            'readyParcels' => $remittances->readyParcels($provider),
            'originShares' => $remittances->originShares($provider),
            'riderUnpaid' => $riderPay->unpaidByCourier($provider),
            'riderPaidThisMonthMinor' => $riderPay->paidThisMonthMinor($provider),
            'riderPayouts' => RiderPayout::query()->where('logistics_provider_id', $provider->getKey())
                ->with(['courier:id,name', 'reversal'])->latest('id')->paginate(20, ['*'], 'rider_payouts_page')->withQueryString(),
            'returnCharges' => LogisticsReturnCharge::query()->where('logistics_provider_id', $provider->getKey())
                ->with('sellerOrder:id,tracking_number')->latest('id')->limit(50)->get(),
            'paymentDetails' => PlatformSetting::query()->where('key', 'remittance_payment_details')->value('value'),
            'earnedThisMonthMinor' => (int) (clone $parcels)
                ->join('seller_orders', 'seller_orders.id', '=', 'cod_remittances.seller_order_id')
                ->where('cod_remittances.created_at', '>=', Carbon::now(StoreSale::TIMEZONE)->startOfMonth()->utc())
                ->sum(DB::raw(CodRemittance::KEPT_SHIPPING_SQL)),
            'parcels' => (clone $parcels)->with('sellerOrder:id,tracking_number,shipping_fee_minor,carrier_provider_id', 'sellerOrder.parcelReturn')
                ->latest('id')->paginate(25, ['*'], 'parcels_page')->withQueryString(),
            'payments' => LogisticsRemittance::query()->where('logistics_provider_id', $provider->getKey())
                ->latest('id')->paginate(25, ['*'], 'payments_page')->withQueryString(),
        ]);
    }

    public function payRider(Request $request, Courier $courier, RiderEarningService $riderPay): RedirectResponse
    {
        $data = $request->validate([
            'through_id' => ['required', 'integer', 'min:1'],
            'expected_amount_minor' => ['required', 'integer', 'min:1'],
            'reference' => ['required', 'string', 'max:100'],
            // "Today" is the Philippine date the form defaults to, not the server clock's date.
            'paid_on' => ['required', 'date', 'before_or_equal:'.Carbon::now(StoreSale::TIMEZONE)->toDateString()],
        ], [
            'reference.required' => 'Enter the bank, GCash or cash voucher reference for this payment.',
            'paid_on.before_or_equal' => 'The payment date cannot be in the future.',
        ]);

        $payout = $riderPay->payCourier(
            $request->user('logistics'),
            $courier,
            (int) $data['through_id'],
            (int) $data['expected_amount_minor'],
            $data['reference'],
            $data['paid_on'],
        );

        return redirect()->route('logistics.earnings.index')
            ->with('success', 'Recorded ₱'.number_format($payout->amount_minor / 100, 2).' paid to '.$courier->name.'.');
    }

    public function reverseRiderPayment(Request $request, RiderPayout $payout, RiderEarningService $riderPay): RedirectResponse
    {
        abort_unless((int) $payout->logistics_provider_id === (int) $request->user('logistics')->getKey(), 404);
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'confirm_reversal' => ['accepted'],
        ]);
        $riderPay->reversePayout($request->user('logistics'), $payout, $data['reason']);

        return to_route('logistics.earnings.index')->with('success', 'Payment record reversed. Its jobs are unpaid again; the original payment remains in history.');
    }

    public function remit(Request $request, LogisticsRemittanceService $remittances): RedirectResponse
    {
        $data = $request->validate([
            'parcels' => ['required', 'array', 'min:1'],
            'parcels.*' => ['integer'],
            'expected_amount_minor' => ['required', 'integer', 'min:0'],
            'reference' => ['required', 'string', 'max:100'],
        ], [
            'parcels.required' => 'Choose at least one parcel this payment covers.',
            'reference.required' => 'Enter the bank or GCash reference for the money you sent.',
        ]);

        $remittances->submit(
            $request->user('logistics'),
            array_map('intval', $data['parcels']),
            (int) $data['expected_amount_minor'],
            $data['reference'],
        );

        return redirect()->route('logistics.earnings.index')
            ->with('success', 'Payment recorded. Zefanya will confirm once the money arrives.');
    }
}
