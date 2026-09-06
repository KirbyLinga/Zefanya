@extends('Buyer.Layouts.app')

@section('title', 'Buyer Login')

@section('content')
<div class="auth-page">
    <div class="auth-card">

        <div class="auth-card__brand">
            <img class="brand__logo" src="{{ asset('Images/Zefanya-Logo-128.png') }}" alt="Zefanya logo" />
        </div>

        <h1 class="auth-card__title">Welcome back</h1>
        <p class="auth-card__subtitle">Log in to continue to Zefanya.</p>

        @if ($errors->any())
            <div class="auth-card__error">
                {{ $errors->first() }}
            </div>
        @endif

        <form class="auth-card__form" method="POST" action="{{ route('buyer.login.submit') }}">
            @csrf

            <label class="auth-card__label" for="loginEmail">Email address</label>
            <input
                class="auth-card__input"
                type="email"
                name="email"
                id="loginEmail"
                placeholder="you@example.com"
                value="{{ old('email') }}"
                required
                autofocus
            />

            <label class="auth-card__label" for="loginPassword">Password</label>
            <div class="auth-card__password-wrap">
                <input
                    class="auth-card__input"
                    type="password"
                    name="password"
                    id="loginPassword"
                    placeholder="••••••••"
                    required
                />
            </div>

            <button type="submit" class="auth-card__submit">LOG IN</button>
        </form>

        <p class="auth-card__footer">
            Don't have an account?
            <a href="{{ route('register.type') }}">Register</a>
        </p>

    </div>
</div>
@endsection
