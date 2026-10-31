<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Seller\StoreSale;
use App\Services\Seller\SellerEarningsService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

class ReportController extends Controller
{
    public function index(Request $request, SellerEarningsService $earnings): View
    {
        $timezone = StoreSale::TIMEZONE;
        $validator = Validator::make($request->query(), [
            'from' => ['nullable', 'date', 'required_with:to'],
            'to' => ['nullable', 'date', 'required_with:from', 'after_or_equal:from'],
        ], [
            'from.required_with' => 'Choose both the start and end dates.',
            'to.required_with' => 'Choose both the start and end dates.',
            'to.after_or_equal' => 'The end date must be on or after the start date.',
        ]);

        if ($validator->fails()) {
            return view('Seller.Reports.index', $this->emptyReport() + [
                'from' => (string) $request->query('from', ''),
                'to' => (string) $request->query('to', ''),
            ])->withErrors($validator);
        }

        $data = $validator->validated();
        $fromDate = $data['from'] ?? now($timezone)->startOfMonth()->toDateString();
        $toDate = $data['to'] ?? now($timezone)->toDateString();
        $from = Carbon::parse($fromDate, $timezone)->startOfDay()->utc();
        $to = Carbon::parse($toDate, $timezone)->endOfDay()->utc();

        return view('Seller.Reports.index', $earnings->report($request->user('seller'), $from, $to) + [
            'from' => $fromDate,
            'to' => $toDate,
        ]);
    }

    /** @return array{salesMinor: int, grossMinor: int, storeSaleMinor: int, sellerVoucherMinor: int, commissionMinor: int, netMinor: int, platformVoucherMinor: int, shippingMinor: int, rows: Collection<int, mixed>} */
    private function emptyReport(): array
    {
        return [
            'salesMinor' => 0,
            'grossMinor' => 0,
            'storeSaleMinor' => 0,
            'sellerVoucherMinor' => 0,
            'commissionMinor' => 0,
            'netMinor' => 0,
            'platformVoucherMinor' => 0,
            'shippingMinor' => 0,
            'rows' => collect(),
        ];
    }
}
