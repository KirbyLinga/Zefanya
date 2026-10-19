@extends('Layouts.footer')
@push('styles') @vite(['resources/css/auth.css']) @endpush
@section('content')
<main class="auth-hero"><div class="auth-hero__intro"><h1 class="auth-hero__title">Pending Logistics Approval</h1><p class="auth-hero__subtitle">Your email is verified. Your selected Logistics provider will review your application. You will receive its approval or rejection decision by email.</p></div><div class="guest-wrap"><a href="{{ route('home') }}" class="guest-card"><h4 class="guest-card__title">Back to home</h4></a></div></main>
@endsection
