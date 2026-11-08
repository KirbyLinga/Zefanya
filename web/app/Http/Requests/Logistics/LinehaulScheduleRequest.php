<?php

namespace App\Http\Requests\Logistics;

use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\Manifest;
use App\Policies\LinehaulPolicy;
use App\Services\Logistics\LinehaulScheduleService;
use Illuminate\Foundation\Http\FormRequest;

class LinehaulScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('logistics') instanceof LogisticsProvider && $this->route('manifest') instanceof Manifest
            && (new LinehaulPolicy)->assign($this->user('logistics'), $this->route('manifest'));
    }

    public function rules(): array
    {
        return LinehaulScheduleService::rules();
    }
}
