<?php

namespace App\Http\Controllers\Buyer;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\CheckoutException;
use App\Http\Controllers\Controller;
use App\Models\Buyer\Buyer;
use App\Models\Buyer\BuyerAddress;
use App\Models\Buyer\CartItem;
use App\Models\Buyer\CheckoutToken;
use App\Models\Catalog\InventoryTransaction;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Models\Seller\Seller;
use App\Models\Seller\StoreSale;
use App\Models\Shared\Order;
use App\Models\Shared\OrderItem;
use App\Models\Shared\Payment;
use App\Models\Shared\SellerOrder;
use App\Models\Shared\Voucher;
use App\Notifications\Seller\NewOrderReceived;
use App\Services\Buyer\BuyerAddressBook;
use App\Services\Buyer\BuyerPurchaseLimit;
use App\Services\Buyer\CartSummary;
use App\Services\Buyer\CartVariantResolver;
use App\Services\Buyer\CheckoutCarrierService;
use App\Services\Buyer\PurchasedVariantSnapshot;
use App\Services\Buyer\VoucherSessionService;
use App\Services\Shared\OfferPricing;
use App\Services\Shared\PriceResolver;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CheckoutController extends Controller
{
    /** Session key holding the ids checkout is allowed to see. */
    public const SESSION_KEY = 'checkout_item_ids';

    public const NOTE_MAX_LENGTH = 200;

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
        $requestedVacation = $items->contains(fn (CartItem $item) => $item->product?->isOnVacation()
            && (! $request->has('items') || in_array($item->id, array_map('intval', (array) $request->input('items', [])), true)));
        if ($requestedVacation && ($request->has('items') || $available->isEmpty())) {
            return to_route('buyer.cart.index')->with('error', Seller::VACATION_MESSAGE);
        }

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

        foreach ($available as $item) {
            if (($selectedIds === null || in_array($item->id, $selectedIds, true)) && $item->quantity > $item->availableQuantity()) {
                return to_route('buyer.cart.index')->with('error', "Only {$item->availableQuantity()} left for {$item->product->name}. Reduce the quantity before checkout.");
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

        $base = new CartSummary($items, null, $selectedIds);

        // A stale ?address= link just falls back to the default, which the page
        // shows clearly; store() is where a bad id stops the order.
        $addressBook = app(BuyerAddressBook::class);
        try {
            $selectedAddress = $addressBook->chosen($buyer, $request->query('address'));
        } catch (CheckoutException) {
            $selectedAddress = $addressBook->chosen($buyer, null);
        }
        $deliverTo = $addressBook->deliveringTo($buyer, $selectedAddress);

        $carrierService = app(CheckoutCarrierService::class);
        $checkoutToken = DB::transaction(function () use ($buyer, $base, $carrierService): CheckoutToken {
            $snapshot = $carrierService->captureRates($base->sellerGroups);
            $token = CheckoutToken::issue($buyer->id);
            $token->forceFill(['shipping_rates_snapshot' => $snapshot])->save();

            return $token;
        });
        session([self::TOKEN_KEY => $checkoutToken->token]);
        $carrierOptions = $carrierService->optionsForCheckout($deliverTo, $base->sellerGroups, $checkoutToken->shipping_rates_snapshot);
        $carriersAvailable = $carrierService->allRoutesAvailable($carrierOptions);
        $defaultShippingFees = $this->defaultShippingFees($carrierOptions, $request);

        $voucherNotice = null;
        $application = app(VoucherSessionService::class)->resolve(
            $base->sellerGroups,
            $base->subtotalCentavos,
            $defaultShippingFees,
            $voucherNotice,
        );

        $summary = new CartSummary($items, $application, $selectedIds);

        $shippingTotalMinor = array_sum($defaultShippingFees);
        $shippingDiscountMinor = $application->shippingDiscountCentavos;
        $payableTotalMinor = max(0, $summary->totalCentavos + $shippingTotalMinor - $shippingDiscountMinor);

        return view('Buyer.Checkout.index', [
            'buyer' => $buyer,
            'deliverTo' => $deliverTo,
            'addresses' => $buyer->addresses()->orderByDesc('is_default')->orderBy('id')->get(),
            'selectedAddress' => $selectedAddress,
            'addressFingerprint' => $this->addressFingerprint($deliverTo),
            'checkoutItemIds' => session(self::SESSION_KEY),
            'items' => $available,
            'summary' => $summary,
            'voucher' => $summary->voucher,
            'vouchers' => $application->toArray(),
            'shippingVoucher' => $application->shippingVoucher,
            'voucherNotice' => $voucherNotice,
            'variantLabels' => $summary->availableItems->mapWithKeys(fn (CartItem $item): array => [
                $item->id => $item->variant?->optionValues
                    ->sortBy(fn ($value) => $value->option->sort_order)
                    ->map(fn ($value): string => $value->option->name.': '.$value->value)
                    ->implode(' | ') ?? '',
            ]),
            'checkoutToken' => $checkoutToken->token,
            'shippingFingerprint' => $carrierService->quoteFingerprint($carrierOptions),
            'voucherFingerprint' => $application->reviewFingerprint(),
            'carrierOptions' => $carrierOptions,
            'carriersAvailable' => $carriersAvailable,
            'merchandiseTotalMinor' => $summary->totalCentavos,
            'shippingTotalMinor' => $shippingTotalMinor,
            'shippingDiscountMinor' => $shippingDiscountMinor,
            'payableTotalMinor' => $payableTotalMinor,
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
            if ($product?->isOnVacation()) {
                $problems[] = $product->name.' ('.Seller::VACATION_MESSAGE.')';

                continue;
            }

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

        foreach ($items->groupBy('product_id') as $productId => $lines) {
            $allowed = app(BuyerPurchaseLimit::class)->remaining((int) $buyer->id, (int) $productId);
            if ((int) $lines->sum('quantity') > $allowed) {
                $name = $lines->first()->product?->name ?? 'A product';
                $problems[] = $name.' ('.BuyerPurchaseLimit::MESSAGE.')';
            }
        }

        if ($problems !== []) {
            return redirect()->route('buyer.cart.index')
                ->with('error', 'Some items are no longer available: '.implode(', ', array_unique($problems)).'. Please review your cart.');
        }

        // Voucher re-resolution identical to index(): every slot is validated
        // against the live selection and the carrier quotes, and invalid
        // vouchers are dropped with a notice.
        $base = new CartSummary($items, null, null);
        $checkoutReturn = route('buyer.checkout.index', ['items' => $parkedIds]);

        $addressBook = app(BuyerAddressBook::class);
        try {
            $address = $addressBook->chosen($buyer, $request->input('address_id'));
        } catch (CheckoutException $e) {
            return redirect()->to($checkoutReturn)->with('error', $e->getMessage());
        }
        $deliverTo = $addressBook->deliveringTo($buyer, $address);
        $checkoutReturn = route('buyer.checkout.index', ['items' => $parkedIds, 'address' => $address?->id]);

        // Anything the buyer did not see stops here and returns them to the
        // checkout page with the new total, so they place the order knowingly.
        $reviewedAddress = $request->input('address_fingerprint');
        if (is_string($reviewedAddress) && ! hash_equals($this->addressFingerprint($deliverTo), $reviewedAddress)) {
            return redirect()->to($checkoutReturn)
                ->with('error', 'Your delivery address changed while you were checking out. Please check it and place your order again.');
        }

        $reviewedPrices = $request->input('price_fingerprint');
        if (is_string($reviewedPrices) && ! hash_equals($base->priceFingerprint(), $reviewedPrices)) {
            return redirect()->to($checkoutReturn)
                ->with('error', 'A product price changed while you were checking out. Please review the updated total and place your order again.');
        }

        $notes = Validator::make($request->only('notes'), [
            'notes' => ['nullable', 'array'],
            'notes.*' => ['nullable', 'string', 'max:'.self::NOTE_MAX_LENGTH],
        ], [
            'notes.*.string' => 'Your note to the seller must be text.',
            'notes.*.max' => 'A note to the seller can be up to '.self::NOTE_MAX_LENGTH.' characters.',
        ]);

        if ($notes->fails()) {
            return redirect()->to($checkoutReturn)->withInput()->withErrors($notes);
        }

        $rateSnapshot = CheckoutToken::query()->where('buyer_id', $buyer->id)->where('token', $formToken)
            ->where('used', false)->where('expires_at', '>', now())->first()?->shipping_rates_snapshot;
        // Revalidate carrier eligibility and server fees before entering checkout.
        if ($request->filled('shipping_fee') || $request->filled('shipping_fees') || $request->filled('shipping_total')) {
            return redirect()->to($checkoutReturn)
                ->withInput()
                ->withErrors(['shipping' => 'Shipping fees are calculated by the server and cannot be submitted.']);
        }

        try {
            $carrierQuote = app(CheckoutCarrierService::class)->validateSelection(
                $deliverTo,
                $base->sellerGroups,
                (array) $request->input('carriers', []),
                $rateSnapshot,
            );
        } catch (ValidationException $e) {
            return redirect()->to($checkoutReturn)
                ->withInput()
                ->withErrors($e->errors())
                ->with('error', 'Shipping is not available for one or more shops in your order. Review carrier options or your delivery address.');
        }

        $carrierQuote['rate_snapshot'] = $rateSnapshot;
        session([CheckoutCarrierService::SESSION_KEY => $carrierQuote['selections']]);

        $reviewedShipping = $request->input('shipping_fingerprint');
        if (is_string($reviewedShipping) && ! hash_equals(app(CheckoutCarrierService::class)->quoteFingerprint($carrierQuote['options']), $reviewedShipping)) {
            return redirect()->to($checkoutReturn)
                ->withInput()
                ->with('error', 'Shipping fees changed while you were checking out. Please review the updated total and place your order again.');
        }

        $notice = null;
        $application = app(VoucherSessionService::class)->resolve(
            $base->sellerGroups,
            $base->subtotalCentavos,
            $carrierQuote['fees'],
            $notice,
        );

        // A voucher that stopped applying must not quietly raise what the buyer
        // pays. resolve() has already dropped it, so the page shows the new total.
        $reviewedVouchers = $request->input('voucher_fingerprint');
        if ($notice !== null || (is_string($reviewedVouchers) && ! hash_equals($application->reviewFingerprint(), $reviewedVouchers))) {
            return redirect()->to($checkoutReturn)
                ->withInput()
                ->with('error', ($notice ?? 'A voucher on this order changed.').' Please review the updated total and place your order again.');
        }

        // Authoritative money for the order — computed from DB rows only.
        $summary = new CartSummary($items, $application, null);

        try {
            $order = $this->placeOrder($buyer, $items, $summary, $formToken, $carrierQuote, (array) $request->input('notes', []), $address);
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

        session()->forget([
            self::SESSION_KEY,
            VoucherSessionService::SESSION_KEY,
            VoucherSessionService::LEGACY_KEY,
            self::TOKEN_KEY,
            CheckoutCarrierService::SESSION_KEY,
        ]);

        return redirect()->route('buyer.checkout.success', $order);
    }

    /**
     * The single transactional unit: order + seller_orders + order_items +
     * payments + locked stock decrement + inventory ledger. Any failure rolls
     * everything back — no partial orders, no phantom stock changes.
     *
     * @param  Collection<int, CartItem>  $items  The buyer's validated selection
     * @param  array<int|string, mixed>  $notes  Seller id => the buyer's validated note
     */
    private function placeOrder(Buyer $buyer, Collection $items, CartSummary $summary, ?string $formToken = null, array $carrierQuote = [], array $notes = [], ?BuyerAddress $address = null): Order
    {
        return DB::transaction(function () use ($buyer, $items, $summary, $formToken, $carrierQuote, $notes, $address): Order {
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

            $offers = app(OfferPricing::class);
            $offers->bind($items);
            $reviewedTotals = [];
            foreach ($items as $item) {
                $reviewedTotals[$item->id] = $item->lineTotalCentavos();
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
                if ($lockedProduct->isOnVacation()) {
                    throw new CheckoutException(Seller::VACATION_MESSAGE);
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

                $lockedPrice = app(PriceResolver::class)->forVariant($product, $variant)->unitCentavos();
                if ($item->unitPriceCentavos() !== $lockedPrice) {
                    throw new CheckoutException('A product price changed while placing your order. Please review your cart and confirm the updated total.');
                }

                // Every accepted snapshot and total uses these same locked rows.
                $item->setRelation('product', $product);
                $item->setRelation('variant', $variant);
            }

            $saleIds = $items
                ->map(fn (CartItem $item) => $item->product?->ongoingSale?->id)
                ->filter()
                ->unique()
                ->sort()
                ->values();
            if ($saleIds->isNotEmpty()) {
                StoreSale::query()->whereIn('id', $saleIds)->orderBy('id')->lockForUpdate()->get();
            }
            foreach ($items as $item) {
                $item->product?->unsetRelation('ongoingSale');
            }
            $offers->bind($items);
            foreach ($items as $item) {
                if ($item->lineTotalCentavos() !== $reviewedTotals[$item->id]) {
                    throw new CheckoutException('A product price changed while placing your order. Please review your cart and confirm the updated total.');
                }
            }

            foreach ($items->groupBy(fn (CartItem $item) => $item->variant->id) as $lines) {
                if ($lines->sum('quantity') > $lines->first()->variant->stock_quantity) {
                    throw new CheckoutException('There is not enough stock for the selected items. Please review your cart.');
                }
            }

            $base = new CartSummary($items);

            // The address book writes under this same buyer lock, so the row read
            // here is the one that gets frozen onto the order.
            $buyer = Buyer::query()->whereKey($buyer->id)->lockForUpdate()->firstOrFail();
            $lockedAddress = $address?->fresh();
            if ($address !== null && $lockedAddress === null) {
                throw new CheckoutException(BuyerAddressBook::UNAVAILABLE_MESSAGE);
            }
            $deliverTo = app(BuyerAddressBook::class)->deliveringTo($buyer, $lockedAddress);

            try {
                $lockedCarrierQuote = app(CheckoutCarrierService::class)->validateSelectionForOrder(
                    $deliverTo, $base->sellerGroups, $carrierQuote['selections'] ?? [], $carrierQuote['rate_snapshot'] ?? null,
                );
            } catch (ValidationException $exception) {
                throw new CheckoutException(collect($exception->errors())->flatten()->implode(' '));
            }
            if ($lockedCarrierQuote['fees'] != $carrierQuote['fees']) {
                throw new CheckoutException('Shipping fees changed while placing your order. Please review your cart and confirm the updated total.');
            }
            $carrierQuote = $lockedCarrierQuote;

            $voucherNotice = null;
            $application = app(VoucherSessionService::class)->resolve(
                $base->sellerGroups,
                $base->subtotalCentavos,
                $carrierQuote['fees'],
                $voucherNotice,
                lock: true,
            );
            $lockedSummary = new CartSummary($items, $application);

            if ($lockedSummary->discountCentavos !== $summary->discountCentavos
                || $lockedSummary->shippingDiscountCentavos !== $summary->shippingDiscountCentavos
                || $application->voucherIds() !== $summary->voucherApplication->voucherIds()) {
                throw new CheckoutException('Your voucher changed while placing your order. Please review your cart and confirm the updated total.');
            }

            $summary = $lockedSummary;

            $order = new Order;
            $order->forceFill([
                'buyer_id' => $buyer->id,
                'reference' => Order::generateUniqueReference(),
                'shipping_address' => $this->addressSnapshot($deliverTo),
                'subtotal_minor' => $summary->subtotalCentavos,
                'discount_minor' => $summary->discountCentavos,
                'shop_discount_minor' => $application->shopDiscountCentavos,
                'platform_discount_minor' => $application->platformDiscountCentavos,
                'shipping_discount_minor' => $application->shippingDiscountCentavos,
                'total_minor' => max(
                    0,
                    $summary->totalCentavos + $carrierQuote['shipping_total_minor'] - $application->shippingDiscountCentavos,
                ),
                'status' => OrderStatus::Placed,
            ])->save();

            // Consume every applied voucher exactly once. The rows are locked
            // above, so two checkouts cannot both pass a usage_limit check.
            foreach ($application->vouchers as $usedVoucher) {
                $usedVoucher->increment('times_used');
            }

            $sellerOrderIdsByCartItemId = [];

            foreach ($summary->sellerGroups as $group) {
                $sellerId = (int) $group['seller']->getKey();
                $subtotalMinor = (int) $group['items']->sum(
                    fn (CartItem $item): int => $item->lineTotalCentavos(),
                );
                $shopVoucher = $application->shopVoucherFor($sellerId);
                $shopDiscount = $application->shopDiscountFor($sellerId);
                $platformDiscount = $application->platformDiscountFor($sellerId);
                $shippingDiscount = $application->shippingDiscountFor($sellerId);
                $shippingFee = (int) ($carrierQuote['fees'][$sellerId] ?? 0);
                $merchandiseDiscount = $shopDiscount + $platformDiscount;

                if ($merchandiseDiscount < 0 || $merchandiseDiscount > $subtotalMinor
                    || $shippingDiscount < 0 || $shippingDiscount > $shippingFee) {
                    throw new CheckoutException('The voucher allocation exceeds the Seller subtotal.');
                }

                $funding = $application->fundingFor($sellerId);
                $legacyVoucher = $application->platformVoucher ?? $shopVoucher;

                $sellerOrder = new SellerOrder;
                $sellerOrder->forceFill([
                    'order_id' => $order->id,
                    'seller_id' => $sellerId,
                    // Legacy single-voucher snapshot retained for old reports.
                    'voucher_id' => $legacyVoucher?->getKey(),
                    'voucher_funding_source' => $legacyVoucher?->funding_source,
                    'voucher_discount_minor' => $merchandiseDiscount,
                    // Immutable, per-slot checkout snapshots.
                    'shop_voucher_id' => $shopVoucher?->getKey(),
                    'shop_voucher_funding_source' => $shopVoucher?->funding_source,
                    'shop_voucher_discount_minor' => $shopDiscount,
                    'platform_voucher_id' => $application->platformVoucher?->getKey(),
                    'platform_voucher_funding_source' => $application->platformVoucher?->funding_source,
                    'platform_voucher_discount_minor' => $platformDiscount,
                    'shipping_voucher_id' => $application->shippingVoucher?->getKey(),
                    'shipping_voucher_funding_source' => $application->shippingVoucher?->funding_source,
                    'shipping_voucher_discount_minor' => $shippingDiscount,
                    'shipping_discount_minor' => $shippingDiscount,
                    'seller_funded_discount_minor' => $funding['seller_minor'],
                    'marketplace_funded_discount_minor' => $funding['marketplace_minor'],
                    'carrier_provider_id' => $carrierQuote['selections'][$sellerId],
                    'handoff_destination_provider_id' => $carrierQuote['destinations'][$sellerId] ?? null,
                    'shipping_fee_minor' => $shippingFee,
                    'subtotal_minor' => $subtotalMinor,
                    // Freeze the parcel weight with the address and prices so
                    // later catalog edits cannot change an in-transit parcel.
                    'parcel_weight_grams' => (int) $group['items']->sum(fn (CartItem $item): int => $item->lineWeightGrams()),
                    'buyer_note' => filled($notes[$sellerId] ?? null) ? trim($notes[$sellerId]) : null,
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

                    foreach (app(OfferPricing::class)->portions($item) as $portion) {
                        OrderItem::query()->create([
                            'seller_order_id' => $sellerOrder->id,
                            'product_id' => $product->id,
                            'variant_id' => $variant?->id,
                            'product_name' => $product->name,
                            'variant_options' => app(PurchasedVariantSnapshot::class)->capture($variant),
                            'quantity' => $portion['quantity'],
                            'price_minor' => $portion['price'],
                            'regular_price_minor' => $portion['regular'],
                            'store_sale_id' => $portion['sale_id'],
                        ]);
                    }

                    $sellerOrderIdsByCartItemId[$item->id] = $sellerOrder->id;
                }

                if ($merchandiseDiscount < 0 || $merchandiseDiscount > $sellerOrder->subtotal_minor) {
                    throw new CheckoutException('The allocated discount exceeds the Seller subtotal.');
                }

                $amountMinor = $sellerOrder->subtotal_minor - $merchandiseDiscount
                    + $sellerOrder->shipping_fee_minor - $shippingDiscount;

                if ($amountMinor < 0) {
                    throw new CheckoutException('The voucher allocation produces a negative payment total.');
                }

                $payment = new Payment;
                $payment->forceFill([
                    'seller_order_id' => $sellerOrder->id,
                    'amount_minor' => $amountMinor,
                    'method' => PaymentMethod::Cod,
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
     * Server-side default shipping fees used for the initial checkout total.
     * The browser recalculates with the buyer's actual radio selection.
     *
     * @param  list<array<string, mixed>>  $carrierOptions
     * @return array<int, int>
     */
    private function defaultShippingFees(array $carrierOptions, Request $request): array
    {
        $old = $request->old('carriers', []);
        $old = is_array($old) ? $old : [];
        $fees = [];

        foreach ($carrierOptions as $row) {
            if (! ($row['available'] ?? false)) {
                continue;
            }

            $sellerId = (int) $row['seller_id'];
            $raw = $old[$sellerId] ?? $old[(string) $sellerId] ?? null;
            $chosen = is_int($raw) || (is_string($raw) && ctype_digit($raw)) ? (int) $raw : 0;
            $match = collect($row['carriers'])->firstWhere('id', $chosen)
                ?? ($row['carriers'][0] ?? null);

            if ($match !== null) {
                $fees[$sellerId] = (int) $match['fee_minor'];
            }
        }

        return $fees;
    }

    /**
     * Identifies the destination the buyer was shown, so an address edited in
     * another tab stops the order instead of shipping somewhere unreviewed.
     */
    private function addressFingerprint(Buyer $deliverTo): string
    {
        return hash('sha256', json_encode($this->addressSnapshot($deliverTo), JSON_THROW_ON_ERROR));
    }

    /**
     * Immutable snapshot of the chosen address at order time
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
