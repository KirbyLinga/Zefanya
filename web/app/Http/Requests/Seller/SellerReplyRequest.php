<?php

namespace App\Http\Requests\Seller;

use App\Models\Buyer\Review;
use Illuminate\Foundation\Http\FormRequest;

class SellerReplyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $review = $this->route('review');
        $seller = $this->user('seller');

        if (! $review instanceof Review || $seller === null) {
            return false;
        }

        return $review->product()->withTrashed()
            ->whereKey($review->product_id)
            ->where('seller_id', $seller->id)
            ->exists();
    }

    public function rules(): array
    {
        return ['reply' => ['required', 'string', 'max:1000']];
    }
}
