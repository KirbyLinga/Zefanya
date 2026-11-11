<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\FinanceReportService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Read-only monthly finance report. Money is never moved here: payouts and
 * remittance confirmations stay on the Settlements pages.
 */
class FinanceController extends Controller
{
    public function __construct(private readonly FinanceReportService $finance) {}

    public function index(Request $request): View
    {
        $period = FinanceReportService::period($request->query('month'));
        $type = $this->type($request->query('type'));
        $ledger = $this->finance->ledger($period);
        $filtered = $type ? $ledger->where('type', $type)->values() : $ledger;

        $page = LengthAwarePaginator::resolveCurrentPage();
        $entries = new LengthAwarePaginator($filtered->forPage($page, 25)->values(), $filtered->count(), 25, $page, [
            'path' => $request->url(),
            'query' => $request->query(),
        ]);

        return view('Admin.Finance.index', [
            'period' => $period,
            'periodOptions' => FinanceReportService::periodOptions(),
            'summary' => $this->finance->summary($period),
            'methods' => $this->finance->paymentMethods($period),
            'trend' => $this->finance->trend($period),
            'totals' => $this->finance->totals($ledger),
            'entries' => $entries,
            'type' => $type,
            'types' => FinanceReportService::TYPES,
        ]);
    }

    /** CSV of the month's summary and ledger (respecting the type filter), for accounting. */
    public function export(Request $request): StreamedResponse
    {
        $period = FinanceReportService::period($request->query('month'));
        $type = $this->type($request->query('type'));
        $ledger = $this->finance->ledger($period);
        $summary = $this->finance->summary($period);
        $totals = $this->finance->totals($ledger);
        $rows = $type ? $ledger->where('type', $type)->values() : $ledger;

        return response()->streamDownload(function () use ($period, $summary, $totals, $rows): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows the peso sign.
            $this->writeSummary($out, $period->format('F Y'), $summary, $totals);
            $this->writeLedger($out, $rows);
            fclose($out);
        }, 'zefanya-finance-'.$period->format('Y-m').($type ? '-'.$type : '').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    private function type(mixed $type): ?string
    {
        return is_string($type) && array_key_exists($type, FinanceReportService::TYPES) ? $type : null;
    }

    /**
     * @param  resource  $out
     * @param  array<string, int>  $summary
     * @param  array<string, int>  $totals
     */
    private function writeSummary($out, string $month, array $summary, array $totals): void
    {
        $amount = fn (int $minor): string => number_format($minor / 100, 2, '.', '');
        $this->csv($out, ['Zefanya finance report', $month]);
        $this->csv($out, []);
        foreach ([
            ['Finalized orders', (string) $summary['finalizedOrders']],
            ['Item sales', $amount($summary['itemSalesMinor'])],
            ['Shipping fees', $amount($summary['shippingFeesMinor'])],
            ['Seller earnings', $amount($summary['sellerNetMinor'])],
            ['Commission', $amount($summary['commissionMinor'])],
            ['Platform vouchers (marketplace-funded)', $amount($summary['platformVoucherMinor'])],
            ['Shipping vouchers (marketplace-funded)', $amount($summary['shippingVoucherMinor'])],
            ['Net platform earnings', $amount($summary['netMinor'])],
        ] as $line) {
            $this->csv($out, $line);
        }
        foreach (FinanceReportService::DIRECTIONS as $key => $label) {
            $this->csv($out, [$label, $amount($totals[$key])]);
        }
        $this->csv($out, []);
    }

    /**
     * @param  resource  $out
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    private function writeLedger($out, Collection $rows): void
    {
        $this->csv($out, ['Date', 'Type', 'Direction', 'Reference', 'Party', 'Method', 'Amount (PHP)', 'Status']);
        foreach ($rows as $row) {
            $this->csv($out, [
                $row['at']->format('Y-m-d H:i'),
                $row['typeLabel'],
                $row['directionLabel'],
                $row['reference'],
                $row['party'],
                $row['method'],
                number_format($row['amountMinor'] / 100, 2, '.', ''),
                $row['status'],
            ]);
        }
    }

    /**
     * Writes one CSV line. Text cells that a spreadsheet would run as a formula
     * (e.g. a seller name or reference starting with "=") are prefixed with a quote.
     *
     * @param  resource  $out
     * @param  list<string>  $cells
     */
    private function csv($out, array $cells): void
    {
        fputcsv($out, array_map(
            fn (string $cell): string => preg_match('/^[=+\-@\t\r]/', $cell) && ! is_numeric($cell) ? "'".$cell : $cell,
            $cells,
        ), escape: '');
    }
}
