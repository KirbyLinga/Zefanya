<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Seller\SellerPayoutRequest;
use App\Services\Seller\SellerPayoutRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SellerPayoutController extends Controller
{
    public function index(): View
    {
        return view('Admin.SellerPayouts.index', [
            'waiting' => SellerPayoutRequest::query()->where('status', SellerPayoutRequest::PENDING)
                ->with(['seller:id,business_name', 'allocations.settlement.sellerOrder:id,tracking_number'])
                ->orderBy('id')->get(),
            'history' => SellerPayoutRequest::query()->whereIn('status', [SellerPayoutRequest::PAID, SellerPayoutRequest::REJECTED])
                ->with(['seller:id,business_name', 'decidedBy:id,name'])
                ->latest('id')->paginate(25),
        ]);
    }

    public function pay(Request $request, SellerPayoutRequest $payout, SellerPayoutRequestService $payouts): RedirectResponse
    {
        $data = $request->validate([
            'reference' => ['required', 'string', 'max:255'],
        ], [
            'reference.required' => 'Enter the bank or GCash reference for the money you sent.',
        ]);

        $paid = $payouts->pay($payout, $request->user('admin'), $data['reference']);

        return redirect()->route('admin.seller-payouts.index')->with(
            'success',
            $paid ? 'Payout recorded. The seller has been told.' : 'This payout was already recorded.',
        );
    }

    public function reject(Request $request, SellerPayoutRequest $payout, SellerPayoutRequestService $payouts): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ], [
            'reason.required' => 'Say why this request is being rejected.',
        ]);

        $payouts->reject($payout, $request->user('admin'), $data['reason']);

        return redirect()->route('admin.seller-payouts.index')
            ->with('success', 'Request rejected. The earnings are available to the seller again.');
    }
}
