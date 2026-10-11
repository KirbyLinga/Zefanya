<?php

namespace App\Http\Requests\Logistics;

use App\Enums\DeliveryFailureReason;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class MarkDeliveryFailedRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            'failure_reason' => ['required', new Enum(DeliveryFailureReason::class)],
            'failure_notes' => ['required_if:failure_reason,other', 'nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'failure_reason.required' => 'Please select a reason for the failed delivery.',
            'failure_reason.enum' => 'Invalid failure reason selected.',
            'failure_notes.required_if' => 'Notes are required when "Other" is selected as the failure reason.',
            'failure_notes.max' => 'Notes may not exceed 1000 characters.',
        ];
    }
}
