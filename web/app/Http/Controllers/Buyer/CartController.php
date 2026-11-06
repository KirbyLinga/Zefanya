<?php

namespace App\Http\Controllers\Buyer;

use App\Exceptions\CartItemUnavailableException;
use App\Http\Controllers\Controller;
use App\Models\Buyer\CartItem;
use App\Models\Catalog\Product;
use App\Models\Seller\Seller;
use App\Models\Shared\Voucher;
use App\Services\Buyer\CartService;
use App\Services\Buyer\CartSummary;
use App\Services\Buyer\CartVariantResolver;
use App\Services\Buyer\VoucherApplication;
use App\Services\Buyer\VoucherSessionService;
use App\Services\Shared\PriceResolver;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class CartController extends Controller
{
    // ── Helpers ──────────────────────────────────────────────────────────────

    /** Set when a previously applied voucher was dropped during re-validation. */
    private ?string $voucherNotice = null;

    /** Load the buyer's cart items with all relations needed by the views. */
    private function loadItems(): Collection
    {
        return app(CartService::class)->items(auth('buyer')->user());
    }

    /**
     * Resolve the `selected_ids` input.
     *
     * Returns null when the key is absent (meaning "no selection was sent" —
     * the summary then covers every available item), and an array of positive
     * ids otherwise. An empty array therefore means "nothing is selected",
     * which is what allows the checkout button to disable and the total to go
     * to zero.
     *
     * @return array<int>|null
     */
    private function selectedIdsFrom(Request $request): ?array
    {
        if (! $request->has('selected_ids')) {
            return null;
        }

        return array_values(array_filter(
            array_map('intval', (array) $request->input('selected_ids', [])),
            fn (int $id): bool => $id > 0,
        ));
    }

    /**
     * Normalize an optional voucher slot from the request. `slot=shop` plus
     * `seller_id` is accepted as shorthand for `shop:{seller_id}`.
     *
     * @param  array<string, mixed>  $validated
     */
    private function requestedSlot(array $validated): ?string
    {
        $slot = $validated['slot'] ?? $validated['scope'] ?? null;

        if ($slot === null && isset($validated['seller_id'])) {
            return 'shop:'.(int) $validated['seller_id'];
        }

        if ($slot === 'shop' && isset($validated['seller_id'])) {
            return 'shop:'.(int) $validated['seller_id'];
        }

        return is_string($slot) && $slot !== '' ? $slot : null;
    }

    /**
     * Recompute the cart totals and return them as JSON, so every mutating
     * endpoint answers with the same shape as `summary`.
     *
     * @param  array<string, mixed>  $extra
     */
    private function summaryResponse(Collection $items, ?array $selectedIds = null, array $extra = []): JsonResponse
    {
        $base = new CartSummary($items, null, $selectedIds);
        $application = $this->resolveVouchers($base);

        $summary = new CartSummary($items, $application, $selectedIds);

        return response()->json(array_merge(
            $summary->toJson($this->voucherNotice),
            $extra,
        ));
    }

    /**
     * Resolve, re-validate, and stack the vouchers stored in the session. Any
     * expired/inactive/below-minimum voucher is dropped by the session service
     * and its reason is captured in $voucherNotice.
     */
    private function resolveVouchers(CartSummary $base): VoucherApplication
    {
        $notice = null;
        $application = app(VoucherSessionService::class)->resolve(
            $base->sellerGroups,
            $base->subtotalCentavos,
            [],
            $notice,
        );

        $this->voucherNotice = $notice;

        return $application;
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function index(): View
    {
        $items = $this->loadItems();
        $base = new CartSummary($items);
        $application = $this->resolveVouchers($base);
        $summary = new CartSummary($items, $application);

        return view('Buyer.Cart.index', [
            'items' => $items,
            'summary' => $summary,
            'voucher' => $summary->voucher,
            'vouchers' => $application->toArray(),
            'voucherNotice' => $this->voucherNotice,
            'shopVouchers' => Voucher::offeredBy(
                $summary->sellerGroups->map(fn (array $group): int => (int) $group['seller']->getKey())->all(),
                (int) auth('buyer')->id(),
            )->groupBy('seller_id'),
        ]);
    }

    // ── add (POST /buyer/cart/add — open to guests via the 401 pattern) ──────

    public function add(Request $request): JsonResponse
    {
        if (! auth('buyer')->check()) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        $validated = $request->validate([
            'product_id' => ['required', 'integer'],
            'variant_id' => ['nullable', 'integer'],
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        $buyer = auth('buyer')->user();

        try {
            $added = app(CartService::class)->add(
                $buyer,
                (int) $validated['product_id'],
                isset($validated['variant_id']) ? (int) $validated['variant_id'] : null,
                (int) $validated['quantity'],
            );
        } catch (CartItemUnavailableException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        } catch (QueryException $exception) {
            if (! in_array((int) ($exception->errorInfo[1] ?? 0), [1213, 1205], true)) {
                throw $exception;
            }

            return response()->json(['error' => 'Your cart is busy. Please try again.'], 503);
        }

        // Count after commit rather than using an earlier retry snapshot.
        $cartCount = (int) CartItem::where('buyer_id', $buyer->id)->count();

        ['product' => $product, 'variant' => $variant] = $added;
        $formattedPrice = app(PriceResolver::class)->forVariant($product, $variant)->formatted();

        return response()->json([
            'success' => true,
            'cart_count' => $cartCount,
            'product_name' => $product->name,
            'product_price' => $formattedPrice,
            'quantity' => $added['quantity'],
            'quantity_limit' => $added['quantity_limit'],
            'quantity_warning' => $added['quantity_warning'],
        ]);
    }

    // ── update (PATCH /buyer/cart/{id}) ──────────────────────────────────────

    public function update(Request $request, int $id): JsonResponse
    {
        // Ownership (404) is checked before validation, as before.
        CartItem::where('id', $id)
            ->where('buyer_id', auth('buyer')->id())
            ->firstOrFail();

        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $updated = app(CartService::class)->updateQuantity(auth('buyer')->user(), $id, (int) $validated['quantity']);
        } catch (CartItemUnavailableException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }

        $item = $updated['item'];

        return $this->summaryResponse($this->loadItems(), $this->selectedIdsFrom($request), [
            'item_id' => $item->id,
            'quantity' => $item->quantity,
            'quantity_limit' => $updated['quantity_limit'],
            'quantity_warning' => $updated['quantity_warning'],
            'line_total' => $item->formattedLineTotal(),
            'line_total_raw' => $item->lineTotalCentavos(),
        ]);
    }

    // ── destroy (DELETE /buyer/cart/{id}) ────────────────────────────────────

    public function destroy(Request $request, int $id): JsonResponse
    {
        $item = app(CartService::class)->remove(auth('buyer')->user(), $id);

        return $this->summaryResponse($this->loadItems(), $this->selectedIdsFrom($request), [
            'removed_id' => $item->id,
        ]);
    }

    // ── bulkDestroy (DELETE /buyer/cart — removes selected ids) ──────────────

    public function bulkDestroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $ids = app(CartService::class)->removeMany(auth('buyer')->user(), $validated['ids']);

        return $this->summaryResponse($this->loadItems(), $this->selectedIdsFrom($request), [
            'removed_ids' => $ids,
        ]);
    }

    // ── summary (GET /buyer/cart/summary — lightweight JSON for JS refresh) ──

    public function summary(Request $request): JsonResponse
    {
        return $this->summaryResponse($this->loadItems(), $this->selectedIdsFrom($request));
    }

    // ── miniCart (GET /buyer/cart/mini — for the navbar dropdown) ────────────

    public function miniCart(Request $request): JsonResponse
    {
        $items = $this->loadItems();
        $base = new CartSummary($items);
        $application = $this->resolveVouchers($base);
        $summary = new CartSummary($items, $application);

        $itemsData = $items->map(fn (CartItem $item) => [
            'id' => $item->id,
            'name' => $item->product?->name ?? '(Deleted)',
            'price' => "\u{20B1}".number_format($item->unitPriceCentavos() / 100, 2),
            'price_centavos' => $item->unitPriceCentavos(),
            'quantity' => $item->quantity,
            'variant' => $item->cartVariantLabel(),
            'image_url' => $item->cartImageUrl(),
            'is_available' => $item->isAvailable(),
        ]);

        return response()->json([
            'items' => $itemsData,
            'count' => (int) $items->count(),
            'subtotal' => $summary->formattedSubtotal(),
            'subtotal_centavos' => $summary->subtotalCentavos,
            'total' => $summary->formattedTotal(),
            'total_centavos' => $summary->totalCentavos,
            'has_items' => $items->isNotEmpty(),
            'checkout_url' => route('buyer.checkout.index'),
        ]);
    }

    // ── applyVoucher (POST /buyer/cart/voucher) ───────────────────────────────

    public function applyVoucher(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'slot' => ['nullable', 'string', 'max:64'],
            'scope' => ['nullable', 'string', 'max:20'],
            'seller_id' => ['nullable', 'integer'],
        ]);

        $items = $this->loadItems();
        $selectedIds = $this->selectedIdsFrom($request);
        $base = new CartSummary($items, null, $selectedIds);

        $service = app(VoucherSessionService::class);

        $refusal = app(CartService::class)->applyVoucher(
            auth('buyer')->user(), $service, $items, $validated['code'], $this->requestedSlot($validated), $selectedIds,
        );
        if ($refusal !== null) {
            return response()->json(['error' => $refusal], 422);
        }

        $resolveNotice = null;
        $application = $service->resolve($base->sellerGroups, $base->subtotalCentavos, [], $resolveNotice);
        $this->voucherNotice = $resolveNotice;

        $summary = new CartSummary($items, $application, $selectedIds);

        return response()->json(array_merge($summary->toJson($this->voucherNotice), ['applied' => true]));
    }

    // ── removeVoucher (DELETE /buyer/cart/voucher) ────────────────────────────

    public function removeVoucher(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'slot' => ['nullable', 'string', 'max:64'],
            'scope' => ['nullable', 'string', 'max:20'],
            'seller_id' => ['nullable', 'integer'],
        ]);

        $slot = $this->requestedSlot($validated);

        app(VoucherSessionService::class)->remove($slot);
        $this->voucherNotice = null;

        $items = $this->loadItems();
        $selectedIds = $this->selectedIdsFrom($request);
        $base = new CartSummary($items, null, $selectedIds);
        $application = $this->resolveVouchers($base);
        $summary = new CartSummary($items, $application, $selectedIds);

        return response()->json(array_merge($summary->toJson(), ['removed' => true]));
    }

    /**
     * "Buy Now" from the PDP: park exactly ONE cart line for this item and
     * return the checkout URL scoped to it, so the rest of the cart is never
     * pulled into the order. Quantity is SET (not added).
     */
    public function buyNow(Request $request): JsonResponse
    {
        $buyer = auth('buyer')->user();

        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'variant_id' => ['nullable', 'integer'],
            'quantity' => ['required', 'integer', 'min:1', 'max:99'],
        ]);

        $product = Product::query()->whereHas('seller', fn ($query) => $query->where('status', 'approved'))->with('variants')->find($data['product_id']);

        if ($product === null || ! $product->isListedForSale()) {
            return response()->json(['success' => false, 'message' => 'This product is no longer available.'], 422);
        }

        return DB::transaction(function () use ($buyer, $product, $data): JsonResponse {
            $product->setRelation('seller', Seller::query()->whereKey($product->seller_id)->sharedLock()->first());
            $variant = app(CartVariantResolver::class)->resolve(
                $product, isset($data['variant_id']) ? (int) $data['variant_id'] : null,
            );

            $item = $buyer->cartItems()
                ->where('product_id', $product->id)
                ->when(
                    $variant !== null,
                    fn ($q) => $q->where(fn ($w) => $w
                        ->where('variant_id', $variant->id)
                        ->when(! $product->hasVariants(), fn ($w2) => $w2->orWhereNull('variant_id'))),
                    fn ($q) => $q->whereNull('variant_id'),
                )
                ->first() ?? new CartItem([
                    'buyer_id' => $buyer->id,
                    'product_id' => $product->id,
                    'quantity' => 1,
                ]);

            $item->variant_id = $variant?->id;
            $item->setRelation('product', $product);
            $item->setRelation('variant', $variant);

            $ceiling = $item->availableQuantity();

            if (! $item->isAvailable() || $ceiling < 1) {
                return response()->json(['success' => false, 'message' => 'Sorry, this item is out of stock.'], 422);
            }

            $item->quantity = min($data['quantity'], $ceiling);
            $item->save();

            return response()->json([
                'success' => true,
                'redirect' => route('buyer.checkout.index', ['items' => [$item->id]]),
                'cart_count' => (int) CartItem::query()->where('buyer_id', $buyer->id)->count(),
                'capped' => $item->quantity < $data['quantity'],
            ]);
        }, 3);
    }
}
