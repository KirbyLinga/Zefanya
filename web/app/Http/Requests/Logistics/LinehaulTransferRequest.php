<?php

namespace App\Http\Requests\Logistics;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class LinehaulTransferRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user('logistics') instanceof \App\Models\Logistics\LogisticsProvider
            && $this->route('manifest') instanceof \App\Models\Logistics\Manifest
            && (new \App\Policies\LinehaulPolicy)->assign($this->user('logistics'), $this->route('manifest'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return \App\Services\Logistics\LinehaulTransferService::rules();
    }
}
