<?php

namespace App\Http\Requests\Rider;

use App\Enums\DeliveryFailureReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class PickupFailureRequest extends FormRequest
{
    /**     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<int, mixed>|string>
     */
    public function rules(): array
    {
        return [
            'failure_reason' => ['required', new Enum(DeliveryFailureReason::class)],
            'failure_notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'failure_reason.required' => 'Please select a reason for the failed pickup.',
            'failure_reason.enum' => 'Invalid failure reason selected.',
            'failure_notes.max' => 'Notes may not exceed 1000 characters.',
        ];
    }
}
