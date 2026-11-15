@extends('Buyer.Layouts.app')
@section('title', 'Help & Support')
@section('content')
@php
    $faqs = [
        ['How do I track my order?', 'Open My Orders and choose the order. Each Seller\'s parcel has its own status and timeline, with the steps recorded so far.'],
        ['How do I pay?', 'Zefanya uses cash on delivery (COD). Pay the Rider when your parcel arrives.'],
        ['How much is shipping? Can I use vouchers?', 'Shipping is calculated at checkout from the route and parcel weight. Vouchers are applied in your cart. A shipping voucher reduces the shipping fee. Shop and platform vouchers give the discount stated on the voucher. There is no automatic free shipping.'],
        ['How do I request a return?', 'Open the order in My Orders and choose the parcel. If the item is defective, damaged, wrong or not as described, request a return with the reason, a short description and photos.'],
        ['How long do I have to request a return?', 'The standard in-app window is seven days from delivery, while the shipment is not yet completed. If you do not confirm receipt, the shipment completes automatically after seven days. This window does not remove any rights you have under Philippine consumer law.'],
        ['What if the Seller rejects my return?', 'The Seller has three days to approve or reject your request, with a reason. If they do not answer in time, it is approved automatically. If they reject it, you can ask a Zefanya Admin to review the rejection once from the same order page. The Admin keeps the rejection or approves the return and explains the decision.'],
        ['When do I get my refund?', 'After an approved return, the refund is paid to you outside the platform and recorded by a Zefanya Admin with a reference, which you can see on your order. If less than you paid is refunded, the reason is shown.'],
        ['What if I miss my delivery?', 'Each parcel gets at most two delivery attempts. If both fail, the parcel goes back to the Seller. Keep your phone number and address up to date.'],
        ['How do I contact a Seller?', 'Use Messages to chat with a Seller about a product or order you have with them.'],
        ['How do I contact Zefanya support?', 'Email support@zefanya.shop with your account email and, if it is about an order, the order number.'],
    ];
@endphp
<div class="bg-cream">
    <div class="mx-auto max-w-[1440px] px-4 py-8 sm:px-8 lg:px-16">
        <div class="max-w-[990px]">
            {{-- Page header --}}
            <div class="mb-8">
                <h1 class="font-serif text-[32px] sm:text-[40px] font-semibold text-buyer-ink">Help & Support</h1>
                <p class="mt-2 text-[14px] text-buyer-ink-soft">Answers to common questions, and how to reach us.</p>
            </div>

            {{-- Help sections --}}
            <div class="space-y-6">
                {{-- FAQs --}}
                <section class="rounded-2xl bg-white border border-buyer-line p-6" aria-labelledby="faq-heading">
                    <h2 id="faq-heading" class="font-serif text-[18px] text-buyer-ink mb-4">Frequently Asked Questions</h2>
                    <div class="space-y-3">
                        @foreach ($faqs as [$question, $answer])
                            <details class="group {{ $loop->last ? '' : 'border-b border-buyer-line' }} pb-3">
                                <summary class="cursor-pointer text-[14px] font-semibold text-buyer-ink group-open:text-primary-800">
                                    {{ $question }}
                                </summary>
                                <p class="mt-2 text-[14px] text-buyer-ink-soft leading-relaxed">{{ $answer }}</p>
                            </details>
                        @endforeach
                    </div>
                </section>

                {{-- Contact --}}
                <section class="rounded-2xl bg-white border border-buyer-line p-6" aria-labelledby="contact-heading">
                    <h2 id="contact-heading" class="font-serif text-[18px] text-buyer-ink mb-4">Get in Touch</h2>
                    <div class="space-y-3">
                        <div>
                            <p class="text-[12px] font-semibold text-buyer-ink-soft uppercase">Email</p>
                            <a href="mailto:support@zefanya.shop" class="text-[14px] text-buyer-ink underline underline-offset-2 hover:text-primary-800">support@zefanya.shop</a>
                        </div>
                        <p class="text-[13px] text-buyer-ink-soft">To ask us to review an account or refund decision, include your account email, the order or return involved, and your explanation.</p>
                    </div>
                </section>

                {{-- Resources --}}
                <section class="rounded-2xl bg-white border border-buyer-line p-6" aria-labelledby="resources-heading">
                    <h2 id="resources-heading" class="font-serif text-[18px] text-buyer-ink mb-4">Resources</h2>
                    <div class="space-y-2">
                        <a href="{{ route('policies.show', 'buyer-protection') }}" class="block text-[13px] font-semibold text-primary-800 hover:text-primary-900">
                            Shipping & Returns →
                        </a>
                        <a href="{{ route('policies.show', 'privacy-policy') }}" class="block text-[13px] font-semibold text-primary-800 hover:text-primary-900">
                            Privacy Policy →
                        </a>
                        <a href="{{ route('policies.show', 'terms-of-service') }}" class="block text-[13px] font-semibold text-primary-800 hover:text-primary-900">
                            Terms of Service →
                        </a>
                        <a href="{{ route('policies.show', 'terms-of-service') }}" class="block text-[13px] font-semibold text-primary-800 hover:text-primary-900">
                            Buyer terms and protection →
                        </a>
                    </div>
                </section>
            </div>
        </div>
    </div>
</div>
@endsection
