<?php

namespace App\Http\Requests\Buyer;

use App\Enums\OrderStatus;
use App\Models\Buyer\Review;
use App\Models\Catalog\Product;
use App\Models\Shared\OrderItem;
use Illuminate\Foundation\Http\FormRequest;

class StoreProductReviewRequest extends FormRequest
{
    /**
     * Only buyers with a completed purchase of this product may submit a review.
     * Item-level eligibility (already reviewed, wrong item) stays in ProductReviewService.
     */
    public function authorize(): bool
    {
        $buyer = $this->user('buyer');
        $product = $this->route('product');

        if ($buyer === null || ! $product instanceof Product) {
            return false;
        }

        return OrderItem::query()
            ->where('product_id', $product->id)
            ->whereHas(
                'sellerOrder',
                fn ($query) => $query
                    ->where('status', OrderStatus::Completed->value)
                    ->whereHas('order', fn ($query) => $query->where('buyer_id', $buyer->id))
            )
            ->exists();
    }

    public function rules(): array
    {
        return [
            'order_item_id' => ['nullable', 'integer'],
            'rating' => ['required', 'integer', 'between:1,5'],
            'body' => ['nullable', 'string', 'max:'.Review::MAX_BODY_LENGTH],
            'photos' => ['nullable', 'array', 'max:'.Review::MAX_PHOTOS],
            'photos.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:3072'],
        ];
    }
}
