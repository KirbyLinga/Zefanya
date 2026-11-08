<?php

namespace App\Http\Requests\Logistics;

use App\Models\Logistics\LinehaulDriver;
use App\Models\Logistics\LogisticsProvider;
use App\Policies\LinehaulPolicy;
use Illuminate\Foundation\Http\FormRequest;

class LinehaulVehicleReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('logistics') instanceof LogisticsProvider
            && ($this->routeIs('logistics.couriers.index') || $this->route('driver') instanceof LinehaulDriver)
            && (new LinehaulPolicy)->reviewProfile($this->user('logistics'), $this->route('driver'));
    }

    public function rules(): array
    {
        return $this->isMethod('POST') ? ['version' => ['required', 'integer', 'min:1'],
            'decision' => ['required', 'in:approved,rejected'], 'note' => ['required', 'string', 'max:500'],
            'capacity_kg' => ['nullable', 'numeric', 'min:1', 'max:50000', 'decimal:0,2']]
            : ['driver_id' => ['prohibited'], 'logistics_provider_id' => ['prohibited']];
    }
}
