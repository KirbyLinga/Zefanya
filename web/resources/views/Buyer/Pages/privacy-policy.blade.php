@extends('Buyer.Layouts.app')
@section('title', 'Privacy Policy')
@section('content')
@php
    $sections = [
        'scope' => 'Who this covers',
        'collect' => 'What we collect',
        'use' => 'Why we use it',
        'share' => 'Who sees it',
        'protect' => 'How we protect it',
        'keep' => 'How long we keep it',
        'rights' => 'Your rights',
        'contact' => 'Contact and complaints',
    ];
    $card = 'scroll-mt-24 rounded-2xl border border-buyer-line bg-white p-6';
    $h2 = 'mb-3 font-serif text-[22px] text-buyer-ink';
    $link = 'underline underline-offset-2 hover:text-buyer-ink';
@endphp
<div class="bg-cream">
    <div class="mx-auto max-w-[1440px] px-4 py-8 sm:px-8 lg:px-16">
        <div class="max-w-[990px]">
            <h1 class="font-serif text-[32px] sm:text-[40px] font-semibold text-buyer-ink mb-2">Privacy Policy</h1>
            <p class="mb-6 text-[13px] text-buyer-ink-soft">Last updated: 11 November 2026. This policy explains how Zefanya handles personal data under the Data Privacy Act of 2012 (Republic Act No. 10173).</p>

            <nav aria-label="On this page" class="mb-6 rounded-2xl border border-buyer-line bg-white p-5 text-[14px]">
                <h2 class="mb-2 font-serif text-[18px] text-buyer-ink">On this page</h2>
                <ol class="list-decimal space-y-1 pl-5 text-buyer-ink-soft">
                    @foreach ($sections as $id => $label)
                        <li><a class="{{ $link }}" href="#{{ $id }}">{{ $label }}</a></li>
                    @endforeach
                </ol>
            </nav>

            <div class="space-y-6 text-[14px] leading-relaxed text-buyer-ink-soft">
                <section id="scope" aria-labelledby="scope-heading" class="{{ $card }}">
                    <h2 id="scope-heading" class="{{ $h2 }}">1. Who this covers</h2>
                    <p>This policy covers everyone with a Zefanya account: Buyers, Sellers, Logistics Providers and their Riders, and people who visit the site. The rules for using the platform are in the <a class="{{ $link }}" href="{{ route('buyer.pages.terms-of-service') }}">Terms of Service</a>.</p>
                </section>

                <section id="collect" aria-labelledby="collect-heading" class="{{ $card }}">
                    <h2 id="collect-heading" class="{{ $h2 }}">2. What we collect</h2>
                    <ul class="list-disc space-y-2 pl-5">
                        <li><strong class="text-buyer-ink">Everyone:</strong> name, sex, email address, phone number, address, password (stored only in scrambled form) and the verification codes we send you. If you sign in with Google, we receive your Google account name and email address.</li>
                        <li><strong class="text-buyer-ink">Buyers:</strong> the identity document you upload at registration, saved delivery addresses, orders, return requests with their photos and descriptions, reviews, and messages with Sellers.</li>
                        <li><strong class="text-buyer-ink">Sellers:</strong> business name, type and line of business, tax identification number (TIN), business registration certificate, pickup address, listings, orders, earnings, payouts and messages with Buyers.</li>
                        <li><strong class="text-buyer-ink">Logistics Providers:</strong> business name, SEC registration number, TIN, service areas, hubs, and records of parcels, custody scans, cash collected and remittances.</li>
                        <li><strong class="text-buyer-ink">Riders:</strong> your details, your Logistics Provider and station hub, online or offline status, assigned jobs, scans, delivery and incident photos, cash collected and earnings.</li>
                        <li><strong class="text-buyer-ink">Delivery evidence:</strong> proof-of-delivery photos, the name of the person who received a parcel, delivery-code checks, failed-delivery reasons and incident reports.</li>
                        <li><strong class="text-buyer-ink">Financial records:</strong> cash-on-delivery amounts collected, shortages and their reasons, remittances between Logistics Providers and Zefanya, Seller settlements, payouts and debts, Rider earnings and payments, refunds and their references, and return and loss charges. Orders are paid in cash and Zefanya does not process card or online payments. Refund and payout records may include the name and account the money was sent to.</li>
                        <li><strong class="text-buyer-ink">Technical data:</strong> sign-in sessions and the records the platform keeps of actions taken on orders, returns and accounts.</li>
                    </ul>
                    <p class="mt-3">Government-issued identifiers and identity documents are sensitive personal information under the law, and we handle them with extra care.</p>
                </section>

                <section id="use" aria-labelledby="use-heading" class="{{ $card }}">
                    <h2 id="use-heading" class="{{ $h2 }}">3. Why we use it</h2>
                    <ul class="list-disc space-y-2 pl-5">
                        <li><strong class="text-buyer-ink">To provide the service you signed up for:</strong> reviewing registrations, running your account, processing orders, arranging pickup and delivery, handling returns and refunds, and paying Sellers, Logistics Providers and Riders.</li>
                        <li><strong class="text-buyer-ink">To meet legal obligations:</strong> keeping transaction, tax and financial records, verifying merchants and answering lawful requests from authorities.</li>
                        <li><strong class="text-buyer-ink">For legitimate interests:</strong> keeping the platform secure, preventing fraud and abuse, and investigating and resolving disputes using the records of each case.</li>
                        <li><strong class="text-buyer-ink">With your consent:</strong> where the law requires it. You can withdraw consent, but we may still keep data that the law or an open dispute requires.</li>
                    </ul>
                    <p class="mt-3">We do not sell personal data. We do not decide that someone is committing fraud by automated profiling. Decisions about disputes and accounts are made by people, using the records of each case.</p>
                </section>

                <section id="share" aria-labelledby="share-heading" class="{{ $card }}">
                    <h2 id="share-heading" class="{{ $h2 }}">4. Who sees it</h2>
                    <ul class="list-disc space-y-2 pl-5">
                        <li><strong class="text-buyer-ink">Sellers</strong> see the Buyer's name, delivery address and order details needed to fulfil the order, and return details for their own orders.</li>
                        <li><strong class="text-buyer-ink">Logistics Providers and Riders</strong> see what they need to collect and deliver a parcel: names, addresses, phone numbers and parcel details. Riders see only parcels assigned to them.</li>
                        <li><strong class="text-buyer-ink">Buyers</strong> see the Seller's shop details. Buyers do not see a Rider's name. Sellers see the name of the Riders handling their parcels.</li>
                        <li><strong class="text-buyer-ink">Zefanya Admins</strong> see what they need to review registrations, listings, returns, disputes and payments. Access depends on their Admin role.</li>
                        <li><strong class="text-buyer-ink">Service providers</strong> that help us run the platform, such as hosting and email delivery, and Google if you use Google Sign-In.</li>
                        <li><strong class="text-buyer-ink">Authorities</strong> when the law requires it, for example a lawful order or subpoena.</li>
                    </ul>
                </section>

                <section id="protect" aria-labelledby="protect-heading" class="{{ $card }}">
                    <h2 id="protect-heading" class="{{ $h2 }}">5. How we protect it</h2>
                    <ul class="list-disc space-y-2 pl-5">
                        <li>Registration documents, delivery proofs, return and dispute photos and chat images are stored privately and shown only to signed-in users who are allowed to see them.</li>
                        <li>Each account type has its own sign-in and can reach only its own records. Admin access is limited by role.</li>
                        <li>Passwords and delivery codes are stored scrambled, not as plain text.</li>
                        <li>Important actions, such as suspensions, refunds and dispute decisions, are recorded with who did them and why.</li>
                    </ul>
                    <p class="mt-3">If a personal data breach is likely to cause you serious harm, we will notify the National Privacy Commission and the people affected, as the law requires.</p>
                </section>

                <section id="keep" aria-labelledby="keep-heading" class="{{ $card }}">
                    <h2 id="keep-heading" class="{{ $h2 }}">6. How long we keep it</h2>
                    <p>We keep personal data only as long as it is needed for the purposes above: while your account is active, and afterwards for as long as order, payment, tax, return or dispute records must be kept or a legal claim may arise.</p>
                    <p class="mt-3"><strong class="text-buyer-ink">Deleting a Buyer account.</strong> Buyers can delete their account in Settings once they have no order in progress, no open return and no refund still being processed. We then remove or anonymize the profile, addresses, cart, wishlist and notifications. Order, payment and return records stay, under the name "Deleted buyer", because they are financial and dispute records.</p>
                    <p class="mt-3"><strong class="text-buyer-ink">Other accounts.</strong> Sellers, Logistics Providers and Riders cannot yet delete their accounts themselves. Email us to ask; we will tell you what we can delete and what we must keep.</p>
                </section>

                <section id="rights" aria-labelledby="rights-heading" class="{{ $card }}">
                    <h2 id="rights-heading" class="{{ $h2 }}">7. Your rights</h2>
                    <p>Under the Data Privacy Act you have the right to:</p>
                    <ul class="mt-2 list-disc space-y-2 pl-5">
                        <li>be informed about how your data is processed;</li>
                        <li>access your data and get a copy of it;</li>
                        <li>object to processing, and withdraw consent where processing is based on consent;</li>
                        <li>have wrong or incomplete data corrected;</li>
                        <li>have data blocked, removed or destroyed when it is no longer needed or was processed unlawfully;</li>
                        <li>get your data in a commonly used electronic format;</li>
                        <li>be compensated for damage caused by unlawful processing; and</li>
                        <li>file a complaint with the National Privacy Commission.</li>
                    </ul>
                    <p class="mt-3">You can update most of your details in your account. For anything else, contact us. We may ask you to confirm your identity first. Some requests may be limited where the law or an open dispute requires us to keep the record.</p>
                </section>

                <section id="contact" aria-labelledby="contact-heading" class="{{ $card }}">
                    <h2 id="contact-heading" class="{{ $h2 }}">8. Contact and complaints</h2>
                    <p>For privacy questions and requests, email <a class="{{ $link }}" href="mailto:support@zefanya.shop">support@zefanya.shop</a> with "Privacy" in the subject line.</p>
                    <p class="mt-3">If you are not satisfied with our answer, you may complain to the National Privacy Commission.</p>
                    <p class="mt-3">We may update this policy. The date at the top shows the latest version.</p>
                </section>
            </div>
        </div>
    </div>
</div>
@endsection
