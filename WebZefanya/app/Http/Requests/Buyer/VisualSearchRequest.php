<?php

namespace App\Http\Requests\Buyer;

use App\Support\ImageUpload;
use Illuminate\Foundation\Http\FormRequest;

class VisualSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Like text catalog browsing, image search is available to guests and Buyers.
        return true;
    }

    public function rules(): array
    {
        return ['image' => ['bail', 'required', 'file', 'max:5120', 'image', 'mimes:jpg,jpeg,png,webp',
            ...ImageUpload::dimensionRules(),
            'dimensions:max_width=5000,max_height=5000'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id']];
    }

    public function messages(): array
    {
        return ['image.required' => 'Choose a JPEG, PNG or WebP image.',
            'image.max' => 'Choose an image no larger than 5 MB.',
            'image.image' => 'Choose a valid JPEG, PNG or WebP image.',
            'image.mimes' => 'Only JPEG, PNG and WebP images are supported.'];
    }
}
