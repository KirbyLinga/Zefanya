<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1" />
        <title>Activate Rider Account — Zefanya</title>
        @vite(['resources/css/app.css', 'resources/css/login-modal.css'])
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

                <h1 class="mb-1 text-xl font-semibold text-gray-900">Activate your rider account</h1>
                <p class="mb-6 text-sm text-gray-500">Choose a password to finish activating. The link expires 24 hours after it was sent.</p>

                @if ($errors->any())
                    <div class="mb-6 rounded border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                        {{ $errors->first() }}
                    </div>
                @endif

                <form method="POST" action="{{ route('rider.activate', $token) }}" class="mb-6 space-y-4">
                    @csrf
                    <div>
                        <label for="riderPassword" class="block text-sm font-medium text-gray-700">Password</label>
                        <input id="riderPassword" name="password" type="password" required autofocus
                               class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500" />
                        <p class="mt-1 text-xs text-gray-500">Min 8 characters with uppercase, lowercase, number, and special character.</p>
                    </div>
                    <div>
                        <label for="riderPasswordConfirm" class="block text-sm font-medium text-gray-700">Confirm password</label>
                        <input id="riderPasswordConfirm" name="password_confirmation" type="password" required
                               class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500" />
                    </div>
                    <button type="submit" class="w-full rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700">
                        ACTIVATE ACCOUNT
                    </button>
                </form>
            </div>
        </div>
    </body>
</html>

