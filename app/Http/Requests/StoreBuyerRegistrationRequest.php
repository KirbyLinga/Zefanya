<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBuyerRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isManual = $this->input('address_mode') === 'manual';

        return [
            'last_name' => ['required', 'string', 'max:100'],
            'first_name' => ['required', 'string', 'max:100'],
            'middle_initial' => ['nullable', 'string', 'max:2'],
            'sex' => ['required', Rule::in(['male', 'female'])],

            'email' => ['required', 'string', 'email', 'max:255', 'unique:buyers,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],

            'contact_no' => ['required', 'regex:/^09\d{9}$/'],
            'birthday' => ['required', 'date', 'before:today'],

            'address_mode' => ['required', Rule::in(['api', 'manual'])],

            'province' => [Rule::requiredIf(! $isManual), 'nullable', 'string'],
            'province_name' => [Rule::requiredIf(! $isManual), 'nullable', 'string'],
            'municipality' => [Rule::requiredIf(! $isManual), 'nullable', 'string'],
            'municipality_name' => [Rule::requiredIf(! $isManual), 'nullable', 'string'],
            'barangay' => [Rule::requiredIf(! $isManual), 'nullable', 'string'],
            'barangay_name' => [Rule::requiredIf(! $isManual), 'nullable', 'string'],

            'street' => [Rule::requiredIf($isManual), 'nullable', 'string', 'max:255'],
            'house_number' => ['nullable', 'string', 'max:50'],
            'address_detail' => ['nullable', 'string', 'max:255'],

            'upload_id' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'contact_no.regex' => 'Enter a valid PH mobile number (09XXXXXXXXX).',
            'upload_id.mimes' => 'Upload ID must be a JPG, PNG, or PDF.',
            'upload_id.max' => 'Upload ID must be smaller than 5MB.',
            'street.required' => 'Street is required when entering your address manually.',
            'province.required' => 'Select a province, or switch to manual address entry.',
        ];
    }
}
