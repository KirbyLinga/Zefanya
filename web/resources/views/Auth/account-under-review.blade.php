@extends('Layouts.footer')

@push('styles')
    @vite('resources/css/auth.css')
@endpush

@section('content')
<div class="auth-hero">
    <div class="auth-card">
        <h1>Your account is under review</h1>
        <p>{{ $message }}</p>
        <a href="{{ route('home') }}">Back to home</a>
        <form method="POST" action="{{ route('unified.logout') }}">
            @csrf
            <button type="submit">Log out</button>
        </form>
    </div>
</div>
@endsection
