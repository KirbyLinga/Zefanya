<?php

namespace App\Http\Requests\Api\Buyer;

use App\Models\Buyer\Buyer;
use App\Models\Buyer\BuyerAddress;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class CheckoutPreviewRequest extends FormRequest
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
        $id = $this->input('address_id');
        $address = is_int($id) || (is_string($id) && ctype_digit($id))
            ? BuyerAddress::query()->find($id) : null;

        return Gate::forUser($buyer)->allows('quote-checkout', [$address]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.product_id' => ['required', 'integer', 'min:1'],
            'lines.*.variant_id' => ['nullable', 'integer', 'min:1'],
            'lines.*.quantity' => ['required', 'integer', 'between:1,99999'],
            'address_id' => ['nullable', 'integer', 'exists:buyer_addresses,id'],
            'voucher_codes' => ['nullable', 'array', 'max:20'],
            'voucher_codes.*' => ['required', 'string', 'regex:/\A[A-Za-z0-9]{4,12}\z/'],
            'carriers' => ['nullable', 'array', 'max:100'],
            'carriers.*' => ['integer', 'min:1'],
            'shipping_fee' => ['prohibited'],
            'shipping_fees' => ['prohibited'],
            'shipping_total' => ['prohibited'],
        ];
    }
}
