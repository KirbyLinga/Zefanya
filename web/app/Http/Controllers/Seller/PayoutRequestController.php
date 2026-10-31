<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Seller\SellerPayoutRequest;
use App\Services\Seller\SellerPayoutRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class PayoutRequestController extends Controller
{
    public function index(Request $request, SellerPayoutRequestService $service): View
    {
        $seller = $request->user('seller');
        $oldKey = $request->old('idempotency_key');

        return view('Seller.PayoutRequests.index', [
            'availableMinor' => $service->availableBalance($seller),
            'pending' => SellerPayoutRequest::query()->where('seller_id', $seller->id)
                ->where('status', SellerPayoutRequest::PENDING)->first(),
            'payouts' => SellerPayoutRequest::query()->where('seller_id', $seller->id)->latest('id')->paginate(20),
            'idempotencyKey' => is_string($oldKey) && Str::isUuid($oldKey) ? $oldKey : (string) Str::uuid(),
        ]);
    }

    public function store(Request $request, SellerPayoutRequestService $service): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'destination' => ['required', 'string', 'max:255'],
        ], [
            'destination.required' => 'Enter the bank or GCash account for this payout.',
        ]);

        $payout = $service->request($request->user('seller'), $request->input('amount'), $request->input('idempotency_key'), $data['destination']);

        return $request->expectsJson()
            ? response()->json($payout, $payout->wasRecentlyCreated ? 201 : 200)
            : to_route('seller.payout-requests.index')->with('success', 'Payout request recorded.');
    }

    public function destroy(Request $request, int $payoutRequest, SellerPayoutRequestService $service): JsonResponse|RedirectResponse
    {
        $payout = $service->cancel($request->user('seller'), $payoutRequest);

        return $request->expectsJson()
            ? response()->json($payout)
            : to_route('seller.payout-requests.index')->with('success', 'Payout request cancelled. Reserved earnings are available again.');
    }
}
