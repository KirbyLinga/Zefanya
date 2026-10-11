@extends('Buyer.Layouts.app')
@section('title', 'Privacy Policy')
@section('content')
<div class="bg-cream">
    <div class="mx-auto max-w-[1440px] px-4 py-8 sm:px-8 lg:px-16">
        <div class="max-w-[990px]">
            <h1 class="font-serif text-[32px] sm:text-[40px] font-semibold text-buyer-ink mb-6">Privacy Policy</h1>

            <div class="bg-white rounded-2xl border border-buyer-line p-6 space-y-6 text-[14px] text-buyer-ink-soft leading-relaxed">
                <section>
                    <h2 class="font-serif text-[18px] text-buyer-ink mb-3">Your Privacy Matters</h2>
                    <p>At Zefanya, we are committed to protecting your personal information and your right to privacy. We collect personal data only for legitimate business purposes and to improve your shopping experience.</p>
                </section>

                <section>
                    <h2 class="font-serif text-[18px] text-buyer-ink mb-3">Information We Collect</h2>
                    <ul class="list-disc list-inside space-y-2">
                        <li>Account information (name, email, phone, address)</li>
                        <li>Payment information (processed securely by third parties)</li>
                        <li>Order and browsing history</li>
                        <li>Communication preferences</li>
                    </ul>
                </section>

                <section>
                    <h2 class="font-serif text-[18px] text-buyer-ink mb-3">How We Use Your Data</h2>
                    <p>We use your information to process orders, improve our service, and communicate with you about your account. We never sell your data to third parties.</p>
                </section>

                <section>
                    <h2 class="font-serif text-[18px] text-buyer-ink mb-3">Contact Us</h2>
                    <p>If you have privacy concerns, please email us at support@zefanya.shop.</p>
                </section>
            </div>
        </div>
    </div>
</div>
@endsection
