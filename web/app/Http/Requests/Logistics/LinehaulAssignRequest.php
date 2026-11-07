<?php

namespace App\Http\Requests\Logistics;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class LinehaulAssignRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $provider = $this->user('logistics');

        return $provider instanceof \App\Models\Logistics\LogisticsProvider
            && \Illuminate\Support\Facades\Gate::forUser($provider)->allows('linehaul-assign', [$this->route('manifest')]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'driver_id' => ['required', 'integer', \Illuminate\Validation\Rule::exists('linehaul_drivers', 'id')->where('logistics_provider_id', $this->user('logistics')?->id)],
        ];
    }
}
