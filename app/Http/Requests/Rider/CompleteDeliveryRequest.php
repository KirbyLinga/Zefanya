<?php

namespace App\Http\Requests\Rider;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the Rider's proof-of-delivery submission.
 *
 * Evidence is mandatory and restricted to still images up to 5 MB; the file
 * is re-validated on the server (never trusted from the browser). The COD
 * amount, when present, is a peso string with at most two decimals and is
 * converted to EXACT centavos so it can be compared with payments.amount_minor
 * without floating-point drift.
 */
class CompleteDeliveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<int, mixed>|string>
     */
    public function rules(): array
    {
        return [
            'proof_photo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'received_by' => ['nullable', 'string', 'max:120'],
            'delivery_notes' => ['nullable', 'string', 'max:1000'],
            'cod_amount' => ['nullable', 'string', 'regex:/^\d{1,9}(\.\d{1,2})?$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'proof_photo.required' => 'Attach a photo as proof of delivery.',
            'proof_photo.mimes' => 'Proof must be a JPG, PNG or WebP image.',
            'proof_photo.max' => 'Proof images may not exceed 5 MB.',
            'cod_amount.regex' => 'Enter the amount collected as a peso value, e.g. 1234.50.',
        ];
    }

    /**
     * Convert the submitted peso string to exact centavos without floats.
     */
    public function codAmountMinor(): ?int
    {
        $value = $this->input('cod_amount');

        if ($value === null || $value === '') {
            return null;
        }

        [$peso, $cents] = array_pad(explode('.', (string) $value, 2), 2, '0');

        return ((int) $peso) * 100 + (int) str_pad(substr($cents, 0, 2), 2, '0');
    }
}
