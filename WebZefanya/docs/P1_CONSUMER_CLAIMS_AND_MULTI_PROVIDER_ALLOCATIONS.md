# P1: Consumer claims and multi-provider shipping allocation

Implemented in `C:\ProjectEcommerce\WebZefanya` on 2026-11-16. The two changes are independent and have targeted pre-edit recovery copies under `C:\ProjectEcommerce\.recovery\p1-consumer-shipping-20261116`.

## Release status

- Shipping safeguards are active in the live source and need no new migration. Existing handoff/allocation tables were confirmed present using read-only schema checks.
- Consumer-claim implementation is prepared and tested, but **disabled** by `config/consumer-claims.php`. The live database has no consumer-claim tables. No production migration, claim, order, refund, settlement, stock adjustment or payment record was created or changed for verification.
- The pending migration was executed only against isolated in-memory SQLite fixtures. Do not activate claims until migration execution and feature activation receive separate approval.
- No commits, pushes, resets, stashes, production service restarts or environment/credential changes were performed. Original asset hashes were retained and the exact pre-build Lucide bytes restored after builds.

## Actual defect and reused infrastructure

The existing `ProviderAllocationService` already made amountless `pending_policy` records for three-provider chains. That ledger did not guard all COD split calculations or financial finalizers. The defect was in applying the two-provider snapshot/default formula without checking distinct financial participants, rather than a money transfer performed by every hub handoff.

The fixes reuse SellerOrder and OrderItem snapshots, delivered StatusHistory records, existing Buyer/Seller/Admin guards and role checks, `DocumentUpload`, `ImageSanitizer`, private storage, database notifications and notification presenters, append-only `OperationsAuditLog`, the provider-allocation ledger, `Money::percentage`, existing remittance/payout transactions and the financial reference registry. Existing ordinary return, refund, COD collection, commission, custody, Rider assignment, address and conduct-review services were not changed.

Dependency tracing used `graphify query "ProviderAllocationService CodRemittance LogisticsRemittanceService" --graph graphify-out/graph.json --budget 350 --depth 1`, followed by current source/caller tracing. Graphify is installed; its graph is older than this change. `../CLAUDE_GRAPH_WORKFLOW.md` is absent and Laravel Boost tools were unavailable. Several coding-agent processes were active; shared route/navigation files were checked against saved hashes before editing and only task-related changes were made. Unrelated dirty work was preserved.

## Consumer claim eligibility and review

A Buyer who owns the shipment may submit a case when its history contains a recorded delivery at or before now. There is no new maximum claim age and no requirement to use or bypass the ordinary Order Received/seven-day return gate. The ordinary return rules remain unchanged. A thirty-day-old delivery is covered by the isolated tests.

The form collects the affected OrderItem, category, description, optional warranty/service history and evidence. The Buyer explicitly selects manufacturer warranty, Seller warranty, or consumer remedy; software does not declare those rights valid or expired. Historical product-name/order-item snapshots survive catalog changes. Admin can inspect the existing order/product/shipment monitoring page, recorded delivery, supplied terms, case history and evidence. The project currently has no separate authoritative manufacturer/Seller warranty catalog fields to infer a warranty from.

| Actor / action | Result |
| --- | --- |
| Buyer submits | `submitted`; authorized Admins notified |
| Admin requests Buyer information | `awaiting_buyer`; Buyer notified |
| Admin requests Seller response | `awaiting_seller`; Buyer and relevant Seller notified |
| Buyer supplies information | `under_review` when answering a Buyer request; an outstanding Seller request stays outstanding |
| Requested Seller responds | `under_review`; Buyer and authorized Admins notified |
| Admin approves or rejects | `approved` / `rejected`; Buyer and relevant Seller notified |
| Admin closes | `closed`; decision/history retained; Buyer and relevant Seller notified |

Every Admin decision requires 10-5000 characters of justification, including rejection. All updates append an immutable case-history row; Admin decisions also append the existing operations audit log in the same transaction. Closing a case is a manual workflow action, not a declaration that statutory rights have expired. No universal automatic expiry or seven-day-based approval/rejection was added.

Buyer and Seller access is scoped to the case's immutable participant IDs. Only existing `admin` / `super_admin` roles may review; support/moderator privileges are not widened. Sellers respond only when requested, and receive no unrelated Buyer address, email, identity or account data through these screens. Existing approved-Seller login restrictions are preserved.

New-case creation locks the shipment. Updates lock the case and require its current revision, preventing silent overwrites of concurrent Admin decisions. Buyer submission UUIDs, per-actor update UUIDs, unique constraints and active-case deduplication by item/basis/category prevent duplicate cases/history from repeat clicks. Different legal/warranty bases are not silently collapsed into each other.

### Evidence and notifications

Up to five JPG/PNG/PDF files, 5 MB each, use the existing content/extension rules. Images pass through the existing bounded decoder/re-encoder; original metadata is removed. Files use private `local` storage beneath `consumer-claims/{id}`, not public image storage. Failed transactions clean up uploaded files. Downloads recheck case ownership/Admin authorization, validate the case/update association, use attachments, `nosniff`, a restrictive CSP and private/no-store caching. Case pages also use private/no-store caching.

Notifications are database notifications after commit. Only the case Buyer, its relevant Seller when a response/decision concerns them, and authorized Admins receive them. Buyer links are built from owned claim IDs; Seller links use the existing scoped claim queue. Stored arbitrary notification URLs are not trusted by the existing presenters. Notification transport failures are logged and do not undo a saved review; no new mail service or external messaging integration was installed.

### Remedy integration deliberately left manual

Approving a case records a review decision only. It does not create a ParcelReturn, authorize/pay a refund, debit a Seller, alter commissions, restock a product, issue a replacement order or generate a shipment. Admin must document next steps in the decision. Any execution workflow for repair/replacement, a post-window refund authorization, or physical return transport needs a separately approved integration with existing financial and custody services. Do not create an ordinary return or fabricate a receipt merely to make such a remedy fit the seven-day process.

DTI's [consumer guidance on defective products and remedies](https://fairtrade.dti.gov.ph/faq/is-no-return-no-exchange-policy-allowed/) and [warranty FAQs](https://www.dti.gov.ph/dti-knowledge-hub/dti-faqs) are reference material for manual review, not an automatic eligibility algorithm or a guarantee that a particular claim succeeds.

## Shipping allocation rules and safeguards

Financial participants are the contracted origin carrier, distinct providers on **received forward handoffs**, and the collecting/delivering COD provider. Multiple hubs within one company do not add participants. Repeated provider visits deduplicate; planned, rejected, cancelled/unreceived and reverse handoffs do not create forward shipping shares. The final collecting provider is included even when a handoff history is incomplete.

| Distinct financial providers | Behavior |
| --- | --- |
| One | Preserve existing single-provider settlement/snapshot behavior |
| Exactly two | Preserve 40% origin, with destination receiving the remainder; integer centavo rounding is unchanged |
| Three or more | No repeated/default 40/60 formula and no invented percentages. New snapshots remain unset; provider ledger entries remain amountless `pending_policy` |

`CodRemittance` now returns unknown/pending shares for unresolved multi-provider allocations, and its SQL equivalents match. Already finalized stored snapshots are read without rewriting them. Missing historical multi-provider shares remain unknown rather than being reconstructed using 40/60.

The ready-parcel query excludes unsupported allocations. Transactional checks also guard stale/forged requests, submitted batch confirmation, origin payouts, historical reconciliation and destination payouts. Finalization rechecks the participant chain under locks. Existing idempotency/reference-registry protections remain in place. Preparing provider records now locks the shipment so repeated preparation remains safe with the existing unique provider/parcel constraint.

An Admin's free-text policy approval is retained as a governance/audit note; it **does not enable** an unimplemented calculation. The success message and new audit payload make that explicit. No 3+ provider payout method is currently enabled.

Affected settlement/earnings pages show route participants, original shipping charge, original collected COD and the allocation-review hold. Pending amounts are excluded from payable/recognized-share totals, rather than shown as a finalized 40/60 split. The Admin dashboard separates held COD from known dues, and shipment monitoring explains the hold. All-held confirmation buttons and affected checkboxes are disabled; server-side checks enforce the same restriction. The review panel is bounded to the latest 100 affected records and discloses that limit.

### Operational consequences and required policy decision

Delivery transitions, parcel tracking, physical custody, Rider assignment, actual COD receipt and shortage recording/recovery continue. Other eligible parcels can still be remitted, and mixed submitted batches can confirm only their supported selected parcels. Affected parcels cannot complete the platform remittance/shipping-share flow while allocation is undefined. This can delay the related Seller payout under its existing platform-cash-receipt requirement; no new Seller charge or change to its earnings calculation is introduced.

There is no invented interim escrow deduction, extra Buyer charge or arbitrary retained-shipping amount. Original charges, payment references, confirmed financial records and recorded cash history remain stored. Where an old sent batch lacks a usable parcel snapshot, the running parcel balance is marked for review instead of fabricating a number.

Before releasing 3+ provider holds, an authorized policy decision must specify the financially eligible participants, exact allocation method, centavo rounding/conservation, versioning and applicable settlement/reconciliation handling. That method then needs implementation and isolated tests. Recording a general policy note alone cannot finalize the held records. No retrospective backfill or correction was run.

## Verification and known baseline failures

Broader isolated result: **165 tests, 150 passed, 15 verified baseline failures, zero new failures** (1179 assertions). All 15 newly added claim/allocation tests and the four existing provider-policy tests pass. The final focused rerun after the historical-cash-summary safeguard passed **19/19 tests, 170 assertions**.

New tests cover post-window claims, unchanged ordinary return rules, Buyer ownership, item scoping, Seller isolation/response, Admin permissions, rejection reasons, authorized recipients and links, private evidence, idempotency, stale revisions, append-only history, disabled/missing-schema gates, no automatic finance/stock effects, one/two/three-provider behavior, SQL/model parity, historical preservation, unresolved historical values, physical cash receipt, policy-note bypasses and repeat financial processing.

The affected PHPUnit run uses in-memory SQLite and synthetic accounts/transactions. Final counts are recorded in `verification-summary.json` in the recovery directory. The broader run has 15 known failures reproduced with the saved pre-change services and view/controller overlay in a separate guarded in-memory process: 14 in `PlatformFundedRefundTest` (existing refund-authorization/form expectations), plus the existing undefined `$collected` variable in the untouched `Logistics/CodRemittances/index.blade.php`. The same COD-screen baseline issue also prevents the four pre-existing complete financial scenario journeys from finishing; those are not claimed as verified end-to-end journeys.

Notification JavaScript checks: `node --test tests/js/live-notifications.test.mjs tests/js/buyer-notifications.test.mjs` has 13 passes and 4 failures in unchanged JavaScript. Two expect a 10-second poll where the implementation uses 30 seconds; two concern existing Buyer badge/focus refresh assertions. No JavaScript source or those test files were changed here. Notification records/recipient scoping and owned-case web links are covered by PHP tests, but live notification polling is not claimed clean.

Scoped Pint, PHP syntax, diff-whitespace checks and `npm run build -- --emptyOutDir false` were run. Exact live Lucide bytes were preserved and old asset hashes retained. Browser checks used **isolated synthetic HTML snapshots**, existing stylesheets and Lucide at 375px/1280px for Buyer create/detail, Seller detail and Admin detail/queue (10 page/viewport combinations), with no horizontal overflow. Admin action selection/required justification was checked. Application scripts were omitted and POSTs blocked during these snapshot checks: this is visual/native-form evidence, not a live authenticated claim journey. Production claims stayed disabled throughout. No MySQL/InnoDB concurrency or full-suite claim is made.

Key commands:

```powershell
php artisan test --compact tests/Feature/Buyer/ConsumerClaimWorkflowTest.php tests/Feature/Logistics/MultiProviderAllocationSafetyTest.php tests/Feature/Logistics/ProviderAllocationTest.php
node --test tests/js/live-notifications.test.mjs tests/js/buyer-notifications.test.mjs
npm run build -- --emptyOutDir false
```

## Pending migration and activation checklist - separate approval required

Pending file: `database/migrations/pending/2026_11_16_210000_create_consumer_claims_tables.php` creates `consumer_claims` and `consumer_claim_updates`, with restricted foreign keys, indexes and idempotency constraints. It does not alter existing transaction tables. It is outside the normal migration root and was not applied live.

After separate approval, follow the normal controlled release/database-backup procedure, review this specific migration, then apply only its approved path:

```powershell
# NOT executed against production in this task
php artisan migrate --path=database/migrations/pending/2026_11_16_210000_create_consumer_claims_tables.php --force
```

Verify both tables/indexes/foreign keys, private storage permissions and hosting upload limits, then separately approve enabling `consumer-claims.enabled`. Refresh configuration cache only through the approved release procedure if that environment caches config. Resolve/accept the documented notification baseline issues and perform authenticated role QA before treating the feature as released. Do not enable the switch first or run every pending migration indiscriminately.

## Manual verification

Use an isolated database and synthetic accounts:

1. Record a delivery more than seven days ago, confirm ordinary return eligibility stays closed, then submit a consumer claim. Confirm its basis/category/evidence and the Admin queue.
2. Repeat submission and update requests with the same UUIDs. Confirm one case/update and no duplicate notifications/audits. Try a stale Admin revision; refresh should be required.
3. Try another Buyer's shipment/item, another Seller's case/evidence and a support/moderator Admin. Confirm access is denied.
4. Request Buyer information, request Seller response, add Buyer supplemental evidence while the Seller request is pending, then respond as that Seller. Confirm status/history and only authorized recipients/links.
5. Reject without a reason (must fail), then with a documented reason. Test approval and closure. Compare payment/remittance/settlement/stock/return rows before and after; they must not move automatically.
6. Reject invalid/oversized evidence. Download authorized evidence as an attachment; unrelated users must be refused. Verify mobile/desktop forms and native required fields.
7. Exercise one-provider and exactly-two-provider fixtures, including an odd-centavo shipping fee and repeated hub/provider visits. Confirm the original fee is conserved and existing finalization remains idempotent.
8. Exercise three/four-provider received chains and an unreceived planned third provider. Only actual financial participants count. Verify unsupported shares remain unset, physical receipt succeeds, financial confirmation/payout fails and other eligible parcels remain payable.
9. Test an already submitted affected batch, a general policy note, historical frozen shares and missing historical shares. Confirm no bypass, no reconstructed 40/60 values and no changes to original records.
10. Keep claims disabled or remove their schema in the isolated environment. All claim entry points must hide/404 without querying missing claim tables. Never change production transactions just to test any step.

## Rollback checklist

- Compare task hunks against the targeted recovery copies and the before/after file manifests. Preserve intervening edits; never blindly restore entire shared files or use reset/clean/stash.
- Keep the claim switch disabled before backing out its UI/routes. Preserve any case history and private evidence if the feature has since been activated. Do not run `down` against populated production claim tables as a source rollback.
- Do not clear allocation holds, manufacture missing shares or rewrite historical money records. Reverting the shipping guard would re-enable the original defect; prefer retaining the guard while reverting presentation changes if necessary, and review any financial rollback explicitly.
- Preserve old hashed assets and unrelated Lucide changes. No deployment, service restart or production data cleanup was performed by this task.

## Exact source inventory

Created:

- `config/consumer-claims.php`
- `database/migrations/pending/2026_11_16_210000_create_consumer_claims_tables.php`
- `app/Models/Buyer/ConsumerClaim.php`
- `app/Models/Buyer/ConsumerClaimUpdate.php`
- `app/Services/Buyer/ConsumerClaimAvailability.php`
- `app/Services/Buyer/ConsumerClaimService.php`
- `app/Http/Middleware/EnsureConsumerClaimsAvailable.php`
- `app/Http/Controllers/ConsumerClaimController.php`
- `app/Notifications/ConsumerClaimChanged.php`
- `routes/consumer-claims.php`
- `resources/views/ConsumerClaims/index.blade.php`
- `resources/views/ConsumerClaims/create.blade.php`
- `resources/views/ConsumerClaims/show.blade.php`
- `resources/views/Components/shipping-allocation-holds.blade.php`
- `tests/Feature/Buyer/ConsumerClaimWorkflowTest.php`
- `tests/Feature/Logistics/MultiProviderAllocationSafetyTest.php`
- `docs/P1_CONSUMER_CLAIMS_AND_MULTI_PROVIDER_ALLOCATIONS.md`

Modified:

- `app/Models/Logistics/CodRemittance.php`
- `app/Services/Logistics/ProviderAllocationService.php`
- `app/Services/Logistics/LogisticsRemittanceService.php`
- `app/Http/Controllers/Admin/LogisticsRemittanceController.php`
- `app/Http/Controllers/Logistics/EarningsController.php`
- `resources/views/Admin/LogisticsRemittances/index.blade.php`
- `resources/views/Logistics/Earnings/index.blade.php`
- `resources/views/Buyer/Orders/show.blade.php`
- `resources/views/Components/admin-sidebar.blade.php`
- `resources/views/Components/seller/sidebar.blade.php`
- `routes/buyer.php`
- `routes/admin.php`
- `routes/seller.php`
- `app/Http/Controllers/Admin/DashboardController.php`
- `resources/views/Admin/dashboard.blade.php`
- `app/Http/Controllers/Buyer/NotificationController.php`
- `app/Support/SellerNotificationPresenter.php`
- `app/Support/BuyerNotificationTitle.php`
- `tests/Feature/Logistics/ProviderAllocationTest.php`
- `resources/views/Admin/OrderMonitoring/_financials.blade.php`

Build output updates the ignored `public/build` manifest/assets. The Lucide source/bundle was restored byte-for-byte to its pre-build state. Recovery copies, source manifests, complete test/baseline output, build output and isolated preview screenshots are outside the public project under the recovery directory above.
