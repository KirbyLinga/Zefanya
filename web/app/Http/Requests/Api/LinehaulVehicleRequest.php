<?php

namespace App\Http\Requests\Api;

use App\Models\Logistics\LinehaulDriver;
use App\Policies\LinehaulPolicy;
use App\Services\Logistics\LinehaulProfileService;
use App\Services\Logistics\VehiclePlateService;
use Illuminate\Foundation\Http\FormRequest;

class LinehaulVehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof LinehaulDriver && (new LinehaulPolicy)->access($this->user());
    }

    protected function prepareForValidation(): void
    {
        if ($this->isMethod('POST')) {
            if (is_string($this->input('plate_number'))) {
                $this->merge(['plate_number' => VehiclePlateService::normalize($this->input('plate_number'))]);
            }
            if (is_string($this->input('license_no'))) {
                $this->merge(['license_no' => strtoupper(trim($this->input('license_no')))]);
            }
        }
    }

    public function rules(): array
    {
        return $this->isMethod('POST') ? LinehaulProfileService::rules()
            : ['driver_id' => ['prohibited'], 'id' => ['prohibited'], 'courier_id' => ['prohibited']];
    }
}
