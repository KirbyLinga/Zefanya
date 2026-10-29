<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GoogleAuthRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // When role is absent, we only need id_token for sign-in lookup / role-check
        if (! $this->has('role')) {
            return [
                'id_token' => ['required', 'string'],
            ];
        }

        $baseRules = [
            'id_token' => ['required', 'string'],
            'role' => ['required', 'string', Rule::in(['buyer', 'courier', 'logistics'])],
            'first_name' => ['required', 'string', 'max:50'],
            'last_name' => ['required', 'string', 'max:50'],
            'middle_initial' => ['nullable', 'string', 'max:2'],
            'sex' => ['required', 'string', Rule::in(['male', 'female', 'Male', 'Female'])],
            'contact_no' => ['required', 'string', 'regex:/^(09\d{9}|\+639\d{9})$/'],
            'birthday' => ['required', 'date', 'before:-18 years'],
            'address_mode' => ['nullable', 'string'],
            'province_name' => ['nullable', 'string', 'max:100'],
            'municipality_name' => ['nullable', 'string', 'max:100'],
            'barangay_name' => ['nullable', 'string', 'max:100'],
            'street' => ['required', 'string', 'max:255'],
            'upload_id' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ];

        $role = strtolower(trim((string) $this->input('role')));
        if ($role === 'courier' || $role === 'logistics') {
            $courierRules = [
                'company' => ['nullable', 'string', 'max:100'],
                'business_name' => ['nullable', 'string', 'max:100'],
                'vehicle_type' => ['required', 'string', 'max:50'],
                'plate_number' => ['required', 'string', 'max:50', 'unique:logistics_providers,plate_number'],
                'license_number' => ['required', 'string', 'max:50'],
                'license_expiry' => ['required', 'date', 'after:today'],
                'hub' => ['nullable', 'string', 'max:100'],
                'license_doc' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
                'or_cr_doc' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            ];
            return array_merge($baseRules, $courierRules);
        }

        return $baseRules;
    }
}
