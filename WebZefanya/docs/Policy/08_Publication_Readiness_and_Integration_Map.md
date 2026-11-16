> **Publication fields:** Replace every `[INSERT ...]` item with verified organizational information before publishing. This document is intended to operate as a real commercial policy once approved; no unverified feature or commercial arrangement may be promised.

# Zefanya Policy Publication & Integration Matrix — Internal Release Control

**Not public-facing.** Public policies should describe approved, supported functionality without advertising prototype status or unimplemented features. This document tracks confirmation obligations and release blockers.

## Publication gates
1. **Legal identity/contact:** registered Operator, physical address, real support/privacy contact, effective date, jurisdiction and legal review. Never invent a company registration, DPO name, or support email.
2. **Data inventory:** validate what each Laravel guard, API resource and Flutter widget actually collects; enumerate hosting/email/push/analytics SDKs; document storage locations and international transfers if any.
3. **Retention:** approve purpose-specific retention durations for government IDs, delivery photos, private chat, access logs, cash and payouts; build a secure deletion/hold procedure. Do not silently invent fixed durations.
4. **Commercial terms:** approve Seller rate schedule and payout terms, Logistics revenue and fee arrangements, actual worker engagement terms, age/eligibility, refunds channels and customer service capacity.
5. **Consumer rights:** support legally required complaints beyond a seven-day online window; avoid blocking finalized-orders' defect claims; honor DTI no-return-no-exchange rules; confirm invoice/receipt and merchant identity disclosures.
6. **Dispute operations:** ensure reports are tracked from filing; seven calendar days unresolved internal complaint mechanism is deemed exhausted for RA11967 purposes, independent of user initial report deadline; make support channel available even without in-app action.
7. **Security:** private authenticated evidence URLs, role-level access, audited Admin decisions, separate optional marketing consent, review account deletion and incident reporting.
8. **Deployment:** friend verifies shared API/schema; apply migrations after backups; verify Hostinger schedule/cron, HTTPS and real Android interactions; no final APK until integrated verification.
9. **Policy acceptance:** record version, role, actor, timestamp and legally appropriate acceptance mechanism; avoid forced privacy consent as a prerequisite for processing based on other lawful grounds.
10. **Version parity:** publish identical active policy text and version on Web and Mobile; offline fallback must be controlled and consistent; old versions remain accessible for audit when needed.

## Display locations
| Surface | Terms | Privacy | Buyer Protection | Role-specific |
|---|---|---|---|---|
| Public website footer and informational pages | Yes | Yes | Yes | Seller/Logistics as public preview if useful |
| Buyer Web/Mobile registration | Link + acceptance | Link and notice | Link | No |
| Buyer product/cart/checkout | Link | Link | Visible refund/return summary and full policy | No |
| Buyer orders/returns/dispute UI | Link | Link | Contextual help and support channel | No |
| Seller registration/approval/settings | Platform | Notice | Applicable | Seller Agreement + accepted version |
| Logistics application/approval/settings | Platform | Notice | Relevant | Logistics Agreement + accepted version |
| Rider mobile onboarding/profile | Platform | Notice | Relevant | Rider Rules / privacy acknowledgement |
| Transport Courier mobile onboarding/profile | Platform | Notice | Relevant | Courier Rules / privacy acknowledgement |
| Admin internal routes | Internal access | Internal privacy rules | Complaint handling | Admin SOP restricted |

## Acceptance and migration safety
- Before changing acceptance gates, audit current registration/login flows and existing accounts. Do not lock out approved existing accounts due to missing historical acceptance records.
- Allow explicit read/view without login for public pages.
- Do not make legal policy views dependent on live checkout or a seeded test account.
- If persistent consent/acceptance needs schema changes, first plan migration and backfill semantics; never fake old consent.
- Do not infer acceptance merely from page view; do not bundle optional marketing consent with necessary ToS acceptance.
- Preserve historical accepted versions and the published text snapshot/hash. Audit version changes without retroactive rewriting.

## Key public legal sources (review versions at approval time)
- RA 11967 (Internet Transactions Act): https://lawphil.net/statutes/repacts/ra2023/ra_11967_2023.html
- RA 10173 (Data Privacy Act): https://privacy.gov.ph/data-privacy-act/
- DPA IRR: https://privacy.gov.ph/implementing-rules-regulations-data-privacy-act-2012/
- DTI No Return No Exchange guidance: https://fairtrade.dti.gov.ph/faq/is-no-return-no-exchange-policy-allowed/

**Status before signoff:** operator identity/contact, legal review and integration verification pending. These status notes are internal only.
