@extends('Buyer.Layouts.app')
@section('title', 'About Zefanya')
@section('content')
<div class="bg-cream">
    <div class="mx-auto max-w-[1440px] px-4 py-8 sm:px-8 lg:px-16">
        <div class="max-w-[990px]">
            <h1 class="font-serif text-[32px] sm:text-[40px] font-semibold text-buyer-ink mb-6">About Zefanya</h1>

            <div class="space-y-8">
                <section class="bg-white rounded-2xl border border-buyer-line p-6">
                    <h2 class="font-serif text-[22px] text-buyer-ink mb-3">Our Story</h2>
                    <p class="text-[14px] text-buyer-ink-soft leading-relaxed">
                        Zefanya is a modern e-commerce marketplace dedicated to bringing curated, conscious, and quality products to conscious consumers. We believe in supporting independent sellers and making shopping more meaningful.
                    </p>
                </section>

                <section class="bg-white rounded-2xl border border-buyer-line p-6">
                    <h2 class="font-serif text-[22px] text-buyer-ink mb-3">Our Mission</h2>
                    <p class="text-[14px] text-buyer-ink-soft leading-relaxed">
                        To create a thriving marketplace that empowers sellers, delights buyers, and promotes sustainable, ethical shopping practices.
                    </p>
                </section>

                <section class="bg-white rounded-2xl border border-buyer-line p-6">
                    <h2 class="font-serif text-[22px] text-buyer-ink mb-3">Why Choose Us</h2>
                    <ul class="space-y-2 text-[14px] text-buyer-ink-soft">
                        <li class="flex gap-3">
                            <span class="text-primary-800 font-bold">✓</span>
                            <span>Curated selection of quality products</span>
                        </li>
                        <li class="flex gap-3">
                            <span class="text-primary-800 font-bold">✓</span>
                            <span>Fast and reliable shipping</span>
                        </li>
                        <li class="flex gap-3">
                            <span class="text-primary-800 font-bold">✓</span>
                            <span>Easy returns within 14 days</span>
                        </li>
                        <li class="flex gap-3">
                            <span class="text-primary-800 font-bold">✓</span>
                            <span>Support independent sellers</span>
                        </li>
                    </ul>
                </section>
            </div>
        </div>
    </div>
</div>
@endsection
