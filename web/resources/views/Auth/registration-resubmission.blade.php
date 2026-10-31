@extends('Layouts.footer')

@section('title', 'Correct your application — Zefanya')

@section('content')
@php
    $fieldClass = 'mt-1 block w-full rounded-sm border border-neutral-300 bg-white px-3 py-2 font-sans text-sm text-neutral-900';
    $seller = $role === 'seller';
    $documents = $seller
        ? ['upload_id' => ['Valid ID', 'upload_id_path'], 'business_permit' => ['Business / DTI permit', 'business_permit_path'], 'sec_certificate' => ['SEC certificate (corporations)', 'sec_certificate_path']]
        : ['upload_id' => ['Valid ID', 'upload_id_path'], 'dti_permit_upload' => ['DTI permit', 'dti_permit_path']];
@endphp
<main class="mx-auto max-w-3xl px-4 py-10">
    <h1 class="font-serif text-3xl text-neutral-950">Correct and resubmit your application</h1>
    <p class="mt-3 font-sans text-sm text-neutral-700">Your {{ $role }} application was rejected. Correct the details below and replace any document that needs updating. Your application will return to Pending for review.</p>
    @if (filled($account->rejection_reason))
        <p class="mt-4 rounded-sm bg-primary-50 p-4 font-sans text-sm text-primary-800">Reason: {{ $account->rejection_reason }}</p>
    @endif
    @if ($errors->any())
        <ul class="mt-4 list-disc rounded-sm border border-primary-300 p-4 pl-8 font-sans text-sm text-primary-800" role="alert">
            @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
        </ul>
    @endif
    <form method="POST" action="{{ $submitUrl }}" enctype="multipart/form-data" id="resubmissionForm" class="mt-8 space-y-8">
        @csrf
        <section>
            <h2 class="font-sans text-lg font-semibold">Applicant details</h2>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                @foreach (['first_name' => 'First name', 'last_name' => 'Last name', 'middle_initial' => 'Middle initial', 'contact_no' => 'Mobile number'] as $field => $label)
                    <label class="font-sans text-sm">{{ $label }}<input type="text" name="{{ $field }}" value="{{ old($field, $account->{$field}) }}" @required($field !== 'middle_initial') class="{{ $fieldClass }}"></label>
                @endforeach
                <label class="font-sans text-sm">Sex<select name="sex" required class="{{ $fieldClass }}">@foreach (['female' => 'Female', 'male' => 'Male'] as $value => $label)<option value="{{ $value }}" @selected(old('sex', $account->sex) === $value)>{{ $label }}</option>@endforeach</select></label>
                <label class="font-sans text-sm">Birthday<input type="date" name="birthday" required value="{{ old('birthday', $account->birthday?->toDateString()) }}" class="{{ $fieldClass }}"></label>
                <label class="font-sans text-sm sm:col-span-2">Verified email<input type="email" name="email" readonly value="{{ $account->email }}" class="{{ $fieldClass }}"><span class="mt-1 block text-xs text-neutral-500">Your verified email and password stay the same.</span></label>
            </div>
        </section>
        <section id="resubmissionAddress" data-initial="{{ json_encode([old('region_code', $account->region_code), old('province', $account->province_code), old('municipality', $account->municipality_code), old('barangay', $account->barangay_code)]) }}">
            <h2 class="font-sans text-lg font-semibold">Address</h2>
            <input type="hidden" name="address_mode" value="api">
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                @foreach (['region_code' => 'Region', 'province' => 'Province', 'municipality' => 'City / municipality', 'barangay' => 'Barangay'] as $field => $label)
                    <label class="font-sans text-sm">{{ $label }}<select name="{{ $field }}" id="resubmit-{{ $field }}" required class="{{ $fieldClass }}"><option value="">Select {{ strtolower($label) }}</option></select></label>
                    @if ($field !== 'region_code')<input type="hidden" name="{{ $field }}_name" id="resubmit-{{ $field }}-name" value="">@endif
                @endforeach
                @foreach (['street' => 'Street', 'house_number' => 'House / unit number', 'address_detail' => 'Additional address details', 'postal_code' => 'Postal code'] as $field => $label)
                    <label class="font-sans text-sm">{{ $label }}<input type="text" name="{{ $field }}" value="{{ old($field, $account->{$field}) }}" @required($field === 'street') @if ($field === 'postal_code') maxlength="4" pattern="[0-9]{4}" inputmode="numeric" @endif class="{{ $fieldClass }}"></label>
                @endforeach
            </div>
        </section>
        <section>
            <h2 class="font-sans text-lg font-semibold">Business details</h2>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <label class="font-sans text-sm">{{ $seller ? 'Shop name' : 'Company name' }}<input type="text" name="business_name" required @if ($seller) minlength="3" maxlength="30" @endif value="{{ old('business_name', $account->business_name) }}" class="{{ $fieldClass }}"></label>
                <label class="font-sans text-sm">TIN<input type="text" name="tin" required pattern="[0-9]{3}-[0-9]{3}-[0-9]{3}-[0-9]{3}" placeholder="000-000-000-000" value="{{ old('tin', $account->tin) }}" class="{{ $fieldClass }}"></label>
                @if ($seller)
                    <label class="font-sans text-sm">Seller type<select name="seller_type" id="resubmitSellerType" required class="{{ $fieldClass }}">@foreach (['sole_proprietor' => 'Sole proprietor', 'corporation' => 'Corporation'] as $value => $label)<option value="{{ $value }}" @selected(old('seller_type', $account->seller_type) === $value)>{{ $label }}</option>@endforeach</select></label>
                    <label class="font-sans text-sm">Primary category<select name="line_of_business_id" required class="{{ $fieldClass }}">@foreach ($categories as $category)<option value="{{ $category->id }}" @selected(old('line_of_business_id', $account->line_of_business_id) == $category->id)>{{ $category->label }}</option>@endforeach</select></label>
                @else
                    <label class="font-sans text-sm">SEC number<input type="text" name="sec_number" required maxlength="11" pattern="[A-Za-z]{2}[0-9]{9}" value="{{ old('sec_number', $account->sec_number) }}" class="{{ $fieldClass }}"></label>
                    <label class="font-sans text-sm sm:col-span-2">Service cities / municipalities<select name="service_areas[]" id="resubmitServiceAreas" required multiple size="6" data-initial="{{ json_encode(old('service_areas', $account->service_areas ?? [])) }}" class="{{ $fieldClass }}"></select><span class="mt-1 block text-xs text-neutral-500">Use Ctrl or Command to select several areas.</span></label>
                @endif
            </div>
        </section>
        <section>
            <h2 class="font-sans text-lg font-semibold">Documents</h2>
            <p class="mt-2 font-sans text-sm text-neutral-600">Leave a document empty to keep the copy already submitted. Replacements must be JPG, PNG or PDF, up to 5 MB each.</p>
            <div class="mt-4 space-y-4">
                @foreach ($documents as $field => [$label, $column])
                    <label class="block font-sans text-sm" @if ($field === 'sec_certificate') id="resubmitSecFields" @endif>{{ $label }}
                        <input type="file" name="{{ $field }}" accept=".jpg,.jpeg,.png,.pdf" @required(!$account->{$column} && $field !== 'sec_certificate') @if ($field === 'sec_certificate') id="resubmitSecCertificate" data-existing="{{ $account->{$column} ? '1' : '0' }}" @endif class="{{ $fieldClass }}">
                        <span class="mt-1 block text-xs text-neutral-500">{{ $account->{$column} ? 'Existing document will be kept unless replaced.' : 'No document on file. Upload a copy.' }}</span>
                    </label>
                @endforeach
            </div>
        </section>
        <label class="flex items-start gap-3 font-sans text-sm"><input type="checkbox" name="terms" value="1" required class="mt-1"><span>I confirm these corrected details and accept the <a href="{{ route('buyer.pages.terms-of-service') }}" class="underline">Terms of Service</a>.</span></label>
        <button type="submit" class="rounded-sm bg-primary-800 px-6 py-3 font-sans text-sm font-semibold text-white">Resubmit for review</button>
        <p id="resubmissionLookupError" role="alert" class="font-sans text-sm text-primary-800"></p>
    </form>
</main>
@endsection

@push('scripts')
    @vite('resources/js/registration-resubmission.js')
@endpush
