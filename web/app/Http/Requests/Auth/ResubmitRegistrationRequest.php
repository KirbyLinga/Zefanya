<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Logistics\StoreLogisticsRegistrationRequest;
use App\Http\Requests\Seller\StoreSellerRegistrationRequest;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Seller\Seller;
use App\Support\DocumentUpload;
use App\Support\EmailRegistry;
use App\Support\PhilippineMobile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResubmitRegistrationRequest extends FormRequest
{
    public function application(): Seller|LogisticsProvider
    {
        return match ($this->route('role')) {
            'seller' => Seller::findOrFail($this->route('application')),
            'logistics' => LogisticsProvider::findOrFail($this->route('application')),
            default => abort(404),
        };
    }

    public function authorize(): bool
    {
        $account = $this->application();

        return $account->getRawOriginal('status') === 'rejected' && $account->email_verified_at !== null;
    }

    protected function prepareForValidation(): void
    {
        foreach (['email', 'contact_no', 'sec_number'] as $field) {
            if (! is_string($this->input($field))) {
                continue;
            }
            $value = $this->input($field);
            $this->merge([$field => match ($field) {
                'email' => EmailRegistry::normalize($value),
                'contact_no' => PhilippineMobile::normalize($value),
                'sec_number' => strtoupper(trim($value)),
            }]);
        }
    }

    private function registrationRequest(): StoreSellerRegistrationRequest|StoreLogisticsRegistrationRequest
    {
        return $this->route('role') === 'seller'
            ? StoreSellerRegistrationRequest::createFrom($this)
            : StoreLogisticsRegistrationRequest::createFrom($this);
    }

    public function rules(): array
    {
        if ($this->isMethod('get')) {
            return [];
        }
        $account = $this->application();
        $rules = $this->registrationRequest()->rules();
        unset($rules['password']);
        $rules['email'] = ['required', 'string', Rule::in([strtolower($account->email)])];
        $documents = $account instanceof Seller
            ? ['upload_id' => 'upload_id_path', 'business_permit' => 'business_permit_path']
            : ['upload_id' => 'upload_id_path', 'dti_permit_upload' => 'dti_permit_path'];
        foreach ($documents as $field => $column) {
            $rules[$field] = DocumentUpload::rules(required: blank($account->{$column}));
        }
        if ($account instanceof Seller) {
            $rules['sec_certificate'] = [
                Rule::excludeIf(fn () => $this->input('seller_type') !== 'corporation'),
                ...DocumentUpload::rules(required: blank($account->sec_certificate_path)),
            ];
        }

        return $rules;
    }

    public function messages(): array
    {
        return ['email.in' => 'Keep your verified email address when resubmitting.', ...$this->registrationRequest()->messages()];
    }
}
