@extends('Buyer.Layouts.app')
@section('title', 'Contact Us')
@section('content')
<div class="bg-cream">
    <div class="mx-auto max-w-[1440px] px-4 py-8 sm:px-8 lg:px-16">
        <div class="max-w-[990px]">
            <h1 class="font-serif text-[32px] sm:text-[40px] font-semibold text-buyer-ink mb-6">Contact Us</h1>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                {{-- Contact Info --}}
                <div class="space-y-6">
                    <section class="bg-white rounded-2xl border border-buyer-line p-6">
                        <h3 class="font-serif text-[18px] text-buyer-ink mb-3">Get in Touch</h3>
                        <div class="space-y-4 text-[14px] text-buyer-ink-soft">
                            <div>
                                <p class="font-semibold text-buyer-ink">Email</p>
                                <a href="mailto:support@zefanya.shop" class="text-primary-800 hover:text-primary-900">support@zefanya.shop</a>
                            </div>
                            <p>Include your account email and, for an order or return, its number. For privacy requests, put "Privacy" in the subject line.</p>
                        </div>
                    </section>

                    <section class="bg-white rounded-2xl border border-buyer-line p-6">
                        <h3 class="font-serif text-[18px] text-buyer-ink mb-3">Quick Links</h3>
                        <ul class="space-y-2 text-[13px]">
                            <li>
                                <a href="{{ route('buyer.help.index') }}" class="text-primary-800 hover:text-primary-900 font-semibold">Help & FAQ</a>
                            </li>
                            <li>
                                <a href="{{ route('buyer.pages.shipping-returns') }}" class="text-primary-800 hover:text-primary-900 font-semibold">Shipping & Returns</a>
                            </li>
                            <li>
                                <a href="{{ route('buyer.pages.privacy-policy') }}" class="text-primary-800 hover:text-primary-900 font-semibold">Privacy Policy</a>
                            </li>
                            <li>
                                <a href="{{ route('buyer.pages.terms-of-service') }}" class="text-primary-800 hover:text-primary-900 font-semibold">Terms of Service</a>
                            </li>
                        </ul>
                    </section>
                </div>

                {{-- Chat Prompt --}}
                <div class="bg-white rounded-2xl border border-buyer-line p-6">
                    <h3 class="font-serif text-[18px] text-buyer-ink mb-3">Question About an Order?</h3>
                    <p class="text-[14px] text-buyer-ink-soft mb-4">
                        Track parcels, request returns and see refund details in My Orders. To ask a Seller about a product or order, use Messages.
                    </p>
                    <a href="{{ route('buyer.orders.index') }}" class="inline-flex items-center gap-2 rounded-lg bg-buyer-ink px-6 py-3 text-[13px] font-semibold text-white hover:opacity-90 transition-opacity">
                        <i data-lucide="package" width="16" height="16" aria-hidden="true"></i>
                        Go to My Orders
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
