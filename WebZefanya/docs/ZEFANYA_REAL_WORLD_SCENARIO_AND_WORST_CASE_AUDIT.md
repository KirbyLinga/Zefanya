# Zefanya Web: Real-World Scenario and Worst-Case Audit (Phase 1)

**Date:** 2026-11-16
**Scope:** `C:\ProjectEcommerce\WebZefanya`, the live tree that serves https://zefanya.shop
**Mode:** read-only. No application source, configuration, database records or migrations were changed for this audit. This report is the only file created.

**Method:**
- Source inspection of controllers, services, models, routes, policies and migrations.
- Read-only database counts and read-only `artisan` queries (`migrate:status`, config reads).
- Non-destructive HTTP status checks against the live site.
- A full run of the existing PHPUnit suite against an isolated in-memory SQLite database. `phpunit.xml` sets `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, `MAIL_MAILER=array`, `QUEUE_CONNECTION=sync` and `SESSION_DRIVER=array`.
- No browser testing and no concurrency testing against production were performed.

**Evidence labels used throughout:**

| Label | Meaning |
|---|---|
| **Verified Pass** | Covered by a test that passed in today's run, or demonstrated by a read-only check. |
| **Protected** | The code clearly enforces the rule (source-verified) but no test was run for this exact case today. |
| **Confirmed Failure** | The gap is demonstrated directly by code or by a test today. |
| **Potential Risk** | A plausible weakness in source that was not reproduced. |
| **Unverified** | Not enough evidence to decide. |
| **Not Applicable** | The scenario cannot occur in this system. |

**Findings marked "explorer":** these were gathered by read-only code explorers. The main ones were re-checked by hand against the cited lines; the rest were not.

---

## 1. Executive summary

**Overall verdict.** The core transactional paths are strong: checkout, parcel custody, COD money movement and refunds. They use row locks, one-time tokens, unique database keys and an append-only audit log. No P0 (critical) defect was confirmed. Nothing found allows unauthorized money movement, cross-account data access, duplicate payouts or duplicate refunds.

**Confirmed failures (demonstrated by code or by tests today):**
1. **No in-app path for statutory or warranty claims on a delivered shipment after the 7-day return window.** A return record can only be created for a failed delivery (`ParcelReturnService::create`, `app/Services/Logistics/ParcelReturnService.php:1082`). The Buyer is told to "contact support", but no Admin tool can open a case for a delivered shipment. (P1, consumer protection.)
2. **The 40/60 shipping split applies to every cross-provider parcel, including chains of three or more providers.** The approved rule is: one provider keeps 100%, two providers split 40/60, and three or more need an approved method. `CodRemittance::isCrossProvider()` (`app/Models/Logistics/CodRemittance.php:63-68`) and `currentOriginShareMinor()` (`:102-107`) do not check chain length. `ProviderAllocationService` only writes "pending_policy" audit rows. (P1, financial.)
3. **A Rider can file a "pickup failed" report on a parcel they have already collected.** `Rider\PickupAssignmentsController::reportFailure` (`app/Http/Controllers/Rider/PickupAssignmentsController.php:127-215`) checks ownership but not status. A first report notifies the Seller and Logistics of a failure that did not happen. Status is unchanged, and a second report is rolled back by the state graph. (P2.)
4. **The new cross-region address hold breaks four existing end-to-end tests.** Their fixtures ship to distant regions without the new Admin phone-check step. The tests pass with `ADDRESS_REVIEW_ENABLED=false` and fail with it on. (P2, test maintenance; the behavior change itself is intended.)

**Highest operational and security risks (not code defects):**
- **Admin login is password-only.** Two-factor login exists but is switched off by default (`config/admin.php:46`, `ADMIN_REQUIRE_2FA` unset), and its routes are not registered.
- **The live site runs on `php artisan serve`.** That is PHP's single-process development server, behind Cloudflare. It was confirmed in Windows Task Scheduler as "Zefanya Laravel Server".
- **The live tree has about 540 uncommitted changes and is being edited by more than one session at once.** That is how new test failures appeared during this audit.
- **Four full database dumps sit on the server disk** (`storage/app/backups/*.sql`). A further dump is in `C:\ProjectEcommerce\.backups`. None are web-reachable.

**Feature status on live (checked today, read-only):**

| Feature | Status | Evidence |
|---|---|---|
| PSGC address validation for saved Buyer addresses | **Enabled** | `BuyerAddressRules` / `BuyerAddressVerifier` accept list-picked addresses only |
| Cross-region address hold (Admin phone check before pickup) | **Enabled** | Migration `2026_11_16_000003` ran (batch 87); `order_address_reviews` exists with 0 rows; `address_review.enabled=true` |
| Buyer conduct review (alerts and manual decisions) | **Not enabled** | Migration `2026_11_16_000002` is **Pending**; `buyer_conduct_reviews` absent |
| Seller "Report Buyer" | **Enabled** | `buyer_conduct_reports` exists; 1 open report |
| COD three-strike restriction | **Enabled** | `cod_delivery_strikes` exists; 1 strike recorded |
| Restock of verified-resellable returns | **Enabled** | `returns.restore_verified_resellable=true` |
| Scheduler (auto-complete receipts, auto-approve returns, overdue alerts, conduct scan) | **Running** | Task "Zefanya Laravel Scheduler" runs `schedule:run` every minute; last run 17:12, result 0 |
| Admin two-factor login | **Off** | `config/admin.php:46` defaults to false; no 2FA routes registered |
| Debug mode | **Off** | `app.debug=false`, `APP_ENV=production` |

**Readiness.**
- **Capstone demonstration:** ready, with the caveats in §15.
- **Real customers and real money:** not ready until the P1 items in §12 are resolved, chiefly the hosting setup, Admin 2FA, change control, the statutory-claim path and the 3+ provider allocation.

---

## 2. Current system architecture and enabled features

**Stack.**
- Laravel web app plus a Sanctum API for the Buyer and Rider mobile apps.
- MySQL database `Zefanya`.
- Sessions are database-backed. The queue is `sync`, so notifications are sent inline. Mail goes over SMTP.

**Routes.** `bootstrap/app.php:25-38` loads `web.php`, `api.php`, `admin.php`, `seller.php`, `logistics.php` and `rider.php`. `buyer.php` is loaded from `AppServiceProvider.php:199`. Each role has its own guard and status middleware:

| Role | Guard and middleware |
|---|---|
| Admin | `auth:admin` plus `admin.role:*` (`routes/admin.php:50,55,84,110`) |
| Seller | `auth:seller, seller.approved` (`routes/seller.php:31`) |
| Logistics | `auth:logistics, logistics.approved` (`routes/logistics.php:36`) |
| Rider | `auth:rider, rider.active` (`routes/rider.php:25`) |
| Buyer | `auth:buyer, buyer.active` (`routes/buyer.php:62`) |
| API | `auth:sanctum` plus `api.role:*` with token abilities (`app/Http/Middleware/ApiRole.php:55-65`) |

**Order state machine.** All status changes go through `SellerOrderStatusService::transition()` (`app/Services/Seller/SellerOrderStatusService.php`).
- The legal graph is in `ALLOWED` (`:97-115`). Which role owns each hop is in `OrderActor::allowedTransitions()` (`app/Enums/OrderActor.php:24-85`).
- The status is re-read under `lockForUpdate`, a replay is a no-op, and a stale view is rejected.
- The only other status writers are `recordTransferMovement` (verified hub scans only) and `recordLoss` (Admin-confirmed). The explorer found no other writer of `READY_FOR_PICKUP` or `PICKED_UP` in `app/`.

**Money.**
- Integer centavos with bcmath (`app/Support/Money.php:11-28`); no floats in money paths.
- Commission is fixed at 10% (`COMMISSION_BPS = 1000`, `app/Services/Shared/CommissionFinalizationService.php:17`). It is frozen on the order at finalization and re-checked on replay.
- Cross-provider origin share is 40% (`CodRemittance::ORIGIN_SHARE_PERCENT`, `app/Models/Logistics/CodRemittance.php:23`).
- `OperationsAuditLog` refuses update and delete (`app/Models/Admin/OperationsAuditLog.php:59-90`).

**Scheduled tasks** (`routes/console.php`):

| Command | Frequency | What it does |
|---|---|---|
| `orders:auto-complete-receipts` | 5 min | Completes delivered orders after 168 h |
| `returns:auto-approve-overdue` | 5 min | Approves returns the Seller didn't answer within 3 days |
| `pickups:roll-missed-windows` | 5 min | Moves a missed pickup to the next window |
| `transfers:flag-overdue` | 15 min | Notifies only |
| `orders:escalate-preparation-overdue` | 15 min | Notifies the Seller once |
| `buyers:scan-conduct` | hourly | Does nothing until migration 000002 is applied |
| Sanctum and queue prune | daily | Housekeeping |

**Live data volume (read-only counts):** 4 Sellers, 4 Buyers, 6 Riders, 3 Logistics providers, 10 orders. The live site carries test and demo data, not real commerce.

---

## 3. Role-by-role workflow findings (summary)

| Role | Strong | Weak or missing |
|---|---|---|
| **Buyer** | Checkout: per-Buyer lock, one-time token, stock and price re-checked under lock; addresses and prices snapshotted on the order; PSGC-only saved addresses; COD 3-strike rule | Phone number ownership not verified; street not verifiable; statutory-claim path missing; auto-completion removes the return right if the Buyer never confirms |
| **Seller** | Cannot skip or reverse status hops; markReady is idempotent; address hold enforced in the service; report Buyer without penalty | Accepted-but-never-packed orders only trigger one Seller reminder; the Buyer cannot cancel after acceptance |
| **Logistics** | Scan tokens, manifests must be dispatched before receipt, partnerships required for cross-provider handoffs, lost parcels decided by Admin only | A manual "received at station" override (reason plus audit) stands in for a scan; there is no per-hub operator identity; payment entries need no proof |
| **Rider** | Exact COD amount, delivery photo, ownership checks, two-attempt delivery limit | No timeout for a pickup that is never collected; failed-pickup report has no status guard; a typed tracking number counts as a scan |
| **Admin** | Role-split money actions (Super Admin pays out); immutable audit; refund and payout idempotency | Password-only login; moderators can approve partnerships and lift COD restrictions; payout *reversals* need only Admin while payouts need Super Admin |

---

## 4. Scenario evaluations

### 4.1 Summary table

| ID | Scenario | Expected | Actual | Result | Severity | Recommendation |
|---|---|---|---|---|---|---|
| S01 | Buyer in Abra orders from a Manila Seller | Address confirmed before dispatch | Flagged at checkout (NCR→CAR not neighbours); pickup blocked until Admin records a confirmed call | Verified Pass (7 tests); not browser-tested | Low | Click through once on live with demo accounts |
| S02 | Valid PSGC codes, nonexistent street | Detect before dispatch | Only distant-region orders are phone-checked; a fake street is caught only by a failed delivery and COD strike | Potential Risk | Medium | Rider "address not found" reason and Admin review on first occurrence; optional landmark field |
| S03 | Buyer enters someone else's phone | Phone belongs to Buyer | Format-checked and unique per role; never verified by OTP or SMS | Potential Risk | Medium | SMS OTP when a provider exists; until then the address-check call confirms identity verbally |
| S04 | Buyer changes saved address after ordering | Order keeps the original address | Order stores a shipping snapshot; routing uses it | Protected | — | — |
| S05 | Buyer places several orders at once | No double stock or vouchers | Per-Buyer row lock serializes checkout | Protected | — | — |
| S06 | Buyer resubmits a slow checkout | One order | One-time checkout token claimed in the transaction; throttle 10/min | Verified Pass (API repeat-submit test) | — | — |
| S07 | Two Buyers want the last unit | One succeeds | Product and variant rows locked; stock re-checked under lock | Protected | — | — |
| S08 | Buyer cancels repeatedly | Admin review alert | Neutral reminder only; conduct review **not enabled** (migration pending) | Potential Risk (feature off) | Medium | Apply migration 000002 after review |
| S09 | Buyer refuses COD deliveries | Restrict after repeated refusals | 3 strikes in 90 days blocks COD checkout; per account and phone | Protected (enabled; 1 strike live) | — | — |
| S10 | Buyer requests returns repeatedly | Admin visibility | Return history shown to Admin; no alert while conduct review is off | Potential Risk (feature off) | Low | Same as S08 |
| S11 | Seller accepts but never packs | Escalation and Buyer remedy | One Seller reminder at 48 h; no Admin alert; Buyer cannot cancel | Potential Risk | Medium | Admin alert at 72 h; Buyer cancellation or support path after the deadline |
| S12 | Seller accepts an out-of-stock order | Impossible | Stock decremented at checkout; acceptance needs no stock | Not Applicable | — | — |
| S13 | Seller changes price after checkout | Order keeps the paid price | `order_items.price_minor` snapshot; price fingerprint checked under lock | Protected | — | — |
| S14 | Seller dispatches an address-held order | Blocked | `transition()` refuses READY_FOR_PICKUP and PICKED_UP while held; no other writer | Verified Pass | — | — |
| S15 | Seller double-clicks "Request pickup" | One request | Locked re-read; already-ready returns a warning | Protected | — | — |
| S16 | Seller sends wrong or damaged item | Buyer can return; Seller pays | Return flow; fault defaults to Seller; Admin can change who pays | Protected | — | — |
| S17 | Seller disputes a returned parcel | Admin resolves | Receipt dispute; Admin resumes or closes; refund decision left separate | Potential Risk | Low | Require a refund decision when closing |
| S18 | Seller files a false report on a Buyer | No automatic penalty | Report is review-only; no account change | Protected | — | — |
| S19 | Seller forces an invalid status change | Rejected | Role and graph gates under lock | Protected | — | — |
| S20 | Rider assigned but never collects | Reassign or escalate | Window rolls forward and keeps the same Rider; only an offline Rider is flagged | Potential Risk | Medium | Flag after N missed windows; auto-release |
| S21 | Rider confirms pickup twice | One pickup | Locked re-read; returns early if already picked up | Protected | — | — |
| S22 | Parcel scanned at the wrong hub | Rejected | Routed parcels: WrongHub hold; station receipt derives the hub from the route; another hub of the same provider is not distinguished | Potential Risk | Low | Per-hub operator identity |
| S23 | Parcel received without a dispatched manifest | Rejected | Receipt requires DISPATCHED status plus a per-item token | Protected | — | — |
| S24 | Parcel lost in an inter-provider handoff | Detected; responsibility clear | Overdue alerts; custody stays with sender until verified receipt; loss decided by Admin only | Protected | — | — |
| S25 | Logistics confirms custody without the parcel | Physical scan required | Manual override moves a picked-up parcel to "at station" with a reason (audited, custody-checked) | Potential Risk | Medium | Restrict override to Admin approval, or require a photo |
| S26 | Two Riders take the same job | One wins | Order row locked; already-assigned rejected | Protected | — | — |
| S27 | Rider completes delivery without evidence | Proof required | Photo required; COD must equal the amount due; scan token required, but a typed tracking number counts | Potential Risk | Medium | Buyer delivery code or OTP; flag manual entries |
| S28 | First failed delivery treated as final | Retry allowed | Two-attempt limit; first failure shown as "Retry Pending" | Protected | — | — |
| S29 | Rider reports pickup failure after collecting | Rejected | Accepted; Seller and Logistics get a false "pickup failed" notice | **Confirmed Failure** | Medium (P2) | Require READY_FOR_PICKUP in `reportFailure` |
| S30 | Shipping fee across 3+ providers | Needs approved method | 40/60 split applied to any cross-provider parcel regardless of chain length | **Confirmed Failure** (code) | High (P1) | Gate payout of the split to chains of exactly 2; hold 3+ for the approved policy |
| S31 | Rider remits less COD than collected | Shortage recorded and recovered | Shortage recorded and blocks Seller settlement; Rider pay not linked to shortage | Potential Risk | Medium | Block or net Rider payout while a shortage is open |
| S32 | Remittance submitted twice | One record | Rider COD: unique per payment and locked; Logistics payment reference checked under lock but no unique index | Protected / Potential Risk (low) | Low | Unique index or registry for the Logistics reference |
| S33 | Logistics records a payment never received | Evidence required | No proof attached; Admin confirms by typed amount | Potential Risk | Medium | Require proof upload on submit or confirm |
| S34 | Order Completed without COD collected | Not possible | Delivered requires exact COD; the Completed hop has no payment check, but payout still needs a remittance | Potential Risk (low) | Low | Assert payment collected before Completed |
| S35 | Seller earnings paid twice | One payout | Locked status checks; unique reference registry | Protected | — | — |
| S36 | Buyer refunded while earnings payable | Earnings reversed | Pending payout blocks; paid becomes Seller debt deducted later | Protected | — | — |
| S37 | Payout reversed by a lower role | Same authority as paying | Paying needs Super Admin; reversing needs only Admin | Potential Risk | Medium | Require Super Admin for reversals and historical reconciliation |
| S38 | Return outside the 7-day window | Refused; other remedies remain | Refused; a Buyer who never confirms receipt loses the ordinary return at auto-completion | Protected / Potential Risk (policy) | Medium | Count auto-completion as eligible within the window, or document it |
| S39 | Second return for one shipment | Refused | Unique DB key plus service checks | Protected | — | — |
| S40 | Refund processed twice | One refund | Locked `refunded_at` check; unique reference registry; void releases the reference | Protected | — | — |
| S41 | Statutory or warranty claim after the window | Some path exists | No record can be created for a delivered shipment; "contact support" leads nowhere in the app | **Confirmed Failure** | High (P1) | Admin "open warranty or statutory case" for delivered shipments |
| S42 | Return dispute or escalation never resolved | Deadline | No deadline on escalation or dispute | Potential Risk | Low | SLA alerts to Admin |
| S43 | Return parcel waiting at the Seller handover | Escalation | Handover code expires; Logistics can re-issue; no alert or Admin close in this state | Potential Risk | Low | Overdue alert for long AwaitingSeller or uncollected states |
| S44 | Buyer suspended with a refund open | Refund still payable | Admin refund paths ignore Buyer status (good); the Buyer cannot log in to escalate or request returns | Potential Risk | Medium | Warn Admin about open cases before suspending |
| S45 | Two Admins decide one conduct case | One decision | Locked; resolved case immutable; replay sends nothing | Verified Pass (tests); feature not enabled | — | — |
| S46 | Admin "confirms" an address without calling | Call actually made | System records a self-attested call result; cannot prove a call | Potential Risk (process) | Medium | Call log or telephony integration; second-person review for high-value orders |
| S47 | Notifications repeated on retry | Sent once | Conduct and address decisions return false on replay; nothing re-sent | Verified Pass | — | — |
| S48 | Stolen Admin password | Second factor required | Password-only; 2FA off and unrouted | Potential Risk (accepted setting) | High (P1) | Enable `ADMIN_REQUIRE_2FA` and register 2FA routes |
| S49 | Moderator performs high-impact actions | Restricted | Moderators can approve, re-prioritise and revoke provider partnerships, lift COD restrictions and set Rider wind-down | Potential Risk | Medium | Confirm policy; limit to Admin or Super Admin |
| S50 | Server disk exposes data | No stray dumps | 4 full SQL dumps in `storage/app/backups`, plus `C:\ProjectEcommerce\.backups`; not web-reachable | Potential Risk | Medium–High | Encrypt or move off-server; retention policy |
| S51 | IDOR across roles | Denied | Owner-scoped queries and policies on every sampled endpoint | Protected | — | — |
| S52 | Traffic spike or slow request | Site stays up | Single-process `php artisan serve` behind Cloudflare; requests queue | Potential Risk (operational) | High (P1) | Run under a real web server (nginx or Apache with PHP-FPM, IIS FastCGI) |
| S53 | Live code changed by concurrent sessions | Controlled releases | About 540 uncommitted changes in the live tree; new test failures appeared during the audit | Potential Risk (change management) | High (P1) | Commit, branch and deploy from a reviewed tree |
| S54 | Security headers and fingerprinting | Hardened | HSTS, X-Frame-Options, nosniff present; CSP report-only; `X-Powered-By: PHP/8.5.9` | Potential Risk | Low | Enforce CSP; set `expose_php=Off` |

### 4.2 Detailed evaluations

Each entry gives the roles involved, the sequence of events, the evidence, and the verification status. Severity and recommendations are in the table above.

**S01 — Abra Buyer, Manila Seller (Buyer, Seller, Admin).**
- **Sequence:** the Buyer picks Abra from the PSGC lists, checks out, and the Seller accepts and packs.
- **Evidence:**
  - Regions are the first two digits of the province code: 13 (NCR) and 14 (CAR) are not neighbours (`config/address_review.php`).
  - `OrderAddressReviewService::flagOrder()` is called inside checkout (`app/Services/Buyer/CheckoutService.php`, after `planRoutesForOrder`). The hold is enforced inside `transition()` for READY_FOR_PICKUP and PICKED_UP.
  - `tests/Feature/Admin/OrderAddressReviewTest.php` passed today (7 tests). `DeliveryScenarioSeederTest` "Batangas to Davao" now includes the Admin call step and passed.
- **Caveats:**
  - Orders placed before the code existed (#5–#10) have no review row and are not held. That's by design.
  - Same-region and neighbouring-region orders are never phone-checked.
- **Status:** Verified Pass (automated tests only; not browser-tested).

**S02 — Valid codes, fake street (Buyer, Rider).**
- `BuyerAddressVerifier` checks only the province, city and barangay chain (`app/Support/BuyerAddressVerifier.php`).
- A Rider's "wrong address" failure counts toward the COD strike only after the final attempt (`CodRiskService::BUYER_FAULT_REASONS`, `app/Services/Buyer/CodRiskService.php:45-50`).
- The business pays the failed trips until then.
- **Status:** Potential Risk.

**S03 — Someone else's phone (Buyer).**
- `PhilippineMobile::rules()` checks format, and uniqueness per role is enforced at registration and profile update (`StoreBuyerRegistrationRequest.php:50-68`, `Buyer/AccountController.php:100-104`).
- Registration OTP is sent by email, not SMS. No SMS provider is configured.
- **Status:** Potential Risk.

**S04 — Address edited after order.** `CheckoutService::addressSnapshot()` (`:516-541`) stores name, phone and all codes on the order. Routing reads the snapshot (`SellerOrder::buyerBarangayCode()`, `app/Models/Shared/SellerOrder.php:380-392`). **Status:** Protected.

**S05 / S06 / S07 — Simultaneous orders, resubmission, last unit.**
- Per-Buyer `lockForUpdate` (`CheckoutService.php:144`) and `CheckoutToken::claim()` (`:152`).
- Locked products, variants, sellers and sales, with stock and price re-checked (`:160-245`). Route throttle 10/min (`routes/buyer.php:88`, `routes/api.php:114`).
- The repeat-submit test `BuyerCommerceApiTest::test_a_repeated_checkout_submit_cannot_create_a_second_order` passed today.
- **Status:** S06 Verified Pass; S05 and S07 Protected.

**S08 / S10 — Repeat cancellations and returns.**
- `BuyerCancellationHistory::notice()` gives a neutral reminder (`app/Services/Buyer/BuyerCancellationHistory.php`). Return history is shown on Admin return cases (`app/Services/Admin/BuyerReturnHistoryService.php`).
- The thresholds (5 cancellations in 30 days, 3 Admin-upheld rejections in 90 days) live in `BuyerConductReviewService` but do nothing until migration 000002 runs. The hourly scan exits early.
- **Status:** Potential Risk (built, not enabled).

**S09 — COD refusals.** `CodRiskService::STRIKE_LIMIT = 3` within `WINDOW_DAYS = 90`, by account and by phone. It is checked before checkout (`CheckoutService.php:140`). **Status:** Protected (enabled; 1 strike on live).

**S11 — Accepted, never packed (Seller, Buyer).**
- `PreparationDeadlineService::escalateOverdue()` notifies the Seller once (`app/Services/Seller/PreparationDeadlineService.php`).
- The Buyer can cancel only while the order is PLACED (`OrderActor.php:74`). No Admin alert, no auto-cancel.
- **Status:** Potential Risk.

**S13 — Price change.** `OrderItem` stores `price_minor` and `product_name` (`CheckoutService.php:383-390`). A locked price mismatch aborts checkout (`:231-234`). **Status:** Protected.

**S14 / S15 — Held dispatch, duplicate pickup request.**
- The hold check runs in `transition()` before the role gate. The explorer confirmed no other code path writes READY_FOR_PICKUP or PICKED_UP.
- `Seller\OrderController::markReady` re-reads under lock and returns "already requested" (`app/Http/Controllers/Seller/OrderController.php:241-288`).
- **Status:** S14 Verified Pass; S15 Protected.

**S17 — Receipt dispute (Seller, Logistics, Admin).**
- `disputeReceiptBySeller` (`ParcelReturnService.php:873-903`). `closeByAdmin` (`:806-822`) closes without restock, refund or charge, and leaves the refund to be recorded separately.
- **Status:** Potential Risk (explorer; close-path lines re-checked).

**S20 — Pickup never collected.**
- `PickupSessionService` rolls the window and keeps the courier (`app/Services/Logistics/PickupSessionService.php:189-219`). Only an offline Rider sets `needs_reassignment` (`RiderAvailabilityService.php:45-53`).
- **Status:** Potential Risk (explorer).

**S22 — Wrong hub.**
- Station receipt hub comes from `requireOriginStationHubId` (`Logistics/IncomingParcelsController.php:286-296`).
- Manifest and handoff receipts are bound to the destination hub (`ManifestService.php:669-675`, `ParcelHandoffService.php:490-496`).
- The WrongHub incident runs only for routed parcels (`ParcelIncidentService.php:318-335`).
- **Status:** Potential Risk (explorer).

**S25 — Custody without receipt.**
- `LogisticsStatusOverrideService::override()` (`app/Services/Logistics/LogisticsStatusOverrideService.php:41-100`) allows PICKED_UP → AT_SORTING_CENTER with a reason of 10+ characters.
- It does check custody ownership and pending recovery (`assertPhysicalState`, `:109-131`). It records origin custody (`receiveAtOrigin`) and writes `shipment_status_overridden` to the audit log.
- This is a deliberate fallback, but it needs no physical evidence.
- **Status:** Potential Risk (re-checked).

**S27 — Delivery evidence.**
- Photo required (`CompleteDeliveryRequest.php:32`). Exact COD amount required (`RiderOperationsService.php:601-608`).
- A typed tracking number is accepted as verification (`ParcelHandoffVerification.php:414`). There is no Buyer delivery code.
- **Status:** Potential Risk (explorer).

**S29 — Pickup failure after collection.**
- `reportFailure` checks ownership only (`assertPickupAssigned` → `assertPickupOwnership`, `RiderOperationsService.php:482-525`).
- It then records attempt #1 and notifies (`PickupAssignmentsController.php:127-215`). No status condition was found in the code path read.
- **Status:** Confirmed Failure (code, re-checked). Not reproduced by a test.

**S30 — 3+ provider allocation.**
- `isCrossProvider()` compares only `carrier_provider_id` with the collecting provider (`CodRemittance.php:63-68`). The 40% origin share is applied (`:102-107`).
- `ProviderAllocationService::policyApproved()` and `payoutsEnabled()` are not called from money paths (explorer).
- This contradicts the approved allocation rule for 3+ provider chains.
- Whether 3+ chains currently occur on live routes is Unverified. Today's suite covers an intermediate-provider route (`RoutedTransitionsTest`).
- **Status:** Confirmed Failure (code).

**S31–S37 — Money.** Locations come from the explorer; S37 was re-checked.

| Item | Evidence |
|---|---|
| Shortage recorded | `CodRemittanceService.php:65-105` |
| Settlement held for a shortage | `:109-111` |
| Rider payout doesn't read shortages | `RiderEarningService::payCourier` (`:165-220`) |
| Logistics payment reference | `LogisticsRemittanceService::submit` (`:343-403`) |
| Admin confirm | `:412-475` |
| Paying needs Super Admin | `FinancialApproval.php:15-17` |
| Reversal needs only Admin | `reverseOriginPayout` (`LogisticsRemittanceService.php:509-511`), `reconcileHistorical` (`:263-265`) |

**S38–S44 — Returns.** Locations come from the explorer; S41 and S43 were re-checked.
- Window: 168 h from verified delivery, and the Buyer must have confirmed receipt (`ParcelReturnService.php:76-100`).
- Unique return per shipment (`parcel_returns.seller_order_id` unique).
- Refund idempotency (`:338-409`, `FinancialReferenceRegistry`).
- Record creation requires a failed delivery (`:1082`). Admin close is limited to uncollected or receipt-disputed returns (`:818-819`).
- Refund actions don't read Buyer status. Buyer routes require `buyer.active`.

**S45–S47 — Conduct and address reviews.**
- `BuyerConductReviewService::decide()` and `OrderAddressReviewService::decide()` lock the row and reject a second decision. Notifications are sent only by the request that changed the state.
- `BuyerConductReviewTest` (11) and `OrderAddressReviewTest` (7) passed today.
- Call results are entered by the Admin; the system cannot verify a call took place.
- **Status:** Verified Pass (S45, S47); Potential Risk (S46).

**S48–S54 — Security and operations.**
- 2FA config: `config/admin.php:40-46`.
- Partnership controller has no role check, and its route group is `admin.role:admin,moderator` (`routes/admin.php`, partnership routes).
- Dumps listed in `storage/app/backups/`.
- Hosting: Windows tasks "Zefanya Laravel Server" (`php artisan serve --host=127.0.0.1 --port=8000`, Running) and "Zefanya Laravel Scheduler".
- Response headers checked live today.

---

## 5. Four delivery-route analyses

These map your four scenarios onto what the code does. The automated end-to-end tests use comparable seeded routes, not the exact towns:
- Pila → Pila
- Lumban → Pila
- Bulacan → Quezon City
- Batangas → Davao (`tests/Feature/Logistics/DeliveryScenarioSeederTest.php:51-57`)

**No browser end-to-end run was performed for any route.**

| Step | A: Los Baños → Los Baños | B: Los Baños → Santa Cruz | C: Laguna → Malolos | D: Laguna → Davao City |
|---|---|---|---|---|
| 1. Buyer address validation | PSGC chain required for new/edited addresses | Same | Same | Same |
| 2. Seller checkout eligibility | Seller enabled providers must cover pickup and destination barangay (`CheckoutCarrierService`) | Same | Cross-province needs an approved directed provider link | Same, plus inter-island legs |
| 3. Coverage and routing | Single station; no transfer | Station to station within one provider (ROAD) | Handoff to destination provider (40/60 split) | ROAD → SEA → ROAD via port gateways |
| 4. Address review | Not held (same region) | Not held | Not held (CALABARZON and Central Luzon are neighbours) | **Held** (CALABARZON → Davao Region); Admin call required |
| 5. Seller acceptance | Accept and pack | Same | Same | Same; pickup blocked until confirmed |
| 6. Pickup and custody | Pickup Rider; scan token; station receipt | Same | Same | Same, after release |
| 7. Station and hub processing | Sort at origin, which is also the destination | Origin station → destination station | Origin hub → handoff | Origin → port gateway → destination gateway → station |
| 8. Transfer manifests | None | Internal manifest | Handoff with dispatch and receipt scans | Manifests and handoffs per leg |
| 9. Delivery attempts | Max 2; first is "Retry Pending" | Same | Same | Same |
| 10. COD collection | Exact amount and photo | Same | Same | Same |
| 11. Rider → Logistics | Remittance with shortage tracking | Same | Same (collecting provider) | Same |
| 12. Logistics → platform | Logistics remittance; Admin confirm by amount | Same | Collector keeps 60% of shipping | Same; 40/60 even if 3+ providers (S30) |
| 13. Completion | Buyer confirms or auto-completes at 168 h | Same | Same | Same |
| 14. Returns and refunds | Standard return flow | Same | Reverse handoff needs a reverse partnership or Admin authorization | Same |
| 15. Notifications and audit | Status history, operations audit, role notifications | Same | Same | Same, plus address-check notices |

**Test results today for the comparable routes:**
- `DeliveryScenarioSeederTest`: all four datasets passed.
- `CompleteFinancialScenariosTest`: all four failed. That test inherits from `DeliveryScenarioSeederTest`, and the failure is an unrelated rendering error in `resources/views/Logistics/CodRemittances/index.blade.php` (`Undefined variable $collected`). That view was edited by a concurrent session today.
- `EndToEndLogisticsStabilizationTest` "Luzon to Mindanao": fails only while the address hold is on, because its fixture lacks the Admin call step.

---

## 6. Financial and COD integrity

**Protected:**
- Payout and refund idempotency.
- Seller debt after refund.
- Exact COD at delivery.
- Locked commission.
- Integer money.
- Immutable audit.

**Findings:**

| ID | Finding | Status | Priority |
|---|---|---|---|
| S30 | 40/60 applied to 3+ provider chains | Confirmed Failure (code) | P1 |
| S37 | Reversal and historical reconciliation require less authority than payout | Potential Risk | P2 |
| S31 | Rider pay not blocked by an open COD shortage | Potential Risk | P2 |
| S33 | Logistics payment entries carry no evidence | Potential Risk | P2 |
| S32 | Logistics payment reference has no unique index | Potential Risk | P3 |
| S34 | Completed hop has no payment assertion (payout still gated) | Potential Risk | P3 |

**Commission:** 10% fixed in code, not configurable (`CommissionFinalizationService.php:17`). It is computed on the subtotal minus Seller-funded discounts, excluding shipping.

---

## 7. Returns, refunds and disputes

**Protected:**
- One return per shipment.
- Refund-once guarantee.
- Seller silence auto-approves after 3 days (scheduler confirmed running).
- Escalation is Buyer-initiated and once only.
- Restock is idempotent and only for "resellable".

**Findings:**
- **S41** — statutory or warranty claim path missing (Confirmed Failure, P1).
- **S38** — auto-completion removes the ordinary return right when the Buyer never confirms (Potential Risk, policy, P2).
- **S44** — suspended Buyer cannot escalate or request (P2).
- **S42 / S43** — no SLA alerts for disputes or a long Seller handover (P3).
- **S17** — closing a receipt dispute leaves the refund decision unrecorded (P3).
- **Restock failure** (explorer): `receiveBySeller` completes even if restock fails, and the recovery path `releaseVerifiedReturnStock` refuses partial ledgers. That's a manual dead end (P3).

---

## 8. Buyer conduct and address verification

| Item | Status |
|---|---|
| Conduct review code | Built and tested (11 passing tests); **not enabled** (migration 000002 pending) |
| Seller reports | Enabled; with conduct review off, Admin decides through the original report form |
| Address hold | **Enabled** on live; 0 reviews so far |
| Address hold enforcement | Server-side in `transition()` |
| Seller and Buyer hold notices | UI only; the backend refuses regardless |
| PSGC enforcement | Enabled for all new and edited Buyer addresses; existing addresses not re-checked |
| COD 3-strike | Enabled and unchanged |

**Audit questions from the brief:**

| # | Question | Answer | Status |
|---|---|---|---|
| 1 | Two Admins decide the same case | Locked and immutable | Verified Pass |
| 2 | Duplicate alerts | Unique open key per Buyer and category | Verified Pass |
| 3 | Buyer warned before Admin approval | Alerts never notify the Buyer | Verified Pass |
| 4 | Seller sees confidential Buyer information | Seller sees only "Closed — reviewed / no action" | Verified Pass |
| 5 | Unauthorized staff issue a restriction | Support role gets 403 | Verified Pass |
| 6 | Admin confirms an address without calling | Self-attested call result | Potential Risk |
| 7 | Confirmation applied to the wrong order | One review per shipment (unique `seller_order_id`) | Protected |
| 8 | Held order enters pickup another way | No other writer found | Protected |
| 9 | Repeated notifications after a retry | Not re-sent | Verified Pass |
| 10 | Suspension leaves orders or refunds stuck | Admin refunds still work; the Buyer is locked out of self-service | Potential Risk |

---

## 9. Security and authorization

**Protected:**
- Per-role guards.
- Owner-scoped queries and policies across Buyer, Seller, Rider and Logistics (explorer sampled at least 3 endpoints per role).
- No CSRF exemptions.
- Sanctum abilities per role with 60-minute tokens.
- Sensitive uploads on the private disk, served through authorized controllers.
- No mass-assignment from raw request data.
- Login, OTP and checkout throttles.
- Notification queries scoped to the signed-in account.

**Live HTTP checks (status codes only, no content read):**
- 404: `/.env`, `/.git/config`, `/storage/logs/laravel.log`, `/phpinfo.php`, `/composer.json`, `/backup.sql`, `/_ignition/health-check`, `/telescope`.
- Present: HSTS, `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy`.

**Findings:**

| ID | Finding | Status | Priority |
|---|---|---|---|
| S48 | Admin login is password-only (2FA off and unrouted) | Potential Risk | P1 |
| S50 | Full DB dumps on server disk (not web-reachable) | Potential Risk | P1 |
| S49 | Moderators can approve, re-prioritise and revoke provider partnerships and lift COD restrictions | Potential Risk | P2 |
| — | Moderator access to legacy refund proofs: `Admin/ReturnController.php:256-258` checks finance roles only when `refund_policy_version === 1` | Potential Risk (explorer) | P2 |
| — | Competing providers in one barangay can view unclaimed parcels (`SellerOrderFleetPolicy.php:50`) | Potential Risk, confirm intent | P3 |
| — | No per-account lockout beyond 5/min per email+IP | Potential Risk | P3 |
| S54 | CSP report-only; PHP version header | Potential Risk | P3 |
| — | Local disk `serve => true` (signed URLs only; app mints none) | Protected; turn off for defense in depth | P3 |

---

## 10. Verified passes versus confirmed failures

**Verified Pass:** S01, S06, S14, S45, S47, plus §8 items 1–5 and 9. Covered by tests that passed today:

| Test file | Tests |
|---|---|
| `OrderAddressReviewTest` | 7 |
| `BuyerConductReviewTest` | 11 |
| `DeliveryScenarioSeederTest` | All 4 routes |
| `BuyerCommerceApiTest` repeat-submit | 1 |
| Address-book and verifier tests | All |

**Confirmed Failure:**

| ID | Failure | Demonstrated by |
|---|---|---|
| S41 | No in-app path for statutory or warranty claims after the window | Code |
| S30 | 40/60 split applied to 3+ provider chains | Code |
| S29 | Rider can report a pickup failure after collecting | Code |
| §11 | Address hold breaks 4 existing test fixtures | Test, hold on vs off |

**Protected (source only, not re-tested today):** S04, S05, S07, S09, S13, S15, S16, S18, S19, S21, S23, S24, S26, S28, S35, S36, S39, S40, S51.

**Not Applicable:** S12.

---

## 11. Test coverage and limitations

**Run today:** full suite, isolated in-memory SQLite, 22 minutes.

| Result | Count |
|---|---|
| Tests | 4,382 |
| Passed | 4,253 |
| Failed | 114 |
| Errors | 10 |
| Skipped | 5 |

**Where the failures cluster (by file):**

| Test file | Count | Likely cause |
|---|---|---|
| `SellerReturnDecisionTest` | 44 | Refund staging ("Authorize this refund and submit payment evidence before confirming it.") |
| `PlatformFundedRefundTest` | 14 | Same |
| `ReturnRefundAccountingTest` | 9 | Same |
| `ReturnRefundApprovalPolicyTest` | 8 | Same |
| `CodReconciliationTest` | 5 | Concurrent-session view edit |
| `CompleteFinancialScenariosTest` | 4 | Concurrent-session view edit (`$collected`) |
| Others | Remainder | See below |

**Most failures predate this audit.** They match the earlier failing list and come from refund-staging tests that expect the old single-step refund. The refund code now requires authorization and evidence before confirming. That is a test-maintenance backlog, not an observed live defect, but it means **the refund area currently lacks a passing regression suite**.

**30 failures are new since the earlier baseline:**

| Count | Cause | Evidence |
|---|---|---|
| 11 | Concurrent-session edit to `Logistics/CodRemittances/index.blade.php` | `Undefined variable $collected` |
| 14 | Pages whose markup changed, mostly the concurrent UI work on Admin returns, Buyer account tabs and the Logistics sidebar | `assertSee` failures |
| 4 | Cross-region address hold | Pass with `ADDRESS_REVIEW_ENABLED=false`: `EndToEndLogisticsStabilizationTest` "Luzon to Mindanao" and 3 `RoutedTransitionsTest` cases |
| 1 | Not attributed | — |

**Limitations:**
- No browser or manual end-to-end testing of any route on live.
- No concurrency testing against production. Concurrency safety is inferred from locks in source and from tests.
- No Flutter or mobile client testing.
- Explorer findings not marked "re-checked" were not personally re-verified line by line.
- The live tree changed during the audit through another session, so results can drift.

---

## 12. Prioritized findings

**P0 — Critical.** None confirmed. No path to unauthorized money movement, cross-account access, duplicate payout or refund, or invalid custody transition was demonstrated.

**P1 — High:**
1. **S52** — Production served by `php artisan serve`. Availability and request serialization risk.
2. **S53** — Live tree uncommitted and concurrently edited; no release control.
3. **S48** — Admin password-only login.
4. **S30** — 40/60 split applied to 3+ provider chains (Confirmed Failure).
5. **S41** — No statutory or warranty claim path for delivered shipments (Confirmed Failure).
6. **S50** — Full DB dumps on server disk.

**P2 — Medium:**
- S29 (Confirmed Failure)
- §11 hold-broken test fixtures (Confirmed)
- S11, S20, S25, S27, S31, S33, S37, S38, S44, S46, S49
- Refund-proof role check for legacy rows
- Refund regression suite out of date

**P3 — Low:** S17, S22, S32, S34, S42, S43, S54, restock dead end, per-account lockout, local disk `serve`.

---

## 13. Relevant source files

| Area | Files |
|---|---|
| State machine | `app/Services/Seller/SellerOrderStatusService.php`, `app/Enums/OrderActor.php`, `app/Enums/OrderStatus.php` |
| Checkout | `app/Services/Buyer/CheckoutService.php`, `app/Services/Buyer/CheckoutCarrierService.php`, `app/Models/Buyer/CheckoutToken.php` |
| Address | `app/Support/BuyerAddressRules.php`, `app/Support/BuyerAddressVerifier.php`, `app/Services/Admin/OrderAddressReviewService.php`, `config/address_review.php` |
| Conduct | `app/Services/Admin/BuyerConductReviewService.php`, `app/Services/Admin/BuyerConductReportService.php`, `app/Services/Buyer/BuyerCancellationHistory.php`, `app/Services/Buyer/CodRiskService.php` |
| Logistics | `CourierAssignmentService.php`, `ManifestService.php`, `ParcelHandoffService.php`, `LogisticsStatusOverrideService.php`, `PickupSessionService.php`, `StuckTransferService.php`, `ParcelIncidentService.php` (all in `app/Services/Logistics/`); `app/Services/Rider/RiderOperationsService.php`; `app/Http/Controllers/Rider/PickupAssignmentsController.php` |
| Money | `app/Models/Logistics/CodRemittance.php`, `CodRemittanceService.php`, `LogisticsRemittanceService.php`, `SellerSettlementService.php`, `SellerPayoutRequestService.php`, `CommissionFinalizationService.php`, `ProviderAllocationService.php`, `app/Support/FinancialApproval.php`, `FinancialReferenceRegistry.php` |
| Returns | `app/Services/Logistics/ParcelReturnService.php`, `app/Models/Logistics/ParcelReturn.php`, `config/returns.php` |
| Security | `routes/*.php`, `bootstrap/app.php`, `app/Policies/*`, `app/Http/Middleware/ApiRole.php`, `config/admin.php`, `config/filesystems.php` |
| Scheduler | `routes/console.php`; Windows tasks "Zefanya Laravel Scheduler" and "Zefanya Laravel Server" |

---

## 14. Recommended corrective-action plan

Ordered by risk and dependency. Each step is a focused change. None of these has been implemented, and each WebZefanya change needs approval first.

1. **Stabilize operations (P1, no app code):**
   - Commit the current tree to a branch and stop concurrent edits of the live folder.
   - Move serving to a real PHP web server (PHP-FPM behind nginx, or IIS FastCGI).
   - Move or encrypt the SQL dumps and set a retention policy.
2. **Admin 2FA (P1):** register the existing `TwoFactorController` routes and middleware, then set `ADMIN_REQUIRE_2FA=true`. Enrol every Admin before enforcing.
3. **3+ provider allocation (P1):** limit the 40/60 split to exactly-two-provider chains. Hold or flag 3+ chains for the approved policy. Add a test.
4. **Statutory and warranty case path (P1):** add an Admin-only action to open a case for a delivered shipment outside the window, reusing the existing refund and exception-basis machinery. Add a test.
5. **Small guards (P2):**
   - Status check in `reportFailure` (S29).
   - Super Admin for payout reversals and historical reconciliation (S37).
   - Role limits on partnership actions and COD lifts (S49, after confirming policy).
   - Finance-role check for all refund proofs.
6. **Operational alerts (P2):** Admin alerts for accepted-not-packed orders (S11), repeatedly missed pickups (S20), and long-pending disputes or handovers (S42, S43). Warn Admin about open cases before suspending a Buyer (S44).
7. **Evidence (P2):**
   - Proof upload for Logistics payment entries (S33).
   - Flag manual tracking-number entries and consider a Buyer delivery code (S27).
   - Require a photo for the manual station override (S25).
8. **Tests (P2):** update the 4 hold-affected fixtures with the Admin call step. Bring the refund-staging tests (about 80) in line with the current flow so refunds regain a passing regression suite.
9. **Enable conduct review (P2):** apply migration 000002 once its manual checks are done.
10. **Hardening (P3):** enforce CSP, set `expose_php=Off`, add per-account lockout, and set local disk `serve=false`.

---

## 15. Production and capstone-readiness assessment

**Capstone readiness: ready to demonstrate, with caveats.**
- The four delivery routes have seeded, passing end-to-end tests.
- Demo route D (to Davao) now requires an Admin phone-check step before pickup.
- Run the demo against demo accounts only.
- Explain to the panel that the refund regression tests are out of date, and that conduct review is built but not yet switched on.

**Production readiness (real Buyers and real money): not yet.** Transactional safety is strong, but:
- Hosting is a development server.
- Admin access is password-only.
- Releases are uncontrolled.
- Two confirmed policy gaps remain: the 3+ provider allocation and the statutory-claim path.

Resolve the P1 list in §12 before taking real customers.
