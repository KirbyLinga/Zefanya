<?php

namespace App\Http\Requests\Seller;

use App\Enums\PromotionKind;
use App\Models\Seller\StoreSale;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SellerPromotionRequest extends FormRequest
{
    private const TIME_FORMAT = 'Y-m-d\TH:i';

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::enum(PromotionKind::class)],
            'name' => ['nullable', 'string', 'max:120'],
            'percent' => ['nullable', 'required_if:kind,'.PromotionKind::BundlePercent->value, 'integer', 'between:'.StoreSale::MIN_PERCENT.','.StoreSale::MAX_PERCENT],
            'buy_quantity' => ['nullable', 'required_if:kind,'.PromotionKind::BuyXGetY->value, 'integer', 'min:1', 'max:99'],
            'get_quantity' => ['nullable', 'required_if:kind,'.PromotionKind::BuyXGetY->value, 'integer', 'min:1', 'max:99'],
            'product_ids' => ['required', 'array', 'min:1'],
            'product_ids.*' => ['integer', Rule::exists('products', 'id')->where('seller_id', $this->user('seller')->id)->whereNull('deleted_at')],
            'starts_at' => ['required', 'date_format:'.self::TIME_FORMAT],
            'ends_at' => ['required', 'date_format:'.self::TIME_FORMAT],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $ids = collect($this->input('product_ids'))->unique();
            $kind = $this->input('kind');

            if ($kind === PromotionKind::BundlePercent->value && $ids->count() < 2) {
                $validator->errors()->add('product_ids', 'A bundle needs at least two products.');
            }

            if ($kind === PromotionKind::BuyXGetY->value && $ids->count() !== 1) {
                $validator->errors()->add('product_ids', 'Buy X Get Y applies to one product.');
            }

            $startsAt = $this->time('starts_at');
            $endsAt = $this->time('ends_at');

            if ($startsAt->lt(now()->startOfMinute())) {
                $validator->errors()->add('starts_at', 'The start time cannot be in the past.');
            }

            if ($endsAt->lte($startsAt)) {
                $validator->errors()->add('ends_at', 'The end time must be after the start time.');
            } elseif ($endsAt->lte(now())) {
                $validator->errors()->add('ends_at', 'The end time must be in the future.');
            }
        }];
    }

    /** @return array<string, mixed> */
    public function promotionAttributes(): array
    {
        $kind = PromotionKind::from($this->input('kind'));

        return [
            'kind' => $kind,
            'name' => $this->filled('name') ? $this->string('name')->toString() : null,
            'percent' => $kind === PromotionKind::BundlePercent ? $this->integer('percent') : null,
            'buy_quantity' => $kind === PromotionKind::BuyXGetY ? $this->integer('buy_quantity') : null,
            'get_quantity' => $kind === PromotionKind::BuyXGetY ? $this->integer('get_quantity') : null,
            'product_ids' => collect($this->input('product_ids'))->map(fn ($id): int => (int) $id)->unique()->values()->all(),
            'starts_at' => $this->time('starts_at'),
            'ends_at' => $this->time('ends_at'),
        ];
    }

    private function time(string $key): CarbonInterface
    {
        return Carbon::createFromFormat('!'.self::TIME_FORMAT, $this->input($key), StoreSale::TIMEZONE)->utc();
    }
}
