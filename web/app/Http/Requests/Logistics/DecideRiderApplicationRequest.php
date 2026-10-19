<?php

namespace App\Http\Requests\Logistics;

use App\Policies\RiderApplicationPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DecideRiderApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $provider = $this->user('logistics');

        return $provider !== null && app(RiderApplicationPolicy::class)->view($provider, $this->route('riderApplication'));
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'reason' => ['required_if:decision,rejected', 'nullable', 'string', 'max:1000'],
        ];
    }
}
