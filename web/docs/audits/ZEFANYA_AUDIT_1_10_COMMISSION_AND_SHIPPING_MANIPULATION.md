# Zefanya Audit 1.10: Commission and shipping fee manipulation

Date: 2026-11-13, Asia/Singapore. Scope: `C:\ProjectEcommerce\WebZefanya` only.

## 1. Overall result

Three reproduced software defects were fixed: valid multi-Seller voucher allocations incorrectly blocked settlement, cancellation of an uncollected return discarded a saved historical commission rate, and refund-date validation rejected the current Manila date during the UTC date boundary.

Two existing cases require an explicit policy decision and were not redesigned: partial-refund commission is currently recognized before COMPLETED, and a subsidized shipment can collect less COD than the shipping fee that the provider is expected to retain. Both are reproduced and described below. Passing characterization tests document these limitations; they do not mean the limitations were fixed.

The current default remains **10%**. Saved **5%** rates remain preserved, including through the corrected cancellation path. The shipping allocation remains **40% origin / 60% destination**, rounded in centavos with the destination receiving the exact remainder. No production history, commission configuration, shipping configuration, migration, return/debt policy or production data was changed. No commit or push was made. Earlier uncommitted work and Audits 1.1–1.9 were retained.

Overall status: **PARTIALLY PROTECTED — REMAINING RISKS**, because the recognition/subsidy questions remain open despite verified code fixes.

## 2. Authoritative rules and source-traced workflow

### Commission

`CommissionFinalizationService.php:17` defines `COMMISSION_BPS = 1000` (10%). This is a code constant, not an editable Platform Settings rate. Searches of runtime application/config/routes found no alternate obsolete 5% default. `PlatformSettingsTest` also checks that a database row named `commission_bps` does not make it an approved editable setting.

Normal new checkout does **not** write a commission rate or recognized commission. `CheckoutService.php:322–364` saves server-calculated order and voucher snapshots. `SellerOrderStatusService.php:325` calls `CommissionFinalizationService::finalize()` when the Seller order reaches COMPLETED. Thus the implemented selection/snapshot moment is finalization, rather than checkout creation. With the unchanged constant, new orders use 10%. This audit did not introduce a new checkout-time rate-locking policy.

The normal calculation is:

```text
eligible base = saved merchandise subtotal - saved Seller-funded discounts
commission = integer-centavo half-up rounding(base × saved/default basis points / 10,000)
Seller net = merchandise subtotal - Seller-funded discounts - commission
```

Shipping **charges are not added** to the commission base. Under the existing funding contract, Seller-funded discounts include Seller-funded shipping vouchers too. Consequently ₱100 of merchandise with a ₱50 Seller-funded shipping voucher has a ₱50 base and ₱5 commission at 10%, rather than ₱10. That existing rule is preserved and explicitly tested; it is not an obsolete 5% rate. Marketplace-funded discounts do not reduce Seller earnings in this manner. Unknown or inconsistent funding keeps settlement on hold rather than inventing payable money.

`seller_orders.commission_bps`, `commission_minor`, `seller_net_minor` and `commission_finalized_at` are the stored normal finalization evidence. Finalization locks the Seller order, validates current snapshots, preserves an already saved rate, refuses inconsistent partial monetary records, and syncs an existing settlement in the same transaction. A saved rate by itself can now survive a return cancellation while recognition/amounts are cleared. It is trusted stored metadata, not an HTTP-editable input. Invalid stored rates outside 0–10,000 basis points reject.

`VoucherFundingService.php:28`, `:83` interprets immutable checkout snapshots. Merchandise discounts and shipping discounts remain distinct. The fixed conservation check compares all original Seller-order merchandise shares with the parent checkout discount; it does not compare each shop's share with the whole checkout. Cancelled siblings retain their original checkout shares for this check.

### Shipping and provider allocation

`ShippingRateService.php:61` returns the marketplace rate for every provider/zone. `fixedRate()` (`:71`) reads `buyer.shipping_fee`: ₱50 includes the first 2 kg, then ₱20 per additional rounded-up kilo. `CheckoutCarrierService::chargeableKg()` (`:70`) and `feeForRate()` (`:512`) calculate the fee from authoritative cart quantity/weight. Per-company saved rate cards still have an authorized/audited management path, but do not price new checkout quotes.

`CheckoutController.php:121`, `:260–282`, `CheckoutToken.php` and `CheckoutService.php:243–268` preserve the legitimate server-side rate snapshot and revalidate route availability, selected carriers, address, products, prices, weights and fees. Posted fee fields are rejected or ignored; valid carrier selection is re-resolved from offered providers. A fingerprint detects changed reviewed values; locked recalculation prevents stale acceptance during placement. Later catalogue/address/rate edits do not rewrite placed order snapshots.

Checkout stores `seller_orders.carrier_provider_id`, destination handoff provider, `shipping_fee_minor`, `parcel_weight_grams`, per-slot voucher discounts/funders, item prices/quantities and a per-Seller COD `payments.amount_minor`. Buyer COD is:

```text
merchandise subtotal - merchandise discounts + gross shipping fee - shipping discount
```

Rider delivery records the actual collecting Courier/provider and one COD obligation per payment. `CodRemittance::snapshotAllocation()` (`CodRemittance.php:90`) freezes the monetary split at delivery. A same-provider delivery keeps the full fee and creates no origin share. If collecting/delivering provider differs from checkout carrier, origin gets half-up rounded 40% and destination retains `gross fee - origin share`. The SQL fallback constants (`:29–35`) match the model's integer arithmetic. Saved splits are not recomputed after input/rule changes.

`LogisticsRemittanceService.php:335`, `:404` recomputes provider-owned parcel dues, subtracts retained shipping once, and separates a submitted external transfer from Admin-confirmed platform receipt. Origin share is payable only after existing confirmed platform receipt and can be allocated to one origin payout. Existing rejection/reversal/correction preserves records and audits (`:171`, `:257`, `:291`, `:501`). Earlier historical 0/0 unallocated snapshots remain under the existing explicit reconciliation workflow; they are not silently converted to 40/60.

`ProviderAllocationService.php:28`, `:49` reads received forward handoffs and records distinct participants for 3+ provider chains. These rows have null amounts and `pending_policy`; they do not create an intermediate shipping payout. The actual delivery caller holds the Seller-order transaction/lock (`SellerOrderStatusService.php:320`). A unique `(seller_order_id, logistics_provider_id)` constraint prevents duplicate participant rows. No unsupported intermediate-provider fee was invented.

### Estimated, collected, recognized, payable and paid

| State | Meaning / authoritative source |
|---|---|
| Quote / estimate | Server checkout quote and legitimate token rate snapshot, not a collected payment or recognized commission. |
| Placed / pending | Immutable order/payment amounts and funding snapshots. Commission fields normally remain unset. |
| Collected COD | `payments.collected_amount_minor/at/by` and one COD obligation; exact collection is validated independently of commission recognition. |
| Company receipt / shortage | Actual `cod_remittances.received_amount_minor`, original shortage and recovery audit history. Rider shortage remains separate from Rider earnings. |
| Recognized normal commission | COMPLETED plus validated stored finalization evidence. The partial-return exception below currently conflicts with a universal COMPLETED-only interpretation. |
| Seller settlement | Full matching collection/company receipt permits preparation; final amounts/funding must reconcile. Completion, existing platform receipt, holds, reservations and debts still control withdrawability/payment. |
| Provider shipping earned / received / paid | Saved retained/origin shares, platform receipt and linked origin payout are different stages. Rider expenses do not reduce COD remittance dues. |
| Paid Seller money | Paid requests/allocations and the direct-paid settlement remainder. Audit 1.8 direct payout reporting remains included; request portions are not counted twice. |

The existing conservation equation is `commission + Seller net + gross shipping = collected COD + marketplace funding`. Funding is not fictitious Rider-collected cash. All payout/remittance guards from the previous audits remain in place.

## 3. Confirmed defects fixed

### F1 — Medium: each Seller's voucher share was incorrectly compared with the entire checkout discount

Fixed source: `app/Services/Shared/VoucherFundingService.php:94–106`.

Reproduction: place a real two-Seller checkout with ₱100/₱200 merchandise and a ₱3 marketplace-funded voucher. Checkout correctly saves ₱1 and ₱2 per-Seller discounts, with parent discount ₱3. Previously `stackAllocation()` required each Seller's merchandise discount to equal ₱3. Both valid shares were classified `financial_reconciliation_required`, leaving Seller net unknown and preventing otherwise valid settlement release.

Root cause: a per-Seller allocation was checked against a global total. Existing checkout allocation, immutable snapshots, exact COD totals and settlement safety holds worked; the read-side interpretation rejected their valid output.

Impact: legitimate multi-Seller purchases with vouchers could collect correct COD but block Seller financial finalization/payment. This was not a demonstrated overpayment bypass: existing holds stopped payment.

Fix: reconcile the sum of original child merchandise snapshots to the parent total, then validate the current Seller's own share against its subtotal/parent bound and funding components. Include cancelled siblings' original amounts. No voucher share, funding responsibility or historical record is rewritten.

Regression evidence: `CommissionShippingIntegrityTest.php:92`, `:114`, `:123`, `:134` covers real checkout through full company receipt/settlement, corrupt totals, cancelled siblings and mixed Seller-funded shop/marketplace-funded shipping discounts. The original real-checkout regression failed before the fix. MySQL parallel Seller finalization verifies the shared checkout discount conserves under independent transactions.

### F2 — Medium: cancelling an uncollected return erased a saved 5% rate

Fixed source: `app/Services/Seller/SellerSettlementService.php:325–350`; `app/Services/Shared/CommissionFinalizationService.php:53–73`.

Reproduction: use an isolated historical record with saved 500 basis points, approve its return while still Delivered, then cancel before Rider collection. Approval preserves the saved rate while holding earnings. Previously cancellation cleared the rate together with recognition; later completion selected today's 1000 basis points. On ₱100 eligible merchandise, commission became ₱10 instead of the saved ₱5, reducing Seller net by ₱5.

This specifically covers a saved-rate/return record, including older return holds. Normal current finalization occurs at COMPLETED; the audit does not assert that arbitrary new Delivered orders should be finalized or that affected production records exist.

Root cause: restoration treated the saved rate as disposable recognition state. The finalizer also required a recognition timestamp before honoring that stored rate. Existing historical protection covered already-finalized repeats and refunds, but not clearing recognition for a cancelled return.

Fix: retain the saved rate while clearing recognition/amounts, then use the stored rate at later finalization. New records without a saved rate still use 1000. Inconsistent partial amount/net snapshots remain rejected and stored rates are range-checked. No historical commission amount or paid settlement was batch-recalculated.

Evidence: `SellerReturnDecisionTest.php:1247` failed before the fix; it now verifies cancellation, cleared recognition and later 5% order/settlement amounts. Existing historical partial-refund test (`:1224`) remains passing. MySQL duplicate finalization datasets cover finalized 5%, pending saved 5%, and new 10% with one settlement each.

### F3 — Medium: the current Manila refund date was rejected as a future UTC date

Fixed source: `app/Http/Controllers/Admin/ReturnController.php:116`; `app/Services/Logistics/ParcelReturnService.php:217`.

During refund/commission regression testing, an existing ReturnRecovery test failed between Manila midnight and 08:00: the UI/test submitted the current Philippine date, while HTTP `before_or_equal:today` and service `Carbon::parse(...)->isFuture()` evaluated the server's earlier UTC date/midnight.

Impact: an authorized current-day refund and its associated existing financial adjustments could not be recorded during that daily boundary. Financial state remained unchanged on rejection; this did not authorize excess refunds.

Fix: both HTTP and service use the same Manila calendar date (`StoreSale::TIMEZONE`) already used by financial reporting/payout dates. Tomorrow remains invalid. The field is a paid-on calendar date, not an external transfer timestamp. No amount, recipient, revision, approval, debt or refund policy was changed.

Evidence: `SellerReturnDecisionTest.php:1272` freezes the UTC/Manila boundary and tests today versus tomorrow. Today's case failed before the fix; both pass afterward. The previously failing ReturnRecovery test now passes without changing its expected behavior.

## 4. Policy-dependent discrepancies left unchanged

### P1 — Partial-refund recognition conflicts with a universal COMPLETED-only rule

Sources: `SellerSettlementService.php:245–313`, `:430–465`, `PlatformFinanceService.php:34–45`, `FinanceReportService.php:88`, `:110`.

Concrete evidence: a Delivered order for ₱100 with an Admin-recorded ₱20 partial refund gets ₱80 retained merchandise, ₱8 commission at 10%, and a non-null `commission_finalized_at`, while its status is still Delivered. Admin earnings sum that ₱8 because they select finalized timestamps without requiring COMPLETED. `SellerReturnDecisionTest::test_partial_refund_records_the_existing_pre_completion_commission_recognition_discrepancy` records this exact result.

This is a confirmed discrepancy from the owner's blanket COMPLETED recognition statement, not a claim that every normal order finalizes early. The refund workflow explicitly charges commission on retained items, and physical-return states can end at RETURNED rather than COMPLETED. Simply filtering reports to COMPLETED could hide commission actually charged on retained items; changing the charge or forcing completion would alter the existing return policy.

Decision required: define whether retained-item commission has a refund/return recognition exception, or when/how it may be recognized if COMPLETED is mandatory. This portion was stopped without changing recognition timestamps, financial report filters, charges or order states. The system is not certified universally COMPLETED-only while this exception remains.

### P2 — Shipping subsidy can exceed COD available for the retained fee

Sources: `CheckoutService.php:385–397`, `CodRemittance.php:75–113`, `SellerSettlementService.php:654–682`.

A real checkout with ₱10 merchandise, ₱50 shipping and a marketplace-funded ₱50 shipping voucher is accepted with ₱10 COD. Its funding snapshots balance mathematically, but a same-provider company cannot retain ₱50 from only ₱10 of actual cash. Full company receipt reaches the existing `shipping allocation > collected amount` guard; receipt/settlement creation rolls back rather than inventing cash. A raw due calculation would be negative, and no supported negative remittance/subsidy disbursement workflow was found.

`CommissionShippingIntegrityTest::test_unsupported_shipping_subsidy_never_invents_collected_cash_or_releases_a_settlement` reproduces this accepted-checkout/later-safe-hold limitation. It is not asserted to be an operationally complete order flow.

Decision required: define provider subsidy funding/settlement or restrictions for offers whose distributable fee cannot be retained from recorded COD. Adding a provider receivable/payout, reducing the fee/split, inventing cash, or banning an approved voucher would introduce financial behavior not authorized by an existing rule. This portion was documented without adding such a policy or feature. External funding is not the same as physical COD collection.

For 3+ providers, intermediate monetary allocation also remains the existing explicitly unsupported policy area: participant rows have no amounts. The established origin/destination 40/60 allocation is unchanged and does not create extra intermediate revenue.

## 5. Scenario classification A–Z

| Scenario | Classification | Result / evidence |
|---|---|---|
| A. Rate HTTP manipulation | ALREADY PROTECTED | New direct checkout tests submit 0%, 1%, 5%, 10%, 50%, 100%, negative and >100% values. Stored commission remains unset at checkout, then normal finalization uses 10%. Wrong-role checkout posts reject. Approved settings keys cannot be expanded by posting/creating a commission key. |
| B. Historical 5% integrity | CONFIRMED VULNERABILITY — fixed F2 | Existing finalized 5% repeat/refund/payout guards work; cancelled-return recognition reset previously discarded the rate. Saved 5% now survives that path and concurrent finalization, without recalculating production history. |
| C. New 10% orders | ALREADY PROTECTED | Sole current default is 1000 in the finalizer. Real new checkout plus completion test verifies 10%; no obsolete 5% runtime default found. Selection is at finalization, not a new checkout-time rate snapshot. |
| D. Commission base | PARTIALLY PROTECTED | Authoritative prices/quantities and saved funding determine the base; shipping charges are excluded. Existing Seller-funded shipping discount treatment is retained. Multi-Seller funding interpretation fixed F1. Unknown funding still holds payment; the refund recognition exception P1 remains. |
| E. Seller earnings forgery | ALREADY PROTECTED | Client subtotal/commission/net/discount/provider/settlement fields cannot replace stored calculations. Existing exact reconciliation, reservation and current debt checks remain passing. F1 fixes a legitimate allocation rejected by those checks rather than weakening them. |
| F. Recognition before completion | OPERATIONAL GAP — P1 | Normal finalization refuses Delivered/Cancelled and is locked to COMPLETED. The existing partial-refund path nevertheless records/aggregates retained-item commission on Delivered/Returned states. This explicit conflict needs the refund recognition decision above; it is not marked fully protected. |
| G. Duplicate recognition | ALREADY PROTECTED | Finalizer locks/re-reads one Seller-order snapshot and validates repeated calculations; no second commission record is inserted. Real MySQL duplicate 5%/10% and completion/COD races verify one settlement and one reported commission. |
| H. Full/partial refund | PARTIALLY PROTECTED | Existing retained-item adjustment uses the saved rate; historical 5% partial refund remains 5%. Full/partial refund, void/replacement, paid debt and rollback regressions pass. P1 remains a recognition-timing discrepancy; F3 fixes valid-day refund rejection. |
| I. Cancellation | ALREADY PROTECTED | Normal cancelled orders cannot finalize commission or become withdrawable. Cancellation before collection leaves no invented COD shipping earnings; existing legitimate return/failed-delivery charges remain separately recorded. |
| J. Shipping HTTP manipulation | ALREADY PROTECTED | Zero/negative/reduced/excessive submitted quotes reject without order/payment/token consumption. Extra minor-unit/provider/distance/discount fields cannot override authoritative stored fees. Forged actual carrier selection rejects through existing coverage/ownership checks. |
| K. Fee consistency | PARTIALLY PROTECTED | Quote/token/locked checkout/order snapshots agree; stale/review changes stop placement and valid saved rate quotes are preserved. SQL/model rounding and saved split agree. P2 remains an accepted subsidized checkout that cannot complete the existing cash-retention step. |
| L. 40/60 | ALREADY PROTECTED | ₱100 distributable saved fee yields ₱40 origin/₱60 destination; odd-cent rounding leaves the exact remainder to destination. Discounts do not silently rewrite gross carrier entitlement. SQL fallback and frozen snapshots tested on SQLite/MySQL. |
| M. Same provider | ALREADY PROTECTED | Origin share is zero and destination retains the fee once; no separate origin payout is created for the same provider. Existing same-provider end-to-end financial/report tests pass. |
| N. Multi-leg routes | PARTIALLY PROTECTED | Actual collected cash belongs to the delivering Courier/provider; checkout carrier identifies origin. Verified handoff participant metadata does not create extra amounts. 3+ provider rows remain amountless/policy-pending. No unsupported intermediate payment or split redesign was added. |
| O. Provider identity forgery | ALREADY PROTECTED | Offered-carrier validation, provider-owned operational endpoints, immutable handoff relationships/FKs and actual collecting provider prevent posted origin/destination IDs redirecting earnings. Extra forged checkout identities are ignored; unauthorized selected carriers reject. |
| P. Cross-provider concurrency | ALREADY PROTECTED in implemented path | Delivery's Seller-order lock serializes allocation preparation; unique provider-per-parcel constraint bounds rows. MySQL parallel preparation verifies one participant row each/no amounts; existing verified receipt/rerouting races also run. No test claims an external physical handoff occurred. |
| Q. Failed delivery | ALREADY PROTECTED within existing rules | Without successful COD delivery no ordinary collected-fee row is created. A completed failed-delivery physical return may create the existing delivery/return-trip charge/debt (`ReturnChargeService.php:43–47`); it is not normal completed-order commission or fabricated COD. No new failed-delivery charging policy. |
| R. Shipping after cancellation | ALREADY PROTECTED | A precollection cancel does not create COD-based shipping earnings. Previously performed delivery/return charges and original cash history are retained where existing policy requires them. No historical shipping row was deleted or rewritten. |
| S. Shipping/refund interaction | PARTIALLY PROTECTED | Original forward fee/split, separate return charges, refund revisions and one debt-per-order guards remain. Full/partial refunds, repeated adjustments and paid-debt races pass. P1/P2 remain documented policy gaps; no automatic COD netting or new provider subsidy was invented. |
| T. Voucher funding | CONFIRMED VULNERABILITY — fixed F1 | Per-Seller shares now reconcile to checkout-wide total while preserving Seller versus marketplace funding and separate shipping discounts. Cancelled sibling original shares remain included. Existing Seller-funded shipping basis is unchanged; unsupported funding remains a hold. |
| U. Precision | ALREADY PROTECTED | Required ₱0.01, ₱0.05, ₱0.10, ₱99.99, ₱999.99, ₱10,000 and ₱1,000,000 inputs tested. Commission half-up and origin-plus-destination reconciliation use integer centavos/BCMath. Existing maximum integer/overflow/negative helper tests remain. MySQL checks SQL fallback versus frozen shares at the same boundaries. |
| V. Unauthorized financial changes | ALREADY PROTECTED | Direct Buyer/Seller/Rider/Logistics/unauthorized Admin role tests run across checkout/settings/provider/remittance/payout routes. Model guarded fields and explicit service writes prevent financial mass assignment. This does not claim a fully privileged database administrator cannot change raw data. |
| W. Stale calculations | ALREADY PROTECTED after F2 fix | Server rate snapshots are intentionally honored; route/address/price/voucher changes are revalidated before locked placement. Stored finalized rates validate rather than overwrite. Old return-cancellation metadata now retains its historical rate. External subsidy gap P2 is not mislabelled as a stale-quote fix. |
| X. Failure/rollback | ALREADY PROTECTED | Injected settlement-sync failure restores new commission plus settlement on SQLite/InnoDB. Allocation failure inside the delivery caller's transaction leaves no partial chain. Existing shipping-rate audit, collection, payout, refund/debt and reversal rollback tests remain passing. |
| Y. Financial reports | PARTIALLY PROTECTED | Saved 5%/10%, actual direct payouts, provider splits, outstanding debts and partial COD reporting remain covered. Normal new multi-Seller net is corrected F1. P1 means the platform report is not universally COMPLETED-only; characterization explicitly demonstrates that remaining discrepancy. |
| Z. Alternate writers | PARTIALLY PROTECTED | Normal/manual/automatic completion, mobile/shared delivery, checkout, return/refund/debt, shipping cards, historical shipping reconciliation and payout reversal paths traced. No extra current 5% rate source or weaker commission edit endpoint found. Return reset fixed F2; refund exception P1 and subsidized cash P2 remain, rather than being hidden behind primary-path success. |

## 6. Files changed

Production files (five):

1. `app/Services/Shared/VoucherFundingService.php`: conserve the full checkout's immutable shares while validating each Seller's allocation.
2. `app/Services/Shared/CommissionFinalizationService.php`: honor a saved pending historical rate, retain partial-amount rejection and range validation; unchanged 1000 default.
3. `app/Services/Seller/SellerSettlementService.php`: retain saved rate through an uncollected-return cancellation while clearing recognition.
4. `app/Http/Controllers/Admin/ReturnController.php`: validate paid-on against the current Manila day.
5. `app/Services/Logistics/ParcelReturnService.php`: matching service-level Manila calendar-date validation.

Tests/config/report:

- New `tests/Feature/Order/CommissionShippingIntegrityTest.php`: actual checkout-to-settlement allocation; corrupt totals; cancelled siblings; mixed funding; wrong roles; forged rates/fees/identities; required monetary boundaries; saved 5%; recognition guard; settlement/allocation rollback; explicit P2 and Seller-funded shipping characterizations.
- `tests/Feature/Seller/SellerReturnDecisionTest.php`: saved 5% cancellation/completion, Manila date boundary and P1 characterization. Previous refund/debt tests retained.
- New `tests/MySql/FinancialCalculationConcurrencyTest.php`: current/historical/pending-rate duplicate finalization, completion/COD lock orders, independent Sellers under a shared checkout, allocation preparation, InnoDB rollback and required fee precision.
- `tests/MySql/worker.php`: additional financial finalization/completion/allocation tasks using existing services and parcel-lock harness; prior audit actions retained.
- `phpunit.mysql.xml`: include the new class in the existing isolated suite.
- This report.

No frontend, notification, export, gateway, new payout route, automatic Rider deduction or new financial policy. No Flutter/integration changes. No production data repair or migration.

## 7. Verification and limitations

Baseline narrow SQLite: **103 tests / 539 assertions passed** across voucher stacking/allocation, shipping, provider allocation, rounding and COD settlement workflows.

Both calculation regressions failed before fixes: multi-Seller funding incorrectly required reconciliation; cancelled return lost 500 basis points. The controlled Manila date regression also failed before the date fix. The unmodified expected behavior of the date-sensitive ReturnRecovery test was restored.

Test-authoring corrections, rather than application guard relaxations: initially a commission-tampering test also posted prohibited `shipping_fees`, which correctly rejected; fee rejection was split into its own cases. A mixed-funding fixture finalized the first child before all sibling snapshots existed; setup now completes the checkout snapshots first. Initial MySQL fixtures omitted an existing settlement for a sync-only method and used non-existent hub IDs; proper prepared settlements and matching provider hubs were added. A later added role test required restoring a previously unused/removed Payment import. No test failure was suppressed by weakening a financial check.

Final SQLite: **658 tests / 5,039 assertions passed**, across the following 33 files:

```text
php vendor/bin/phpunit tests/Feature/Order/CommissionShippingIntegrityTest.php tests/Feature/Order/Rule10MoneyRoundingTest.php tests/Feature/Buyer/CheckoutShippingTest.php tests/Feature/Buyer/CheckoutCarrierPersistenceTest.php tests/Feature/Buyer/CheckoutCarrierSelectionTest.php tests/Feature/Buyer/CrossProvinceCheckoutTest.php tests/Feature/Buyer/PlatformVoucherAllocationTest.php tests/Feature/Buyer/VoucherStackingTest.php tests/Feature/Http/Controllers/Buyer/CheckoutFinancialIntegrityTest.php tests/Feature/Logistics/ProviderAllocationTest.php tests/Feature/Logistics/ShippingRateManagementTest.php tests/Feature/Logistics/ReturnRecoveryTest.php tests/Feature/Admin/PlatformSettingsTest.php tests/Feature/Seller/SellerEarningsPageTest.php tests/Feature/Seller/SellerReportPageTest.php tests/Feature/Logistics/CodRemittanceShortageTest.php tests/Feature/Logistics/CodRemittanceFinancialWorkflowTest.php tests/Feature/Logistics/CodRemittanceCreationTest.php tests/Feature/Logistics/LogisticsRemittanceTest.php tests/Feature/Logistics/CodReconciliationTest.php tests/Feature/Console/OrdersResyncCommandTest.php tests/Feature/Rider/RiderDeliveryTest.php tests/Feature/Rider/DeliveryOtpTest.php tests/Feature/Rider/RiderMobileApiTest.php tests/Feature/Order/Rule02OrderStatusFlowTest.php tests/Feature/Seller/SellerPayoutRequestTest.php tests/Feature/Rider/RiderEarningsTest.php tests/Feature/Admin/AdminFinancePageTest.php tests/Feature/Logistics/OperationsAuditLogTest.php tests/Feature/Seller/SellerReturnDecisionTest.php tests/Feature/Logistics/CompleteFinancialScenariosTest.php tests/Feature/Admin/OrderMonitoringTest.php tests/Feature/Logistics/AutomaticBuyerReceiptTest.php
```

MySQL verification command:

```text
php vendor/bin/phpunit -c phpunit.mysql.xml tests/MySql/FinancialCalculationConcurrencyTest.php tests/MySql/LogisticsConcurrencyTest.php tests/MySql/CheckoutConcurrencyTest.php tests/MySql/SellerPayoutConcurrencyTest.php tests/MySql/RiderPayoutConcurrencyTest.php tests/MySql/CodRemittanceConcurrencyTest.php
```

Final isolated MySQL: **61 tests / 722 assertions passed** in the single combined command above. It includes the new financial class (15 datasets: seven concurrency cases, one rollback case and seven precision boundaries), actual Logistics receipt/rerouting races, checkout concurrency and the earlier Seller/Rider payout, refund/debt and COD race/rollback suites. The initial new financial class had also passed **7 tests / 52 assertions** before adding the pending-rate race and seven precision datasets. No final failing or skipped tests were reported in either final run.

`php vendor/bin/pint --dirty --format agent` completed and formatted audit-related PHP files; targeted Pint `--test` and `git diff --check` passed. No frontend files changed.

SQLite uses isolated in-memory databases. MySQL uses the existing preflight-verified disposable `zefanya_concurrency_test` database, restricted test account, server identity/grant/application-schema denial checks and InnoDB. Concurrency workers have independent connections; barriers observe overlapping lock statements (or both independent transactions held). The provider-chain financial test seeds synthetic received-handoff snapshots with matching hub/provider foreign keys; it does not claim a real-world scan or external handoff. The separate existing LogisticsConcurrency tests exercise actual receipt/rerouting services.

Search scope included routes/controllers/services/models/console commands and relevant migrations/tests. Runtime financial mutation paths found include checkout, normal/manual/seven-day completion, shared web/mobile Rider delivery, the two remittance services, return/refund/debt calculations, rate-card administration and historical shipping payout correction. No separate `app/Jobs` directory exists in this checkout. Setup seeders/migrations are not presented as exposed user endpoints and were not run against production.

Not verified: affected historical production-record counts, external transfers/cash custody, live browser actions, production configuration values or a full unrelated repository test suite. No production financial rows were inspected to infer a loss amount. No frontend build is needed for these PHP/test-only changes.

## 8. Final status

**PARTIALLY PROTECTED — REMAINING RISKS.** The three software defects are fixed and verified by **658 SQLite tests / 5,039 assertions** and **61 isolated MySQL tests / 722 assertions**, with passing formatting/diff checks. Historical rate retention and the 40/60 split are preserved. P1 and P2 remain explicitly unresolved because implementing their resolution requires a recognition/funding policy decision or an unsupported workflow; this audit did not introduce either. Production history was not repaired or certified as complete.
