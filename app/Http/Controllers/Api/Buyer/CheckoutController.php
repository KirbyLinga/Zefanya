<?php

namespace App\Http\Controllers\Api\Buyer;

use App\Exceptions\CheckoutException;
use App\Http\Controllers\Buyer\CheckoutController as WebCheckoutController;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Traits\ApiResponses;
use App\Http\Resources\Mobile\ShipmentResource;
use App\Models\Buyer\Buyer;
use App\Models\Buyer\CartItem;
use App\Models\Buyer\CheckoutToken;
use App\Models\Shared\Order;
use App\Services\Buyer\BuyerAddressBook;
use App\Services\Buyer\CartSummary;
use App\Services\Buyer\CheckoutCarrierService;
use App\Services\Buyer\CheckoutService;
use App\Services\Buyer\VoucherSessionService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Buyer mobile checkout (Cash on Delivery).
 *
 *  1. POST /api/buyer/checkout/quote — the web checkout page as JSON: the
 *     selected lines, carriers and server fees per shop, vouchers applied,
 *     totals, a one-time checkout token and the four review fingerprints.
 *  2. POST /api/buyer/checkout — the web store(): every guard re-runs, each
 *     fingerprint must match what the buyer reviewed (else 409 + re-quote),
 *     and CheckoutService::placeOrder writes the order under row locks,
 *     consuming the one-time token (a repeat answers 409, never a second order).
 *
 * Nothing priced is accepted from the client: item ids, an address id,
 * carrier choices, voucher codes and notes only.
 */
class CheckoutController extends Controller
{
    use ApiResponses;

    public function __construct(private readonly CheckoutService $checkout) {}

    public function quote(Request $request, BuyerAddressBook $addresses, CheckoutCarrierService $carriers): JsonResponse
    {
        $data = $this->validateCommon($request);
        $buyer = $this->buyer($request);

        if (! Schema::hasTable('checkout_tokens')) {
            return $this->error('Checkout is temporarily unavailable. Please try again later.', [], 503);
        }

        $items = $this->lines($buyer, $data['item_ids']);
        $problems = $this->checkout->selectionProblems($items, $data['item_ids']);
        if ($problems !== []) {
            return $this->conflict('Some items are no longer available: '.implode(', ', array_unique($problems)).'. Please review your cart.');
        }

        try {
            $address = $addresses->chosen($buyer, $data['address_id'] ?? null);
        } catch (CheckoutException $e) {
            return $this->error($e->getMessage(), ['address_id' => [$e->getMessage()]]);
        }
        $deliverTo = $addresses->deliveringTo($buyer, $address);

        $base = new CartSummary($items, null, null);
        $token = $this->checkout->issueToken($buyer, $base->sellerGroups);
        $options = $carriers->optionsForCheckout($deliverTo, $base->sellerGroups, $token->shipping_rates_snapshot);
        $fees = $carriers->defaultFees($options, (array) ($data['carriers'] ?? []));

        $vouchers = $this->vouchers($request, $buyer);
        $notice = null;
        $application = $vouchers->resolve($base->sellerGroups, $base->subtotalCentavos, $fees, $notice);
        $summary = new CartSummary($items, $application, null);

        $shippingTotal = array_sum($fees);
        $shippingDiscount = $application->shippingDiscountCentavos;

        return $this->success('Checkout quote ready.', [
            'checkout_token' => $token->token,
            'item_ids' => $data['item_ids'],
            'address' => $address === null ? null : AddressController::present($address),
            'deliver_to' => $this->checkout->addressSnapshot($deliverTo),
            'items' => $summary->availableItems->map(fn (CartItem $item): array => [
                'id' => $item->id,
                'name' => $item->product?->name,
                'variant' => $item->cartVariantLabel(),
                'seller_id' => $item->product?->seller_id,
                'quantity' => (int) $item->quantity,
                'unit_price_minor' => $item->unitPriceCentavos(),
                'line_total_minor' => $item->lineTotalCentavos(),
            ])->values(),
            'shipping' => [
                'all_routes_available' => $carriers->allRoutesAvailable($options),
                'options' => $options,
                'selected_fees' => (object) $fees,
            ],
            'payment_method' => 'cod',
            'totals' => [
                'merchandise_subtotal_minor' => $summary->subtotalCentavos,
                'merchandise_discount_minor' => $summary->discountCentavos,
                'merchandise_total_minor' => $summary->totalCentavos,
                'shipping_total_minor' => $shippingTotal,
                'shipping_discount_minor' => $shippingDiscount,
                'payable_total_minor' => max(0, $summary->totalCentavos + $shippingTotal - $shippingDiscount),
            ],
            'vouchers' => $application->toArray(),
            'vouchers_state' => (object) $vouchers->appliedState(),
            'voucher_notice' => $notice,
            'fingerprints' => [
                'price' => $base->priceFingerprint(),
                'address' => $this->checkout->addressFingerprint($deliverTo),
                'shipping' => $carriers->quoteFingerprint($options),
                'voucher' => $application->reviewFingerprint(),
            ],
        ]);
    }

    public function store(Request $request, BuyerAddressBook $addresses, CheckoutCarrierService $carriers): JsonResponse
    {
        $data = $this->validateCommon($request, placing: true);
        $buyer = $this->buyer($request);

        if (! Schema::hasTable('checkout_tokens')) {
            return $this->error('Checkout is temporarily unavailable. Please try again later.', [], 503);
        }

        if ($request->filled('shipping_fee') || $request->filled('shipping_fees') || $request->filled('shipping_total')) {
            return $this->error('Shipping fees are calculated by the server and cannot be submitted.', ['shipping' => ['Shipping fees are calculated by the server and cannot be submitted.']]);
        }

        $parkedIds = $data['item_ids'];
        $items = $this->lines($buyer, $parkedIds);
        $problems = $this->checkout->selectionProblems($items, $parkedIds);
        if ($problems !== []) {
            return $this->conflict('Some items are no longer available: '.implode(', ', array_unique($problems)).'. Please review your cart.');
        }

        $base = new CartSummary($items, null, null);

        try {
            $address = $addresses->chosen($buyer, $data['address_id'] ?? null);
        } catch (CheckoutException $e) {
            return $this->conflict($e->getMessage());
        }
        $deliverTo = $addresses->deliveringTo($buyer, $address);

        if (! hash_equals($this->checkout->addressFingerprint($deliverTo), $data['address_fingerprint'])) {
            return $this->conflict('Your delivery address changed while you were checking out. Please check it and place your order again.');
        }

        if (! hash_equals($base->priceFingerprint(), $data['price_fingerprint'])) {
            return $this->conflict('A product price changed while you were checking out. Please review the updated total and place your order again.');
        }

        $rateSnapshot = CheckoutToken::query()->where('buyer_id', $buyer->id)->where('token', $data['checkout_token'])
            ->where('used', false)->where('expires_at', '>', now())->first()?->shipping_rates_snapshot;

        try {
            $carrierQuote = $carriers->validateSelection($deliverTo, $base->sellerGroups, (array) ($data['carriers'] ?? []), $rateSnapshot);
        } catch (ValidationException $e) {
            return $this->error('Shipping is not available for one or more shops in your order. Review carrier options or your delivery address.', $e->errors());
        }
        $carrierQuote['rate_snapshot'] = $rateSnapshot;

        if (! hash_equals($carriers->quoteFingerprint($carrierQuote['options']), $data['shipping_fingerprint'])) {
            return $this->conflict('Shipping fees changed while you were checking out. Please review the updated total and place your order again.');
        }

        $vouchers = $this->vouchers($request, $buyer);
        $notice = null;
        $application = $vouchers->resolve($base->sellerGroups, $base->subtotalCentavos, $carrierQuote['fees'], $notice);

        // A voucher that stopped applying must not quietly raise what the buyer pays.
        if ($notice !== null || ! hash_equals($application->reviewFingerprint(), $data['voucher_fingerprint'])) {
            return $this->conflict(($notice ?? 'A voucher on this order changed.').' Please review the updated total and place your order again.');
        }

        $summary = new CartSummary($items, $application, null);

        try {
            $order = $this->checkout->placeOrder($buyer, $items, $summary, $data['checkout_token'], $carrierQuote, (array) ($data['notes'] ?? []), $address, $vouchers);
        } catch (CheckoutException $e) {
            return $this->conflict($e->getMessage());
        }

        $this->checkout->notifySellers($order);
        $buyer->cartItems()->whereIn('id', $parkedIds)->delete();

        return $this->success('Order placed.', $this->present($order), 201);
    }

    /** @return array<string, mixed> */
    private function validateCommon(Request $request, bool $placing = false): array
    {
        $max = WebCheckoutController::NOTE_MAX_LENGTH;

        $data = $request->validate([
            'item_ids' => ['required', 'array', 'min:1', 'max:100'],
            'item_ids.*' => ['integer', 'min:1'],
            'address_id' => ['nullable', 'integer'],
            'carriers' => ['nullable', 'array'],
            'carriers.*' => ['nullable', 'integer'],
            'vouchers' => ['nullable', 'array', 'max:20'],
            'vouchers.*' => ['nullable', 'string', 'max:64'],
            ...($placing ? [
                'checkout_token' => ['required', 'string', 'max:128'],
                'price_fingerprint' => ['required', 'string', 'max:128'],
                'address_fingerprint' => ['required', 'string', 'max:128'],
                'shipping_fingerprint' => ['required', 'string', 'max:128'],
                'voucher_fingerprint' => ['required', 'string', 'max:128'],
                'notes' => ['nullable', 'array'],
                'notes.*' => ['nullable', 'string', 'max:'.$max],
            ] : []),
        ], [
            'notes.*.string' => 'Your note to the seller must be text.',
            'notes.*.max' => 'A note to the seller can be up to '.$max.' characters.',
        ]);

        $data['item_ids'] = array_values(array_unique(array_map('intval', $data['item_ids'])));

        return $data;
    }

    /**
     * The buyer's own cart rows for the requested ids, keyed by id. A tampered
     * id is simply absent and reported as "no longer in your cart".
     *
     * @param  array<int>  $ids
     */
    private function lines(Buyer $buyer, array $ids): Collection
    {
        return $buyer->cartItems()
            ->with(['product' => fn ($q) => $q->withTrashed()->with(['images', 'seller', 'category']), 'variant.optionValues.option'])
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');
    }

    private function vouchers(Request $request, Buyer $buyer): VoucherSessionService
    {
        $state = $request->input('vouchers', []);

        return app(VoucherSessionService::class)->forStatelessBuyer((int) $buyer->id, is_array($state) ? $state : []);
    }

    /** @return array<string, mixed> */
    private function present(Order $order): array
    {
        $order->load(['sellerOrders.seller', 'sellerOrders.orderItems.product', 'sellerOrders.payment']);

        return [
            'id' => $order->id,
            'reference' => $order->reference,
            'status' => $order->status?->value,
            'subtotal_minor' => (int) $order->subtotal_minor,
            'discount_minor' => (int) $order->discount_minor,
            'shipping_discount_minor' => (int) $order->shipping_discount_minor,
            'total_minor' => (int) $order->total_minor,
            'payment_method' => 'cod',
            'shipping_address' => $order->shipping_address,
            'shipments' => ShipmentResource::collection($order->sellerOrders)->resolve(),
            'created_at' => $order->created_at?->toIso8601String(),
        ];
    }

    private function conflict(string $message): JsonResponse
    {
        return $this->error($message, ['checkout' => [$message]], 409);
    }

    private function buyer(Request $request): Buyer
    {
        return $request->user();
    }
}
