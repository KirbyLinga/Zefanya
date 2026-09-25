<?php

namespace App\Http\Requests\Seller;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // seller-guard middleware already gates access to this route
    }

    public function rules(): array
    {
        $isUpdate = $this->isMethod('put') || $this->isMethod('patch');

        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'price' => ['required', 'numeric', 'min:0', 'max:9999999.99'],
            'stock_quantity' => ['required', 'integer', 'min:0'],
            'low_stock_threshold' => ['nullable', 'integer', 'min:0'],
            'status' => ['required', Rule::in(['draft', 'active', 'inactive'])],
            'images' => [$isUpdate ? 'nullable' : 'required', 'array', 'max:6'],
            'images.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:3072'],
        ];
    }

    public function messages(): array
    {
        return [
            'images.required' => 'Upload at least one product photo.',
            'images.*.image' => 'Each file must be an image (JPG, PNG, or WEBP).',
            'images.*.max' => 'Each image must be smaller than 3MB.',
            'price.min' => 'Price cannot be negative.',
        ];
    }
}