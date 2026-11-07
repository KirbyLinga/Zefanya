<?php

namespace App\Http\Requests\Api\Buyer;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class MobileReviewRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $buyer = $this->user();
        if (! $buyer instanceof \App\Models\Buyer\Buyer) {
            return false;
        }

        return $this->isMethod('GET')
            ? \Illuminate\Support\Facades\Gate::forUser($buyer)->allows('viewAny', \App\Models\Buyer\Review::class)
            : \Illuminate\Support\Facades\Gate::forUser($buyer)->allows('writeMobileReview', $this->route('shipment'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->isMethod('GET') ? [] : [
            'stars' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:500'],
            'photos' => ['nullable', 'array', 'max:5'],
            'photos.*' => ['file', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],
        ];
    }
}
