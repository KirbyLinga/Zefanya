<?php

namespace App\Http\Requests\Api\Buyer;

use App\Models\Buyer\Buyer;
use App\Models\Buyer\Review;
use App\Support\ImageUpload;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class MobileReviewRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $buyer = $this->user();
        if (! $buyer instanceof Buyer) {
            return false;
        }

        return $this->isMethod('GET')
            ? Gate::forUser($buyer)->allows('viewAny', Review::class)
            : Gate::forUser($buyer)->allows('writeMobileReview', $this->route('shipment'));
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
            'photos.*' => ['file', 'image', 'mimes:jpg,jpeg,png', 'max:5120', ...ImageUpload::dimensionRules()],
        ];
    }
}
