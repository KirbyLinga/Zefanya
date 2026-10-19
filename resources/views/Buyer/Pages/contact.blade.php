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
                            <div>
                                <p class="font-semibold text-buyer-ink">Hours</p>
                                <p>Monday – Friday, 9 AM – 6 PM PHT</p>
                            </div>
                            <div>
                                <p class="font-semibold text-buyer-ink">Response Time</p>
                                <p>Typically within 24 hours</p>
                            </div>
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
                        </ul>
                    </section>
                </div>

                {{-- Chat Prompt --}}
                <div class="bg-white rounded-2xl border border-buyer-line p-6">
                    <h3 class="font-serif text-[18px] text-buyer-ink mb-3">Prefer Chat?</h3>
                    <p class="text-[14px] text-buyer-ink-soft mb-4">
                        For immediate assistance, use the chat button at the bottom of any page. Our team is here to help!
                    </p>
                    <a href="{{ route('buyer.help.index') }}" class="inline-flex items-center gap-2 rounded-lg bg-buyer-ink px-6 py-3 text-[13px] font-semibold text-white hover:opacity-90 transition-opacity">
                        <i data-lucide="message-circle" width="16" height="16"></i>
                        Start Chat
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
