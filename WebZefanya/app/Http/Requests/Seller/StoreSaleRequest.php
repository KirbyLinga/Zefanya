<?php

namespace App\Http\Requests\Seller;

use App\Enums\StoreSaleStatus;
use App\Enums\StoreSaleType;
use App\Models\Catalog\ProductVariant;
use App\Models\Seller\StoreSale;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Creating a store sale, or editing one that has not ended. */
class StoreSaleRequest extends FormRequest
{
    private const TIME_FORMAT = 'Y-m-d\TH:i';

    public function authorize(): bool
    {
        $sale = $this->sale();

        return $sale === null || Gate::forUser($this->user('seller'))->allows('manage', $sale);
    }

    public function rules(): array
    {
        $rules = [
            'type' => ['required', Rule::enum(StoreSaleType::class)],
            'percent' => ['nullable', 'required_if:type,'.StoreSaleType::Percent->value, 'integer', 'between:'.StoreSale::MIN_PERCENT.','.StoreSale::MAX_PERCENT],
            'fixed_price' => ['nullable', 'required_if:type,'.StoreSaleType::FixedPrice->value, 'decimal:0,2', 'min:0.01', 'max:99999999.99'],
            'ends_at' => ['required', 'date_format:'.self::TIME_FORMAT],
            'flash_quantity' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ];

        if ($this->isRunning()) {
            return $rules;
        }

        return $rules + [
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')
                ->where('seller_id', $this->user('seller')->id)
                ->whereNull('deleted_at')],
            'variant_id' => ['nullable', 'integer', Rule::exists('product_variants', 'id')
                ->where('product_id', $this->integer('product_id'))],
            'starts_at' => ['required', 'date_format:'.self::TIME_FORMAT],
        ];
    }

    public function messages(): array
    {
        return [
            'percent.required_if' => 'Enter a percentage from 1 to 99.',
            'percent.integer' => 'The percentage must be a whole number from 1 to 99.',
            'percent.between' => 'The percentage must be a whole number from 1 to 99.',
            'fixed_price.required_if' => 'Enter the sale price.',
            'fixed_price.decimal' => 'The sale price can have at most two decimal places.',
            'fixed_price.min' => 'The sale price must be above zero.',
            'variant_id.exists' => 'Choose a variant of the selected product.',
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $startsAt = $this->isRunning() ? $this->sale()->starts_at : $this->time('starts_at');
            $endsAt = $this->time('ends_at');

            // The form picks whole minutes, so the current minute still counts as now.
            if (! $this->isRunning() && $startsAt->lt(now()->startOfMinute())) {
                $validator->errors()->add('starts_at', 'The start time cannot be in the past.');
            }

            if ($endsAt->lte($startsAt)) {
                $validator->errors()->add('ends_at', 'The end time must be after the start time.');
            } elseif ($endsAt->lte(now())) {
                $validator->errors()->add('ends_at', 'The end time must be in the future. Use End Now to stop a running sale.');
            }

            if ($this->input('type') === StoreSaleType::FixedPrice->value) {
                $this->assertBelowRegularPrice($validator);
            }
        }];
    }

    /**
     * Attributes for StoreSaleService, with times converted to app UTC.
     *
     * @return array<string, mixed>
     */
    public function saleAttributes(): array
    {
        $type = StoreSaleType::from($this->input('type'));

        $attributes = [
            'type' => $type,
            'value' => $type === StoreSaleType::Percent
                ? $this->integer('percent')
                : $this->centavos($this->input('fixed_price')),
            'ends_at' => $this->time('ends_at'),
            'flash_quantity' => $this->filled('flash_quantity') ? $this->integer('flash_quantity') : null,
        ];

        if ($this->isRunning()) {
            return $attributes;
        }

        return $attributes + [
            'product_id' => $this->integer('product_id'),
            'variant_id' => $this->filled('variant_id') ? $this->integer('variant_id') : null,
            'starts_at' => $this->time('starts_at'),
        ];
    }

    /** A whole-product sale price has to beat the cheapest variant to be a discount on all of them. */
    private function assertBelowRegularPrice(Validator $validator): void
    {
        $productId = $this->isRunning() ? $this->sale()->product_id : $this->integer('product_id');
        $variantId = $this->isRunning() ? $this->sale()->variant_id : ($this->filled('variant_id') ? $this->integer('variant_id') : null);

        $regular = ProductVariant::query()
            ->where('product_id', $productId)
            ->when($variantId, fn ($query) => $query->whereKey($variantId))
            ->min('price');

        if ($regular !== null && $this->centavos($this->input('fixed_price')) >= $this->centavos($regular)) {
            $validator->errors()->add('fixed_price', 'The sale price must be below the regular price of ₱'.number_format((float) $regular, 2).'.');
        }
    }

    private function sale(): ?StoreSale
    {
        return $this->route('storeSale');
    }

    private function isRunning(): bool
    {
        return $this->sale()?->status() === StoreSaleStatus::Ongoing;
    }

    /** Sellers type Manila time; "!" clears the seconds Carbon would otherwise take from now. */
    private function time(string $key): CarbonInterface
    {
        return Carbon::createFromFormat('!'.self::TIME_FORMAT, $this->input($key), StoreSale::TIMEZONE)->utc();
    }

    private function centavos(mixed $pesos): int
    {
        return (int) bcmul((string) $pesos, '100', 0);
    }
}
