<?php

namespace App\Http\Requests\Rider;

use App\Http\Requests\Logistics\StoreRiderRequest;
use App\Support\PasswordRule;
use Illuminate\Validation\Rule;

class StoreRiderRegistrationRequest extends StoreRiderRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), $this->addressRules(required: true), [
            'address_mode' => ['required', Rule::in(['api'])],
            // Riders choose their company, never a station or coverage area.
            'station_hub_id' => ['prohibited'],
            // Email keeps the shared format rule from StoreRiderRequest.
            'password' => PasswordRule::rules(),
            'logistics_provider_id' => ['required', 'integer', Rule::exists('logistics_providers', 'id')->where('status', 'approved')->whereNull('archived_at')],
            'terms' => ['required', 'accepted'],
        ]);
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), PasswordRule::messages());
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
