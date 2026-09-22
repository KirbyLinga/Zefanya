<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\CartItem;
use App\Models\Voucher;
use App\Services\CartSummary;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CheckoutController extends Controller
{
    /** Session key holding the ids checkout is allowed to see. */
    public const SESSION_KEY = 'checkout_item_ids';

    /**
     * Show checkout for the buyer's SELECTED cart items.
     *
     * Accepts `?items[]=…` from the cart page. Anything that is not one of the
     * buyer's own available cart lines is ignored, so a tampered id can never
     * expose another buyer's line. When nothing is passed the whole available
     * cart is used.
     *
     * NOTE: the checkout screen itself is still a stub — this only resolves and
     * parks the selection (query + session) so the UI phase can build on it.
     */
    public function index(Request $request): View
    {
        $buyer = auth('buyer')->user();

        /** @var Collection<int, CartItem> $items */
        $items = $buyer->cartItems()
            ->with(['product' => fn ($q) => $q->withTrashed()->with(['images', 'seller', 'category'])])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $available = $items
            ->filter(fn (CartItem $item): bool => $item->isAvailable())
            ->values();

        // null = "no selection sent" → CartSummary covers every available item.
        $selectedIds = null;

        if ($request->has('items')) {
            $requested = array_filter(
                array_map('intval', (array) $request->input('items', [])),
                fn (int $id): bool => $id > 0,
            );

            $selectedIds = $available->whereIn('id', $requested)->pluck('id')->all();
        }

        // Park the resolved selection for the future checkout.store step.
        session([self::SESSION_KEY => $selectedIds ?? $available->pluck('id')->all()]);

        $voucherNotice = null;
        $voucher = Voucher::resolveCode(
            session('cart_voucher'),
            (new CartSummary($items, null, $selectedIds))->subtotalCentavos,
            $voucherNotice,
        );

        $summary = new CartSummary($items, $voucher, $selectedIds);

        return view('Buyer.Checkout.index', [
            'items' => $available,
            'summary' => $summary,
            'voucher' => $voucher,
            'voucherNotice' => $voucherNotice,
        ]);
    }
}
