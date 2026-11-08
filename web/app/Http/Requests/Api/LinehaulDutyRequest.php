<?php

namespace App\Http\Requests\Api;

use App\Models\Logistics\LinehaulDriver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class LinehaulDutyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof LinehaulDriver
            && Gate::forUser($this->user())->allows('linehaul-access');
    }

    public function rules(): array
    {
        return \App\Services\Logistics\LinehaulDutyService::rules($this->route('dutyAction'));
    }
}
