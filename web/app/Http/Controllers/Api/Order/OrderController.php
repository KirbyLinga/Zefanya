<?php

namespace App\Http\Controllers\Api\Order;

use App\Exceptions\CheckoutException;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Buyer\Buyer;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\SellerOrder;
use App\Models\Voucher;
use App\Services\CartSummary;
use App\Services\OrderPlacer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;

/**
 * The buyer's own order history: `GET /api/orders`.
 *
 * ## The first authenticated read on this API
 *
 * Everything else here either precedes a token (registration, sign-in) or is public
 * because it is a shop window (the catalogue). An order history is per-person by
 * definition, so it sits behind `auth:sanctum` — and it is the reason `/me` exists
 * beside it: a token that lists orders has to be checkable.
 *
 * ## The query starts at the join, so there is nothing to tamper with
 *
 * It begins at `seller_orders` because the unit the app lists is a parcel (see
 * `OrderResource`), and it is scoped through `orders.buyer_id` rather than through
 * an id from the request. That is deliberate: a request cannot name another buyer's
 * order, so there is no ownership check to forget, and a `404` is not even needed to
 * hide one.
 *
 * ## Newest first, by the checkout's own timestamp
 *
 * The parent order's `created_at`, not the seller order's. One checkout writes all
 * of its parcels in a single transaction, so ordering by the child's own timestamp
 * would reshuffle the parcels of one checkout against each other — technically
 * newer, and confusing to read.
 */
class OrderController extends Controller
{
    /**
     * The placing rules are the web checkout's, to the centavo.
     *
     * Which is the point of the service: the app's cart lives on the phone, so
     * this door can only hand over **lines** — a product and a quantity each —
     * and everything authoritative (the price, the stock, the discount split,
     * the shipping snapshot) is computed on the server from the locked rows. The
     * request chooses *what* is bought and *how many*, never *how much*.
     */
    public function __construct(private readonly OrderPlacer $orderPlacer) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $buyer = $request->user();

        // `auth:sanctum` accepts any tokenable; only buyers can hold one today.
        // This is the guard for the day that stops being true.
        abort_unless(
            $buyer instanceof Buyer,
            403,
            'This token belongs to a different kind of account.',
        );

        $orders = SellerOrder::query()
            ->join('orders', 'orders.id', '=', 'seller_orders.order_id')
            ->where('orders.buyer_id', $buyer->id)
            ->orderByDesc('orders.created_at')
            ->orderBy('seller_orders.id')
            ->select('seller_orders.*')
            // Eager-loaded because the resource reads all three; without it a
            // history of N parcels would issue 3N queries.
            ->with(['seller', 'orderItems', 'order:id,reference'])
            ->get();

        return OrderResource::collection($orders);
    }

    /**
     * `POST /api/orders` — place the order for the phone's basket.
     *
     * ## The reply is the same shape as the history, so the app has one parser
     *
     * `201` with the new order's **parcels**, each exactly as `OrderResource`
     * already renders them for `GET /orders`. The app's confirmation and its
     * history therefore read one payload between them, and placing an order needs
     * no second spelling of the shape on the Dart side.
     *
     * Order-level money — which a parcel cannot carry, because a discount is split
     * *across* parcels — rides beside them in `meta`, so a basket that applied ₱200
     * off gets the total it should show, in centavos, next to the same rows the
     * history returns. Additive: a client that knows only `data` parses this reply
     * unchanged.
     *
     * ## `422` is "the basket is no longer placeable", not bad syntax
     *
     * A line that has sold out, gone inactive or vanished answers `422` with the
     * service's own sentence, and nothing is written: the checkout transaction
     * rolls back whole, so there is no order, no phantom stock decrement and no
     * ledger row. That is a *retryable with a fresh basket* answer, which the app
     * can show with one "Refresh basket" act — hence a refusal meant to be read
     * rather than an opaque `500`.
     *
     * A voucher code that does not apply is refused the same way, and deliberately
     * **not** dropped. The web door can afford to drop one and carry on, because
     * its next page re-renders the totals; a phone showing "₱200 off" would
     * otherwise place the order, be charged full price, and display the wrong
     * number. Refusing makes a stale basket visible instead of letting it quietly
     * change the price.
     *
     * ## The one thing this route does not yet do
     *
     * It is not idempotent: two taps that both reach here are two orders, as two
     * taps on the web's button are. The web relies on a client-side guard (disable
     * the button while the request is in flight); an idempotency key would be the
     * server's own answer, and is not invented here.
     */
    public function store(Request $request): JsonResponse
    {
        $buyer = $request->user();

        // Same guard as index(): `auth:sanctum` accepts any tokenable, and only
        // buyers can hold one today.
        abort_unless(
            $buyer instanceof Buyer,
            403,
            'This token belongs to a different kind of account.',
        );

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'voucher_code' => ['nullable', 'string', 'max:50'],
        ]);

        /** @var list<array{product_id: int, quantity: int}> $lines */
        $lines = collect($data['items'])
            ->map(fn (array $line): array => [
                'product_id' => (int) $line['product_id'],
                'quantity' => (int) $line['quantity'],
            ])
            ->values()
            ->all();

        // A voucher is a *door's* question (which code did the shopper type?) while
        // the arithmetic is shared, so it is resolved here from the code and handed
        // over ready to use — exactly as the web's checkout does.
        $voucher = null;
        $code = trim((string) ($data['voucher_code'] ?? ''));

        if ($code !== '' && ($refusal = $this->resolveVoucher($code, $lines, $voucher)) !== null) {
            return $refusal;
        }

        try {
            $order = $this->orderPlacer->place($buyer, $lines, $voucher);
        } catch (CheckoutException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // `order:id,reference` for the reference each parcel reports: loaded
        // explicitly rather than relied on, so the resource cannot pick it up from
        // a parent that merely happens to be in memory.
        $parcels = $order->sellerOrders()
            ->with(['seller', 'orderItems', 'order:id,reference'])
            ->get();

        return OrderResource::collection($parcels)
            ->additional([
                'meta' => [
                    'order_id' => (string) $order->id,
                    'reference' => (string) $order->reference,
                    'subtotal_minor' => (int) $order->subtotal_minor,
                    'discount_minor' => (int) $order->discount_minor,
                    'total_minor' => (int) $order->total_minor,
                ],
            ])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Resolve the submitted code, or return the refusal to send back.
     *
     * The minimum-spend test needs a subtotal, and the authoritative one is only
     * knowable under the lock — so this reads the prices first, the same pre-lock
     * read the web door makes, and shapes the lines as `CartSummary` expects. Two
     * consequences, both deliberate:
     *
     *  - a price that moves between this read and the transaction can only make
     *    the voucher *stricter* (a smaller basket is likelier to miss a minimum),
     *    never cheaper, because the discount actually charged is computed from the
     *    locked rows inside the service;
     *  - a line whose product no longer exists contributes nothing here, so a
     *    minimum-spend voucher may be refused on a basket that could not have been
     *    placed anyway.
     *
     * @param  list<array{product_id: int, quantity: int}>  $lines
     */
    private function resolveVoucher(string $code, array $lines, ?Voucher &$voucher): ?JsonResponse
    {
        $products = Product::query()
            ->withTrashed()
            ->with('seller')
            ->whereIn('id', array_column($lines, 'product_id'))
            ->get()
            ->keyBy('id');

        /** @var Collection<int, CartItem> $items */
        $items = collect($lines)
            ->map(function (array $line) use ($products): CartItem {
                $item = new CartItem([
                    'product_id' => $line['product_id'],
                    'quantity' => $line['quantity'],
                ]);

                // Never saved: these stand in for cart rows the phone owns, so the
                // money keeps its one spelling (`CartItem::lineTotalCentavos`).
                return $item->setRelation('product', $products->get($line['product_id']));
            })
            ->filter(fn (CartItem $item): bool => $item->product !== null)
            ->values();

        $notice = null;
        $voucher = Voucher::resolveCode($code, (new CartSummary($items))->subtotalCentavos, $notice);

        if ($voucher !== null) {
            return null;
        }

        return response()->json([
            'message' => $notice ?? 'This voucher code is no longer valid.',
            'voucher_rejected' => $code,
        ], 422);
    }
}
