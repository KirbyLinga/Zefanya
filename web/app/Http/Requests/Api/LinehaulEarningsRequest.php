<?php

namespace App\Http\Requests\Api;

use App\Models\Logistics\LinehaulDriver;
use App\Policies\LinehaulPolicy;
use Illuminate\Foundation\Http\FormRequest;

class LinehaulEarningsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof LinehaulDriver && (new LinehaulPolicy)->access($this->user());
    }

    public function rules(): array
    {
        return ['from' => ['required_with:to', 'date_format:Y-m-d'], 'to' => ['required_with:from', 'date_format:Y-m-d', 'after_or_equal:from'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'], 'driver_id' => ['prohibited'], 'courier_id' => ['prohibited'],
            'logistics_provider_id' => ['prohibited'], 'id' => ['prohibited']];
    }
}
