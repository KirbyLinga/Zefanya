@extends('Buyer.Layouts.app')
@section('title', 'Shipping & Returns')
@section('content')
<div class="bg-cream">
    <div class="mx-auto max-w-[1440px] px-4 py-8 sm:px-8 lg:px-16">
        <div class="max-w-[990px]">
            <h1 class="font-serif text-[32px] sm:text-[40px] font-semibold text-buyer-ink mb-2">Shipping & Returns</h1>
            <p class="mb-6 text-[13px] text-buyer-ink-soft">Last updated: 11 November 2026. The full rules are in the <a class="underline underline-offset-2 hover:text-buyer-ink" href="{{ route('buyer.pages.terms-of-service') }}#buyers">Buyer terms</a>.</p>

            <div class="space-y-8">
                <section class="bg-white rounded-2xl border border-buyer-line p-6" aria-labelledby="shipping-heading">
                    <h2 id="shipping-heading" class="font-serif text-[22px] text-buyer-ink mb-3">Shipping Information</h2>
                    <div class="space-y-4 text-[14px] text-buyer-ink-soft leading-relaxed">
                        <p><strong class="text-buyer-ink">Shipping Fees:</strong> Calculated automatically at checkout from the route and parcel weight.</p>
                        <p><strong class="text-buyer-ink">Payment:</strong> Cash on delivery. Pay the Rider when your parcel arrives, then give them the delivery code shown on your order page.</p>
                        <p><strong class="text-buyer-ink">Delivery Time:</strong> Depends on the distance and the carrier's route. Orders from different Sellers arrive as separate parcels.</p>
                        <p><strong class="text-buyer-ink">Tracking:</strong> Follow each parcel's progress on your order page.</p>
                        <p><strong class="text-buyer-ink">Failed Deliveries:</strong> Each parcel gets at most two delivery attempts. After two failed attempts it goes back to the Seller.</p>
                    </div>
                </section>

                <section class="bg-white rounded-2xl border border-buyer-line p-6" aria-labelledby="returns-heading">
                    <h2 id="returns-heading" class="font-serif text-[22px] text-buyer-ink mb-3">Return Policy</h2>
                    <div class="space-y-4 text-[14px] text-buyer-ink-soft leading-relaxed">
                        <p><strong class="text-buyer-ink">7-Day Returns:</strong> Request a return from your order page within seven days of delivery, before the shipment is completed. If you do not confirm receipt, the shipment completes automatically after seven days.</p>
                        <p><strong class="text-buyer-ink">What to Send:</strong> The reason, a short description and photos of the item and packaging.</p>
                        <p><strong class="text-buyer-ink">Seller Decision:</strong> The Seller has three days to approve or reject, with a reason. No answer in time means the return is approved. You can ask a Zefanya Admin to review a rejection once.</p>
                        <p><strong class="text-buyer-ink">Collection:</strong> A Rider collects the approved return from you. You can cancel the request before collection and keep the item.</p>
                        <p><strong class="text-buyer-ink">Refunds:</strong> Refunds are paid to you outside the platform and recorded by a Zefanya Admin with a reference, which you can see on your order. If less than you paid is refunded, you will see the reason.</p>
                        <p><strong class="text-buyer-ink">Your Legal Rights:</strong> The seven-day in-app window is the standard way to request a return on Zefanya. It does not remove any rights you have under Philippine consumer law. If a problem appears later, email <a class="underline underline-offset-2 hover:text-buyer-ink" href="mailto:support@zefanya.shop">support@zefanya.shop</a>.</p>
                    </div>
                </section>
            </div>
        </div>
    </div>
</div>
@endsection
