<?php

namespace App\Http\Requests\Logistics;

use App\Models\Logistics\LogisticsProvider;
use App\Services\Logistics\ManifestCloseoutService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class LinehaulCloseReceivingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $provider = $this->user('logistics');

        return $provider instanceof LogisticsProvider
            && Gate::forUser($provider)->allows('linehaul-close-receiving', $this->route('manifest'));
    }

    public function rules(): array
    {
        return ManifestCloseoutService::rules();
    }
}
