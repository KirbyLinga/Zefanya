<?php

namespace App\Http\Requests\Api\Buyer;

use App\Models\Buyer\Buyer;
use Illuminate\Foundation\Http\FormRequest;

class ReconcileCheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof Buyer;
    }

    public function rules(): array
    {
        return ['checkout_token' => ['required', 'string', 'regex:/\\A[0-9a-f]{64}\\z/']];
    }
}
