@extends('Buyer.Layouts.app')
@section('title', 'My Account')
@section('content')
<div class="bg-[var(--bg-page)] py-8 sm:py-10">
    <div class="mx-auto w-full max-w-[1240px] px-4 sm:px-6 lg:px-8">
        <div class="grid grid-cols-1 gap-8 lg:grid-cols-[280px_1fr]">
            @include('Components.buyer.account-sidebar', ['active' => 'profile'])
            <main></main>
        </div>
    </div>
</div>
@endsection
