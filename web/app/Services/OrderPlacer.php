<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\CheckoutException;
use App\Models\Buyer\Buyer;
use App\Models\CartItem;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SellerOrder;
use App\Models\Voucher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Placing an order: the one spelling of what a checkout writes.
 *
 * ## Why these rules are a service rather than a controller method
 *
 * They began private to `Buyer\CheckoutController`, which is the *web* door: it
 * reads a selection parked in the session, over `cart_items` rows the web app owns.
 * The mobile app has no server-side cart — its cart lives on the device — so when
 * the API grew `POST /orders` there was nothing for it to hand over, and the money
 * rules existed exactly once, in a place the app could not reach.
 *
 * So they moved here and take **lines**: a product id and a quantity each. From
 * whichever door, everything authoritative is still computed server-side from the
 * database — a request contributes *which* product and *how many*, never a price.
 * That is the rule the controller's own doc already stated, and it is why a body
 * cannot talk this service into a cheaper order.
 *
 * ## The transaction, and what is inside it
 *
 * Order, seller orders, order items, payments, the locked stock decrement and the
 * append-only inventory ledger all happen in one transaction, so a failure rolls
 * everything back: no partial orders, no phantom stock changes. Product rows are
 * locked in id order, which is what stops two concurrent checkouts deadlocking.
 */
final class OrderPlacer
{
    /**
     * @param  list<array{product_id: int, quantity: int}>  $lines
     * @param  Voucher|null  $voucher  Already resolved by the caller — the web reads
     *                                 it from the session, the API from the request —
     *                                 because *which* voucher is a door's question
     *                                 while `Voucher::resolveCode` is shared.
     *
     * @throws CheckoutException when a line is no longer buyable under the lock
     */
    public function place(Buyer $buyer, array $lines, ?Voucher $voucher = null): Order
    {
        if ($lines === []) {
            throw new CheckoutException('There is nothing to place an order for.');
        }

        return DB::transaction(function () use ($buyer, $lines, $voucher): Order {
            $products = $this->lockProducts($lines);

            // The shape `CartSummary` reads — a product and a quantity — built from
            // the *locked* rows so the money and the stock guard agree about what was
            // bought. These are not cart rows and are never saved: the web's cart is
            // the web's, and the app has none.
            $items = collect();

            foreach ($lines as $line) {
                /** @var Product|null $product */
                $product = $products->get($line['product_id']);

                $this->assertBuyable($product, $line['quantity']);

                $item = new CartItem([
                    'product_id' => $product->id,
                    'quantity' => $line['quantity'],
                ]);

                $items->push($item->setRelation('product', $product));
            }

            $summary = new CartSummary($items, $voucher);

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
            // allocated proportionally so the per-shop COD payments sum exactly to
            // the discounted order total (largest-remainder method).
            $discounts = $this->allocateDiscount(
                $summary->discountCentavos,
                $summary->sellerGroups
                    ->map(fn (array $group): int => $this->groupSubtotal($group['items']))
                    ->all(),
            );

            // Keyed by the line object itself. This was `$sellerOrderIdsByCartItemId`,
            // keyed on `cart_items` — which is exactly what pinned these rules to the
            // web: a line from the app has no cart row to be keyed by.
            $sellerOrderIdsByLine = [];

            foreach ($summary->sellerGroups as $index => $group) {
                $sellerOrder = new SellerOrder;
                $sellerOrder->forceFill([
                    'order_id' => $order->id,
                    'seller_id' => $group['seller']->id,
                    'subtotal_minor' => $this->groupSubtotal($group['items']),
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
                        // Price snapshot at order time, from the same source
                        // `CartSummary` used, so totals and lines always agree.
                        'price_minor' => (int) bcmul((string) $product->price, '100', 0),
                    ]);

                    $sellerOrderIdsByLine[spl_object_id($item)] = $sellerOrder->id;
                }

                $payment = new Payment;
                $payment->forceFill([
                    'seller_order_id' => $sellerOrder->id,
                    'amount_minor' => $sellerOrder->subtotal_minor - $discounts[$index],
                    'method' => PaymentMethod::Cod,
                    'status' => PaymentStatus::Pending,
                ])->save();
            }

            // Decrement stock + write the append-only ledger, still under the same
            // row locks, inside the same transaction.
            foreach ($items as $item) {
                /** @var Product $product */
                $product = $products->get($item->product_id);
                $before = (int) $product->stock_quantity;

                $product->stock_quantity = $before - $item->quantity;
                $product->save();

                InventoryTransaction::query()->create([
                    'product_id' => $product->id,
                    'seller_order_id' => $sellerOrderIdsByLine[spl_object_id($item)],
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
     * The products in [lines], locked for update in id order.
     *
     * Id order rather than arrival order: two checkouts holding the same two
     * products in opposite orders would otherwise deadlock.
     *
     * @param  list<array{product_id: int, quantity: int}>  $lines
     * @return Collection<int, Product>
     */
    private function lockProducts(array $lines): Collection
    {
        return Product::query()
            ->whereIn('id', array_column($lines, 'product_id'))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
    }

    /**
     * The authoritative gate on whether a line can be bought.
     *
     * The caller's own pre-check is advisory — a cart page renders before anyone
     * clicks — so this one runs under the lock, and the stock it reads is the stock
     * nobody else can change until this transaction finishes.
     */
    private function assertBuyable(?Product $product, int $quantity): void
    {
        if ($product === null
            || $product->trashed()
            || $product->status !== 'active'
            || $product->stock_quantity < $quantity) {
            throw new CheckoutException(
                'Some items just went out of stock while placing your order. Please review your cart and try again.'
            );
        }
    }

    /**
     * One shop's subtotal, in centavos.
     *
     * @param  Collection<int, CartItem>  $items
     */
    private function groupSubtotal(Collection $items): int
    {
        return (int) $items->sum(fn (CartItem $item): int => $item->lineTotalCentavos());
    }

    /**
     * Split an order-level voucher discount across seller orders proportionally to
     * their subtotals (largest-remainder method), so the per-shop COD payments sum
     * exactly to the discounted order total.
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
}
