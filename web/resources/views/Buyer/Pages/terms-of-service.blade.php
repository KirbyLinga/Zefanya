@extends('Buyer.Layouts.app')
@section('title', 'Terms of Service')
@section('content')
@php
    $sections = [
        'general' => 'General terms',
        'buyers' => 'Buyer terms and protection',
        'sellers' => 'Seller terms and protection',
        'logistics' => 'Logistics Provider agreement',
        'riders' => 'Rider code of conduct',
        'disputes' => 'Disputes and account enforcement',
        'contact' => 'Contact and changes',
    ];
@endphp
<div class="bg-cream">
    <div class="mx-auto max-w-[1440px] px-4 py-8 sm:px-8 lg:px-16">
        <div class="max-w-[990px]">
            <h1 class="font-serif text-[32px] sm:text-[40px] font-semibold text-buyer-ink mb-2">Terms of Service</h1>
            <p class="mb-6 text-[13px] text-buyer-ink-soft">Last updated: 11 November 2026. These terms apply to every Zefanya account: Buyers, Sellers, Logistics Providers and Riders. Read the general terms and the section for your role.</p>

            <nav aria-label="On this page" class="mb-6 rounded-2xl border border-buyer-line bg-white p-5 text-[14px]">
                <h2 class="mb-2 font-serif text-[18px] text-buyer-ink">On this page</h2>
                <ol class="list-decimal space-y-1 pl-5 text-buyer-ink-soft">
                    @foreach ($sections as $id => $label)
                        <li><a class="underline underline-offset-2 hover:text-buyer-ink" href="#{{ $id }}">{{ $label }}</a></li>
                    @endforeach
                </ol>
                <p class="mt-3 text-buyer-ink-soft">How we handle personal data is explained in the <a class="underline underline-offset-2 hover:text-buyer-ink" href="{{ route('buyer.pages.privacy-policy') }}">Privacy Policy</a>.</p>
            </nav>

            <div class="space-y-6 text-[14px] leading-relaxed text-buyer-ink-soft">
                {{-- 1. General --}}
                <section id="general" aria-labelledby="general-heading" class="scroll-mt-24 rounded-2xl border border-buyer-line bg-white p-6">
                    <h2 id="general-heading" class="mb-3 font-serif text-[22px] text-buyer-ink">1. General terms</h2>
                    <div class="space-y-3">
                        <p><strong class="text-buyer-ink">What Zefanya is.</strong> Zefanya is an online marketplace. Sellers list and sell their own goods to Buyers. Approved Logistics Providers collect and deliver parcels, using Riders who work for them. Zefanya runs the platform, reviews accounts and listings, and records orders, custody scans, returns and payments.</p>
                        <p><strong class="text-buyer-ink">Accepting these terms.</strong> You accept these terms and the Privacy Policy when you register. If you do not agree, do not create an account or use the platform.</p>
                        <p><strong class="text-buyer-ink">Eligibility.</strong> You must be able to enter into a binding contract under Philippine law. Each person or business may use only an account registered in its own name, and one email address belongs to one account role.</p>
                        <p><strong class="text-buyer-ink">Accounts.</strong> Registrations are reviewed before an account can be used. Give true, current information and keep it up to date. Keep your password and verification codes private; you are responsible for activity on your account. Tell us at once if you think someone else has used it.</p>
                        <p><strong class="text-buyer-ink">Payment.</strong> Orders are paid by cash on delivery (COD). Zefanya does not hold a wallet or stored balance for any user.</p>
                        <p><strong class="text-buyer-ink">Records.</strong> Status changes, custody scans, delivery proofs, return decisions and payment records are stored by the platform. They are the main evidence we use to resolve questions and disputes.</p>
                        <p><strong class="text-buyer-ink">Conduct everyone must follow.</strong> Do not give false information, impersonate anyone, use another person's account, submit altered or misleading photos or documents, interfere with scans or verification codes, harass other users, or use the platform for anything unlawful.</p>
                        <p><strong class="text-buyer-ink">Your rights under the law.</strong> Nothing in these terms removes rights you have under Philippine law, including consumer protection and data privacy law.</p>
                    </div>
                </section>

                {{-- 2. Buyers --}}
                <section id="buyers" aria-labelledby="buyers-heading" class="scroll-mt-24 rounded-2xl border border-buyer-line bg-white p-6">
                    <h2 id="buyers-heading" class="mb-3 font-serif text-[22px] text-buyer-ink">2. Buyer terms and protection</h2>
                    <div class="space-y-3">
                        <p><strong class="text-buyer-ink">Ordering and paying.</strong> Each Seller in your checkout ships a separate parcel. Pay the Rider in cash when your parcel arrives. Give the Rider the delivery code sent to you; the Rider also takes a proof-of-delivery photo. Share the code only with the Rider at your door.</p>
                        <p><strong class="text-buyer-ink">Failed deliveries.</strong> A parcel gets at most two delivery attempts. If both fail, for example because no one could receive it or the parcel was refused, it goes back to the Seller. Please be reachable at the address and number you gave.</p>
                        <p><strong class="text-buyer-ink">Confirming receipt.</strong> Check your parcel when it arrives, then confirm receipt. If you do nothing, the shipment is completed automatically seven days after delivery.</p>
                        <p><strong class="text-buyer-ink">Requesting a return.</strong> If an item is defective, damaged, wrong or not as described, request a return from your order page within seven days of delivery and before the shipment is completed. Give the reason, a description and photos. The Seller has three days to approve or reject it, with a reason. If the Seller does not answer in time, the return is approved automatically.</p>
                        <p><strong class="text-buyer-ink">If the Seller rejects your return.</strong> You can ask a Zefanya Admin to review the rejection once. The Admin either keeps the rejection or approves the return, writes a note explaining the decision, and both you and the Seller are notified.</p>
                        <p><strong class="text-buyer-ink">Collection and refunds.</strong> After approval, a Rider collects the item from you with a verified scan. You may cancel the return before it is collected; you then keep the item and no refund is due. Refunds are paid outside the platform and recorded by an Admin with a reference. If less than you paid is refunded, the reason is shown to you.</p>
                        <p><strong class="text-buyer-ink">Not allowed.</strong> False missing-item or damage claims, returning a different or used item, edited or borrowed photos, and refusing to hand over an item you asked to return. These may lead to a rejected return and action on your account under section 6.</p>
                        <p><strong class="text-buyer-ink">Fair treatment.</strong> Asking for returns is your right. We do not decide that a Buyer is acting in bad faith because of how often they return items or because of a percentage. Each case is decided on its own records and evidence.</p>
                    </div>
                </section>

                {{-- 3. Sellers --}}
                <section id="sellers" aria-labelledby="sellers-heading" class="scroll-mt-24 rounded-2xl border border-buyer-line bg-white p-6">
                    <h2 id="sellers-heading" class="mb-3 font-serif text-[22px] text-buyer-ink">3. Seller terms and protection</h2>
                    <div class="space-y-3">
                        <p><strong class="text-buyer-ink">Registration.</strong> Sellers submit business details, tax and registration documents. An Admin reviews them before the shop can operate.</p>
                        <p><strong class="text-buyer-ink">Listings.</strong> Every listing must show the correct name, brand, price, description and condition, with photos of the actual item. Each variation (such as colour or size) must have its own correct price, stock and photos. Listings are reviewed by an Admin before they appear to Buyers, and significant edits are reviewed again. Admins may reject or restrict a listing.</p>
                        <p><strong class="text-buyer-ink">Prohibited items.</strong> Do not list counterfeit goods, items that infringe someone else's rights, goods whose sale is illegal, or regulated goods without the permits or licences the law requires.</p>
                        <p><strong class="text-buyer-ink">Stock and fulfilment.</strong> Keep stock accurate. Accept or decline orders promptly, pack exactly what was ordered, and request pickup only when the parcel is ready. You can decline an order only before it is handed to Logistics.</p>
                        <p><strong class="text-buyer-ink">Return requests.</strong> Answer each return request within three days, or it is approved automatically. If you reject it, give a clear reason. The Buyer may ask an Admin to review your rejection once.</p>
                        <p><strong class="text-buyer-ink">Checking a returned parcel.</strong> When a returned parcel is handed to you, you can report a wrong item, damage or refuse the parcel, with a note and photos, instead of confirming receipt. The parcel then stays with Logistics until an Admin decides what happens next.</p>
                        <p><strong class="text-buyer-ink">Who pays for a return.</strong> An approved return is charged to the Seller by default, because it usually means the item was wrong, defective or not as described. If the item was damaged in transit, an Admin can assign the cost to the Logistics Provider instead; you are then not charged. The decision and its reason are recorded.</p>
                        <p><strong class="text-buyer-ink">Earnings, commission and debts.</strong> Your earnings and the commission on each order are shown in Seller Earnings. If a refund and return charges are more than what you are owed for that order, the difference is recorded as a debt. Debts are deducted only when you request a payout, and the amount deducted is shown on the payout record.</p>
                        <p><strong class="text-buyer-ink">Lost parcels.</strong> If an Admin confirms a parcel lost while a Logistics Provider held it, the compensation is the order's merchandise value and is owed by the company responsible for custody at the time. It is not charged to you.</p>
                    </div>
                </section>

                {{-- 4. Logistics --}}
                <section id="logistics" aria-labelledby="logistics-heading" class="scroll-mt-24 rounded-2xl border border-buyer-line bg-white p-6">
                    <h2 id="logistics-heading" class="mb-3 font-serif text-[22px] text-buyer-ink">4. Logistics Provider agreement</h2>
                    <div class="space-y-3">
                        <p><strong class="text-buyer-ink">Approval and hubs.</strong> Logistics Providers submit business, registration and tax details for Admin review. Hubs and service areas must be approved before they are used. Keep your station hubs, Rider areas and barangay coverage accurate: Buyers can only choose you for addresses you actually cover.</p>
                        <p><strong class="text-buyer-ink">Pickup assignment.</strong> When a Seller requests pickup, the parcel waits in your Incoming Parcels queue. You choose an eligible Rider and confirm the dispatch; that confirmation notifies the Rider. Riders are assigned only manually; nothing is assigned automatically. A Rider who is not approved, does not cover the area, is offline, or is at their job or weight limit cannot be assigned.</p>
                        <p><strong class="text-buyer-ink">Custody.</strong> Custody moves only with verified scans: Rider collection, hub receipt, manifests and handoffs between providers. A handoff must be accepted by the receiving provider and scanned on both sides. Before the receiver's scan, the sender is responsible for the parcel; after it, the receiver is. Cross-provider transfers follow the parcel's saved route and need an active partnership; a receiving provider should accept only parcels it can carry onward.</p>
                        <p><strong class="text-buyer-ink">Incidents and lost parcels.</strong> Report missing, damaged or delayed parcels as incidents with evidence. An Admin reviews them. Only an Admin can confirm a parcel lost. If custody at the time is unclear, the Admin names the responsible company before any payment is recorded. A late transfer is flagged as overdue, never as lost.</p>
                        <p><strong class="text-buyer-ink">Cash on delivery.</strong> You record the cash your Riders actually hand over. A shortage is recorded with the responsible Rider and the reason; it is never marked as received. You keep each parcel's shipping fee and send Zefanya the rest, which Zefanya confirms on receipt.</p>
                        <p><strong class="text-buyer-ink">Return charges.</strong> If an Admin finds that you damaged a parcel in transit, you repay that refund to Zefanya. Zefanya pays you for collecting Seller-fault returns and for qualifying failed COD deliveries. Each charge is shown on Logistics Earnings and settled by an Admin with a payment reference. Charges are not deducted from your COD remittances automatically.</p>
                        <p><strong class="text-buyer-ink">Your Riders.</strong> You pay your own Riders and record each payment. A mistaken payment record is reversed with a reason, not deleted.</p>
                        <p><strong class="text-buyer-ink">Outside your custody.</strong> You are not responsible for a parcel before your verified collection or receipt scan, or after a verified handover to the next party.</p>
                    </div>
                </section>

                {{-- 5. Riders --}}
                <section id="riders" aria-labelledby="riders-heading" class="scroll-mt-24 rounded-2xl border border-buyer-line bg-white p-6">
                    <h2 id="riders-heading" class="mb-3 font-serif text-[22px] text-buyer-ink">5. Rider code of conduct</h2>
                    <div class="space-y-3">
                        <p><strong class="text-buyer-ink">Your account.</strong> Riders register with a Logistics Provider, verify their email and are approved by that company. Use only your own account and keep your sign-in private.</p>
                        <p><strong class="text-buyer-ink">Assigned jobs only.</strong> Handle only parcels assigned to you. Scan each parcel when you collect it and when you start a delivery; the system refuses parcels that are not yours.</p>
                        <p><strong class="text-buyer-ink">Online and offline.</strong> Going offline stops new jobs. Jobs you have not started are flagged for your company to reassign; parcels already in your hands stay with you to finish.</p>
                        <p><strong class="text-buyer-ink">Delivery proof.</strong> Complete each delivery with the Buyer's delivery code and a proof-of-delivery photo. Record the cash collected accurately.</p>
                        <p><strong class="text-buyer-ink">Failed deliveries and incidents.</strong> If a delivery fails, record the true reason and notes. Report damage, loss or safety problems as incidents.</p>
                        <p><strong class="text-buyer-ink">Cash.</strong> COD cash you collect belongs to your company until it confirms receiving it from you. You cannot confirm your own hand-over. A shortage is recorded with a reason; it is not taken from your earnings automatically by the platform.</p>
                        <p><strong class="text-buyer-ink">Earnings.</strong> Pickup and delivery pay follow the rates in effect when the job was done, and each earning keeps its rate. Failed attempts are not paid. Your company pays you and records each payment; corrections are recorded as reversals with a reason. If a payment record looks wrong, raise it with your company first; if it is not resolved, email Zefanya support (section 6).</p>
                        <p><strong class="text-buyer-ink">Not allowed.</strong> Faking scans, codes or photos, keeping or delaying COD cash, opening parcels, and sharing Buyer details outside the job.</p>
                        <p><strong class="text-buyer-ink">Fair treatment.</strong> An accusation of a shortage, loss or misconduct must be supported by the records: scans, delivery codes and photos, cash records and incident reports. You can give your account of what happened, for example in an incident report or by email to Zefanya support.</p>
                    </div>
                </section>

                {{-- 6. Disputes and enforcement --}}
                <section id="disputes" aria-labelledby="disputes-heading" class="scroll-mt-24 rounded-2xl border border-buyer-line bg-white p-6">
                    <h2 id="disputes-heading" class="mb-3 font-serif text-[22px] text-buyer-ink">6. Disputes and account enforcement</h2>
                    <div class="space-y-3">
                        <p><strong class="text-buyer-ink">Evidence first.</strong> Before deciding that anyone acted fraudulently or carelessly, we look at the records for that case: status history, custody scans, delivery codes and photos, return photos and notes, payment records and the parties' explanations. We do not reach that conclusion from statistics, return frequency or a percentage alone, and there is no automatic fraud score or automatic suspension.</p>
                        <p><strong class="text-buyer-ink">How disputes are handled.</strong> Return disputes, receipt disputes, refunds, who pays, lost parcels and charge settlements are decided by a Zefanya Admin. Each decision requires a written note and is recorded. Only Admins with financial authority can record refunds, decide who pays or settle charges.</p>
                        <p><strong class="text-buyer-ink">Account actions.</strong> An Admin can suspend a Buyer, Seller or Logistics Provider account with a recorded reason, and reactivate it. A Logistics Provider controls its Riders' accounts. A suspension takes effect immediately. A suspended Logistics Provider may be given a short period to finish parcels already out for delivery.</p>
                        <p><strong class="text-buyer-ink">When we act.</strong> We act on serious or repeated breaches of these terms that the records support, or immediately where there is a risk of harm, loss or fraud. Where it is safe to do so, we will contact you about a problem before suspending your account.</p>
                        <p><strong class="text-buyer-ink">Asking for a review.</strong> If you disagree with an account or financial decision, email support@zefanya.shop with your account email, the order or return involved, and your explanation and evidence. An Admin will review it. There is no in-app appeal form yet. A Buyer's review of a Seller's return rejection is done in the app, once per return.</p>
                        <p><strong class="text-buyer-ink">Outside Zefanya.</strong> You may also take a complaint to the Department of Trade and Industry or another competent government agency.</p>
                    </div>
                </section>

                <section id="contact" aria-labelledby="contact-heading" class="scroll-mt-24 rounded-2xl border border-buyer-line bg-white p-6">
                    <h2 id="contact-heading" class="mb-3 font-serif text-[22px] text-buyer-ink">7. Contact and changes</h2>
                    <div class="space-y-3">
                        <p>Questions about these terms: <a class="underline underline-offset-2 hover:text-buyer-ink" href="mailto:support@zefanya.shop">support@zefanya.shop</a>.</p>
                        <p>We may update these terms. The date at the top shows the latest version.</p>
                    </div>
                </section>
            </div>
        </div>
    </div>
</div>
@endsection
