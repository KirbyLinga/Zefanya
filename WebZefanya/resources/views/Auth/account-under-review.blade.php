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
        <form method="POST" action="{{ route('unified.logout') }}" data-app-confirm data-confirm-variant="logout" data-confirm-title="Log out?" data-confirm-message="You will be signed out of all Zefanya accounts in this browser." data-confirm-label="Log out">
            @csrf
            <button type="submit">Log out</button>
        </form>
    </div>
</div>
@endsection
