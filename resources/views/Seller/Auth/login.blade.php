@extends('Layouts.footer')

@push('styles')
    @vite('resources/css/auth.css')
@endpush

@section('content')
<div class="auth-hero">
    <div class="auth-card" style="max-width: 420px; margin: 0 auto;">
        <h1>Seller Log In</h1>
        <p>Access your seller dashboard.</p>

        @if (session('auth.status_message'))
            <div class="buyer-modal__error-summary">
                {{ session('auth.status_message') }}
            </div>
        @elseif ($errors->any())
            <div class="buyer-modal__error-summary">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ route('unified.login') }}" novalidate>
            @csrf

            <div class="buyer-modal__field">
                <label for="email">Email *</label>
                <input type="email" name="email" id="email" value="{{ old('email') }}" required autofocus>
            </div>

            <div class="buyer-modal__field">
                <label for="password">Password *</label>
                <input type="password" name="password" id="password" required>
            </div>

            <div class="buyer-modal__field">
                <label>
                    <input type="checkbox" name="remember"> Remember me
                </label>
            </div>

            <button type="submit" class="buyer-modal__submit" style="width: 100%;">Log In</button>
        </form>
    </div>
</div>
@endsection
