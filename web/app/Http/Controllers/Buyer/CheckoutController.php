<?php

namespace App\Http\Controllers\Buyer;

use App\Exceptions\CheckoutException;
use App\Http\Controllers\Controller;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Voucher;
use App\Services\CartSummary;
use App\Services\OrderPlacer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CheckoutController extends Controller
{
    /** Session key holding the ids checkout is allowed to see. */
    public const SESSION_KEY = 'checkout_item_ids';

    /**
     * The placing rules live in `OrderPlacer`, shared with the app's door on the
     * same API: what the two doors can share is **lines**, not cart rows, because
     * this one's cart is server-side and the phone's is on the phone.
     */
    public function __construct(private readonly OrderPlacer $orderPlacer) {}

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

    /**
     * Place the order for the buyer's parked checkout selection.
     *
     * Everything authoritative is re-resolved server-side from the session +
     * database: the selection (parked by index()), the voucher code, and all
     * money via CartSummary. Nothing in the request body is trusted for
     * pricing. The whole write happens in one transaction with locked stock.
     */
    public function store(Request $request): RedirectResponse
    {
        $buyer = auth('buyer')->user();

        $parkedIds = array_values(array_unique(array_map('intval', (array) session(self::SESSION_KEY, []))));

        if ($parkedIds === []) {
            return redirect()->route('buyer.cart.index')
                ->with('error', 'Your checkout selection is empty. Please select items in your cart first.');
        }

        // Re-resolve from the LIVE cart: a parked id may have been removed in
        // another tab, and ownership is re-asserted by scoping to the buyer's
        // own cart rows (a tampered id can never check out someone else's line).
        /** @var Collection<int, CartItem> $items */
        $items = $buyer->cartItems()
            ->with(['product' => fn ($q) => $q->withTrashed()->with(['images', 'seller', 'category'])])
            ->whereIn('id', $parkedIds)
            ->get()
            ->keyBy('id');

        $problems = [];

        foreach ($parkedIds as $id) {
            $item = $items->get($id);

            if ($item === null) {
                $problems[] = 'an item no longer in your cart';

                continue;
            }

            $product = $item->product;

            if ($product === null || ! $item->isAvailable()) {
                $problems[] = $product?->name ?? 'an unavailable item';

                continue;
            }

            if ($item->quantity > $product->stock_quantity) {
                $problems[] = "{$product->name} (only {$product->stock_quantity} left)";
            }
        }

        if ($problems !== []) {
            return redirect()->route('buyer.cart.index')
                ->with('error', 'Some items are no longer available: '.implode(', ', array_unique($problems)).'. Please review your cart.');
        }

        // Voucher re-resolution identical to index(): resolved against the
        // selection's subtotal, dropped with a notice when invalid/expired.
        $notice = null;
        $base = new CartSummary($items, null, null);
        $voucher = Voucher::resolveCode(session('cart_voucher'), $base->subtotalCentavos, $notice);

        try {
            // Lines rather than cart rows: the service is shared with the API, whose
            // cart lives on the phone. The voucher is resolved above (a door's own
            // question) and every amount is computed inside, from the locked rows.
            $order = $this->orderPlacer->place(
                $buyer,
                $items
                    ->map(fn (CartItem $item): array => [
                        'product_id' => (int) $item->product_id,
                        'quantity' => (int) $item->quantity,
                    ])
                    ->values()
                    ->all(),
                $voucher,
            );
        } catch (CheckoutException $e) {
            return redirect()->route('buyer.cart.index')->with('error', $e->getMessage());
        }

        // Success: remove the purchased lines and clear the checkout/voucher
        // session state. Only the SELECTED ids are removed — anything else
        // left in the cart survives for the next checkout.
        $buyer->cartItems()->whereIn('id', $parkedIds)->delete();

        session()->forget([self::SESSION_KEY, 'cart_voucher']);

        $redirect = redirect()->route('buyer.checkout.success', $order);

        if ($notice !== null) {
            $redirect = $redirect->with('voucher_notice', $notice);
        }

        return $redirect;
    }

    /**
     * Minimal order-confirmation page (full tracking UI is Phase 3).
     */
    public function success(Request $request, Order $order): View
    {
        $buyer = auth('buyer')->user();

        abort_unless($order->buyer_id === $buyer->id, 404);

        $order->load(['sellerOrders.seller', 'sellerOrders.orderItems.product', 'sellerOrders.payment']);

        return view('Buyer.Checkout.success', ['order' => $order]);
    }
}
