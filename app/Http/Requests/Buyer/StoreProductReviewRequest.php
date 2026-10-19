<?php

namespace App\Http\Requests\Buyer;

use Illuminate\Foundation\Http\FormRequest;

class StoreProductReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth('buyer')->check();
    }

    public function rules(): array
    {
        return ['rating' => ['required', 'integer', 'between:1,5'], 'body' => ['nullable', 'string', 'max:2000']];
    }
}
