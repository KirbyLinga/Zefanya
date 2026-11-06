<?php

namespace App\Http\Requests\ParcelIncident;

use App\Services\Logistics\ParcelIncidentService;
use Illuminate\Foundation\Http\FormRequest;

class StoreIncidentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('rider') !== null || $this->user('logistics') !== null;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('type'))) {
            $this->merge(['type' => strtolower(trim($this->input('type')))]);
        }
    }

    public function rules(): array
    {
        return ParcelIncidentService::reportRules();
    }

    public function messages(): array
    {
        return ['photo.required_if' => 'Attach a photo when reporting a damaged parcel.'];
    }
}
