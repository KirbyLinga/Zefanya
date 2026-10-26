<?php

namespace App\Http\Controllers\Buyer;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\CheckoutException;
use App\Http\Controllers\Controller;
use App\Models\Buyer\Buyer;
use App\Models\CartItem;
use App\Models\CheckoutToken;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Seller\Seller;
use App\Models\SellerOrder;
use App\Models\Voucher;
use App\Notifications\Seller\NewOrderReceived;
use App\Services\CartSummary;
use App\Services\CartVariantResolver;
use App\Services\CheckoutCarrierService;
use App\Services\PurchasedVariantSnapshot;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CheckoutController extends Controller
{
    /** Session key holding the ids checkout is allowed to see. */
    public const SESSION_KEY = 'checkout_item_ids';

    /** Session key for the one-time checkout form token. */
    public const TOKEN_KEY = 'checkout_form_token';

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
    public function index(Request $request): View|RedirectResponse
    {
        $buyer = auth('buyer')->user();

        /** @var Collection<int, CartItem> $items */
        $items = $buyer->cartItems()
            ->with(['product' => fn ($q) => $q->withTrashed()->with(['images', 'seller', 'category']), 'variant.optionValues.option'])
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

            // The buyer (or a stale/tampered link) asked for specific items but
            // NONE of them resolved to this buyer's available cart lines — the
            // page would otherwise render a dead-end ₱0.00 checkout. Send them
            // back to the cart with an explanation instead.
            if ($requested !== [] && $selectedIds === []) {
                return redirect()->route('buyer.cart.index')
                    ->with('error', 'The items you selected are no longer in your cart. Please review your cart and try again.');
            }
        }

        // Park the resolved selection for the future checkout.store step.
        session([self::SESSION_KEY => $selectedIds ?? $available->pluck('id')->all()]);

        // One-time form token against double-submits: stored in the session
        // AND the checkout_tokens table; placeOrder() consumes it inside the
        // order transaction, so a double-clicked second POST is rejected.
        if (! Schema::hasTable('checkout_tokens')) {
            return redirect()->route('buyer.cart.index')->with('error', 'Checkout is temporarily unavailable. Please try again later.');
        }
        $checkoutToken = CheckoutToken::issue($buyer->id);
        session([self::TOKEN_KEY => $checkoutToken->token]);

        $voucherNotice = null;
        $voucher = Voucher::resolveCode(
            session('cart_voucher'),
            (new CartSummary($items, null, $selectedIds))->subtotalCentavos,
            $voucherNotice,
        );

        $summary = new CartSummary($items, $voucher, $selectedIds);
        $carrierService = app(CheckoutCarrierService::class);
        $carrierOptions = $carrierService->optionsForCheckout($buyer, $summary->sellerGroups);
        $carriersAvailable = $carrierService->allRoutesAvailable($carrierOptions);

        return view('Buyer.Checkout.index', [
            'buyer' => $buyer,
            'items' => $available,
            'summary' => $summary,
            'voucher' => $voucher,
            'voucherNotice' => $voucherNotice,
            'variantLabels' => $summary->availableItems->mapWithKeys(fn (CartItem $item): array => [
                $item->id => $item->variant?->optionValues
                    ->sortBy(fn ($value) => $value->option->sort_order)
                    ->map(fn ($value): string => $value->option->name.': '.$value->value)
                    ->implode(' | ') ?? '',
            ]),
            'checkoutToken' => $checkoutToken->token,
            'carrierOptions' => $carrierOptions,
            'carriersAvailable' => $carriersAvailable,
            'merchandiseTotalMinor' => $summary->totalCentavos,
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

        // The form token from the rendered checkout page. Consumed INSIDE the
        // order transaction (see placeOrder) so only the first of two racing
        // requests creates an order.
        $formToken = is_string($request->input('checkout_token')) ? $request->input('checkout_token') : null;

        // Re-resolve from the LIVE cart: a parked id may have been removed in
        // another tab, and ownership is re-asserted by scoping to the buyer's
        // own cart rows (a tampered id can never check out someone else's line).
        /** @var Collection<int, CartItem> $items */
        $items = $buyer->cartItems()
            ->with(['product' => fn ($q) => $q->withTrashed()->with(['images', 'seller', 'category']), 'variant'])
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
                $problems[] = $item->requiresVariantReselection()
                    ? ($product?->name.' (choose a color or size on the product page first)')
                    : ($product?->name ?? 'an unavailable item');

                continue;
            }

            if ($item->quantity > $item->availableQuantity()) {
                $problems[] = "{$product->name} (only {$item->availableQuantity()} left)";
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

        // Payment method chosen at checkout. An unknown/absent value falls back
        // to COD, so a missing field never places an order with no method. Only
        // COD is integrated; the rest are recorded as Pending (see the enum).
        $paymentMethod = PaymentMethod::tryFrom((string) $request->input('payment_method', ''))
            ?? PaymentMethod::Cod;

        // Revalidate carrier eligibility and server fees before entering checkout.
        $checkoutReturn = route('buyer.checkout.index', ['items' => $parkedIds]);

        if ($request->filled('shipping_fee') || $request->filled('shipping_fees') || $request->filled('shipping_total')) {
            return redirect()->to($checkoutReturn)
                ->withInput()
                ->withErrors(['shipping' => 'Shipping fees are calculated by the server and cannot be submitted.']);
        }

        try {
            $carrierQuote = app(CheckoutCarrierService::class)->validateSelection(
                $buyer,
                $summary->sellerGroups,
                (array) $request->input('carriers', []),
            );
        } catch (ValidationException $e) {
            return redirect()->to($checkoutReturn)
                ->withInput()
                ->withErrors($e->errors())
                ->with('error', 'Shipping is not available for one or more shops in your order. Review carrier options or your delivery address.');
        }

        session([CheckoutCarrierService::SESSION_KEY => $carrierQuote['selections']]);

        try {
            $order = $this->placeOrder($buyer, $items, $summary, $formToken, $paymentMethod, $carrierQuote['selections']);
        } catch (CheckoutException $e) {
            return redirect()->route('buyer.cart.index')->with('error', $e->getMessage());
        }

        // Let each shop know a new order came in (in-app notification).
        $order->loadMissing('sellerOrders.seller');
        foreach ($order->sellerOrders as $sellerOrder) {
            $sellerOrder->seller?->notify(new NewOrderReceived($sellerOrder));
        }

        // Success: remove the purchased lines and clear the checkout/voucher
        // session state. Only the SELECTED ids are removed — anything else
        // left in the cart survives for the next checkout.
        $buyer->cartItems()->whereIn('id', $parkedIds)->delete();

        session()->forget([self::SESSION_KEY, 'cart_voucher', self::TOKEN_KEY, CheckoutCarrierService::SESSION_KEY]);

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
    private function placeOrder(Buyer $buyer, Collection $items, CartSummary $summary, ?string $formToken = null, PaymentMethod $paymentMethod = PaymentMethod::Cod, array $carrierSelections = []): Order
    {
        return DB::transaction(function () use ($buyer, $items, $summary, $formToken, $paymentMethod, $carrierSelections): Order {
            // ONE-TIME TOKEN GUARD (double-submit): claim the token first,
            // inside this transaction, via a conditional UPDATE. On a
            // double-click the second request finds used=1 already and gets
            // bounced — no second order, no double stock decrement.
            //
            // Never bypass the guard for an empty token or an unready schema.
            if (! Schema::hasTable('checkout_tokens')) {
                throw new CheckoutException('Checkout is temporarily unavailable. Please try again later.');
            }
            if (! CheckoutToken::claim($buyer->id, $formToken)) {
                throw new CheckoutException('Your checkout session is invalid, expired or already used. Please open checkout again.');
            }

            // Lock the product AND variant rows in a stable order (id order
            // avoids deadlocks between concurrent checkouts) and re-validate
            // stock UNDER THE LOCK — the pre-transaction check is advisory;
            // this is the authoritative gate.
            $lockedProducts = Product::query()
                ->whereIn('id', $items->pluck('product_id')->unique()->values())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $lockedSellers = Seller::query()
                ->whereIn('id', $lockedProducts->pluck('seller_id')->unique())
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($lockedProducts as $lockedProduct) {
                $lockedProduct->setRelation('seller', $lockedSellers->get($lockedProduct->seller_id));
                if (! $lockedProduct->isListedForSale()) {
                    throw new CheckoutException('A seller is no longer available. Please review your cart and try again.');
                }
            }

            $lockedVariants = ProductVariant::query()
                ->whereIn('product_id', $lockedProducts->keys())
                ->with('optionValues.option')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($lockedProducts as $lockedProduct) {
                $lockedProduct->setRelation('variants', $lockedVariants->where('product_id', $lockedProduct->id)->values());
            }

            foreach ($items as $item) {
                $product = $lockedProducts->get($item->product_id);
                if ($product === null) {
                    throw new CheckoutException('A product is no longer available. Please review your cart.');
                }
                try {
                    $variant = app(CartVariantResolver::class)->resolve($product, $item->variant_id);
                } catch (ValidationException $exception) {
                    throw new CheckoutException(collect($exception->errors())->flatten()->implode(' '));
                }

                // Authoritative gate: ONLY the locked rows count. $item->variant
                // and $item->product still hold the copies loaded before the
                // lock, and a concurrent checkout can have moved the stock since
                // — trusting those stale copies is what let two buyers both pass.
                if ($product === null || ! $product->isListedForSale()
                    || $variant === null
                    || ! $variant->is_available
                    || $variant->stock_quantity < $item->quantity) {
                    throw new CheckoutException(
                        'Some items just went out of stock while placing your order. Please review your cart and try again.'
                    );
                }

                $lockedPrice = (int) bcmul((string) $variant->price, '100', 0);
                if ($item->unitPriceCentavos() !== $lockedPrice) {
                    throw new CheckoutException('A product price changed while placing your order. Please review your cart and confirm the updated total.');
                }

                // Every accepted snapshot and total uses these same locked rows.
                $item->setRelation('product', $product);
                $item->setRelation('variant', $variant);
            }

            foreach ($items->groupBy(fn (CartItem $item) => $item->variant->id) as $lines) {
                if ($lines->sum('quantity') > $lines->first()->variant->stock_quantity) {
                    throw new CheckoutException('There is not enough stock for the selected items. Please review your cart.');
                }
            }

            $base = new CartSummary($items);
            $voucherCode = session('cart_voucher', $summary->voucher?->code);
            $voucher = $voucherCode ? Voucher::query()
                ->where('code', strtoupper(trim($voucherCode)))
                ->lockForUpdate()->first() : null;
            if ($voucher !== null && $voucher->rejectionReason($base->subtotalCentavos) !== null) {
                $voucher = null;
            }
            $lockedSummary = new CartSummary($items, $voucher);
            if ($lockedSummary->discountCentavos !== $summary->discountCentavos) {
                throw new CheckoutException('Your voucher changed while placing your order. Please review your cart and confirm the updated total.');
            }
            $summary = $lockedSummary;

            $buyer = Buyer::query()->whereKey($buyer->id)->lockForUpdate()->firstOrFail();
            try {
                $carrierQuote = app(CheckoutCarrierService::class)->validateSelectionForOrder(
                    $buyer, $summary->sellerGroups, $carrierSelections,
                );
            } catch (ValidationException $exception) {
                throw new CheckoutException(collect($exception->errors())->flatten()->implode(' '));
            }

            $order = new Order;
            $order->forceFill([
                'buyer_id' => $buyer->id,
                'reference' => Order::generateUniqueReference(),
                'shipping_address' => $this->addressSnapshot($buyer),
                'subtotal_minor' => $summary->subtotalCentavos,
                'discount_minor' => $summary->discountCentavos,
                'total_minor' => $summary->totalCentavos + $carrierQuote['shipping_total_minor'],
                'status' => OrderStatus::Placed,
            ])->save();

            // Consume the voucher. Voucher::rejectionReason() gates reuse on
            // times_used, so leaving the counter untouched let a single-use
            // code be redeemed over and over. increment() is an atomic
            // `times_used = times_used + 1` inside this transaction.
            $summary->voucher?->increment('times_used');

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

            if (array_sum($discounts) !== $summary->discountCentavos) {
                throw new CheckoutException('The voucher allocation does not match the applied discount.');
            }

            $sellerOrderIdsByCartItemId = [];

            foreach ($summary->sellerGroups as $index => $group) {
                $sellerOrder = new SellerOrder;
                $sellerOrder->forceFill([
                    'order_id' => $order->id,
                    'seller_id' => $group['seller']->id,
                    // Snapshot the locked voucher and the exact existing payment allocation.
                    'voucher_id' => $summary->voucher?->getKey(),
                    'voucher_funding_source' => $summary->voucher?->funding_source,
                    'voucher_discount_minor' => $discounts[$index],
                    'carrier_provider_id' => $carrierQuote['selections'][$group['seller']->id],
                    'handoff_destination_provider_id' => $carrierQuote['destinations'][$group['seller']->id] ?? null,
                    'shipping_fee_minor' => $carrierQuote['fees'][$group['seller']->id],
                    'subtotal_minor' => (int) $group['items']->sum(
                        fn (CartItem $item): int => $item->lineTotalCentavos(),
                    ),
                    // Freeze the parcel weight with the address and prices so
                    // later catalog edits cannot change an in-transit parcel.
                    'parcel_weight_grams' => (int) $group['items']->sum(function (CartItem $item): int {
                        $weight = $item->variant?->effectiveWeightGrams()
                            ?? (int) ($item->product?->weight_grams ?? 0);

                        return $item->quantity * $weight;
                    }),
                    'status' => OrderStatus::Placed,
                    // Tracking number exists from the moment the parcel is a
                    // seller_order, so the seller can print the waybill BEFORE
                    // the rider ever picks it up.
                    'tracking_number' => SellerOrder::generateUniqueTrackingNumber(),
                ])->save();

                foreach ($group['items'] as $item) {
                    /** @var Product $product */
                    $product = $item->product;
                    $variant = $item->variant;

                    OrderItem::query()->create([
                        'seller_order_id' => $sellerOrder->id,
                        'product_id' => $product->id,
                        'variant_id' => $variant?->id,
                        'product_name' => $product->name,
                        'variant_options' => app(PurchasedVariantSnapshot::class)->capture($variant),
                        'quantity' => $item->quantity,
                        // Price snapshot at order time — the variant's price
                        // when the line targets one, otherwise the product
                        // rollup (CartSummary uses the same source, so totals
                        // and lines always agree).
                        'price_minor' => $item->unitPriceCentavos(),
                    ]);

                    $sellerOrderIdsByCartItemId[$item->id] = $sellerOrder->id;
                }

                if ($discounts[$index] < 0 || $discounts[$index] > $sellerOrder->subtotal_minor) {
                    throw new CheckoutException('The allocated discount exceeds the Seller subtotal.');
                }

                $payment = new Payment;
                $payment->forceFill([
                    'seller_order_id' => $sellerOrder->id,
                    'amount_minor' => $sellerOrder->subtotal_minor - $discounts[$index] + $sellerOrder->shipping_fee_minor,
                    'method' => $paymentMethod,
                    'status' => PaymentStatus::Pending,
                ])->save();
            }

            // Decrement VARIANT stock + write the append-only ledger, still
            // under the same row locks, inside the same transaction. The
            // products.stock rollup is refreshed once per touched product.
            foreach ($items as $item) {
                // $item->variant was bound to the LOCKED row by the check loop,
                // so $before is the under-lock current value — never the
                // pre-lock copy whose stale value caused the overwrite.
                $variant = $item->variant;
                $before = (int) $variant->stock_quantity;

                $variant->stock_quantity = $before - $item->quantity;
                $variant->save();

                InventoryTransaction::query()->create([
                    'product_id' => $variant->product_id,
                    'seller_order_id' => $sellerOrderIdsByCartItemId[$item->id],
                    'variant_id' => $variant->id,
                    'quantity_before' => $before,
                    'quantity_after' => $variant->stock_quantity,
                    'delta' => -$item->quantity,
                    'reason' => 'checkout',
                ]);
            }

            Product::syncRollupsForProducts($items->pluck('product_id'));

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
            $weighted = bcmul((string) $discount, (string) $subtotal, 0);
            $allocated[$key] = (int) bcdiv($weighted, (string) $total, 0);
            $remainders[$key] = (int) bcmod($weighted, (string) $total);
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
     * Normalize phone number formats (strip formatting, return consistent format).
     */
    private function normalizePhoneNumber(?string $phone): ?string
    {
        if (! $phone) {
            return null;
        }

        // Remove spaces, hyphens, parentheses
        $phone = preg_replace('/[\s\-()]/u', '', $phone);

        // If starts with +63, replace with 0
        if (str_starts_with($phone, '+63')) {
            return '0'.substr($phone, 3);
        }

        // If starts with 63 (without +), replace with 0
        if (str_starts_with($phone, '63')) {
            return '0'.substr($phone, 2);
        }

        return $phone;
    }

    /**
     * Build a full address string from saved address components.
     */
    private function buildAddressString(Buyer $buyer): string
    {
        $parts = array_filter([
            $buyer->house_number,
            $buyer->street,
            $buyer->barangay_name,
        ]);

        return implode(', ', $parts);
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
            $buyer->postal_code,
            $buyer->address_detail,
        ]);

        return [
            'name' => $buyer->fullName(),
            'phone' => $buyer->contact_no,
            'province' => $buyer->province_name,
            'province_code' => $buyer->province_code,
            'municipality' => $buyer->municipality_name,
            'municipality_code' => $buyer->municipality_code,
            'barangay' => $buyer->barangay_name,
            'barangay_code' => $buyer->barangay_code,
            'street' => $buyer->street,
            'house_number' => $buyer->house_number,
            'address_detail' => $buyer->address_detail,
            'postal_code' => $buyer->postal_code,
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
