<?php

namespace App\Http\Controllers\Api\Cart;

use App\Http\Controllers\Controller;
use App\Http\Resources\VoucherResource;
use App\Models\Voucher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * `GET /api/vouchers` — the vouchers a buyer may choose from.
 *
 * Only the ones a person could actually use are listed: active, unexpired, and
 * under their usage limit. `Voucher::rejectionReason()` is the single place that
 * decides this for a *given subtotal*, and it cannot be reused here because a
 * catalogue read has no subtotal to test the minimum spend against — so this
 * applies the three subtotal-independent rules and leaves the minimum spend to be
 * checked when a voucher is actually applied. The app renders a voucher's minimum
 * spend rather than enforcing it, so the split costs nothing.
 *
 * The list is public for the same reason the catalogue is: it is shop-window
 * content, and the web's cart page sits behind a buyer session only because a
 * cart does.
 */
class VoucherController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $vouchers = Voucher::query()
            ->where('is_active', true)
            ->where(fn (Builder $q) => $q
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', now()))
            ->where(fn (Builder $q) => $q
                ->whereNull('usage_limit')
                ->orWhereColumn('times_used', '<', 'usage_limit'))
            ->orderBy('id')
            ->get();

        return VoucherResource::collection($vouchers);
    }
}
