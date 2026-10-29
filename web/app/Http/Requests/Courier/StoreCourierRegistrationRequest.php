<?php

namespace App\Http\Requests\Courier;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCourierRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:50'],
            'last_name' => ['required', 'string', 'max:50'],
            'middle_initial' => ['nullable', 'string', 'max:2'],
            'sex' => ['required', 'string', Rule::in(['male', 'female', 'Male', 'Female'])],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:logistics_providers,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'contact_no' => ['required', 'string', 'regex:/^(09\d{9}|\+639\d{9})$/'],
            'birthday' => ['required', 'date', 'before:-18 years'],
            'company' => ['nullable', 'string', 'max:100'],
            'business_name' => ['nullable', 'string', 'max:100'],
            'vehicle_type' => ['required', 'string', 'max:50'],
            'plate_number' => ['required', 'string', 'max:50', 'unique:logistics_providers,plate_number'],
            'license_number' => ['required', 'string', 'max:50'],
            'license_expiry' => ['required', 'date', 'after:today'],
            'province_name' => ['nullable', 'string', 'max:100'],
            'municipality_name' => ['nullable', 'string', 'max:100'],
            'barangay_name' => ['nullable', 'string', 'max:100'],
            'street' => ['nullable', 'string', 'max:255'],
            'hub' => ['nullable', 'string', 'max:100'],
            'upload_id' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'license_doc' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'or_cr_doc' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'This email address is already registered.',
            'plate_number.unique' => 'This plate number is already registered to another vehicle.',
            'license_expiry.after' => 'Your driver license has expired. Enter a valid future expiry date.',
            'birthday.before' => 'You must be at least 18 years old to apply as a rider.',
            'password.min' => 'Password must be at least 8 characters.',
            'upload_id.required' => 'Upload a photo or PDF of your government ID.',
        ];
    }
}
