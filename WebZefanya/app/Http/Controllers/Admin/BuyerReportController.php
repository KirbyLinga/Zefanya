<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Shared\BuyerConductReport;
use App\Models\Shared\BuyerConductReview;
use App\Services\Admin\BuyerConductReportService;
use App\Services\Admin\BuyerConductReviewService;
use App\Services\Buyer\BuyerCancellationHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class BuyerReportController extends Controller
{
    public function index(Request $request)
    {
        abort_unless(BuyerConductReport::available(), 503, 'Buyer reporting is awaiting the database update.');
        $data = $request->validate(['status' => ['nullable', Rule::in(['open', 'all'])]]);
        $status = $data['status'] ?? 'open';
        $reports = BuyerConductReport::query()->with(['seller', 'sellerOrder.order'])
            ->when($status === 'open', fn ($q) => $q->where('status', 'open'))
            ->latest('id')->paginate(20)->withQueryString();

        return response()->view('Admin.BuyerReports.index', compact('reports', 'status'))->header('Cache-Control', 'no-store, private');
    }

    public function show(BuyerConductReport $report, BuyerCancellationHistory $history)
    {
        $report->load(['seller', 'sellerOrder.order']);
        $cancellations = $history->query((int) $report->buyer_id)->with('sellerOrder.order')->latest('id')->paginate(15);
        $orderCount = $history->orderCount((int) $report->buyer_id);
        // Once conduct review is installed, the report's decision is recorded there.
        $conductReview = BuyerConductReview::available() ? app(BuyerConductReviewService::class)->syncReport($report) : null;

        return response()->view('Admin.BuyerReports.show', compact('report', 'cancellations', 'orderCount', 'conductReview'))->header('Cache-Control', 'no-store, private');
    }

    public function decide(Request $request, BuyerConductReport $report, BuyerConductReportService $service)
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(array_keys(BuyerConductReport::DECISIONS))],
            'decision_note' => ['required', 'string', 'min:10', 'max:2000'],
        ]);
        $service->decide($report, $request->user('admin'), $data['decision'], $data['decision_note']);

        return back()->with('success', 'Review recorded. This report does not change the Buyer account or order.');
    }

    public function evidence(BuyerConductReport $report, int $index)
    {
        $path = $report->evidence[$index] ?? null;
        abort_unless(is_string($path) && str_starts_with($path, 'buyer-reports/'.$report->seller_order_id.'/'), 404);
        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, [
            'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
        ]);
    }
}
