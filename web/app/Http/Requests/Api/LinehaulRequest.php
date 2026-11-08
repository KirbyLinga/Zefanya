<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class LinehaulRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $driver = $this->user();

        return $driver instanceof \App\Models\Logistics\LinehaulDriver
            && \Illuminate\Support\Facades\Gate::forUser($driver)->allows('linehaul-access', [$this->route('manifest')]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...($this->isMethod('GET') && $this->is('api/courier/profile') ? [
                'id' => ['prohibited'],
                'driver_id' => ['prohibited'],
                'courier_id' => ['prohibited'],
                'logistics_provider_id' => ['prohibited'],
                'home_hub_id' => ['prohibited'],
            ] : []),
            ...($this->route('action') === 'exception-note' ? [
                'tracking_number' => ['required', 'string', 'max:100'],
                'note' => ['required', 'string', 'max:500'],
            ] : []),
            ...($this->route('action') === 'scan' ? [
                'tracking_number' => ['required', 'string', 'max:100'],
                'method' => ['nullable', 'in:camera,manual'],
            ] : []),
            ...($this->route('action') === 'depart' ? [
                'seal_number' => ['required', 'string', 'regex:/\ABH-\d{7}\z/'],
            ] : []),
        ];
    }
}
