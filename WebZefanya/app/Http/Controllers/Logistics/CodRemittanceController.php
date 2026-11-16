<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Models\Logistics\CodRemittance;
use App\Models\Rider\Rider;
use App\Services\Logistics\CodRemittanceService;
use App\Services\Logistics\LogisticsRemittanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CodRemittanceController extends Controller
{
    public function index(Request $request): View
    {
        $provider = $request->user('logistics');
        $ledger = CodRemittance::query()
            ->where('logistics_provider_id', $provider->getKey());
        $confirmedCash = 'CASE WHEN status IN (?, ?) THEN COALESCE(received_amount_minor, 0) ELSE 0 END';
        $cashStatuses = [CodRemittance::REMITTED_TO_LOGISTICS, CodRemittance::PARTIALLY_REMITTED];
        $riderReconciliation = (clone $ledger)
            ->select('courier_id')
            ->selectRaw('SUM(amount_minor) AS collected_minor')
            ->selectRaw("SUM({$confirmedCash}) AS remitted_minor", $cashStatuses)
            ->selectRaw("SUM(amount_minor) - SUM({$confirmedCash}) AS difference_minor", $cashStatuses)
            ->groupBy('courier_id')
            ->with('courier.rider')
            ->orderByDesc('difference_minor')->orderBy('courier_id')
            ->paginate(20, ['*'], 'riders_page')->withQueryString();
        $reconciliationTotals = (clone $ledger)
            ->selectRaw('COALESCE(SUM(amount_minor), 0) AS collected_minor')
            ->selectRaw("COALESCE(SUM({$confirmedCash}), 0) AS remitted_minor", $cashStatuses)
            ->first();

        $remittances = (clone $ledger)
            ->where('status', CodRemittance::PENDING_REMITTANCE)
            ->with(['courier.rider', 'sellerOrder'])
            ->orderBy('id')
            ->paginate(20)->withQueryString();

        $shortages = (clone $ledger)
            ->where('status', CodRemittance::PARTIALLY_REMITTED)
            ->with(['courier.rider', 'shortageRider', 'sellerOrder'])
            ->orderBy('id')
            ->get();

        $riders = Rider::query()
            ->where('logistics_provider_id', $provider->getKey())
            ->orderBy('first_name')->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name']);

        $overdueDays = LogisticsRemittanceService::OVERDUE_AFTER_DAYS;
        $overdueCutoff = now()->subDays($overdueDays);
        $overdueCount = (clone $ledger)->where('status', CodRemittance::PENDING_REMITTANCE)
            ->where('created_at', '<', $overdueCutoff)->count();

        return view('Logistics.CodRemittances.index', compact('remittances', 'shortages', 'riders', 'riderReconciliation', 'reconciliationTotals', 'overdueDays', 'overdueCutoff', 'overdueCount'));
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
            'shortage_rider_id' => ['nullable', 'integer'],
            'shortage_reason' => ['nullable', 'string', 'max:1000'],
        ], [
            'received_amount.required' => 'Enter the cash amount physically received.',
            'received_amount.regex' => 'Enter a non-negative peso amount with at most two decimal places.',
        ]);

        // Convert pesos to centavos using integers, matching delivery collection.
        [$pesos, $centavos] = array_pad(explode('.', $data['received_amount'], 2), 2, '0');
        $receivedAmountMinor = ((int) $pesos) * 100 + (int) str_pad($centavos, 2, '0');

        $confirmed = $service->confirmReceipt(
            $remittance,
            $provider,
            $receivedAmountMinor,
            isset($data['shortage_rider_id']) ? (int) $data['shortage_rider_id'] : null,
            $data['shortage_reason'] ?? null,
        );

        $short = $receivedAmountMinor < (int) $remittance->amount_minor;

        return redirect()->route('logistics.cod-remittances.index')->with(
            'success',
            ! $confirmed ? 'This cash receipt was already confirmed.'
                : ($short ? 'Cash receipt confirmed with a shortage. The missing amount stays outstanding until recovered.' : 'Cash receipt confirmed.'),
        );
    }

    public function recoverShortage(
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
            'recovered_amount' => ['required', 'string', 'regex:/\A\d{1,9}(?:\.\d{1,2})?\z/'],
            'reference' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
        ], [
            'recovered_amount.required' => 'Enter the cash recovered from the Rider.',
            'recovered_amount.regex' => 'Enter a peso amount with at most two decimal places.',
            'reference.required' => 'A recovery reference of at most 255 characters is required.',
        ]);

        [$pesos, $centavos] = array_pad(explode('.', $data['recovered_amount'], 2), 2, '0');
        $service->recordShortageRecovery(
            $remittance,
            $provider,
            ((int) $pesos) * 100 + (int) str_pad($centavos, 2, '0'),
            $data['reference'],
            $data['note'] ?? null,
        );

        return redirect()->route('logistics.cod-remittances.index')
            ->with('success', 'Shortage recovery recorded. The missing amount is now smaller.');
    }
}
