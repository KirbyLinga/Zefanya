<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\Voucher;
use App\Services\CartSummary;
use App\Services\CartVariantResolver;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CartController extends Controller
{
    // ── Helpers ──────────────────────────────────────────────────────────────

    /** Set when a previously applied voucher was dropped during re-validation. */
    private ?string $voucherNotice = null;

    /** Load the buyer's cart items with all relations needed by the views. */
    private function loadItems(): Collection
    {
        return auth('buyer')->user()
            ->cartItems()
            ->with([
                'product' => fn ($q) => $q->withTrashed()->with(['images', 'seller', 'category']),
                'variant.optionValues',
            ])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
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
     * Recompute the cart totals and return them as JSON, so every mutating
     * endpoint answers with the same shape as `summary`.
     *
     * @param  array<string, mixed>  $extra
     */
    private function summaryResponse(Collection $items, ?array $selectedIds = null, array $extra = []): JsonResponse
    {
        $voucher = $this->resolveVoucher(
            (new CartSummary($items, null, $selectedIds))->subtotalCentavos
        );

        $summary = new CartSummary($items, $voucher, $selectedIds);

        return response()->json(array_merge(
            $summary->toJson($this->voucherNotice),
            $extra,
        ));
    }

    /**
     * Resolve the voucher stored in the session (re-validated on every request
     * against the current subtotal). An expired, inactive or below-min-spend
     * voucher is dropped and its reason is captured in $voucherNotice so the
     * page / JSON response can explain why.
     */
    private function resolveVoucher(int $subtotalCentavos): ?Voucher
    {
        $code = session('cart_voucher');

        if (! $code) {
            return null;
        }

        $voucher = Voucher::where('code', strtoupper($code))->first();

        $reason = $voucher === null
            ? 'This voucher code is no longer valid.'
            : $voucher->rejectionReason($subtotalCentavos);

        if ($reason !== null) {
            session()->forget('cart_voucher');
            $this->voucherNotice = $reason;

            return null;
        }

        return $voucher;
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function index(): View
    {
        $items = $this->loadItems();
        $voucher = $this->resolveVoucher((new CartSummary($items))->subtotalCentavos);
        $summary = new CartSummary($items, $voucher);

        return view('Buyer.Cart.index', [
            'items' => $items,
            'summary' => $summary,
            'voucher' => $voucher,
            'voucherNotice' => $this->voucherNotice,
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

        // `status = active` + the SoftDeletes default scope already excludes
        // drafts and deleted products.
        $product = Product::where('status', 'active')->whereHas('seller', fn ($query) => $query->where('status', 'approved'))
            ->find($validated['product_id']);

        if (! $product) {
            return response()->json(['error' => 'This product is not available.'], 422);
        }

        $variant = app(CartVariantResolver::class)->resolve(
            $product, isset($validated['variant_id']) ? (int) $validated['variant_id'] : null,
        );
        $variantId = $variant->id;

        $buyer = auth('buyer')->user();

        // Read-merge-write inside a transaction with a row lock. This protects
        // the unique(buyer_id, product_id, variant_id) constraint from
        // concurrent adds.
        // NOTE: this never touches variant/product stock — a cart is not a
        // stock reservation, so no inventory_transactions row is written here.
        try {
            // Missing-row gap locks can deadlock on concurrent first inserts.
            // Retry the entire read/merge/write transaction, never just INSERT.
            DB::transaction(function () use ($buyer, $product, $variantId, $validated): void {
                $currentProduct = Product::query()->find($product->id);
                if ($currentProduct === null) {
                    throw ValidationException::withMessages(['product_id' => 'This product is not available.']);
                }
                $currentVariant = app(CartVariantResolver::class)->resolve($currentProduct, $variantId);
                $stockCeiling = $currentVariant->stock_quantity;

                $existing = CartItem::where('buyer_id', $buyer->id)
                    ->where('product_id', $product->id)
                    ->where('variant_id', $variantId)
                    ->lockForUpdate()
                    ->first();

                $requested = (int) $validated['quantity'];
                $newQty = min(($existing?->quantity ?? 0) + $requested, $stockCeiling);

                if ($existing !== null) {
                    $existing->update(['quantity' => $newQty]);
                } else {
                    CartItem::create([
                        'buyer_id' => $buyer->id,
                        'product_id' => $product->id,
                        'variant_id' => $variantId,
                        'quantity' => min($requested, $stockCeiling),
                    ]);
                }

            }, attempts: 3);
        } catch (QueryException $exception) {
            if (! in_array((int) ($exception->errorInfo[1] ?? 0), [1213, 1205], true)) {
                throw $exception;
            }

            return response()->json(['error' => 'Your cart is busy. Please try again.'], 503);
        }

        // Count after commit rather than using an earlier retry snapshot.
        $cartCount = (int) CartItem::where('buyer_id', $buyer->id)->count();

        // Calculate price: use variant price if available, else product price
        $priceCentavos = (int) round((float) $variant->price * 100);
        $formattedPrice = '₱'.number_format($priceCentavos / 100, 2);

        return response()->json([
            'success' => true,
            'cart_count' => $cartCount,
            'product_name' => $product->name,
            'product_price' => $formattedPrice,
        ]);
    }

    // ── update (PATCH /buyer/cart/{id}) ──────────────────────────────────────

    public function update(Request $request, int $id): JsonResponse
    {
        $item = CartItem::where('id', $id)
            ->where('buyer_id', auth('buyer')->id())
            ->firstOrFail();

        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        // The product may have been soft-deleted since it was added, in which
        // case $item->product is null and we must not touch the quantity.
        $product = Product::withTrashed()->find($item->product_id);

        if ($product === null) {
            return response()->json(['error' => 'This product no longer exists.'], 422);
        }

        if (! $item->isAvailable()) {
            return response()->json(['error' => 'This item is no longer available.'], 422);
        }

        $item->update([
            'quantity' => min((int) $validated['quantity'], $item->availableQuantity()),
        ]);

        $item->refresh();

        return $this->summaryResponse($this->loadItems(), $this->selectedIdsFrom($request), [
            'item_id' => $item->id,
            'quantity' => $item->quantity,
            'line_total' => $item->formattedLineTotal(),
            'line_total_raw' => $item->lineTotalCentavos(),
        ]);
    }

    // ── destroy (DELETE /buyer/cart/{id}) ────────────────────────────────────

    public function destroy(Request $request, int $id): JsonResponse
    {
        $item = CartItem::where('id', $id)
            ->where('buyer_id', auth('buyer')->id())
            ->firstOrFail();

        $item->delete();

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

        $buyerId = (int) auth('buyer')->id();
        $ids = array_values(array_unique(array_map('intval', $validated['ids'])));

        // Every requested id must belong to this buyer, otherwise the whole
        // request is rejected — never silently delete only a subset.
        $ownedCount = CartItem::whereIn('id', $ids)->where('buyer_id', $buyerId)->count();

        if ($ownedCount !== count($ids)) {
            abort(403, 'One or more cart items do not belong to you.');
        }

        CartItem::whereIn('id', $ids)->where('buyer_id', $buyerId)->delete();

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
        $voucher = $this->resolveVoucher((new CartSummary($items))->subtotalCentavos);
        $summary = new CartSummary($items, $voucher);

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
        ]);

        $code = strtoupper(trim($validated['code']));
        $voucher = Voucher::where('code', $code)->first();

        if ($voucher === null) {
            return response()->json(['error' => 'Voucher code not found.'], 422);
        }

        $items = $this->loadItems();
        $selectedIds = $this->selectedIdsFrom($request);
        $subtotal = (new CartSummary($items, null, $selectedIds))->subtotalCentavos;
        $reason = $voucher->rejectionReason($subtotal);

        if ($reason !== null) {
            return response()->json(['error' => $reason], 422);
        }

        session(['cart_voucher' => $code]);
        $this->voucherNotice = null;

        $summary = new CartSummary($items, $voucher, $selectedIds);

        return response()->json(array_merge($summary->toJson(), ['applied' => true]));
    }

    // ── removeVoucher (DELETE /buyer/cart/voucher) ────────────────────────────

    public function removeVoucher(Request $request): JsonResponse
    {
        session()->forget('cart_voucher');
        $this->voucherNotice = null;

        $summary = new CartSummary($this->loadItems(), null, $this->selectedIdsFrom($request));

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
    }
}
