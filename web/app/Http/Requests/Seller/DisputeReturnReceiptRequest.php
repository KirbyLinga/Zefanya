<?php

namespace App\Http\Requests\Seller;

use App\Models\Logistics\ParcelReturn;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DisputeReturnReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        $return = $this->route('return');

        return $return instanceof ParcelReturn
            && ($this->user('seller')?->can('view', $return->sellerOrder) ?? false);
    }

    public function rules(): array
    {
        return [
            'issue' => ['required', Rule::in(array_keys(ParcelReturn::RECEIPT_DISPUTE_ISSUES))],
            'note' => ['required', 'string', 'min:10', 'max:1000'],
            'photos' => ['sometimes', 'array', 'max:5'],
            'photos.*' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],
        ];
    }
}
