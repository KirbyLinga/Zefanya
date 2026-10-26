<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Models\CodRemittance;
use App\Services\CodRemittanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CodRemittanceController extends Controller
{
    public function index(Request $request): View
    {
        $remittances = CodRemittance::query()
            ->where('logistics_provider_id', $request->user('logistics')->getKey())
            ->where('status', CodRemittance::PENDING_REMITTANCE)
            ->with(['courier.rider', 'sellerOrder'])
            ->orderBy('id')
            ->paginate(20);

        return view('Logistics.CodRemittances.index', compact('remittances'));
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
