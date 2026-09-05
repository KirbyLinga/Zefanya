@extends('Layouts.footer')

@push('styles')
    @vite('resources/css/auth.css')
@endpush

@section('content')
<div class="auth-hero">
    <div class="auth-card" style="max-width: 480px; margin: 0 auto; text-align: center;">
        <h1>Email verified</h1>

        @if (session('success'))
            <p>{{ session('success') }}</p>
        @else
            <p>Your email is verified. Please wait for the administrator's approval, which will be sent to your email.</p>
        @endif

        <a href="{{ route('home') }}">Back to home</a>
    </div>
</div>
@endsection