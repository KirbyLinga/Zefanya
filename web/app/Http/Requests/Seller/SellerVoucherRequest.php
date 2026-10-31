<?php

namespace App\Http\Requests\Seller;

use App\Enums\VoucherType;
use App\Models\Seller\StoreSale;
use App\Models\Shared\Voucher;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Creating a shop voucher, or editing one. Rules lock once a buyer claims or uses it. */
class SellerVoucherRequest extends FormRequest
{
    public const MIN_PERCENT = 1;

    public const MAX_PERCENT = 99;

    private const TIME_FORMAT = 'Y-m-d\TH:i';

    private const MAX_PESOS = 99999999.99;

    public function authorize(): bool
    {
        $voucher = $this->voucher();

        return $voucher === null || Gate::forUser($this->user('seller'))->allows('manage', $voucher);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }
    }

    public function rules(): array
    {
        $rules = [
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'per_buyer_limit' => ['nullable', 'integer', 'min:1'],
            'expires_at' => ['required', 'date_format:'.self::TIME_FORMAT],
        ];

        if ($this->rulesLocked()) {
            return $rules;
        }

        return $rules + [
            'min_spend' => ['nullable', 'decimal:0,2', 'min:0', 'max:'.self::MAX_PESOS],
            'starts_at' => ['required', 'date_format:'.self::TIME_FORMAT],
            'availability' => ['required', Rule::in(['claimable', 'code_only'])],
            'applies_to' => ['required', Rule::in(['all', 'selected'])],
            'product_ids' => ['nullable', 'required_if:applies_to,selected', 'array'],
            'product_ids.*' => ['integer', Rule::exists('products', 'id')
                ->where('seller_id', $this->user('seller')->id)
                ->whereNull('deleted_at')],
            'is_active' => ['nullable', 'boolean'],
            'code' => ['required', 'string', 'regex:/^[A-Z0-9]{4,12}$/', Rule::unique('vouchers', 'code')->ignore($this->voucher())],
            'type' => ['required', Rule::enum(VoucherType::class)],
            'percent' => ['nullable', 'required_if:type,'.VoucherType::Percent->value, 'integer', 'between:'.self::MIN_PERCENT.','.self::MAX_PERCENT],
            'max_discount' => ['nullable', 'required_if:type,'.VoucherType::Percent->value, 'decimal:0,2', 'min:0.01', 'max:'.self::MAX_PESOS],
            'fixed_amount' => ['nullable', 'required_if:type,'.VoucherType::Fixed->value, 'decimal:0,2', 'min:0.01', 'max:'.self::MAX_PESOS],
        ];
    }

    public function messages(): array
    {
        return [
            'code.regex' => 'The code must be 4 to 12 letters and numbers, with no spaces or symbols.',
            'code.unique' => 'This voucher code is already taken.',
            'percent.required_if' => 'Enter a percentage from 1 to 99.',
            'percent.integer' => 'The percentage must be a whole number from 1 to 99.',
            'percent.between' => 'The percentage must be a whole number from 1 to 99.',
            'max_discount.required_if' => 'Enter the most this percentage can take off an order.',
            'fixed_amount.required_if' => 'Enter the amount off.',
            'product_ids.required_if' => 'Choose at least one product.',
            'product_ids.*.exists' => 'Choose only products from your own store.',
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $voucher = $this->voucher();
            $locked = $this->rulesLocked();
            $startsAt = $locked ? $voucher->starts_at : $this->time('starts_at');
            $expiresAt = $this->time('expires_at');

            // Saving an older voucher unchanged must work; only a new time is checked.
            if (! $locked && ! $this->sameMinute($startsAt, $voucher?->starts_at) && $startsAt->lt(now()->startOfMinute())) {
                $validator->errors()->add('starts_at', 'The start time cannot be in the past.');
            }

            if ($startsAt !== null && $expiresAt->lte($startsAt)) {
                $validator->errors()->add('expires_at', 'The end time must be after the start time.');
            } elseif (! $this->sameMinute($expiresAt, $voucher?->expires_at) && $expiresAt->lte(now())) {
                $validator->errors()->add('expires_at', 'The end time must be in the future.');
            }

            if ($this->filled('usage_limit') && $this->filled('per_buyer_limit')
                && $this->integer('per_buyer_limit') > $this->integer('usage_limit')) {
                $validator->errors()->add('per_buyer_limit', 'The per-buyer limit cannot be more than the total usage limit.');
            }
        }];
    }

    /**
     * Attributes for SellerVoucherService, in centavos and app UTC.
     *
     * @return array<string, mixed>
     */
    public function voucherAttributes(): array
    {
        $attributes = [
            'usage_limit' => $this->filled('usage_limit') ? $this->integer('usage_limit') : null,
            'per_buyer_limit' => $this->filled('per_buyer_limit') ? $this->integer('per_buyer_limit') : null,
            'expires_at' => $this->time('expires_at'),
        ];

        if ($this->rulesLocked()) {
            return $attributes;
        }

        $percent = $this->input('type') === VoucherType::Percent->value;

        return $attributes + [
            'min_spend' => $this->filled('min_spend') ? $this->centavos($this->input('min_spend')) : null,
            'starts_at' => $this->time('starts_at'),
            'is_claimable' => $this->input('availability') === 'claimable',
            'applies_to_all_products' => $this->input('applies_to') === 'all',
            'is_active' => $this->boolean('is_active'),
            'code' => $this->input('code'),
            'type' => VoucherType::from($this->input('type')),
            'value' => $percent ? $this->integer('percent') : $this->centavos($this->input('fixed_amount')),
            'max_discount' => $percent ? $this->centavos($this->input('max_discount')) : null,
        ];
    }

    /** @return list<int> */
    public function productIds(): array
    {
        return $this->input('applies_to') === 'selected'
            ? array_values(array_unique(array_map('intval', (array) $this->input('product_ids'))))
            : [];
    }

    private function voucher(): ?Voucher
    {
        return $this->route('voucher');
    }

    private function rulesLocked(): bool
    {
        // An ended voucher may have no claims left; the service refuses it either way.
        return $this->voucher()?->hasEnded() === true || $this->voucher()?->rulesLocked() === true;
    }

    /** Sellers type Manila time; "!" clears the seconds Carbon would otherwise take from now. */
    private function time(string $key): CarbonInterface
    {
        return Carbon::createFromFormat('!'.self::TIME_FORMAT, $this->input($key), StoreSale::TIMEZONE)->utc();
    }

    private function sameMinute(CarbonInterface $time, ?CarbonInterface $stored): bool
    {
        return $stored !== null && $time->eq($stored->copy()->startOfMinute());
    }

    private function centavos(mixed $pesos): int
    {
        return (int) bcmul((string) $pesos, '100', 0);
    }
}
