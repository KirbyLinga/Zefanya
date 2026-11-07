<?php

namespace App\Services\Buyer;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\CheckoutException;
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
use App\Notifications\Seller\NewOrderReceived;
use App\Services\Logistics\RoutePlannerService;
use App\Services\Shared\OfferPricing;
use App\Services\Shared\PriceResolver;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Order placement for the buyer web checkout and the Buyer mobile API.
 *
 * placeOrder(), planRoutesForOrder(), addressFingerprint() and
 * addressSnapshot() were moved verbatim from Buyer\CheckoutController, as
 * were the selection checks, token issue and seller notification. The only
 * change: the voucher store is a parameter (the web session by default, the
 * stateless copy for bearer callers), so both paths lock and re-validate
 * vouchers with the same rules inside the order transaction.
 */
class CheckoutService
{
    /**
     * Problems that stop checkout for the parked selection (missing, on
     * vacation, unavailable, over stock).
     *
     * @param  Collection<int, CartItem>  $items  keyed by id
     * @param  array<int>  $parkedIds
     * @return array<int, string>
     */
    public function selectionProblems(Collection $items, array $parkedIds): array
    {
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

        return $problems;
    }

    /**
     * Issue the one-time checkout token with the shipping-rate snapshot that
     * the review and the order are both priced from.
     */
    public function issueToken(Buyer $buyer, BaseCollection $sellerGroups): CheckoutToken
    {
        $carrierService = app(CheckoutCarrierService::class);

        return DB::transaction(function () use ($buyer, $sellerGroups, $carrierService): CheckoutToken {
            $snapshot = $carrierService->captureRates($sellerGroups);
            $token = CheckoutToken::issue($buyer->id);
            $token->forceFill(['shipping_rates_snapshot' => $snapshot])->save();

            return $token;
        });
    }

    /** Let each shop know a new order came in (in-app notification). */
    public function notifySellers(Order $order): void
    {
        $order->loadMissing('sellerOrders.seller');
        foreach ($order->sellerOrders as $sellerOrder) {
            $sellerOrder->seller?->notify(new NewOrderReceived($sellerOrder));
        }
    }

    /**
     * The single transactional unit: order + seller_orders + order_items +
     * payments + locked stock decrement + inventory ledger. Any failure rolls
     * everything back — no partial orders, no phantom stock changes.
     *
     * @param  Collection<int, CartItem>  $items  The buyer's validated selection
     * @param  array<int|string, mixed>  $notes  Seller id => the buyer's validated note
     */
    public function placeOrder(Buyer $buyer, Collection $items, CartSummary $summary, ?string $formToken = null, array $carrierQuote = [], array $notes = [], ?BuyerAddress $address = null, ?VoucherSessionService $vouchers = null, ?int $expectedTotalMinor = null): Order
    {
        $vouchers ??= app(VoucherSessionService::class);

        return DB::transaction(function () use ($buyer, $items, $summary, $formToken, $carrierQuote, $notes, $address, $vouchers, $expectedTotalMinor): Order {
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
            $application = $vouchers->resolve(
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

            $payable = max(0, $summary->totalCentavos + $carrierQuote['shipping_total_minor'] - $application->shippingDiscountCentavos);
            if ($expectedTotalMinor !== null && $expectedTotalMinor !== $payable) {
                throw new CheckoutException('The order total is now ₱'.number_format($payable / 100, 2).'. Review the updated total before placing your order.');
            }

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

            // Phase 4: Plan multi-leg routes for all seller orders
            $this->planRoutesForOrder($order, $deliverTo, $lockedSellers);

            return $order;
        });
    }

    /**
     * Phase 4: Plan and persist multi-leg routes for all seller orders in an order.
     *
     * Called AFTER order creation but inside transaction. Routes are persisted
     * as immutable snapshots. If any route fails to persist, transaction rolls back.
     *
     * @param  Buyer  $deliverTo  buyer carrying the address chosen for this order
     * @param  Collection<int, Seller>  $sellers  locked sellers keyed by id
     *
     * @throws CheckoutException if route planning fails (triggers transaction rollback)
     */
    public function planRoutesForOrder(Order $order, Buyer $deliverTo, Collection $sellers): void
    {
        $routePlanner = app(RoutePlannerService::class);

        foreach ($order->sellerOrders as $sellerOrder) {
            $seller = $sellers->get($sellerOrder->seller_id);
            $provider = $sellerOrder->selectedLogisticsProvider();

            if (! $seller || ! $provider) {
                continue;  // Skip if data is unavailable (validation passed earlier)
            }

            // Skip if route already exists (idempotent)
            if ($sellerOrder->parcelRoute) {
                continue;
            }

            try {
                // Plan the route (will throw ValidationException if no path exists)
                $route = $routePlanner->planRoute(
                    $seller,
                    $provider,
                    $deliverTo->barangay_code ?? '',
                    $deliverTo->province_code ?? '',
                );

                // Persist route with seller_order_id
                $route->seller_order_id = $sellerOrder->id;
                $route->save();
            } catch (ValidationException $e) {
                throw new CheckoutException(
                    'No shipping route available for this delivery address. '.
                    'Please select a different carrier or address.'
                );
            }
        }
    }

    /**
     * Identifies the destination the buyer was shown, so an address edited in
     * another tab stops the order instead of shipping somewhere unreviewed.
     */
    public function addressFingerprint(Buyer $deliverTo): string
    {
        return hash('sha256', json_encode($this->addressSnapshot($deliverTo), JSON_THROW_ON_ERROR));
    }

    /**
     * Immutable snapshot of the chosen address at order time
     * (orders.shipping_address is JSON, deliberately not a live FK).
     *
     * @return array<string, string|null>
     */
    public function addressSnapshot(Buyer $buyer): array
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
}
