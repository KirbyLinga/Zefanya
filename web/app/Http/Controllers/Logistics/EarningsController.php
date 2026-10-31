<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Models\Admin\PlatformSetting;
use App\Models\Logistics\CodRemittance;
use App\Models\Logistics\LogisticsRemittance;
use App\Models\Seller\StoreSale;
use App\Services\Logistics\LogisticsRemittanceService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EarningsController extends Controller
{
    public function index(Request $request, LogisticsRemittanceService $remittances): View
    {
        $provider = $request->user('logistics');
        $parcels = CodRemittance::query()->where('cod_remittances.logistics_provider_id', $provider->getKey());

        return view('Logistics.Earnings.index', [
            'totals' => $remittances->totals($provider),
            'readyParcels' => $remittances->readyParcels($provider),
            'paymentDetails' => PlatformSetting::query()->where('key', 'remittance_payment_details')->value('value'),
            'earnedThisMonthMinor' => (int) (clone $parcels)
                ->join('seller_orders', 'seller_orders.id', '=', 'cod_remittances.seller_order_id')
                ->where('cod_remittances.created_at', '>=', Carbon::now(StoreSale::TIMEZONE)->startOfMonth()->utc())
                ->sum('seller_orders.shipping_fee_minor'),
            'parcels' => (clone $parcels)->with('sellerOrder:id,tracking_number,shipping_fee_minor', 'sellerOrder.parcelReturn')
                ->latest('id')->paginate(25, ['*'], 'parcels_page')->withQueryString(),
            'payments' => LogisticsRemittance::query()->where('logistics_provider_id', $provider->getKey())
                ->latest('id')->paginate(25, ['*'], 'payments_page')->withQueryString(),
        ]);
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
