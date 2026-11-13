<?php

namespace App\Services\Buyer;

use App\Models\Buyer\Buyer;
use App\Models\Buyer\CartItem;
use App\Models\Catalog\Product;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/** Read-only adapter over the same cart prices, vouchers and carriers as placement. */
class CheckoutPreviewService
{
    public function quote(Buyer $buyer, array $data): array
    {
        $addresses = app(BuyerAddressBook::class);
        $address = $addresses->chosen($buyer, $data['address_id'] ?? null);
        $deliverTo = $addresses->deliveringTo($buyer, $address);
        $items = new Collection;
        foreach ($data['lines'] as $index => $line) {
            $product = Product::query()->with(['seller', 'variants.optionValues', 'category'])->find($line['product_id']);
            if ($product === null) {
                throw ValidationException::withMessages(['lines' => 'A product is no longer available.']);
            }
            $variant = app(CartVariantResolver::class)->resolve($product, $line['variant_id'] ?? null);
            $item = new CartItem(['buyer_id' => $buyer->id, 'product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => $line['quantity']]);
            $item->id = $index + 1;
            $item->setRelation('product', $product);
            $item->setRelation('variant', $variant);
            if (! $item->isAvailable()) {
                throw ValidationException::withMessages(['lines' => 'An item is no longer available.']);
            }
            $items->push($item);
        }
        foreach ($items->groupBy('variant_id') as $lines) {
            if ($lines->sum('quantity') > $lines->first()->availableQuantity()) {
                throw ValidationException::withMessages(['lines' => 'There is not enough stock for the selected items.']);
            }
        }
        $base = new CartSummary($items);
        $carriers = app(CheckoutCarrierService::class);
        $options = $carriers->optionsForCheckout($deliverTo, $base->sellerGroups);
        if (! $carriers->allRoutesAvailable($options)) {
            throw ValidationException::withMessages(['address_id' => 'Shipping is not available to this address for one or more shops.']);
        }
        $fees = empty($data['carriers'])
            ? $carriers->defaultFees($options)
            : $carriers->validateSelection($deliverTo, $base->sellerGroups, $data['carriers'])['fees'];
        $vouchers = app(VoucherSessionService::class)->forStatelessBuyer($buyer->id, $data['voucher_codes'] ?? []);
        $notice = null;
        $application = $vouchers->resolve($base->sellerGroups, $base->subtotalCentavos, $fees, $notice);
        $summary = new CartSummary($items, $application);

        $checkout = app(CheckoutService::class);

        return [
            'deliver_to' => $checkout->addressSnapshot($deliverTo),
            'address_fingerprint' => $checkout->addressFingerprint($deliverTo),
            'items' => $items->map(fn (CartItem $item): array => [
                'product_id' => $item->product_id, 'variant_id' => $item->variant_id,
                'name' => $item->product->name, 'quantity' => $item->quantity,
                'unit_price_minor' => $item->unitPriceCentavos(), 'line_total_minor' => $item->lineTotalCentavos(),
            ])->values()->all(),
            'shipping' => ['all_routes_available' => true, 'options' => $options, 'selected_fees' => (object) $fees],
            'totals' => [
                'merchandise_subtotal_minor' => $summary->subtotalCentavos,
                'merchandise_discount_minor' => $summary->discountCentavos,
                'merchandise_total_minor' => $summary->totalCentavos,
                'shipping_total_minor' => array_sum($fees),
                'shipping_discount_minor' => $application->shippingDiscountCentavos,
                'payable_total_minor' => max(0, $summary->totalCentavos + array_sum($fees) - $application->shippingDiscountCentavos),
            ],
            'stores' => $base->sellerGroups->map(fn (array $group): array => [
                'seller_id' => $group['seller']->id,
                'shop_discount_minor' => $application->shopDiscountFor($group['seller']->id),
                'platform_discount_minor' => $application->platformDiscountFor($group['seller']->id),
                'shipping_discount_minor' => $application->shippingDiscountFor($group['seller']->id),
            ])->values()->all(),
            'vouchers' => $application->toArray(), 'vouchers_state' => (object) $vouchers->appliedState(),
            'voucher_notice' => $notice,
        ];
    }
}
