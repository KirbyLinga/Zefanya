# Zefanya Audit 1.7 — Unverified Buyer Refund Destination

Date: 2026-11-12. Scope: `WebZefanya` only, using the current working tree and the existing Audit 1.4–1.6 safeguards. No production database or external payment account was accessed. Existing uncommitted work was preserved.

## 1. Overall result

**No new confirmed software vulnerability was found. No production files were changed by this audit.** Source tracing and new focused regressions show that refund-to-order associations, money-role authorization, stale-request rejection, correction history and transactional financial updates are protected through the supported application routes.

The system cannot establish that an entered destination belongs to the intended Buyer, that a reference is genuine, that an uploaded receipt matches the entered amount, or that an external transfer settled. These are real limitations of the current Admin-attested workflow. Resolving them requires a separately approved verification policy or workflow; this audit did not invent one. Optional proof remains optional and no global reference uniqueness rule was added.

## 2. Existing destination and evidence workflow

### Identity and intake

The intended beneficiary is determined by the persisted chain:

```text
parcel_returns.seller_order_id → seller_orders.order_id → orders.buyer_id
```

`Buyer/OrderController::requestReturn()` authorizes the signed-in Buyer's order and verifies that the shipment belongs to that order (`app/Http/Controllers/Buyer/OrderController.php:197`). The service locks the shipment and independently checks its persisted Buyer (`app/Services/Logistics/ParcelReturnService.php:89`). The existing delivery clock/seven-day eligibility rule remains unchanged.

The Buyer return form supplies reason, description and photos. It does not provide a refund destination. No saved Buyer refund/payout account was found in the Buyer model, account controller, relevant schema or return intake. Ordinary Buyer profile/shipping names are not an authoritative verified bank-account ownership record.

### Destination and transfer evidence

| Concept | Current authoritative source | Meaning / limitation |
|---|---|---|
| Intended Buyer | Persisted order chain above | Identifies who ordered; independent of text recipient entry. |
| Entered recipient name | `parcel_returns.refund_recipient` | Admin text. No separate verified recipient identity. |
| Entered destination account | Same `refund_recipient` text | Name/account can be combined in one string; no structured destination field. |
| Transfer method | No dedicated refund method/provider field | Admin may mention GCash/Maya/bank in text. No provider namespace for validation. |
| External transfer reference | `refund_reference` | Required nonblank text, maximum 255 characters; no provider authenticity check or cross-order uniqueness. |
| Uploaded proof | `refund_proof_path` on private disk | Optional JPG/JPEG/PNG/PDF, up to 5 MB; format validation is not verification of receipt contents. |
| Entered amount/date | `refund_amount_minor`, `refund_paid_on` | Validated amount in centavos and Admin-entered transfer date. |
| Admin attestation | `refunded_at` plus `refund_recorded[_n]` event | Records the submission and acting Admin; does not establish independent settlement. |
| Independently verified settlement | Not implemented | No bank/wallet verification or automatic payment action occurs here. |

`database/migrations/2026_11_03_140100_add_refund_details_to_parcel_returns.php:12` adds the amount, recipient, date and proof fields. `2026_10_24_050133_create_physical_parcel_returns.php:17` establishes one return per shipment; lines 39–48 establish uniquely keyed per-return events. Neither migration defines a verified beneficiary, provider namespace or globally unique refund reference.

### Authorization, recording and financial changes

`routes/admin.php:46` requires Admin authentication. Money decisions require `admin` or `super_admin` in both `ReturnController::authorizeFinancialDecision()` (`app/Http/Controllers/Admin/ReturnController.php:22`) and `ParcelReturnService::moneyAdmin()` (`app/Services/Logistics/ParcelReturnService.php:715`). Existing authorized return-review roles can inspect Returns; this audit did not change read permissions or remove operational evidence from authorized readers.

`ReturnController::recordRefund()` (`:107`) explicitly validates reference, recipient, decimal amount, paid date, optional proof, reason and revision. It sends only these selected values to the service. Body identifiers such as Buyer/order/shipment/return IDs and internal fields such as `refund_recipient`, `refund_reference`, `refund_amount_minor` and `status` cannot replace the route-bound return or its persisted relationships.

`ParcelReturnService::recordRefund()` (`:207`) trims recipient/reference, enforces positive amount, paid collected cash and any required fault decision, and rejects a current active refund. `Payment::refundableCollectedMinor()` (`app/Models/Shared/Payment.php:72`) caps the amount at paid/refundable collected cash, including its intentional legacy paid-record fallback. The service then writes current fields and a numbered event with actor type/ID, reference, recipient, amount, paid date, reason and proof-presence flag.

The Seller Order and return are locked before validation/writes. Refund, event, Seller settlement/debt adjustments and applicable Logistics charges share the transaction and its retry boundary. Destination text itself does not select a different Seller or debt: those derive from the locked order. Prior Audit 1.5 payout recovery rules and Audit 1.6 reporting fixes were preserved.

### Voiding and correcting

`voidRefund()` (`ParcelReturnService.php:251`) requires an explanation and the current refund-history revision. It snapshots the prior recipient/reference/amount/date/proof path into a numbered void event and operations audit, then clears current refund fields and updates financials. The old proof file remains stored. A replacement record is another numbered event on the same return.

`assertRefundRevision()` (`:275`) compares the expected ID with the latest locked refund-history event. History is scoped to this return (`app/Models/Logistics/ParcelReturn.php:159`), so a revision from another case cannot authorize a correction. Stale record and void requests cannot overwrite the current recipient.

A void is an authorized attestation that the transfer failed, not proof that money sent to a wrong person was recovered. No external payment, reversal, collection or second transfer is executed by recording, voiding or correcting. The operator must establish external facts before using the existing failed-transfer correction process.

## 3. Scenarios A–O

| Scenario | Classification | Source-traced result |
|---|---|---|
| A — wrong recipient | OPERATIONAL GAP | A money Admin can enter an unrelated nonblank name/account. No authoritative beneficiary account exists for comparison. New test explicitly demonstrates acceptance as an attestation; no new verification requirement imposed. |
| B — HTTP manipulation | PARTIALLY PROTECTED | Unauthorized roles are rejected; malformed recipient/reference inputs are rejected; unexpected financial/association fields are ignored. Authorized `recipient` text is intentionally editable at initial recording, so semantic destination correctness is still the gap in A. |
| C — another Buyer/order | PARTIALLY PROTECTED | Forged body Buyer/order/shipment/return IDs cannot change the route-bound association or another return. Buyer intake checks ownership. A platform money Admin can intentionally choose another legitimate return URL; the app cannot match an external transfer's real beneficiary to that choice without additional evidence. |
| D — repeated reference | OPERATIONAL GAP | New two-order test confirms `REF-001` can be stored on separate active refunds, each with its own return/history. This does not prove two distinct external payments happened. No safe global/provider uniqueness policy can be derived from the existing unstructured data. Same-reference correction remains possible and tested. |
| E — fabricated reference | OPERATIONAL GAP | Only nonblank string/length checks exist. New test records a synthetic fabricated reference and verifies the submitting Admin is recorded in history. No bank-authenticity or independently verified settlement claim is made. |
| F — missing proof | OPERATIONAL GAP | Optional by controller validation and existing form label. Successful no-proof attestation retains actor/reference/amount/recipient/date history. No requirement was changed. |
| G — stale recipient | ALREADY PROTECTED | Current active-refund check and latest-history revision reject old forms. Correction replay test preserves the newer recipient and all financial records; MySQL cases also use different old/new account strings. |
| H — destination correction | PARTIALLY PROTECTED | Original recipient/reference/amount/date/proof remain in history; replacement stays on the same order and keeps one debt. No automatic second payment or verified recovery of the original transfer occurs. External disposition of a successful wrong-recipient transfer requires reconciliation. |
| I — unauthorized recording | ALREADY PROTECTED | Existing Audit 1.6 direct HTTP matrix covers Buyer, Seller, Rider, Logistics, Support and Moderator; both record and void are rejected. A different guard does not impersonate an authenticated Admin. |
| J — exposure | ALREADY PROTECTED | Destination/proof are omitted on tested Buyer, Seller, Logistics and mobile projections; own Buyer sees refund amount/reference only. Another Buyer's order is hidden with 404. Proof route requires authorized Admin authentication. Authorized Admin Returns and per-order finance keep the details needed for review. See visibility findings below. |
| K — cross-method collision semantics | NOT VERIFIED | Source has no structured method/provider/account identifier or provider reference contract. This audit did not verify live GCash/Maya/bank uniqueness semantics and makes no claim that references are globally unique. No new constraint was imposed. |
| L — evidence amount mismatch | OPERATIONAL GAP | Entered amount is compared with structured collected COD, not receipt-image/PDF contents. No structured external transfer amount, OCR or bank verification exists. File validation alone cannot establish the amount shown in the evidence. |
| M — history editing | ALREADY PROTECTED | Active recipient/reference/amount/date cannot be overwritten through another recording request. Only supported void/replacement preserves the original event and audit. No application financial-history edit/delete endpoint found. Privileged direct database access is outside this authorization claim; return events are not a cryptographically immutable database ledger. |
| N — concurrent confirmations | ALREADY PROTECTED | Extended real MySQL duplicate-confirmation test submits different destinations from two Admins. One succeeds; the other conflicts. Assertions verify the winning recipient, actor and single debt/history row. |
| O — finance consistency | ALREADY PROTECTED | Corrections preserve one order debt and paid settlement/payment history. Existing injected-failure regressions roll back refund, history, Seller finances and Logistics charges. Payout/refund races and prior audit fixes remain covered. No independently verified transfer is inferred from accounting consistency. |

### Visibility findings

- `resources/views/Admin/Returns/index.blade.php:155` displays recipient/current evidence to permitted return reviewers; per-order financial detail also displays recipient (`resources/views/Admin/OrderMonitoring/_financials.blade.php:100`). These are authenticated administrative surfaces, not public pages.
- Buyer order progress (`resources/views/Buyer/Orders/_shipment-next-steps.blade.php:69`) shows the Buyer's own recorded amount/reference, without destination/proof. Seller money breakdown shows liability, not recipient/account. Logistics return history renders event titles/actors without refund metadata (`resources/views/Logistics/Returns/show.blade.php:22`).
- `ShipmentResource` and `RiderParcelResource` explicitly select mobile fields; even with refund/history relations loaded they do not serialize recipient, proof path or raw events. The actual Buyer order API was also tested.
- Refund proof uses `storage/app/private`; ordinary Admin proof routing is authenticated. The framework's private-storage serving route requires a valid relative signature (`vendor/laravel/framework/src/Illuminate/Filesystem/ServeFile.php:30`), not merely possession of a file path.
- Refund void operations audits are not assigned to a Logistics company: `OperationsAuditLog::record()` (`app/Models/Admin/OperationsAuditLog.php:89`) derives the company from the entity's `logistics_provider_id`, which a return does not supply. Company audit lists are filtered by company ID; new privacy checks confirm absence of destination/proof on that surface.
- Blade output uses escaping. Validation errors do not return a different return's stored destination. Authorized Admin form-value preservation is not evidence of exposure to another guard.

## 4. Confirmed vulnerabilities

None newly confirmed in this audit. Therefore there is no new severity, exploit reproduction or production patch to report. Accepted fake/unrelated recipient/reference tests demonstrate the documented trust boundary, not an authorization bypass or independent verification capability.

The financial exposure remains important: inaccurate or dishonest Admin attestations can associate an unverified external payment with a refund obligation. A separately approved beneficiary/evidence/reference policy is needed to reduce that exposure. This audit stops that portion at reporting, as requested, rather than adding forms, OTP, gateways, mandatory proof or provider rules.

## 5. Changes made

Production files modified by Audit 1.7: **none**. Previous Audit 1.4–1.6 production changes remain in the working tree.

| File modified by this audit | Purpose |
|---|---|
| `tests/Feature/Seller/SellerReturnDecisionTest.php` | Eight malformed recipient/reference cases; forged cross-order IDs/internal fields; explicit manual-attestation and reference-reuse limitations; same-reference correction with original evidence; stale replay; recipient/proof privacy on web, Buyer API and mobile projections. |
| `tests/MySql/SellerPayoutConcurrencyTest.php` | Extend existing confirmation and replacement races with conflicting recipient values and original/winning recipient/actor assertions. Existing cases and assertions are preserved. |
| `tests/MySql/worker.php` | Permit recipient text in isolated test tasks; prior default remains unchanged for other cases. |
| This report | Full A–O classification, source trace, residual risks and verification. |

No new product feature, policy, dashboard, notification, payment integration, PDF/CSV functionality, migration or historical financial rewrite. No Flutter/integration/configuration changes. No commit or push.

## 6. Verification

Baseline SQLite: **190 tests / 1,389 assertions passed** (Seller returns, recovery, Admin finance and Seller payout requests). Baseline MySQL payout/refund suite: **15 tests / 223 assertions passed**.

New focused destination tests: **13 cases / 116 assertions passed** before adding the live Buyer API privacy assertion. The final privacy check, including that API request, passed **1 case / 28 assertions**. No production change was needed for these behaviors.

The first new-test run had two assertion failures and one test error: a freshly created model snapshot omitted database-default columns; an inherited ascending event order selected the original event instead of the void event; and an unrelated Buyer correctly received 404 rather than the test's expected 403. The fixtures/assertions were corrected to compare reloaded records, explicitly select the latest event, and assert the existing concealed-order response. These were test-authoring errors, not reproduced application vulnerabilities.

Final combined SQLite command:

```text
php vendor/bin/phpunit tests/Feature/Seller/SellerReturnDecisionTest.php tests/Feature/Seller/SellerPayoutRequestTest.php tests/Feature/Logistics/ReturnRecoveryTest.php tests/Feature/Admin/AdminFinancePageTest.php tests/Feature/Admin/AdminDashboardStatsTest.php tests/Feature/Buyer/BuyerCommerceApiTest.php tests/Feature/Admin/OrderMonitoringTest.php tests/Feature/Logistics/OperationsAuditLogTest.php
```

Final MySQL command:

```text
php vendor/bin/phpunit --configuration phpunit.mysql.xml --filter SellerPayoutConcurrencyTest
```

MySQL uses the existing disposable `zefanya_concurrency_test` schema/account. The preflight verifies server identity, exact database/account, restrictive grants, denied application-schema access and InnoDB. Race evidence requires independent connections and observed overlapping locking statements; it is retained in `storage/logs/payout-races/*/evidence.json`. SQLite success alone is not claimed as concurrency proof.

Final combined SQLite run: **273 tests / 1,938 assertions passed**. Final isolated MySQL run: **15 tests / 232 assertions passed**. The conflicting-destination confirmation retained only the winning recipient/actor and one debt/history entry. Both stale replacement races retained the corrected recipient and the original record/void recipient evidence. Existing payout/refund, duplicate recovery, transaction rollback, authorization, finance and privacy regressions passed.

`php vendor/bin/pint --dirty --format agent` completed; final targeted Pint `--test` and `git diff --check` passed. Final failing tests: **none**. The initial new-test assertion/fixture errors are disclosed above.

Not executed: full unrelated repository suite, production-data reconciliation, live bank/wallet operations or verification of provider reference semantics. No frontend build was required because no frontend files changed.

## 7. Remaining limitations

- No verified Buyer-owned account or approved structured destination exists. Operator intent cannot be inferred safely from names or shipping contact details.
- Reference authenticity and cross-provider/account uniqueness are unverified. One reference string does not prove a distinct payment, correct recipient or successful settlement.
- Optional uploaded proof may be absent, misleading or inconsistent with the entered amount. File-format checks do not verify financial content.
- A void/correction preserves app history but does not prove recovery of money from an incorrect recipient, reverse a bank transfer or initiate another payment.
- Seller debt, Logistics charges and reports follow the current authorized refund attestation. Correct internal accounting is distinct from external settlement truth. Already settled charges and incomplete legacy records can require manual reconciliation.
- Existing role-based inspection is preserved. No narrower administrative evidence-access policy was inferred or implemented.

## 8. Final status

**PARTIALLY PROTECTED — REMAINING RISKS.** Tested application mutation, association, history and concurrency boundaries are protected; no new confirmed code defect required a production patch. Beneficiary ownership, reference authenticity, evidence correctness and actual external settlement remain unverified operational facts. Reducing those risks requires separately approved policy/workflow decisions; none were implemented in this audit.
