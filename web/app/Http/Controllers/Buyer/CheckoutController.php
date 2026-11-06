<?php

namespace App\Http\Controllers\Buyer;

use App\Exceptions\CheckoutException;
use App\Http\Controllers\Controller;
use App\Models\Buyer\Buyer;
use App\Models\Buyer\BuyerAddress;
use App\Models\Buyer\CartItem;
use App\Models\Buyer\CheckoutToken;
use App\Models\Seller\Seller;
use App\Models\Shared\Order;
use App\Models\Shared\Voucher;
use App\Services\Buyer\BuyerAddressBook;
use App\Services\Buyer\CartSummary;
use App\Services\Buyer\CheckoutCarrierService;
use App\Services\Buyer\CheckoutService;
use App\Services\Buyer\VoucherSessionService;
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
        $checkoutToken = app(CheckoutService::class)->issueToken($buyer, $base->sellerGroups);
        session([self::TOKEN_KEY => $checkoutToken->token]);
        $carrierOptions = $carrierService->optionsForCheckout($deliverTo, $base->sellerGroups, $checkoutToken->shipping_rates_snapshot);
        $carriersAvailable = $carrierService->allRoutesAvailable($carrierOptions);
        $old = $request->old('carriers', []);
        $defaultShippingFees = app(CheckoutCarrierService::class)->defaultFees($carrierOptions, is_array($old) ? $old : []);

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
            'addressFingerprint' => app(CheckoutService::class)->addressFingerprint($deliverTo),
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

        // The rate snapshot below reads this table before placeOrder() does, so
        // an unready schema has to stop here to fail closed for the buyer.
        if (! Schema::hasTable('checkout_tokens')) {
            return redirect()->route('buyer.cart.index')->with('error', 'Checkout is temporarily unavailable. Please try again later.');
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

        $problems = app(CheckoutService::class)->selectionProblems($items, $parkedIds);

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
        if (is_string($reviewedAddress) && ! hash_equals(app(CheckoutService::class)->addressFingerprint($deliverTo), $reviewedAddress)) {
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

        app(CheckoutService::class)->notifySellers($order);

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
     * The transactional placement unit now lives in CheckoutService; kept here
     * with its original signature as the seam the concurrency tests drive.
     *
     * @param  Collection<int, CartItem>  $items
     * @param  array<int|string, mixed>  $notes
     */
    private function placeOrder(Buyer $buyer, Collection $items, CartSummary $summary, ?string $formToken = null, array $carrierQuote = [], array $notes = [], ?BuyerAddress $address = null): Order
    {
        return app(CheckoutService::class)->placeOrder($buyer, $items, $summary, $formToken, $carrierQuote, $notes, $address);
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
