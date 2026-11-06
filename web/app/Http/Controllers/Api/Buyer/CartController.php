<?php

namespace App\Http\Controllers\Api\Buyer;

use App\Exceptions\CartItemUnavailableException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Traits\ApiResponses;
use App\Http\Resources\Mobile\ProductCardResource;
use App\Models\Buyer\Buyer;
use App\Models\Buyer\CartItem;
use App\Models\Shared\Voucher;
use App\Services\Buyer\CartService;
use App\Services\Buyer\VoucherSessionService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Buyer mobile cart and voucher slots.
 *
 * Lines live in the same cart_items table as the web cart (CartService).
 * Vouchers: the web keeps the buyer's slot => code choices in the session;
 * a bearer client has none, so the app sends them as `vouchers[slot]=CODE`
 * with each request and VoucherSessionService (stateless copy, same rules,
 * buyer-specific limits included) re-validates them every time. The
 * response's `vouchers_state` is what the app should send next time.
 */
class CartController extends Controller
{
    use ApiResponses;

    public function __construct(private readonly CartService $cart) {}

    public function show(Request $request): JsonResponse
    {
        return $this->cartResponse($request, 'Cart retrieved.');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'variant_id' => ['nullable', 'integer'],
            'quantity' => ['required', 'integer', 'min:1', 'max:999'],
        ]);

        try {
            $added = $this->cart->add($this->buyer($request), (int) $data['product_id'], isset($data['variant_id']) ? (int) $data['variant_id'] : null, (int) $data['quantity']);
        } catch (CartItemUnavailableException $e) {
            return $this->error($e->getMessage(), ['product_id' => [$e->getMessage()]]);
        } catch (QueryException $e) {
            if (! in_array((int) ($e->errorInfo[1] ?? 0), [1213, 1205], true)) {
                throw $e;
            }

            return $this->error('Your cart is busy. Please try again.', [], 503);
        }

        return $this->cartResponse($request, 'Added to cart.', 201, [
            'quantity' => $added['quantity'],
            'quantity_limit' => $added['quantity_limit'],
            'quantity_warning' => $added['quantity_warning'],
        ]);
    }

    public function update(Request $request, int $item): JsonResponse
    {
        $data = $request->validate(['quantity' => ['required', 'integer', 'min:1', 'max:999']]);

        try {
            $updated = $this->cart->updateQuantity($this->buyer($request), $item, (int) $data['quantity']);
        } catch (CartItemUnavailableException $e) {
            return $this->error($e->getMessage(), ['quantity' => [$e->getMessage()]]);
        }

        return $this->cartResponse($request, 'Cart updated.', 200, [
            'quantity' => (int) $updated['item']->quantity,
            'quantity_limit' => $updated['quantity_limit'],
            'quantity_warning' => $updated['quantity_warning'],
        ]);
    }

    public function destroy(Request $request, int $item): JsonResponse
    {
        $this->cart->remove($this->buyer($request), $item);

        return $this->cartResponse($request, 'Removed from cart.');
    }

    public function destroyMany(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:200'], 'ids.*' => ['integer']]);
        $this->cart->removeMany($this->buyer($request), $data['ids']);

        return $this->cartResponse($request, 'Removed from cart.');
    }

    public function applyVoucher(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'slot' => ['nullable', 'string', 'max:64'],
            'seller_id' => ['nullable', 'integer'],
        ]);

        $buyer = $this->buyer($request);
        $vouchers = $this->vouchers($request);
        $slot = $data['slot'] ?? null;
        if (($slot === null || $slot === 'shop') && isset($data['seller_id'])) {
            $slot = 'shop:'.(int) $data['seller_id'];
        }

        $refusal = $this->cart->applyVoucher($buyer, $vouchers, $this->cart->items($buyer), $data['code'], $slot, $this->selectedIds($request));
        if ($refusal !== null) {
            return $this->error($refusal, ['code' => [$refusal]]);
        }

        return $this->cartResponse($request, 'Voucher applied.', 200, [], $vouchers);
    }

    /**
     * Claimable shop vouchers for the sellers in the cart (or the given
     * seller_ids) — the same Voucher::offeredBy list the web cart shows,
     * minus vouchers this buyer has used up.
     */
    public function offers(Request $request): JsonResponse
    {
        $data = $request->validate(['seller_ids' => ['nullable', 'array', 'max:50'], 'seller_ids.*' => ['integer']]);
        $buyer = $this->buyer($request);
        $sellerIds = $data['seller_ids'] ?? $this->cart->items($buyer)
            ->map(fn (CartItem $item) => $item->product?->seller_id)->filter()->unique()->values()->all();

        return $this->success('Vouchers retrieved.', Voucher::offeredBy(array_map('intval', $sellerIds), (int) $buyer->id)
            ->map(fn (Voucher $voucher): array => [
                'code' => $voucher->code,
                'scope' => $voucher->effectiveScope()->value,
                'seller_id' => $voucher->seller_id,
                'type' => $voucher->type->value,
                'value' => (int) $voucher->value,
                'max_discount_minor' => $voucher->max_discount,
                'min_spend_minor' => $voucher->min_spend,
                'expires_at' => $voucher->expires_at?->toIso8601String(),
                'summary' => $voucher->offerSummary(),
            ])->values());
    }

    public function removeVoucher(Request $request): JsonResponse
    {
        $data = $request->validate(['slot' => ['nullable', 'string', 'max:64']]);
        $vouchers = $this->vouchers($request);
        $vouchers->remove($data['slot'] ?? null);

        return $this->cartResponse($request, 'Voucher removed.', 200, [], $vouchers);
    }

    /** @param  array<string, mixed>  $extra */
    private function cartResponse(Request $request, string $message, int $status = 200, array $extra = [], ?VoucherSessionService $vouchers = null): JsonResponse
    {
        $buyer = $this->buyer($request);
        $vouchers ??= $this->vouchers($request);
        $items = $this->cart->items($buyer);
        ['summary' => $summary, 'notice' => $notice] = $this->cart->summary($items, $vouchers, $this->selectedIds($request));

        return $this->success($message, [
            ...$extra,
            'items' => $items->map(fn (CartItem $item): array => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'variant_id' => $item->variant_id,
                'name' => $item->product?->name ?? '(Deleted)',
                'seller' => $item->product?->seller === null ? null : ['id' => $item->product->seller->id, 'name' => $item->product->seller->business_name],
                'variant' => $item->cartVariantLabel(),
                'image_url' => ProductCardResource::absoluteUrl($item->cartImageUrl()),
                'quantity' => (int) $item->quantity,
                'unit_price_minor' => $item->unitPriceCentavos(),
                'line_total_minor' => $item->lineTotalCentavos(),
                'available' => $item->isAvailable(),
                'available_quantity' => $item->availableQuantity(),
                'offer_note' => $item->offerNote(),
            ])->values(),
            'summary' => $summary->toJson($notice),
            'vouchers_state' => (object) $vouchers->appliedState(),
        ], $status);
    }

    private function vouchers(Request $request): VoucherSessionService
    {
        $state = $request->input('vouchers', []);

        return app(VoucherSessionService::class)->forStatelessBuyer(
            (int) $this->buyer($request)->id,
            is_array($state) ? array_slice($state, 0, 20, true) : [],
        );
    }

    /** @return array<int>|null null = every available line, as on the web */
    private function selectedIds(Request $request): ?array
    {
        if (! $request->has('selected_ids')) {
            return null;
        }

        return array_values(array_filter(
            array_map('intval', (array) $request->input('selected_ids', [])),
            fn (int $id): bool => $id > 0,
        ));
    }

    private function buyer(Request $request): Buyer
    {
        return $request->user();
    }
}
