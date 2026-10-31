<?php

namespace App\Http\Requests\Buyer;

use App\Models\Buyer\Review;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProductReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        $review = $this->route('review');

        return $review instanceof Review
            && $this->user('buyer')?->id === $review->buyer_id;
    }

    public function rules(): array
    {
        return [
            'rating' => ['required', 'integer', 'between:1,5'],
            'body' => ['nullable', 'string', 'max:'.Review::MAX_BODY_LENGTH],
            'photos' => ['nullable', 'array', 'max:'.Review::MAX_PHOTOS],
            'photos.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:3072'],
            'remove_photos' => ['nullable', 'array'],
            'remove_photos.*' => ['integer'],
        ];
    }
}
