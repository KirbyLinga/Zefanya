<?php

namespace App\Http\Requests\Logistics;

use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\Manifest;
use App\Policies\LinehaulPolicy;
use App\Services\Logistics\LinehaulPayoutService;
use Illuminate\Foundation\Http\FormRequest;

class LinehaulPayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        $provider = $this->user('logistics');
        $manifest = $this->route('manifest');

        return $provider instanceof LogisticsProvider && $manifest instanceof Manifest
            && (new LinehaulPolicy)->assign($provider, $manifest);
    }

    public function rules(): array
    {
        return LinehaulPayoutService::rules();
    }
}
