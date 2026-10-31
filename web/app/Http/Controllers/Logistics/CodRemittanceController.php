<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Models\Logistics\CodRemittance;
use App\Services\Logistics\CodRemittanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CodRemittanceController extends Controller
{
    public function index(Request $request): View
    {
        $ledger = CodRemittance::query()
            ->where('logistics_provider_id', $request->user('logistics')->getKey());
        $confirmedCash = 'CASE WHEN status = ? THEN COALESCE(received_amount_minor, 0) ELSE 0 END';
        $riderReconciliation = (clone $ledger)
            ->select('courier_id')
            ->selectRaw('SUM(amount_minor) AS collected_minor')
            ->selectRaw("SUM({$confirmedCash}) AS remitted_minor", [CodRemittance::REMITTED_TO_LOGISTICS])
            ->selectRaw("SUM(amount_minor) - SUM({$confirmedCash}) AS difference_minor", [CodRemittance::REMITTED_TO_LOGISTICS])
            ->groupBy('courier_id')
            ->with('courier.rider')
            ->orderByDesc('difference_minor')->orderBy('courier_id')
            ->paginate(20, ['*'], 'riders_page')->withQueryString();
        $reconciliationTotals = (clone $ledger)
            ->selectRaw('COALESCE(SUM(amount_minor), 0) AS collected_minor')
            ->selectRaw("COALESCE(SUM({$confirmedCash}), 0) AS remitted_minor", [CodRemittance::REMITTED_TO_LOGISTICS])
            ->first();

        $remittances = (clone $ledger)
            ->where('status', CodRemittance::PENDING_REMITTANCE)
            ->with(['courier.rider', 'sellerOrder'])
            ->orderBy('id')
            ->paginate(20)->withQueryString();

        return view('Logistics.CodRemittances.index', compact('remittances', 'riderReconciliation', 'reconciliationTotals'));
    }

    public function confirm(
        Request $request,
        CodRemittance $remittance,
        CodRemittanceService $service,
    ): RedirectResponse {
        $provider = $request->user('logistics');

        abort_unless(
            (int) $remittance->logistics_provider_id === (int) $provider->getKey(),
            403,
            'This remittance belongs to another Logistics provider.',
        );

        $data = $request->validate([
            'received_amount' => ['required', 'string', 'regex:/\A\d{1,9}(?:\.\d{1,2})?\z/'],
        ], [
            'received_amount.required' => 'Enter the cash amount physically received.',
            'received_amount.regex' => 'Enter a non-negative peso amount with at most two decimal places.',
        ]);

        // Convert pesos to centavos using integers, matching delivery collection.
        [$pesos, $centavos] = array_pad(explode('.', $data['received_amount'], 2), 2, '0');
        $receivedAmountMinor = ((int) $pesos) * 100 + (int) str_pad($centavos, 2, '0');

        $confirmed = $service->confirmReceipt($remittance, $provider, $receivedAmountMinor);

        return redirect()->route('logistics.cod-remittances.index')->with(
            'success',
            $confirmed ? 'Cash receipt confirmed.' : 'This cash receipt was already confirmed.',
        );
    }
}
