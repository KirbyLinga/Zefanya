@extends('Buyer.Layouts.app')
@section('title', 'Shipping & Returns')
@section('content')
<div class="bg-cream">
    <div class="mx-auto max-w-[1440px] px-4 py-8 sm:px-8 lg:px-16">
        <div class="max-w-[990px]">
            <h1 class="font-serif text-[32px] sm:text-[40px] font-semibold text-buyer-ink mb-6">Shipping & Returns</h1>

            <div class="space-y-8">
                <section class="bg-white rounded-2xl border border-buyer-line p-6">
                    <h2 class="font-serif text-[22px] text-buyer-ink mb-3">Shipping Information</h2>
                    <div class="space-y-4 text-[14px] text-buyer-ink-soft leading-relaxed">
                        <p><strong class="text-buyer-ink">Free Shipping:</strong> Orders over ₱1,500 ship free. Standard shipping otherwise.</p>
                        <p><strong class="text-buyer-ink">Delivery Time:</strong> 3-5 business days for Metro Manila, 5-7 days for provincial areas.</p>
                        <p><strong class="text-buyer-ink">Tracking:</strong> You'll receive a tracking number via email once your order ships.</p>
                    </div>
                </section>

                <section class="bg-white rounded-2xl border border-buyer-line p-6">
                    <h2 class="font-serif text-[22px] text-buyer-ink mb-3">Return Policy</h2>
                    <div class="space-y-4 text-[14px] text-buyer-ink-soft leading-relaxed">
                        <p><strong class="text-buyer-ink">14-Day Returns:</strong> You have 14 days from delivery to return items.</p>
                        <p><strong class="text-buyer-ink">Condition:</strong> Items must be unused and in original packaging.</p>
                        <p><strong class="text-buyer-ink">How to Return:</strong> Contact our support team to initiate a return.</p>
                        <p><strong class="text-buyer-ink">Refund Processing:</strong> Refunds are processed within 7-10 business days after we receive and inspect the item.</p>
                    </div>
                </section>
            </div>
        </div>
    </div>
</div>
@endsection
