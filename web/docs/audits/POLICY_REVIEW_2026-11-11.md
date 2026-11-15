# Policy review — 2026-11-11

Scope: Privacy Policy, Terms of Service (with Buyer, Seller, Logistics and Rider sections and a Disputes & Enforcement section), Shipping & Returns, and the registration links to them. **Not legal advice.** Every item under "Legal review" needs a qualified Philippine lawyer or privacy professional before the pages are treated as final.

## 1. Audit of the previous documents

| Page | Problem found | Evidence in code |
|---|---|---|
| Privacy Policy | Generic template. Did not list identity documents, TIN, SEC data, delivery photos, delivery codes, rider data, or who sees what. No retention, rights or NPC complaint information (DPA §16). | `Store*RegistrationRequest`, `RiderOperationsService::completeDelivery()`, `BuyerAccountDeletionService` |
| Privacy Policy | "Payment information (processed securely by third parties)" — there is no online payment processor. | `App\Enums\PaymentMethod` has only `cod` |
| Terms of Service | Generic web template ("Use License", "download one copy of the materials"). No marketplace roles, returns, COD, custody, disputes or enforcement. | — |
| Terms of Service | "We do not guarantee that ... inventory information is accurate" and a blanket liability exclusion conflict with ITA §23 product accuracy duties and RA 7394. | — |
| Shipping & Returns | "14-Day Returns" — the code enforces **7 days** (168 h) and auto-completes the shipment. | `AutomaticBuyerReceiptService::WAIT_HOURS = 168`, `ParcelReturnService::canBuyerRequest()` |
| Shipping & Returns | "Items must be unused and in original packaging", "Contact our support team to initiate a return", "Refunds within 7-10 business days" — none implemented; returns are requested in-app and refunds are recorded manually with no deadline. | `ParcelReturnService::requestByBuyer()`, `recordRefund()` |
| Shipping & Returns | "3-5 business days ... 5-7 days" and "tracking number via email" — no such delivery promise or email exists. | — |
| All four registration forms | Every role linked to the same generic Terms; no role-specific obligations. | `Components/*-register-modal.blade.php` |

## 2. What changed (user-facing pages)

- **Terms of Service** rewritten: §1 General, §2 Buyer terms and protection, §3 Seller terms and protection, §4 Logistics Provider agreement, §5 Rider code of conduct, §6 Disputes and account enforcement, §7 Contact. Anchors: `#general`, `#buyers`, `#sellers`, `#logistics`, `#riders`, `#disputes`.
- **Privacy Policy** rewritten per DPA §16(b): data per role, purposes and lawful bases (§12), sharing between participants, security, retention and deletion, rights, NPC complaint.
- **Shipping & Returns** corrected to the implemented rules (7 days, 3-day Seller decision, one Admin review, two delivery attempts, COD, manual refunds).
- **Registration forms** keep the same single required checkbox and server-side `terms` validation; the Terms link now opens the role's own section (`#buyers`, `#sellers`, `#logistics`, `#riders`) and names it.
- **Help & Support** FAQs rewritten (tracking in My Orders, COD and delivery code, shipping and vouchers, 7-day return request, Seller decision and one Admin review, manual refunds, failed deliveries, contacting Sellers and support). Removed the email tracking number, 14-day returns, support chat, 24-hour response and office-hours claims.
- **Contact** and **About** pages: removed office hours, the 24-hour response time, the non-existent support chat and "returns within 14 days".
- Covered by `tests/Feature/Buyer/PolicyPagesTest.php`.
- Every statement describes behaviour that exists in code today. Nothing new is enforced. No consent checkbox, acceptance record, route, controller, service or schema was changed.

## 3. Rules documented (all already implemented)

- No automatic fraud scoring, thresholds or suspension. Return frequency is not evidence of bad faith (the Admin Buyer Return History panel shows stored outcomes only).
- Decisions use case records: status history, custody scans, delivery code + photo, return photos/notes, return events, audit logs.
- Admin decisions need a written note; refunds, fault and charge settlement are restricted to Admin/Super Admin (`ReturnController::authorizeFinancialDecision()`).
- Account suspension/reactivation by Admin with a reason, logged in `account_action_histories`; Logistics wind-down; Logistics controls its Riders.
- Return fault defaults to Seller; Admin may assign transit damage to Logistics (Seller then not charged). Seller debts are deducted only through payout requests and shown on the record.
- Lost parcels: Admin-only confirmation; custody-based liability (sender before receiver's scan, receiver after); compensation = merchandise subtotal; overdue is never lost.
- COD shortages are recorded with Rider and reason, never marked received, never auto-deducted from Rider earnings; Rider payouts are reversed by record, not deleted.
- Pickup assignment is manual only (Logistics assigns and confirms; confirmation notifies the Rider).

## 4. Legal review items (for qualified Philippine counsel)

1. **Return window vs. RA 7394.** Art. 68(e) sets implied warranties at not less than 60 days and Art. 100 gives remedies for imperfections not corrected within 30 days. The platform's 7-day in-app window and auto-completion may be narrower than statutory rights. The Terms state that statutory rights are not limited, but the operational window itself needs review.
2. **Personal information controller identity.** DPA §16(b)(6) requires the controller's identity and contact details. The pages name "Zefanya" and support@zefanya.shop only; the registered legal entity, address and any Data Protection Officer (DPA §21, IRR) must be confirmed and added.
3. **Retention periods.** DPA §11(e) requires retention only as long as necessary. No retention schedule exists; tax/commercial record periods must be set by counsel before any period is published.
4. **Lawful basis for Buyer ID uploads.** Government IDs are sensitive personal information (§3(l), §13). Confirm the basis and proportionality of requiring an ID from every Buyer.
5. **ITA (RA 11967) obligations.** §21(b)-(c) merchant vetting and verified merchant list; §21(f) and §23(i)/§24 an "effective and responsive" redress mechanism with the 7-calendar-day exhaustion rule; §23(h) invoices/receipts; §23(f)(1) e-retailer disclosures; §26-27 platform subsidiary/solidary liability and expeditious takedown. Confirm which apply to Zefanya and its Sellers, and whether the current email-based review satisfies §21(f).
6. **Refund timing.** ITA §20 and RA 7394 remedies; no refund deadline exists in the platform. Decide a committed timeframe before publishing one.
7. **Processors and cross-border transfer.** Name hosting, email and Google Sign-In processors and confirm data-sharing/outsourcing agreements (DPA §20-21) and any cross-border transfer.
8. **Breach procedure.** The policy states NPC and data-subject notification "as the law requires" (DPA §20(f), NPC Circular 16-03). An internal breach-management procedure must exist to back this.
9. **Liability limits.** The old blanket exclusion was removed. Any replacement limitation clause must be drafted by counsel to be enforceable and consistent with RA 7394 and ITA §25-27.
10. **Rider relationship.** Riders are described as working for their Logistics Provider, which pays them. Confirm this characterisation and the code-of-conduct wording with counsel.
11. **Eligibility.** The Terms require capacity to contract under Philippine law; the platform has no age or capacity check. Confirm the wording and whether minors may hold Buyer accounts.
12. **Contract acceptance evidence.** Acceptance is validated but not stored (see gaps). Counsel should advise what proof of acceptance and version is required.

## 5. Functionality gaps (not implemented; not promised on the pages)

| Gap | Current state | Where |
|---|---|---|
| Terms/Privacy acceptance record | `terms` is validated `accepted` but no timestamp or policy version is stored. Re-acceptance on change is impossible. | `Store*RegistrationRequest` |
| Policy versioning | Pages show a static "Last updated" date only. | policy views |
| Appeals | No appeal form or workflow for suspensions, listing rejections or financial decisions; the pages direct users to email. | — |
| Warnings | No warning or strike system; only suspend/reactivate. | `AccountStatusService` |
| Suspension notice reason | The suspension email does not include the Admin's reason. | `Notifications/Admin/AccountStatusChanged` |
| Complaints & disputes module | Admin sidebar placeholder ("Soon"). | `docs/4-Flow.md` §9.2 |
| Account deletion for Sellers, Logistics, Riders | Only Buyers can self-delete (anonymization). | `BuyerAccountDeletionService` |
| Data export / access request | No self-service export (DPA §18 portability). | — |
| Retention / purge jobs | None. | — |
| Prohibited-items list | Rule stated in Terms; enforcement is Admin listing review only, no category blocklist. | `ProductApprovalService` (uncommitted) |
| Refund deadline | Refunds are recorded manually with no timeframe. | `ParcelReturnService::recordRefund()` |
| Rider earning rates in docs | `RiderEarningService` docblock says ₱10/₱10/₱30; tests assert defaults ₱30/₱5/₱50. Pages do not state amounts. | `RiderEarningService` |

## 6. Sources

- RA 10173 Data Privacy Act — https://lawphil.net/statutes/repacts/ra2012/ra_10173_2012.html
- RA 11967 Internet Transactions Act — https://lawphil.net/statutes/repacts/ra2023/ra_11967_2023.html
- ITA IRR (JAO 24-03), DTI — https://dti.gov.ph/sdm_downloads/implementing-rules-and-regulations-of-the-internet-transactions-act-of-2023
- RA 7394 Consumer Act — https://lawphil.net/statutes/repacts/ra1992/ra_7394_1992.html
- DPA IRR, as amended (NPC) — https://privacy.gov.ph/wp-content/uploads/2023/06/IRR_RA-10173-as-amended.pdf

## 7. Post-review implementation update (2026-11-15)

- Buyer delivery OTP was removed from Rider web and mobile completion, buyer order pages, shipment API, notifications, service layer and storage. Delivery completion still uses the rider handoff scan, required photo proof and exact COD collection when applicable. Registration and account-security OTP remain in place.
- Database cleanup is provided by `2026_11_15_000000_drop_delivery_otps_table`; it has not been applied to a database in this work session. Findings above describe the 2026-11-11 audit snapshot.
