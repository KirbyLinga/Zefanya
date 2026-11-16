<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Shared\Voucher;
use App\Models\Shared\VoucherClaim;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class VoucherWalletController extends Controller
{
    public function index(Request $request): View
    {
        $claims = VoucherClaim::query()
            ->where('buyer_id', $request->user('buyer')->id)
            ->with('voucher.seller')
            ->latest('id')
            ->paginate(20);

        return view('Buyer.Vouchers.index', compact('claims'));
    }

    /** Only what is on offer right now can be claimed; claiming twice changes nothing. */
    public function claim(Request $request, Voucher $voucher): RedirectResponse
    {
        $buyerId = (int) $request->user('buyer')->id;

        // The voucher lock keeps a claim from landing just after the Seller ends it.
        DB::transaction(function () use ($voucher, $buyerId): void {
            $locked = Voucher::query()->whereKey($voucher->id)->lockForUpdate()->firstOrFail();

            abort_unless(
                Voucher::offeredBy([(int) $locked->seller_id], $buyerId)->contains('id', $locked->id),
                404,
            );

            $locked->claims()->firstOrCreate(['buyer_id' => $buyerId]);
        });

        return back()->with('success', "{$voucher->code} is in your vouchers.");
    }
}
