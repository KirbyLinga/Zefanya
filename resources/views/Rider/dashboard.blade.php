<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1" />
        <title>Rider Dashboard — Zefanya</title>
        @vite(['resources/css/app.css', 'resources/css/login-modal.css'])
    </head>
    <body class="flex items-center justify-center min-h-screen bg-gray-50">
        <div class="w-full max-w-md">
            <div class="rounded-xl border border-gray-200 bg-white p-8 shadow-sm text-center">
                <div class="mb-4 flex justify-center">
                    <div class="flex h-12 w-12 items-center justify-center rounded-full bg-[#F7D6D0] text-[#7a4550] shadow-sm">
                        <span class="text-lg font-semibold">{{ mb_substr($rider->first_name, 0, 1) }}{{ mb_substr($rider->last_name, 0, 1) }}</span>
                    </div>
                </div>
                <p class="text-[11px] font-semibold uppercase tracking-[1.2px] text-neutral-400">Rider Dashboard</p>
                <h1 class="mt-1 text-xl font-semibold text-gray-900">{{ $rider->first_name }} {{ $rider->last_name }}</h1>
                <p class="mt-1 text-sm text-gray-500">{{ $rider->provider->business_name ?? '' }}</p>
                <span class="mt-3 inline-flex items-center rounded-full bg-emerald-50 border border-emerald-200 px-3 py-1 text-[11px] font-semibold text-emerald-700">Active</span>
                <form method="POST" action="{{ route('unified.logout') }}" class="mt-6">
                    @csrf
                    <button type="submit" class="w-full rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">LOG OUT</button>
                </form>
            </div>
        </div>
    </body>
</html>
