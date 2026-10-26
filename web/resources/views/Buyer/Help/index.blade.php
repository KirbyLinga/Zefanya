@extends('Buyer.Layouts.app')
@section('title', 'Help & Support')
@section('content')
<div class="bg-cream">
    <div class="mx-auto max-w-[1440px] px-4 py-8 sm:px-8 lg:px-16">
        <div class="max-w-[990px]">
            {{-- Page header --}}
            <div class="mb-8">
                <h1 class="font-serif text-[32px] sm:text-[40px] font-semibold text-buyer-ink">Help & Support</h1>
                <p class="mt-2 text-[14px] text-buyer-ink-soft">We're here to help. Browse our FAQs or contact us.</p>
            </div>

            {{-- Help sections --}}
            <div class="space-y-6">
                {{-- FAQs --}}
                <div class="rounded-2xl bg-white border border-buyer-line p-6">
                    <h3 class="font-serif text-[18px] text-buyer-ink mb-4">Frequently Asked Questions</h3>
                    <div class="space-y-3">
                        <details class="group border-b border-buyer-line pb-3">
                            <summary class="cursor-pointer text-[13px] font-semibold text-buyer-ink group-open:text-primary-800">
                                How do I track my order?
                            </summary>
                            <p class="mt-2 text-[12px] text-buyer-ink-soft leading-relaxed">
                                Once your order ships, you'll receive a tracking number via email. You can track your order status in "My Orders" anytime.
                            </p>
                        </details>
                        <details class="group border-b border-buyer-line pb-3">
                            <summary class="cursor-pointer text-[13px] font-semibold text-buyer-ink group-open:text-primary-800">
                                What is your return policy?
                            </summary>
                            <p class="mt-2 text-[12px] text-buyer-ink-soft leading-relaxed">
                                We offer easy 14-day returns on most items. Visit our Shipping & Returns page for details and to initiate a return.
                            </p>
                        </details>
                        <details class="group border-b border-buyer-line pb-3">
                            <summary class="cursor-pointer text-[13px] font-semibold text-buyer-ink group-open:text-primary-800">
                                Do you offer free shipping?
                            </summary>
                            <p class="mt-2 text-[12px] text-buyer-ink-soft leading-relaxed">
                                Yes! We offer free shipping on orders over ₱1,500. Orders under ₱1,500 have standard shipping rates.
                            </p>
                        </details>
                        <details class="group pb-3">
                            <summary class="cursor-pointer text-[13px] font-semibold text-buyer-ink group-open:text-primary-800">
                                How do I contact customer support?
                            </summary>
                            <p class="mt-2 text-[12px] text-buyer-ink-soft leading-relaxed">
                                Reach out to us via the chat button on any page, or email support@zefanya.shop. We typically respond within 24 hours.
                            </p>
                        </details>
                    </div>
                </div>

                {{-- Contact --}}
                <div class="rounded-2xl bg-white border border-buyer-line p-6">
                    <h3 class="font-serif text-[18px] text-buyer-ink mb-4">Get in Touch</h3>
                    <div class="space-y-3">
                        <div>
                            <p class="text-[12px] font-semibold text-buyer-ink-soft uppercase">Email</p>
                            <p class="text-[14px] text-buyer-ink">support@zefanya.shop</p>
                        </div>
                        <div>
                            <p class="text-[12px] font-semibold text-buyer-ink-soft uppercase">Hours</p>
                            <p class="text-[14px] text-buyer-ink">Monday – Friday, 9 AM – 6 PM (PHT)</p>
                        </div>
                    </div>
                </div>

                {{-- Resources --}}
                <div class="rounded-2xl bg-white border border-buyer-line p-6">
                    <h3 class="font-serif text-[18px] text-buyer-ink mb-4">Resources</h3>
                    <div class="space-y-2">
                        <a href="{{ route('buyer.pages.shipping-returns') }}" class="block text-[13px] font-semibold text-primary-800 hover:text-primary-900">
                            Shipping & Returns →
                        </a>
                        <a href="{{ route('buyer.pages.privacy-policy') }}" class="block text-[13px] font-semibold text-primary-800 hover:text-primary-900">
                            Privacy Policy →
                        </a>
                        <a href="{{ route('buyer.pages.terms-of-service') }}" class="block text-[13px] font-semibold text-primary-800 hover:text-primary-900">
                            Terms of Service →
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
