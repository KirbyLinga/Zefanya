<?php

namespace App\Http\Requests\Rider;

use App\Http\Requests\Logistics\StoreRiderRequest;
use Illuminate\Validation\Rule;

class StoreRiderRegistrationRequest extends StoreRiderRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), $this->addressRules(required: true), [
            'address_mode' => ['required', Rule::in(['api'])],
            // Riders choose their company, never a station or coverage area.
            'station_hub_id' => ['prohibited'],
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:72', 'confirmed', 'not_regex:/\x00/',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (is_string($value) && strlen($value) > 72) {
                        $fail('The password must not exceed 72 bytes.');
                    }
                }, 'regex:/^(?=.*\p{Lu})(?=.*\p{Ll})(?=.*\p{N})(?=.*[\p{Z}\p{S}\p{P}]).+$/u'],
            'logistics_provider_id' => ['required', 'integer', Rule::exists('logistics_providers', 'id')->where('status', 'approved')->whereNull('archived_at')],
            'drivers_license' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'extensions:jpg,jpeg,png,pdf', 'max:5120'],
            'or_cr' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'extensions:jpg,jpeg,png,pdf', 'max:5120'],
            'terms' => ['required', 'accepted'],
        ]);
    }

    /**
     * A duplicate licence is usually the rider's own earlier application whose
     * OTP was lost. Point them at the in-modal recovery instead of a dead end.
     */
    protected function licenseTakenMessage(): string
    {
        return 'This license number is already registered. If this is your application, go back to Personal information and choose "Already applied? Resend code", or use a different license number.';
    }
}
