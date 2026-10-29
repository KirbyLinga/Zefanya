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
 * The buyer's own order history: `GET /api/orders`, checkout: `POST /api/orders`,
 * and lightweight milestone tracker: `GET /api/orders/{sellerOrder}/milestone`.
 */
class OrderController extends Controller
{
    /**
     * The placing rules are the web checkout's, to the centavo.
     */
    public function __construct(private readonly OrderPlacer $orderPlacer) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $buyer = $request->user();

        // `auth:sanctum` accepts any tokenable; only buyers can hold one today.
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
            ->with(['seller', 'orderItems', 'order:id,reference'])
            ->get();

        return OrderResource::collection($orders);
    }

    /**
     * `POST /api/orders` — place the order for the phone's basket.
     */
    public function store(Request $request): JsonResponse
    {
        $buyer = $request->user();

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
     * `GET /api/orders/{sellerOrder}/milestone`
     *
     * Lightweight poll target for the tracker screen. Ownership is checked
     * against the parent order's buyer.
     */
    public function milestone(Request $request, SellerOrder $sellerOrder): JsonResponse
    {
        $buyer = $request->user();

        abort_unless(
            $buyer instanceof Buyer,
            403,
            'This token belongs to a different kind of account.',
        );

        abort_unless(
            (int) $sellerOrder->order->buyer_id === (int) $buyer->id,
            403,
            'This order belongs to another customer.',
        );

        $statusStr = $sellerOrder->status instanceof \BackedEnum
            ? $sellerOrder->status->value
            : (string) $sellerOrder->status;

        return response()->json([
            'milestone' => $statusStr,
        ]);
    }

    /**
     * Resolve the submitted code, or return the refusal to send back.
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
