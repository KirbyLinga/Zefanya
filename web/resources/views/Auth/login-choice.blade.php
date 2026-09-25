<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1" />
        <title>Login — Zefanya</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="flex items-center justify-center min-h-screen bg-gray-50">
        <div class="w-full max-w-md">
            <div class="rounded-xl border border-gray-200 bg-white p-8 shadow-sm">
                <div class="mb-6 flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <img class="h-8 w-8" src="{{ asset('Images/Zefanya-Logo.png') }}" alt="Zefanya" />
                        <span class="font-semibold text-gray-900">Zefanya</span>
                    </div>
                    <a href="{{ route('home') }}" class="text-sm text-gray-500 hover:text-gray-700">Back to home</a>
                </div>

                <h1 class="mb-1 text-xl font-semibold text-gray-900">Welcome back</h1>
                <p class="mb-6 text-sm text-gray-500">Log in to continue to Zefanya.</p>

                @if (session('auth.status_message'))
                    <div class="mb-6 rounded border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                        {{ session('auth.status_message') }}
                    </div>
                @elseif ($errors->any())
                    <div class="mb-6 rounded border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                        {{ $errors->first() }}
                    </div>
                @endif

                <form method="POST" action="{{ route('unified.login') }}" class="mb-6 space-y-4">
                    @csrf

                    <div>
                        <label for="loginEmail" class="block text-sm font-medium text-gray-700">Email address</label>
                        <input
                            id="loginEmail"
                            name="email"
                            type="email"
                            value="{{ old('email') }}"
                            required
                            autofocus
                            class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                        />
                    </div>

                    <div>
                        <label for="loginPassword" class="block text-sm font-medium text-gray-700">Password</label>
                        <input
                            id="loginPassword"
                            name="password"
                            type="password"
                            required
                            class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                        />
                    </div>

                    <div class="flex items-center gap-2">
                        <label class="flex items-center">
                            <input type="checkbox" name="remember" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                            <span class="ml-2 text-sm text-gray-600">Remember me</span>
                        </label>
                        <a href="{{ Route::has('password.request') ? route('password.request') : '#' }}" class="ml-auto text-sm text-gray-500 hover:text-gray-700">
                            Forgot password?
                        </a>
                    </div>

                    <button type="submit" class="w-full rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                        LOG IN
                    </button>
                </form>

                <div class="relative my-6 flex items-center gap-4">
                    <span class="h-px flex-1 bg-gray-200"></span>
                    <span class="text-sm text-gray-500">or</span>
                    <span class="h-px flex-1 bg-gray-200"></span>
                </div>

                <a href="{{ route('home') }}" class="block w-full rounded-md border border-gray-300 px-4 py-2 text-center text-sm font-medium text-gray-700 hover:bg-gray-50">
                    CONTINUE AS GUEST
                </a>

                <p class="mt-6 text-center text-sm text-gray-500">
                    Don't have an account?
                    <a href="{{ Route::has('register.type') ? route('register.type') : '#' }}" class="text-indigo-600 hover:text-indigo-500">Register</a>
                </p>
            </div>
        </div>
    </body>
</html>
