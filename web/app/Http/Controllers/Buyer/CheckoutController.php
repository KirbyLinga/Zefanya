<?php

namespace App\Http\Controllers\Buyer;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\CheckoutException;
use App\Http\Controllers\Controller;
use App\Models\Buyer\Buyer;
use App\Models\CartItem;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SellerOrder;
use App\Models\Voucher;
use App\Services\CartSummary;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        // Authoritative money for the order — computed from DB rows only.
        $summary = new CartSummary($items, $voucher, null);

        try {
            $order = $this->placeOrder($buyer, $items, $summary);
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
     * The single transactional unit: order + seller_orders + order_items +
     * payments + locked stock decrement + inventory ledger. Any failure rolls
     * everything back — no partial orders, no phantom stock changes.
     *
     * @param  Collection<int, CartItem>  $items  The buyer's validated selection
     */
    private function placeOrder(Buyer $buyer, Collection $items, CartSummary $summary): Order
    {
        return DB::transaction(function () use ($buyer, $items, $summary): Order {
            // Lock the product rows in a stable order (id order avoids
            // deadlocks between concurrent checkouts) and re-validate stock
            // UNDER THE LOCK — the pre-transaction check is advisory; this is
            // the authoritative gate.
            $lockedProducts = Product::query()
                ->whereIn('id', $items->pluck('product_id')->unique()->values())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($items as $item) {
                $product = $lockedProducts->get($item->product_id);

                if ($product === null || $product->trashed()
                    || $product->status !== 'active'
                    || $product->stock_quantity < $item->quantity) {
                    throw new CheckoutException(
                        'Some items just went out of stock while placing your order. Please review your cart and try again.'
                    );
                }
            }

            $order = new Order;
            $order->forceFill([
                'buyer_id' => $buyer->id,
                'reference' => Order::generateUniqueReference(),
                'shipping_address' => $this->addressSnapshot($buyer),
                'subtotal_minor' => $summary->subtotalCentavos,
                'discount_minor' => $summary->discountCentavos,
                'total_minor' => $summary->totalCentavos,
                'status' => OrderStatus::Placed,
            ])->save();

            // One seller_order per shop; the order-level voucher discount is
            // allocated proportionally so the per-shop COD payments sum to the
            // discounted order total (largest-remainder method).
            $discounts = $this->allocateDiscount(
                $summary->discountCentavos,
                $summary->sellerGroups
                    ->map(fn (array $group): int => (int) $group['items']->sum(
                        fn (CartItem $item): int => $item->lineTotalCentavos(),
                    ))
                    ->all(),
            );

            $sellerOrderIdsByCartItemId = [];

            foreach ($summary->sellerGroups as $index => $group) {
                $sellerOrder = new SellerOrder;
                $sellerOrder->forceFill([
                    'order_id' => $order->id,
                    'seller_id' => $group['seller']->id,
                    'subtotal_minor' => (int) $group['items']->sum(
                        fn (CartItem $item): int => $item->lineTotalCentavos(),
                    ),
                    'status' => OrderStatus::Placed,
                ])->save();

                foreach ($group['items'] as $item) {
                    /** @var Product $product */
                    $product = $item->product;

                    OrderItem::query()->create([
                        'seller_order_id' => $sellerOrder->id,
                        'product_id' => $product->id,
                        'product_name' => $product->name,
                        'quantity' => $item->quantity,
                        // Price snapshot at order time (CartSummary uses the
                        // same source, so totals and lines always agree).
                        'price_minor' => (int) bcmul((string) $product->price, '100', 0),
                    ]);

                    $sellerOrderIdsByCartItemId[$item->id] = $sellerOrder->id;
                }

                $payment = new Payment;
                $payment->forceFill([
                    'seller_order_id' => $sellerOrder->id,
                    'amount_minor' => $sellerOrder->subtotal_minor - $discounts[$index],
                    'method' => PaymentMethod::Cod,
                    'status' => PaymentStatus::Pending,
                ])->save();
            }

            // Decrement stock + write the append-only ledger, still under the
            // same row locks, inside the same transaction.
            foreach ($items as $item) {
                /** @var Product $product */
                $product = $lockedProducts->get($item->product_id);
                $before = (int) $product->stock_quantity;

                $product->stock_quantity = $before - $item->quantity;
                $product->save();

                InventoryTransaction::query()->create([
                    'product_id' => $product->id,
                    'seller_order_id' => $sellerOrderIdsByCartItemId[$item->id],
                    'quantity_before' => $before,
                    'quantity_after' => $product->stock_quantity,
                    'delta' => -$item->quantity,
                    'reason' => 'checkout',
                ]);
            }

            return $order;
        });
    }

    /**
     * Split an order-level voucher discount across seller orders proportionally
     * to their subtotals (largest-remainder method), so the per-shop COD
     * payments sum exactly to the discounted order total.
     *
     * @param  array<int, int>  $subtotals  Centavos per seller group, insertion order preserved
     * @return array<int, int> Discount in centavos per seller group, same keys
     */
    private function allocateDiscount(int $discount, array $subtotals): array
    {
        $total = array_sum($subtotals);

        if ($total <= 0 || $discount <= 0) {
            return array_map(fn (): int => 0, $subtotals);
        }

        $allocated = [];
        $remainders = [];

        foreach ($subtotals as $key => $subtotal) {
            $exact = ($discount * $subtotal) / $total;
            $allocated[$key] = (int) floor($exact);
            $remainders[$key] = $exact - $allocated[$key];
        }

        // Hand the leftover centavos to the largest fractional remainders
        // (floor() can leave at most one centavo per group behind).
        $left = $discount - array_sum($allocated);
        arsort($remainders);

        foreach (array_keys($remainders) as $key) {
            if ($left <= 0) {
                break;
            }

            $allocated[$key]++;
            $left--;
        }

        return $allocated;
    }

    /**
     * Immutable snapshot of the buyer's profile address at order time
     * (orders.shipping_address is JSON, deliberately not a live FK).
     *
     * @return array<string, string|null>
     */
    private function addressSnapshot(Buyer $buyer): array
    {
        $parts = array_filter([
            $buyer->house_number,
            $buyer->street,
            $buyer->barangay_name,
            $buyer->municipality_name,
            $buyer->province_name,
            $buyer->address_detail,
        ]);

        return [
            'name' => $buyer->fullName(),
            'phone' => $buyer->contact_no,
            'province' => $buyer->province_name,
            'municipality' => $buyer->municipality_name,
            'barangay' => $buyer->barangay_name,
            'street' => $buyer->street,
            'house_number' => $buyer->house_number,
            'address_detail' => $buyer->address_detail,
            'full' => implode(', ', $parts),
        ];
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
