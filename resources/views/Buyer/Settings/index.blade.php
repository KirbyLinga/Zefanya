@extends('Buyer.Layouts.app')

@section('title', 'Settings')

@section('content')
<div class="bg-[var(--bg-page)] py-8 sm:py-10">
    <div class="mx-auto w-full max-w-[1240px] px-4 sm:px-6 lg:px-8">
        <div class="grid grid-cols-1 gap-8 lg:grid-cols-[280px_1fr]">

            {{-- ── Left Sidebar: Account Summary ── --}}
            @include('Components.buyer.account-sidebar', ['active' => 'settings'])

            {{-- ── Right Column: Settings Sections ── --}}
            <main>
                {{-- Page Header --}}
                <div class="mb-8">
                    <h1 class="font-serif text-[28px] font-normal leading-tight text-[var(--text-ink)] sm:text-[32px]">
                        Settings
                    </h1>
                    <p class="mt-1 font-sans text-[13px] text-[var(--text-muted)]">
                        Manage your preferences and account settings
                    </p>
                </div>

                {{-- Success Message --}}
                @if (session('success'))
                    <div class="mb-6 flex items-center gap-2.5 rounded-lg border border-[var(--pill-success-border)] bg-[var(--pill-success-bg)] px-3.5 py-2.5 font-sans text-[12.5px] font-medium text-[var(--status-success)]" role="status">
                        <i data-lucide="check-circle-2" width="15" height="15" class="shrink-0"></i>
                        <span class="text-[var(--status-success)]">{{ session('success') }}</span>
                    </div>
                @endif

                {{-- Settings Sections --}}
                <div class="space-y-6">

                    {{-- ═══ NOTIFICATIONS CARD ═══ --}}
                    <section class="rounded-2xl border border-[var(--border)] bg-[var(--bg-surface)] p-6 shadow-xs">
                        <div class="mb-6">
                            <h2 class="font-serif text-[18px] font-medium text-[var(--text-ink)]">
                                Notifications
                            </h2>
                            <p class="mt-1 font-sans text-[13px] text-[var(--text-muted)]">
                                Choose how and when to receive notifications from Zefanya
                            </p>
                        </div>

                        <form action="{{ route('buyer.settings.update') }}" method="POST" id="notifications-form">
                            @csrf
                            @method('PATCH')

                            {{-- Notification preferences --}}
                            <div class="space-y-5">
                                {{-- Order Updates --}}
                                <div class="space-y-3">
                                    <h3 class="font-sans text-[12.5px] font-semibold uppercase tracking-wider text-[var(--text-muted)]">
                                        Order Updates
                                    </h3>
                                    <div class="space-y-2 pl-1">
                                        <label class="flex items-center gap-3 cursor-pointer">
                                            <input type="hidden" name="notifications[order_updates][email]" value="0">
                                            <input type="checkbox"
                                                   name="notifications[order_updates][email]"
                                                   value="1"
                                                   {{ $settings['notifications']['order_updates']['email'] ? 'checked' : '' }}
                                                   class="h-5 w-5 rounded border-[var(--border)] bg-[var(--bg-surface)] accent-primary-800 cursor-pointer transition-colors">
                                            <span class="font-sans text-[13px] text-[var(--text-ink)]">
                                                Order status updates via email
                                            </span>
                                        </label>
                                        <label class="flex items-center gap-3 cursor-pointer">
                                            <input type="hidden" name="notifications[order_updates][sms]" value="0">
                                            <input type="checkbox"
                                                   name="notifications[order_updates][sms]"
                                                   value="1"
                                                   {{ $settings['notifications']['order_updates']['sms'] ? 'checked' : '' }}
                                                   class="h-5 w-5 rounded border-[var(--border)] bg-[var(--bg-surface)] accent-primary-800 cursor-pointer transition-colors">
                                            <span class="font-sans text-[13px] text-[var(--text-ink)]">
                                                Order status updates via SMS
                                            </span>
                                        </label>
                                    </div>
                                </div>

                                {{-- Promotions --}}
                                <div class="space-y-3 border-t border-[var(--border-faint)] pt-5">
                                    <h3 class="font-sans text-[12.5px] font-semibold uppercase tracking-wider text-[var(--text-muted)]">
                                        Promotions & Deals
                                    </h3>
                                    <div class="space-y-2 pl-1">
                                        <label class="flex items-center gap-3 cursor-pointer">
                                            <input type="hidden" name="notifications[promotions][email]" value="0">
                                            <input type="checkbox"
                                                   name="notifications[promotions][email]"
                                                   value="1"
                                                   {{ $settings['notifications']['promotions']['email'] ? 'checked' : '' }}
                                                   class="h-5 w-5 rounded border-[var(--border)] bg-[var(--bg-surface)] accent-primary-800 cursor-pointer transition-colors">
                                            <span class="font-sans text-[13px] text-[var(--text-ink)]">
                                                Promotional offers via email
                                            </span>
                                        </label>
                                        <label class="flex items-center gap-3 cursor-pointer">
                                            <input type="hidden" name="notifications[promotions][sms]" value="0">
                                            <input type="checkbox"
                                                   name="notifications[promotions][sms]"
                                                   value="1"
                                                   {{ $settings['notifications']['promotions']['sms'] ? 'checked' : '' }}
                                                   class="h-5 w-5 rounded border-[var(--border)] bg-[var(--bg-surface)] accent-primary-800 cursor-pointer transition-colors">
                                            <span class="font-sans text-[13px] text-[var(--text-ink)]">
                                                Promotional offers via SMS
                                            </span>
                                        </label>
                                    </div>
                                </div>

                                {{-- Wishlist Price Drops --}}
                                <div class="space-y-3 border-t border-[var(--border-faint)] pt-5">
                                    <h3 class="font-sans text-[12.5px] font-semibold uppercase tracking-wider text-[var(--text-muted)]">
                                        Wishlist
                                    </h3>
                                    <div class="space-y-2 pl-1">
                                        <label class="flex items-center gap-3 cursor-pointer">
                                            <input type="hidden" name="notifications[wishlist_drops][email]" value="0">
                                            <input type="checkbox"
                                                   name="notifications[wishlist_drops][email]"
                                                   value="1"
                                                   {{ $settings['notifications']['wishlist_drops']['email'] ? 'checked' : '' }}
                                                   class="h-5 w-5 rounded border-[var(--border)] bg-[var(--bg-surface)] accent-primary-800 cursor-pointer transition-colors">
                                            <span class="font-sans text-[13px] text-[var(--text-ink)]">
                                                Price drop alerts via email
                                            </span>
                                        </label>
                                        <label class="flex items-center gap-3 cursor-pointer">
                                            <input type="hidden" name="notifications[wishlist_drops][sms]" value="0">
                                            <input type="checkbox"
                                                   name="notifications[wishlist_drops][sms]"
                                                   value="1"
                                                   {{ $settings['notifications']['wishlist_drops']['sms'] ? 'checked' : '' }}
                                                   class="h-5 w-5 rounded border-[var(--border)] bg-[var(--bg-surface)] accent-primary-800 cursor-pointer transition-colors">
                                            <span class="font-sans text-[13px] text-[var(--text-ink)]">
                                                Price drop alerts via SMS
                                            </span>
                                        </label>
                                    </div>
                                </div>
                            </div>

                            {{-- Appearance Setting (same form) --}}
                            <div class="mt-8 border-t border-[var(--border-faint)] pt-6">
                                <h3 class="font-sans text-[12.5px] font-semibold uppercase tracking-wider text-[var(--text-muted)] mb-3">
                                    Appearance
                                </h3>
                                <div class="space-y-2 pl-1">
                                    <label class="flex items-center gap-3 cursor-pointer">
                                        <input type="radio"
                                               name="appearance"
                                               value="light"
                                               {{ $settings['appearance'] === 'light' ? 'checked' : '' }}
                                               class="h-5 w-5 border-[var(--border)] bg-[var(--bg-surface)] accent-primary-800 cursor-pointer">
                                        <span class="font-sans text-[13px] text-[var(--text-ink)]">Light Mode</span>
                                    </label>
                                    <label class="flex items-center gap-3 cursor-pointer">
                                        <input type="radio"
                                               name="appearance"
                                               value="dark"
                                               {{ $settings['appearance'] === 'dark' ? 'checked' : '' }}
                                               class="h-5 w-5 border-[var(--border)] bg-[var(--bg-surface)] accent-primary-800 cursor-pointer">
                                        <span class="font-sans text-[13px] text-[var(--text-ink)]">Dark Mode</span>
                                    </label>
                                    <label class="flex items-center gap-3 cursor-pointer">
                                        <input type="radio"
                                               name="appearance"
                                               value="system"
                                               {{ $settings['appearance'] === 'system' ? 'checked' : '' }}
                                               class="h-5 w-5 border-[var(--border)] bg-[var(--bg-surface)] accent-primary-800 cursor-pointer">
                                        <span class="font-sans text-[13px] text-[var(--text-ink)]">System (Follow device settings)</span>
                                    </label>
                                </div>
                            </div>

                            <div class="mt-6 flex items-center gap-3">
                                <button type="submit"
                                        class="inline-flex items-center justify-center rounded-xl bg-primary-800 px-5 py-2.5 font-sans text-[12.5px] font-semibold tracking-wide text-white transition-colors hover:bg-primary-900">
                                    Save Settings
                                </button>
                            </div>
                        </form>

                        @if ($errors->any())
                            <div class="mt-4 rounded-lg border border-red-200 bg-red-50 p-3">
                                @foreach ($errors->all() as $error)
                                    <p class="font-sans text-[12px] text-red-700">{{ $error }}</p>
                                @endforeach
                            </div>
                        @endif
                    </section>

                    {{-- ═══ DANGER ZONE CARD ═══ --}}
                    <section class="rounded-2xl border border-red-200 bg-red-50 p-6 shadow-xs">
                        <div class="mb-4">
                            <h2 class="font-serif text-[18px] font-medium text-red-700">
                                Danger Zone
                            </h2>
                            <p class="mt-1 font-sans text-[13px] text-red-600">
                                Permanently delete your account and all associated data
                            </p>
                        </div>

                        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                            <p class="font-sans text-[13px] text-red-700">
                                This action cannot be undone. Your account will be deleted permanently.
                            </p>
                            <button type="button"
                                    onclick="document.getElementById('deleteAccountModal').showModal()"
                                    class="inline-flex shrink-0 items-center justify-center rounded-xl border border-red-300 bg-white px-5 py-2.5 font-sans text-[12.5px] font-semibold text-red-700 transition-colors hover:bg-red-100">
                                Delete Account
                            </button>
                        </div>
                    </section>

                </div>
            </main>
        </div>
    </div>
</div>

{{-- ═══ DELETE ACCOUNT CONFIRMATION MODAL ═══ --}}
<dialog id="deleteAccountModal" class="backdrop:bg-black/50 rounded-2xl border border-[var(--border)] bg-[var(--bg-surface)] p-6 shadow-lg max-w-[420px]">
    <div class="mb-4">
        <h2 class="font-serif text-[20px] font-medium text-red-700">
            Delete Account?
        </h2>
        <p class="mt-2 font-sans text-[13px] text-[var(--text-muted)]">
            This will permanently delete your account and all associated data including orders, addresses, and wishlists. This action cannot be undone.
        </p>
    </div>

    <form action="{{ route('buyer.settings.deleteAccount') }}" method="POST">
        @csrf

        {{-- Password Confirmation --}}
        <div class="mb-5">
            <label for="password" class="block font-sans text-[12px] font-semibold uppercase tracking-wider text-[var(--text-muted)] mb-2">
                Confirm your password
            </label>
            <input type="password"
                   name="password"
                   id="password"
                   placeholder="Enter your password"
                   required
                   class="w-full rounded-xl border border-[var(--border)] bg-[var(--bg-surface)] px-3.5 py-2.5 font-sans text-[13px] text-[var(--text-ink)] outline-none transition-colors placeholder:text-[var(--text-placeholder)] focus:border-red-400 focus:ring-2 focus:ring-red-200">
            @if ($errors->has('password'))
                <p class="mt-1.5 font-sans text-[11.5px] text-red-600">
                    {{ $errors->first('password') }}
                </p>
            @endif
        </div>

        {{-- Confirmation Checkbox --}}
        <label class="mb-5 flex items-center gap-3 cursor-pointer">
            <input type="checkbox"
                   name="confirm"
                   value="1"
                   required
                   class="h-5 w-5 rounded border-[var(--border)] accent-red-700">
            <span class="font-sans text-[12.5px] text-[var(--text-ink)]">
                I understand this action is permanent
            </span>
        </label>

        {{-- Action Buttons --}}
        <div class="flex gap-3">
            <button type="button"
                    onclick="document.getElementById('deleteAccountModal').close()"
                    class="flex-1 rounded-xl border border-[var(--border)] bg-[var(--bg-surface)] px-4 py-2.5 font-sans text-[12.5px] font-semibold text-[var(--text-ink)] transition-colors hover:bg-neutral-100">
                Cancel
            </button>
            <button type="submit"
                    class="flex-1 rounded-xl bg-red-700 px-4 py-2.5 font-sans text-[12.5px] font-semibold text-white transition-colors hover:bg-red-800">
                Delete Account
            </button>
        </div>
    </form>
</dialog>

@endsection
