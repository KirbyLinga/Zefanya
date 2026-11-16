<?php

namespace App\Http\Requests\Seller;

use Illuminate\Foundation\Http\FormRequest;

/** Per-product purchase limits. Peso amounts in, centavos out; every limit is optional and empty means "no limit". */
class UpdateProductPurchaseLimitsRequest extends FormRequest
{
    /** Stays below the order-amount ceiling enforced at checkout (CheckoutService::MAX_ORDER_MINOR). */
    public const MAX_PESOS = 40_000_000;

    public const MAX_QUANTITY = 999;

    private const AMOUNT_FIELDS = ['max_order', 'max_active'];

    public function authorize(): bool
    {
        $seller = $this->user('seller');
        $product = $this->route('product');

        return $seller !== null && $seller->isApproved()
            && $product !== null && (int) $product->seller_id === (int) $seller->getKey();
    }

    protected function prepareForValidation(): void
    {
        $clean = [];
        foreach ([...self::AMOUNT_FIELDS, 'max_quantity'] as $key) {
            $value = $this->input($key);
            if (is_string($value)) {
                $value = trim(str_replace([',', '₱', ' '], '', $value));
                // Only an empty field means "no limit"; a typed 0 must still fail min:1.
                $value = $value === '' ? null : $value;
            }
            $clean[$key] = $value;
        }
        $this->merge($clean);
    }

    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'exempt_trusted' => ['required', 'boolean'],
            'max_quantity' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_QUANTITY],
            'max_order' => ['nullable', 'numeric', 'decimal:0,2', 'min:1', 'max:'.self::MAX_PESOS],
            'max_active' => ['nullable', 'numeric', 'decimal:0,2', 'min:1', 'max:'.self::MAX_PESOS],
        ];
    }

    public function messages(): array
    {
        return [
            'max_quantity.min' => 'Enter at least 1 unit, or leave it empty for no limit.',
            'max_quantity.max' => 'Enter at most '.self::MAX_QUANTITY.' units, or leave it empty for no limit.',
            'max_quantity.integer' => 'Enter a whole number of units.',
            'max_order.min' => 'Enter at least ₱1, or leave it empty for no limit.',
            'max_active.min' => 'Enter at least ₱1, or leave it empty for no limit.',
            'max_order.decimal' => 'Enter an amount with at most 2 decimal places.',
            'max_active.decimal' => 'Enter an amount with at most 2 decimal places.',
        ];
    }

    public function attributes(): array
    {
        return ['max_quantity' => 'units per order', 'max_order' => 'value per order', 'max_active' => 'active COD value'];
    }

    public function after(): array
    {
        return [function ($validator): void {
            $order = $this->input('max_order');
            $active = $this->input('max_active');
            if (is_numeric($order) && is_numeric($active) && (float) $active < (float) $order) {
                $validator->errors()->add('max_active', 'This must be at least the value per order, or no order could reach that value.');
            }
            if ($this->boolean('enabled') && blank($order) && blank($active) && blank($this->input('max_quantity'))) {
                $validator->errors()->add('enabled', 'Set at least one limit before turning purchase limits on.');
            }
        }];
    }

    /** @return array{purchase_limit_enabled: bool, purchase_limit_max_quantity: ?int, purchase_limit_max_order_minor: ?int, purchase_limit_max_active_minor: ?int, purchase_limit_exempt_trusted: bool} */
    public function limits(): array
    {
        $data = $this->validated();
        $minor = fn ($pesos): ?int => $pesos === null ? null : (int) round(((float) $pesos) * 100);

        return [
            'purchase_limit_enabled' => (bool) $data['enabled'],
            'purchase_limit_max_quantity' => isset($data['max_quantity']) ? (int) $data['max_quantity'] : null,
            'purchase_limit_max_order_minor' => $minor($data['max_order'] ?? null),
            'purchase_limit_max_active_minor' => $minor($data['max_active'] ?? null),
            'purchase_limit_exempt_trusted' => (bool) $data['exempt_trusted'],
        ];
    }
}
