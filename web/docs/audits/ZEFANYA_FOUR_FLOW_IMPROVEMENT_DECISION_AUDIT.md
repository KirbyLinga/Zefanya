# Zefanya — Four-Flow Logistics & Financial Improvement Decision Audit

**Date:** 2026-11-14 · **Project:** `C:\ProjectEcommerce\WebZefanya` · **Mode:** read-only audit. No application code, test fixture, migration, database write, live transaction, commit, push, deploy, stash or reset was performed. This file is the only file created.

This is a *decision* audit: it re-verifies the previous audit (`ZEFANYA_COMPLETE_LOGISTICS_RETURNS_FINANCIAL_ARCHITECTURE_AUDIT.md`, "prior audit") against current source, adds new findings, compares alternatives, and ends with what to approve, change or build next. It does not repeat the prior audit's flow matrices; read that file for step-by-step tables.

Evidence labels: **CODE** (source opened, `file:line`), **DB** (read-only `SELECT`), **OS** (read-only Windows service/scheduled-task query), **TEST** (a test run earlier in this engagement; I did not re-run suites for this document), **WEB** (public page; third-party sources labelled), **DOC** (project docs, not re-verified), **UNKNOWN / BLOCKED** (not verified; never assumed). Money figures that I compute are **arithmetic on the live settings**, not measured costs, and are labelled as such. I invent no rates, legal deadlines or approved policies.

---

## 1. Executive summary

**Bottom line.** Zefanya's *mechanics* (hub graph routing, QR/OTP handoffs, derived station inventory, custody-based returns, centavo-exact refunds with evidence) are stronger than its *economics* and its *operating environment*. The three problems that most limit a real launch are not in the parcel code:

1. **The shipping fee cannot pay for the longer routes.** Every route is priced from one flat fee (₱50 for ≤2 kg, +₱20/kg) while a Rider is paid a flat ₱10 for pickup and ₱30 for delivery (live settings). On a ≤2 kg parcel that leaves ₱10 for everything else on the same-provider route and ₱0 of destination margin on the two-provider route *before* hubs, transfer couriers or any sea/air leg (§6). Provider rate cards already exist in the database and are ignored by the pricing code (§6, F-02).
2. **Production is a developer workstation.** `zefanya.shop` is PHP's built-in `artisan serve` on `127.0.0.1:8000` behind a Cloudflare tunnel, reading the same working tree, `.env`, MySQL database and `storage/` that people and AI agents edit. Queue is `sync`, mail is live SMTP, time-based consumer protections depend on one PC's scheduled task, backups are manual and **database-only** while 331 private evidence files (IDs, permits, delivery/return photos) live outside the SQL dump (§8).
3. **Flows 3 and 4 do not exist as operable routes** on live data (no Cavite or Cebu provider, 0 partnerships) and Flow 4 lacks a carrier/insurance/pricing model (§3).

**Corrections to the prior audit** (honesty items): (a) **F-20 was wrong** — the Logistics station *receipt scan already auto-sorts at the parcel's final hub* (`IncomingParcelsController.php:333-339`, `ParcelSortingService::sortAfterStationReceipt` `:89`); I had relied on documentation. (b) The prior audit said "23 findings"; the IDs run F-01…F-23 but **F-08 and F-11 were never assigned**, so there are **21** findings. (c) F-06 overstated: some time-based protections do exist (§2).

**What I recommend approving, changing, building next** (full list §16): approve a **release-separation plan** and a **pricing decision** before any feature work; change the **refund/return rules to carry timers and an Admin overdue queue**; build **custody events + manifest-connection validation**, then the **external-carrier extension**; defer double-entry accounting, new Rider account types and a third hub tier.

---

## 2. Validation of the prior audit's findings (against current source)

Classes: **Confirmed**, **Already addressed**, **Partially addressed**, **Unsupported**, **Requires investigation**, **Requires owner decision**. "Today" = checked in this session.

| ID | Prior claim | Verdict | Evidence today / change since |
|---|---|---|---|
| F-01 | Working tree is production; custody rule live | **Confirmed — expanded** | OS: scheduled task *Zefanya Laravel Server* runs `php artisan serve --host=127.0.0.1 --port=8000` (boot trigger, restart count 3, dir `C:\ProjectEcommerce\WebZefanya`), *Cloudflared* service uses a token file; live GET showed uncommitted contact-page text. New: uncommitted `Api/Rider/JobController` now returns `return_pickups` read-only on the live API (CODE `JobController.php:48-58`). Prior browser audit §12 row 3 says the custody fix is "verified only in staging" — **that is inaccurate; there is no staging** (§8). |
| F-02 | Flat fee ignores zone/mode; rate cards ignored | **Confirmed — refined** | `ShippingRateService::resolveRate` `:54-57` returns `fixedRate()` regardless of card; the checkout *does* request a zone (`CheckoutCarrierService.php:483,500`: `cross_province`, `same_municipality`, `same_province`) and snapshots per-zone rates, so zone plumbing exists. DB: 3 stored rate rows (one per zone) are never used. There is **no inter-island zone** in `LogisticsShippingRate::ZONES` (`:16-20`). |
| F-03 | No third-party carrier entity/insurance/declared value | **Confirmed** | `TransportConnection.operating_provider_id` → `LogisticsProvider` (`TransportConnection.php:34-40,55-57`); DB table list has no carrier/booking tables; no declared-value column. |
| F-04 | No Cavite provider; 0 links live | **Confirmed** | DB: live = 1 operational provider (Ninja Express), `davao logistics` approved with **no hubs**, 0 links. Demo DB: Cebu present (provider 26), no Cavite. |
| F-05 | Custody is a mutable pointer, no append-only history | **Confirmed** | `custody_provider_id/custody_hub_id` forced in 11 files (grep); DB table list shows no custody/movement table. |
| F-06 | No inaction timers | **Partially addressed** | Exist (CODE `routes/console.php:20-36`, `ParcelReturn.php:19`): Seller 3-day decision → auto-approve; buyer auto-receipt 168 h; missed pickup windows; overdue transfers (warn sender, then Admin); overdue order preparation; buyer-collection failures escalate to Admin after the attempt limit. COD overdue is **displayed** (`LogisticsRemittanceService::OVERDUE_AFTER_DAYS = 3`, `:41,82`) but has no notification job. **Still absent:** unassigned/uncollected approved return, station-receipt delay, Seller non-receipt, authorized-but-unpaid refund, any refund deadline. |
| F-07 | 7-day window vs consumer law | **Requires owner decision** | `WAIT_HOURS=168` unchanged; project's own policy review §4.1 flags it for counsel. I make no legal conclusion. |
| F-09 | Rider API lacks return actions | **Partially addressed (uncommitted, live)** | API `jobs` now *lists* return pickups (above); `confirmBuyerCollection`/`reportCollectionFailure` are called only from the web `Rider/ReturnPickupController` (grep). No collect/failed endpoint, no pickup-failure, money or waybill API. Flutter not audited. |
| F-10 | Refund recipient/reference free text | **Confirmed** | `parcel_returns` has `refund_recipient`, `refund_reference` strings; no buyer refund-destination column anywhere (grep none). |
| F-12 | Manifest has no connection check | **Confirmed** | `ManifestService.php` has 0 `TransportConnection` references. The file is modified (uncommitted): it now blocks return movement before station receipt (`movementBlockedReason`), but still validates no connection. |
| F-13 | BFS by hop count | **Confirmed** | `RoutePlannerService.php:171-232` unchanged (no edits in `git status`). |
| F-14 | No journal/ledger | **Confirmed** | ≥15 purpose tables (DB list); no ledger/journal table. |
| F-15 | 3+ provider amounts undefined | **Requires owner decision** | `ProviderAllocationService` header; Audit 1.17 §10 item 3 carries it as blocking for multi-hop. |
| F-16 | Refund needs COD collection, not remittance | **Confirmed / owner decision** | `ParcelReturnService::authorizeRefund` checks `collected_at` only; browser audit H3 (order #41) shows refund vs pending remittance. |
| F-17 | Four-route end-to-end not proven | **Requires investigation** | I did not run outbound/linehaul suites; suites exist (names in prior audit). |
| F-18 | Entangled tree; demo test fails on pending product | **Confirmed** | Diagnosed earlier: seeded product `pending` → `isListedForSale()` false → 404. Tree still 354 changes (`git status`). |
| F-19 | MySQL runner config omits refund tests; concurrent runs | **Partially confirmed / investigate** | `phpunit.mysql.xml` omission confirmed earlier; the concurrent foreign `phpunit` process was observed once (cannot recur-test read-only). |
| F-20 | Three manual station actions | **Already addressed — prior claim wrong** | Receipt scan also sorts at the final hub (`IncomingParcelsController.php:333-339`). Remaining manual step: assign delivery Rider. |
| F-21 | No partial/line-level returns | **Confirmed** | `parcel_returns` columns: no item/quantity columns (DB). |
| F-22 | No suspension-with-active-shipments rule | **Partially addressed** | Logistics suspension has a Rider wind-down window ≤72 h (`AccountStatusService.php:15,63-91`); Seller/Buyer transitions have no shipment handling (grep). |
| F-23 | No dispute/complaints module | **Confirmed** | Admin nav shows "Soon" placeholders (`Components/admin/nav-item.blade.php:12`). |

**No finding was classed Unsupported.**

### New findings (N-series)

| ID | Class | Finding | Evidence |
|---|---|---|---|
| N-01 | MF | Riders earn only `pickup` and `delivery` credits tied to status transitions; **return collection from a Buyer and transfer driving earn nothing in-system**. | `RiderEarning.php:16-18`; `creditFor` is called only from `SellerOrderStatusService.php:303`. Whether companies pay these off-system: UNKNOWN. |
| N-02 | AW/PG | Unit economics at live settings leave no margin on long routes (§6). | DB `platform_settings`; CODE `config/buyer.php:26-30`. |
| N-03 | UX/AW | Provider "own" rate cards are stored (3 rows) and displayed by `zonesFor` as "own rate if set" while pricing ignores them. Whether the page tells providers this: **Requires investigation** (UI not viewed). | `ShippingRateService.php:54-57,75-88`. |
| N-04 | OL | Production runtime is a dev server with sync queue and live SMTP; one `.env`, one DB, one storage for dev and prod (§8). | OS + `.env` keys. |
| N-05 | OL | Time-based consumer protections (auto-approve, auto-receipt, overdue flags) run from a per-minute Windows Scheduled Task on one PC; downtime = protections pause. | OS: *Zefanya Laravel Scheduler*, `artisan schedule:run`, last result 0. |
| N-06 | AW | One 168 h timer couples **payout release/auto-completion** with the **return window**. | `AutomaticBuyerReceiptService::WAIT_HOURS`, `ParcelReturnService.php:61-76`. |
| N-07 | PG | No target lead time for return legs (Buyer→Seller). | no config/job; competitor context §11. |
| N-08 | MF | Overdue COD remittance (3 days) is shown on a dashboard only; no reminder/escalation. | `LogisticsRemittanceService.php:41,82-105`. |
| N-09 | OL | Backups: no scheduled backup task found by name; only my manual dump (DB only). Evidence/photos (`storage/app/private`: 331 files, 12 MB; `public`: 4,312 files, 50 MB) are not in it. | OS task list; `du`. |
| N-10 | OL | Multiple coding agents work in the same live tree (Codex runtime processes, `.codex/` run dir, a foreign `phpunit` run on the shared MySQL runner). | process list, `.codex/`. |
| N-11 | PG | Audit 1.17 §10 items still open: commission recognition before `Completed`, shipping voucher > COD, confirmed Logistics→Zefanya cash has **no reversal**, station staff identity (provider account is the actor). | `ZEFANYA_AUDIT_1_17…` §10. |

---

## 3. Four outbound flows — readiness (necessity, efficiency, correctness)

Verification basis: routing/handoff/manifest/return code read in this and the prior session; live DB topology; **no live transaction**. "Works" = code path exists and an existing test covers it per file name, **not re-run**.

### Flow 1 — Los Baños → Los Baños

| Step | Necessary? | Efficient? | Correct? | Alternative / note |
|---|---|---|---|---|
| Seller Request Pickup + window | Yes | Yes (windows, sessions) | CODE/DOC | Keep |
| Manual Rider assignment + confirm (two actions) | Policy (approved 2026-11-11) | Medium: browser audit UI note — assign then confirm = 2 steps, repeats on reassign | Works | Merge into "assign & notify" with a confirm-only-on-reassign setting (UX, small) |
| Pickup scan → station receipt | Yes (custody + COD point) | **Improved**: receipt also sorts at final hub | CODE | Keep |
| Assign delivery Rider | Yes | Manual; batching possible | CODE | Auto-suggest by area/load, human confirms |
| Delivery OTP + photo; COD recorded | Yes | Yes | DOC/TEST | Keep |

**Routing alternatives.** (a) *Station-mediated* (current): one custody checkpoint, COD hand-in point, failed-delivery return point. (b) *Direct door-to-door* by the pickup Rider: removes a scan and a station touch but removes the only independent custody scan, and puts COD and failed-delivery handling on the Rider alone. (c) *Hybrid direct*: allowed only for same-station routes with prepaid parcels. **Recommendation: keep (a)**; consider (c) only after real volume shows station dwell time is a bottleneck. Readiness: **PARTIALLY IMPLEMENTED** (supportable live; not transaction-tested by me).

### Flow 2 — Los Baños → Bay

Live: a direct `Los Baños ↔ Bay` ROAD connection exists (DB connection #7/#8), so BFS yields one leg with no province-hub visit; 60 of 62 live connections are hub-and-spoke through *Laguna Sorting Center*.

| Step | Necessary? | Efficient? | Correct? | Note |
|---|---|---|---|---|
| Manifest batch + Transfer Courier + dispatch/receive scans | Yes | Batching OK; **manual batching** | Works; **F-12**: no connection check on manifest creation | Auto-*suggest* batches by destination; humans dispatch |
| Province-hub transit | **No** when a direct link exists | n/a | Not required (`Phase4MultiLegRoutingTest.php:579`) | Keep optional |
| Destination station receipt → auto-sort → assign → deliver | Yes | Yes | Works | — |

**Routing alternatives:** direct-link-first (current BFS happens to favour fewer hops) vs hub-and-spoke only vs weighted. **Prefer weighted** (cost/hours) so a direct link is not chosen when slower or costlier (F-13). Readiness: **PARTIALLY IMPLEMENTED**.

### Flow 3 — Los Baños → Silang, Cavite

BLOCKED by data: no Cavite provider anywhere, 0 provider links live. Code path (partner links → hub-to-hub `ParcelHandoff` with QR/OTP, 40/60 snapshot) exists. **Necessary steps:** origin station → origin province hub → handoff → destination hub → destination station → Rider. **Design questions I would settle before building more:** (1) must every cross-province parcel pass *both* province hubs, or may a station hand off directly to a partner's station (fewer touches, more partner pairs to maintain)? (2) Who pays the hub dwell? Recommendation: keep hub-mediated handoffs (one acceptance point per provider) and add acceptance/ rejection **timers**. Readiness: **BLOCKED**.

### Flow 4 — Los Baños → Liloan, Cebu

Structural support exists only in the demo DB (Cebu provider with 53 stations and Liloan coverage; 5 SEA + 5 AIR connections; 20 ACTIVE links). Missing: carrier/booking/port release, declared value/insurance, inter-island fee, sailing cancel/reschedule, ≥3-provider allocation (F-03, F-02, F-15). **Alternatives:** (A) provider-operated connection (today) — adequate only for a demo; (B) *external carrier record* (name, booking no., vessel/flight, cargo-acceptance and port-release scans, optional declared value) attached to the existing manifest/handoff; (C) full transport-management module. **Recommend (B).** Readiness: **BLOCKED** (live) / **MISSING** (carrier model).

---

## 4. Four reverse flows — readiness

The buyer-collected return chain (collect → station → reverse transport → origin → Seller QR receipt) is implemented in one path (`ParcelReturnService.php:555-640,1175,1311`); refund custody gate now enforced for the normal refund path (TEST this session: policy tests 42/42 and 49/49; MySQL return/refund suites all passed; end-to-end same- and cross-provider chain test passed).

| Return | Necessary steps | Findings | Readiness |
|---|---|---|---|
| R1 same municipality | collect → station → (origin station) → Seller | Return Rider has **no earning type** (N-01); no pickup-delay timer (F-06); optional direct-to-Seller route does not exist for buyer returns (Lazada publishes a Direct Return to Merchant policy — title only, page unreadable) | PARTIALLY IMPLEMENTED |
| R2 Bay → Los Baños | + reverse manifest between stations | return manifests skip route-feasibility by design and manifest creation has no connection check (F-12) | PARTIALLY IMPLEMENTED / NOT TESTED live |
| R3 Silang → Los Baños | + reverse handoff, needs reverse partnership or per-parcel Admin authorization | BLOCKED (no data); Admin-per-parcel authorization is a manual bottleneck at scale | BLOCKED |
| R4 Liloan → Los Baños | + reverse SEA/AIR | no reverse-connection validation; carrier gaps | BLOCKED / MISSING |

Alternatives for return routing: (a) **mirror outbound** (current), (b) **shortest valid path** from the Buyer's station to the Seller's station (may differ from outbound, e.g. if a direct link exists only one way), (c) **direct-to-Seller** for same-cluster returns. **Prefer (b) with (a) as default**, (c) as an owner-approved option. Refund steps: authorize → evidence → Admin confirm exists; deadlines absent (§8).

---

## 5. Station and hub operations

| Capability | Today | Keep manual | Safe to automate | Gap / redundancy |
|---|---|---|---|---|
| Station receipt + sort | receipt scan sorts at final hub | physical scan (custody proof) | — | none (F-20 retracted) |
| Delivery Rider assignment | manual | final choice | suggestions by area/load | assign + confirm two-step UI |
| Manifest creation/dispatch/receipt | manual | dispatch and receipt scans | **batch proposals** by destination hub; overdue flags exist | no connection validation (F-12) |
| Partial batch receipt / missing parcel | partial receipt + exceptions + incident flow (DOC/CODE `ParcelIncidentService`) | Admin loss confirmation | auto-open an incident when a receipt omits scanned items | liability for cross-provider shortages undefined |
| Wrong-station delivery | receipt tokens bound to expected hub/provider (`ManifestService.php:626,746`) | — | — | no explicit "misrouted parcel" return-to-route workflow found (UNKNOWN) |
| Damaged parcel | incident report with photo | Admin decision | — | — |
| Inter-provider transfer dispute | handoff exceptions (`parcel_handoff_exceptions`) | Admin | — | no provider-to-provider dispute with evidence thread (UNKNOWN) |
| Inventory accuracy | derived read-only view; "conflicting transport records" flagged (`StationInventoryService`) | — | nightly pointer-vs-event reconciler (needs F-05) | no stored custody history |
| Return parcel routing | reverse manifests/handoffs | Admin authorization when no reverse link | — | — |
| Custody accountability | provider account is the recorded actor (Audit 1.17 §10.6) | — | record the staff user per scan | individual staff not identified |

---

## 6. Complete financial evaluation

### 6.1 Money map (see prior audit §8 for the owes/holds/receives table)

Unchanged and verified there: COD Rider → Company → Zefanya; 40% origin / 60% destination for two-provider shipping; Seller settlement and manual payouts; return-trip and failed-delivery charges; refunds with frozen payer; loss compensation = merchandise subtotal. Additional gaps from Audit 1.17 §10: **no reversal of confirmed Logistics→Zefanya cash**, **shipping voucher larger than collected COD** accepted at checkout but refused at settlement, commission recognition timing.

### 6.2 Unit economics (arithmetic on live settings — not measured cost)

Live settings (DB): Rider pickup ₱10 first / ₱10 additional, delivery ₱30; marketplace fee ₱50 for ≤2 kg + ₱20/kg (`config/buyer.php:26-30`). Pickup pay is the origin company's expense (DOC §Pickup sessions).

| Route (≤2 kg, one parcel per pickup) | Fee | Rider pay | Left for station, hub, courier, linehaul, overhead |
|---|---|---|---|
| Same provider, same station | ₱50 | ₱10 + ₱30 | **₱10** |
| Same provider, two stations | ₱50 | ₱40 | **₱10** (before a Transfer Courier) |
| Two providers (40/60) | origin ₱20 / destination ₱30 | origin ₱10 / destination ₱30 | origin **₱10**; destination **₱0** |
| Inter-island (any split) | ₱50 | ₱40+ | negative unless sea/air cost is below the leftover; **no cost data exists** (UNKNOWN) |

Heavier parcels add ₱20/kg to the fee but the delivery Rider's pay does not change, so the margin improves with weight; zero-margin cases are the light, long routes. Pickup grouping (one visit, several parcels) improves the origin side. Real station, fuel, packaging and courier costs are **UNKNOWN** and must come from the providers before any rate is chosen.

### 6.3 Shipping price alternatives (rates left as owner inputs: ₱X, ₱Y)

| | P1 Keep flat | P2 Marketplace zone card | P3 Provider-set cards (floor/cap) | P4 Route-computed |
|---|---|---|---|---|
| What | one fee for all | Admin sets fee per zone (existing 3 zones + a new inter-island/AIR/SEA zone) and weight steps | each provider submits cards; Admin approves within floor/cap | fee = Σ leg rates by mode from connection costs |
| Fairness | low for long routes | medium | high (reflects each provider) | highest |
| Sustainability | negative on long routes (§6.2) | fixable by owner | fixable, provider-driven | self-correcting |
| Implementation | none | **small**: zone snapshot and `LogisticsShippingRate` already exist; change `resolveRate` and add a zone | medium (approval workflow, checkout provider choice) | large (cost on every connection) |
| Ops efficiency | simplest | simple | more admin review | needs cost data upkeep |
| Security/abuse | no manipulation surface | low | providers could price to win checkout; needs cap | low once data exists |
| Scalability | poor | good | good | best |
| Buyer impact | cheap but may not cover | predictable by zone | may vary by carrier choice | precise |
| Seller/Logistics impact | Sellers cheap; providers lose | predictable | providers control revenue | transparent |

**Preferred: P2 for launch** (cheapest change that reuses existing plumbing; no new approval surface), keep P3 as an evolution once more than one provider serves a zone, and treat P4 as long-term. Do not enable P2 until the owner supplies rates and providers supply cost data. Existing orders keep their frozen fee.

### 6.4 Multi-provider allocation alternatives (percentages are owner inputs)

| | A1 role-based fixed shares | A2 leg-rate table (cost-plus) | A3 distance/leg-weighted | A4 destination keeps all minus fixed origin fee |
|---|---|---|---|---|
| Rule | extend 40/60 with fixed % for intermediate providers | each leg/role has a marketplace rate; fee must cover Σ, shortfall = platform subsidy | split by number/mode of legs | origin gets a fixed flat fee |
| Fairness | medium | high | medium-high | low for origin |
| Sustainability | tied to flat-fee problem | explicit subsidy visible | follows route | origin-risk |
| Difficulty | small | medium (rate table + subsidy ledger) | medium | small |
| Security | low | low | low | low |
| Scalability | OK | good | good | poor |
| Impact | simple to explain | most transparent to providers | harder to explain | discourages origin providers |

**Preferred: A1 for the first multi-hop launch** (the schema already stores amountless allocations, `ProviderAllocationService`), moving to **A2** once leg costs are known. 40/60 for two providers is verified as implemented and stays unless the owner changes it.

### 6.5 Rider compensation

Flat ₱30 per delivery and ₱10/₱10 per pickup session, independent of distance, weight, COD handling, failures or **return collection (no earning type, N-01)**. Alternatives: (R1) keep flat, add a **return-pickup** credit (smallest change); (R2) tiered by zone/weight; (R3) per-km. **Prefer R1 now, R2 when P2 pricing exists.** The rule "never auto-deduct COD shortages from Rider earnings" is preserved (approved policy).

### 6.6 Platform, Seller, refunds, loss

- **Commission** (10%, or 5% for orders finalized before 2026-11-08) is snapshotted per order; recognition before `Completed` is an open policy item (Audit 1.17 §10.1).
- **Subsidies:** no cap; shipping voucher > COD refused at settlement — owner decision needed (alternatives: cap voucher at collectible COD; or platform pays the difference through an explicit subsidy entry; **prefer the cap**, simplest and cash-safe).
- **Refund payer:** Seller, Logistics or Platform with frozen payer at authorization; platform-funded refunds have no budget (Audit 1.17 §10.5). Alternatives: per-refund threshold needing a second approval (conflicts with the single-Super-Admin model), or **monthly platform-refund budget with an alert** (**prefer**; no second person required).
- **Refund evidence:** proof file + reference + free-text recipient; no verified destination (F-10). Alternative: capture the Buyer's refund destination at request time and show Admin a match/mismatch (**prefer**, additive).
- **Lost/damaged:** compensation = merchandise subtotal; no declared-value tier. Alternative: optional declared value for high-value items (needed for inter-island).
- **Reconciliation:** see §12 (journal projection recommended; double-entry not justified yet).

---

## 7. Refunds and return deadlines

I do **not** assert any binding legal deadline. Public context only: the Internet Transactions Act (RA 11967) and Consumer Act (RA 7394) give consumers remedies; secondary commentary disagrees on section numbers and day-counts, so the statute text/IRR and counsel decide ([LawPhil RA 11967](https://lawphil.net/statutes/repacts/ra2023/ra_11967_2023.html)).

**Current timers (CODE):** return window 168 h from delivery; Seller decision 3 days then auto-approve; collection-failure escalation; handover code 10 minutes; no refund deadline. **Gap list:** return pickup unassigned; Rider not dispatched; station receipt late; Seller never receives; Admin never authorizes; authorized refund never paid.

| Timer design | T1 fixed calendar-day SLAs in config | T2 business-day SLAs | T3 risk-tiered (value/route/mode) |
|---|---|---|---|
| Simplicity | **simplest** | needs holiday calendar | complex |
| Fairness to Buyer | predictable | weekends may delay | best |
| Fit with multi-island | weak for sea legs | weak | **good** |
| Cost | small | medium | large |

**Preferred: T1 with per-mode extensions** (the existing `config/transfers.php` pattern for SEA/AIR overdue hours). Values are **owner inputs**; competitor reference points are not rules: Shopee help center states Seller response within 2 days with auto-approval on silence and a gradual rollout ([Shopee Help Center](https://help.shopee.ph/portal/4/article/81230-[Return-Refund]-What-happens-after-a-seller-receives-my-return%2Frefund-request%3F)); Shopee's Returns Window is 15 days (Mall) / 7 days (non-Mall) and buyer-confirmation is 7 days (Mall) / 3 days (non-Mall) on the page I fetched ([Shopee Help Center](https://help.shopee.ph/portal/4/article/80957-[Order-Protection]-What-is-Shopee-Guarantee)); Lazada community posts (older, unverified) mention 25 calendar days for customer returns to reach sellers and 45 for failed deliveries with two return attempts ([Lazada PH community](https://community.lazada.com.ph/article/what-is-the-lead-time-to-return-customer-returns-and-failed-deliveries-560500-hc)).

**Needed (no implementation now):** (1) a per-step *due-at* and owner on every waiting return; (2) a scheduled overdue command that notifies the responsible party once, then escalates to Admin, in the pattern of `FlagOverdueTransfersCommand`; (3) a Buyer-visible due date; (4) decouple **payout release/auto-completion** from **return window** (N-06); (5) an Admin "overdue returns & refunds" queue. **Evidence strength:** early refund exceptions already require documented basis + private proof; historical refunds are preserved (`refund_policy_version` NULL). **Single Super Admin stays:** replace second-approver ideas with a weekly self-review report and the existing audit log; no evidence requires another Admin account.

---

## 8. Staging and production safety

### 8.1 Findings (OS/CODE/DB; nothing changed)

| Area | Observed | Risk |
|---|---|---|
| Web server | `php artisan serve` (built-in dev server) on 127.0.0.1:8000 as scheduled task *Zefanya Laravel Server*, boot trigger, run as user `Rei` (Limited), working dir = the working tree. Cloudflare tunnel runs as service with a token (remotely managed; ingress rules **not viewable** by me). A second web stack (EDB Apache, port 8080) exists and is unrelated. | Dev server is single-threaded by default and not hardened; every file edit is live; syntax error = site error. |
| Why edits are live | PHP interprets the tree per request; no build/release step; same directory for dev, tests and prod. | "Uncommitted" ≠ "not deployed". |
| Database | One MySQL 80 service; app uses `Zefanya` (root account in `.env`); test/demo schemas (`zefanya_ninja_demo`, `zefanya_concurrency_test`, `zefanya_recovery_test`, `zefanya_scale`, `zefanya_schema`) live on the same server. A restricted runner account protects the concurrency DB (CODE `Preflight`). | One mistaken `migrate`/seeder on the default connection hits production; app connects as `root`. |
| Storage | `FILESYSTEM_DISK=local`; evidence in `storage/app/private` (331 files, 12 MB: IDs, permits, delivery proofs, return photos, receipt-dispute photos), `public` 4,312 files, `demo-isolated` 636 files. | Not in SQL backups; privacy exposure if copied to staging unmasked. |
| Queue / mail / cache / session | `QUEUE_CONNECTION=sync`, `MAIL_MAILER=smtp`, `CACHE_STORE=database`, `SESSION_DRIVER=database`, `APP_ENV=production`, `APP_DEBUG=false`. | Sync queue ⇒ SMTP latency/failure inside user requests; seeding/testing against this env can send real mail (project memory already warns). |
| Scheduler | Windows task `schedule:run` every minute, result 0. | Single PC; sleep/shutdown pauses auto-approve, auto-receipt, overdue flags. |
| Backups | none scheduled found by name; manual DB dump + restore test done earlier; files not covered | Dangling file references after a DB-only restore. |
| Migrations/rollback | `database/migrations/pending/` pattern requires explicit activation (good); the pending refund migration has a `down()`; no rehearsal environment. | Rollback untested on a staging copy. |
| Concurrent agents | `.codex/`, Codex node runtimes, a foreign `phpunit` run on the shared MySQL runner (runner lock file exists). | Two agents editing one live tree. |

### 8.2 Recommended staging architecture (plan only)

1. **Two checkouts, one repo:** `WebZefanya-live` (a clean worktree on a **tagged commit**, served on :8000) and `WebZefanya-staging` (feature-branch worktree on :8001). The scheduled server task points at the live worktree; developers and AI agents only touch staging/feature worktrees.
2. **Separate `.env` per tree:** staging uses `APP_ENV=staging`, DB `zefanya_staging` with its **own least-privilege MySQL user (not root)**, `MAIL_MAILER=log`, `QUEUE_CONNECTION=database` (or sync with log mailer), distinct `APP_KEY`, own `storage/`.
3. **Data for staging:** restore the latest production dump into `zefanya_staging` and **mask** personal data and evidence files (or use the demo dataset); never copy `storage/app/private` unmasked.
4. **Exposure:** an owner action in Cloudflare Zero Trust to add a protected hostname for :8001 (access policy), or keep it local-only.
5. **Production runtime hardening (separate decision):** replace `artisan serve` with a production-grade PHP runtime (PHP-FPM/Caddy/IIS/Apache already on the machine), real queue worker, and a scheduler that restarts on failure; consider moving off a developer PC entirely.

### 8.3 Release workflow (plan only)

feature branch → staging worktree + restored DB → narrow tests + MySQL runner (single runner) → **migration dry-run on the restored copy** → review → backup (**DB + `storage/app/private` + `storage/app/public`**, with checksum, stored outside temp and outside the repo; verify restore on an isolated schema as done earlier) → merge and tag → switch live worktree to the tag → run explicit migrations (`pending/` convention) → smoke test → rollback plan = previous tag + `down()` or restore.

### 8.4 Feature flags

Appropriate for **behaviour** rollouts (custody-gate enforcement, refund timers, pricing zone) using the existing `config/*.php` precedent (`returns.restore_verified_resellable`, `transfers.require_transport`). **Not sufficient alone:** code under edit is still executed in the live tree; a flag cannot protect against a half-edited file. Use flags *after* separation. Default new restrictions to off until approved.

### 8.5 Concurrent agents

One worktree per agent; one MySQL runner lock owner; no agent edits `WebZefanya-live`; record the runner holder in `.codex/` lock content.

---

## 9. UI/UX and competitor benchmarking

Only verified or clearly labelled public facts. Zefanya UI observations come from the prior browser audit (screens not re-viewed by me).

| Area | Zefanya (evidence) | Public marketplace behaviour (source) | Improvement | Measure |
|---|---|---|---|---|
| Order placement | undeliverable products still show Add to Cart/Checkout (browser audit H1); stale cart label (§7) | not verified | disable checkout when route unavailable; show reason early | failed-checkout rate |
| Shipping transparency | zone fee flat; checkout quote exists | Lazada: coverage/terms under *Service details* on the product page (search summary, third-party guide) | show fee basis and delivery coverage on the product page | support tickets about fees |
| Tracking | custody-based summary (`RouteExecutionService::trackingSummary` `:111`) | not verified | show current holder, next step, due date | WISMO contacts |
| Returns | Seller 3 days; no partial-refund offer | Shopee: seller 2 days, auto-approval on silence, partial-refund offer with 1 day to respond, Shopee mediation (help.shopee.ph) | add partial-refund-without-return; Buyer-visible due dates | median request→refund days |
| Refund communication | statuses: authorized → evidence submitted → confirmed | Shopee: refund after returned item checked; no fixed timeframe on that page | publish committed timeline after owner/counsel decision | % refunds within promise |
| Seller operations | manual payout requests; return breakdown server-side | not verified | surface per-return deductions in Seller UI | payout disputes |
| Rider tasks | web portal complete; API partial (F-09); Flutter not audited | not verified | API parity; return-pickup earning | tasks completed in-app |
| Logistics efficiency | assign+confirm two-step; cryptic "Destination region 04" (browser audit §7) | not verified | one-step assign, readable region | assignment time |
| Financial reporting | no provider statements/export | not verified | statements + reconciliation report (journal) | unmatched COD remittances |
| Error recovery | branded 404/405 added (F5) | — | — | — |
| Mobile usability | **BLOCKED** (viewport not changed in browser audit) | — | test on real devices | task completion on mobile |
| Disputes | Admin "Soon" placeholder | Shopee mediates disputes | minimal dispute queue | resolution time |

**Competitive advantage candidates that are real** (not copies): custody-visible tracking for Buyers; provider-neutral routing with verified handoffs; cash-accountability without automatic Rider deductions. I claim no superiority over Lazada/Shopee.

---

## 10. Architecture review: keep / refactor / redesign

| Subsystem | A Keep & improve | B Refactor | C Redesign | Recommendation | Reason |
|---|---|---|---|---|---|
| Hub model | add attributes (capacity, cut-off, port) | — | add a third tier | **A** | two tiers already model ports (demo "Port Gateway"); no demonstrated need for a tier |
| Routing | keep graph | weighted shortest path + connection validation | external TMS | **B** | hop-count BFS can pick a worse route (F-13, F-12) |
| Pricing | — | zone × mode card using existing tables | per-leg costing | **B → later C-lite (P4)** | live economics (§6.2) |
| Custody history | — | append-only events + reconciler | event-sourced parcels | **B** | pointer-only, 11 writers (F-05) |
| Inter-island | provider-labelled leg | external carrier record | separate linehaul module | **B** | medium cost, reuses handoff/QR logic |
| Rider model | keep single Rider + no-login transfer courier | link transfer courier to Rider login later | new account types | **A** | no evidence for new types |
| Money | keep tables | journal projection + statements | full double-entry | **B** | ≥15 tables, no statement; C unjustified at this volume |
| Refunds | keep authorize→evidence→confirm | add timers, destination match, budget alert | external payment integration | **A + timers** | manual by approved design |
| Production runtime | — | separate worktrees, flags | managed hosting | **B now, hosting later** | §8 |
| Admin model | keep single Super Admin | weekly self-review report | second approver | **A** | owner decision; no evidence otherwise |

---

## 11. Missing features and confirmed defects (consolidated)

**Confirmed defects / risks:** F-01, F-02 (pricing ignores cards), F-12, F-16, F-18, N-04, N-05, N-09. **Missing functionality:** F-03, F-05, F-09 (actions), F-14, F-21, F-23, N-01, N-08. **Policy gaps:** F-06 remainder, F-07, F-15, N-06, N-07, N-11, F-22 (Seller/Buyer suspension). **Not defects (verified working):** auto-sort on final-hub receipt, direct station links, custody-gated refunds, evidence-bound refund confirmation, 40/60 snapshot, no auto Rider deduction.

---

## 12. Proposed architecture improvements (no implementation)

1. **Release separation** (§8): live worktree on tags; staging worktree/DB; per-tree `.env`; storage-inclusive backups.
2. **Pricing zone + mode** (P2): change resolution to honour a marketplace card per zone; add an inter-island zone; keep order-time fee snapshots.
3. **Allocation** (A1 → A2): extend role-based shares to ≥3 providers; add subsidy visibility.
4. **Custody events:** `parcel_custody_events` written inside existing transactions; reconciler; no backfill (no fabricated history).
5. **Manifest/connection validation** incl. reverse legs; Admin-authorized exception retained.
6. **External carrier record** for SEA/AIR legs with booking/port scans and optional declared value.
7. **Return/refund timers + overdue queue**; decouple payout release from return window.
8. **Journal projection** (`financial_entries`) + provider statements + export; evaluate double-entry only after real volume.
9. **Rider**: return-pickup earning, API collect/failed endpoints, optional login for transfer couriers.
10. **Refund destination capture and match**; monthly platform-refund budget alert.

---

## 13. Policy decisions requiring approval

See Table D. Principle: nothing here is approved until the owner decides; this audit changed no policy.

---

## 14. Risks, dependencies, acceptance criteria

| Risk | Mitigation | Acceptance criterion |
|---|---|---|
| Further edits reach production before review | separation plan first | no agent/developer edits the served worktree; live tree == a tag |
| Pricing change misprices existing orders | snapshots frozen per order | orders placed before the change unchanged in tests |
| Timer jobs notify wrongly or twice | idempotent once-only notices (pattern: `FlagOverdueTransfersCommand`) | time-travel tests: one notice, one escalation |
| Custody events drift from pointer | reconciler report | drift report empty in staging for the four routes |
| Staging leaks personal data | masked data, no private storage copy | no unmasked IDs/photos in staging |
| Scheduler single-PC failure | restart policy / alerting | missed-run alert within N minutes |
| Owner delays policy decisions | keep new rules behind flags default-off | flag state documented |

Dependencies: owner decisions (pricing, allocation, deadlines, window) precede pricing/timer work; staging precedes any feature merge; custody events precede the nightly reconciler.

---

## 15. What to approve, change, build next

**Approve (owner):** (1) the release-separation plan; (2) that no new restriction is activated in production without a staging run; (3) the decision *process* for pricing (provider cost data collection) and for return/refund timers.
**Change (small, after approval of 1):** refund/return timers + overdue queue; honour zone cards (P2) *only after rates are supplied*; manifest–connection validation; return-pickup Rider credit; storage-inclusive backups.
**Build next (in order):** (1) staging worktree + DB + storage-inclusive backup/restore rehearsal; (2) custody events + reconciler; (3) timers/queue; (4) pricing zone + mode; (5) external carrier record; (6) journal/statements; (7) Rider API parity; (8) dispute queue.
**Do not build yet:** new Rider account types, a third hub tier, full double-entry accounting, a transport-management module.

---

# Tables

### TABLE A — What currently works

| Feature | Evidence | Status |
|---|---|---|
| Graph routing with saved legs, reroute, feasibility | `RoutePlannerService`, `RouteExecutionService` | VERIFIED (code); NOT RE-TESTED |
| Direct station-to-station route (Los Baños↔Bay) | DB connections #7/#8; `Phase4MultiLegRoutingTest.php:579` | VERIFIED (data + code) |
| Receipt scan auto-sorts at final hub | `IncomingParcelsController.php:333-339` | VERIFIED (code) |
| QR/OTP cross-provider handoffs | `ParcelHandoffService`, `ParcelHandoffVerification` | VERIFIED (code) |
| Derived station inventory with conflict rows | `StationInventoryService` | VERIFIED (code) |
| Return custody chain + refund custody gate | `ParcelReturnService`, `ParcelReturn::refundCustodyProblem`; policy tests 42/42 and 49/49; MySQL refund/return suites passed | VERIFIED (isolated tests) |
| Refund authorize → evidence → Admin confirm, frozen payer | `ParcelReturnService.php:209,248,270` | VERIFIED |
| 40/60 allocation snapshot | `CodRemittance.php:23,90` | VERIFIED (code) |
| COD cash accountability, no auto Rider deduction | `CodRemittanceService.php:22-28` | VERIFIED (code) |
| Scheduler runs and protections fire | OS task result 0; `routes/console.php` | VERIFIED (config), behaviour NOT TESTED |
| Pending-migration convention | `database/migrations/pending/` | VERIFIED |

### TABLE B — What is missing or needs fixing

| Priority | Problem | Impact | Recommended Change |
|---|---|---|---|
| P0 | Production = developer working tree (F-01, N-04, N-10) | any edit/agent error is live | worktree separation + per-tree env |
| P0 | Backups DB-only, manual (N-09) | evidence files lost/dangling on restore | storage-inclusive scheduled backup + restore rehearsal |
| P0 | No return/refund inaction timers or deadline (F-06) | Buyers can wait indefinitely | timers, overdue queue, due dates |
| P0 | Flat fee vs flat Rider pay (F-02, N-02) | long routes lose money | decide pricing; honour zone card |
| P0 | Window/consumer-law fit (F-07) | compliance exposure | counsel review |
| P1 | No Cavite/Cebu operable data; 0 links (F-04) | Flows 3–4 unavailable | isolated demo data first |
| P1 | No carrier/insurance (F-03) | untraceable inter-island liability | external carrier record |
| P1 | Pointer-only custody (F-05) | weak disputes/audits | custody events + reconciler |
| P1 | Manifest has no connection check (F-12) | invalid transfers possible | validate incl. reverse |
| P1 | No return-pickup Rider earning; API lacks return actions (N-01, F-09) | returns under-supported on mobile/pay | credit + endpoints |
| P1 | ≥3 provider allocation (F-15) | unpaid middle carriers | A1 then A2 |
| P1 | Refund before COD remittance (F-16) | cash exposure | rule or explicit acceptance |
| P2 | Weighted routing (F-13), journal/statements (F-14), partial returns (F-21), dispute queue (F-23), COD overdue reminders (N-08), suspension rules (F-22), refund destination (F-10) | efficiency, transparency | per §12 |

### TABLE C — Architecture alternatives

| Area | Current Design | Alternatives | Recommended Option | Reason |
|---|---|---|---|---|
| Hubs | Province + Station | add tier / add attributes | Keep + attributes | ports already modelled as hubs |
| Routing | hop-count BFS | weighted / TMS | Weighted | cost-aware, bounded effort |
| Pricing | flat fee | zone card / provider cards / route-computed | Zone card (P2) | reuses existing plumbing |
| Allocation (3+) | amountless rows | role shares / leg-rate / weighted / fixed origin | Role shares (A1) → leg-rate (A2) | fast now, transparent later |
| Custody | mutable pointer | + events / event-sourced | + events | audit without rewrite |
| Inter-island | labelled connection | carrier record / TMS | Carrier record | reuse handoff logic |
| Rider | one Rider + transfer record | new roles / linked login | Keep, link later | no evidence for roles |
| Money | many tables | journal / double-entry | Journal projection | integrity already strong |
| Runtime | dev server on working tree | worktrees+flags / managed host | Worktrees now | immediate risk reduction |
| Admin | single Super Admin | second approver | Keep + weekly report | owner decision |

### TABLE D — Owner policy decisions

| Policy | Options | Tradeoffs | Recommendation |
|---|---|---|---|
| Shipping price model | P1 flat / P2 zone / P3 provider cards / P4 computed | fairness vs effort (§6.3) | P2 once rates supplied |
| 3+ provider split | A1 / A2 / A3 / A4 | simple vs transparent (§6.4) | A1 then A2 |
| Return window and warranty path | keep 7 days / longer / separate defect path | Buyer protection vs Seller exposure | counsel review; consider separate defect path |
| Seller decision time | keep 3 days / shorten | speed vs Seller burden | owner (Shopee uses 2 days, WEB) |
| Return-leg lead time | none / fixed / per-mode | clarity vs ops burden | T1 + per-mode extensions |
| Refund deadline | none / fixed / tiered | trust vs cash-flow | fixed, announced after counsel |
| Payout release vs return window | coupled (168 h) / decoupled | Seller cash vs Buyer protection | decouple |
| Refund before COD remittance | allow / require remit-or-shortage | speed vs cash risk | show status; owner decides |
| Platform-funded refunds | uncapped / budget alert / second approval | control vs single-admin model | budget alert |
| Shipping voucher > COD | accept / cap | cash-safe vs promo reach | cap |
| Rider return-pickup pay | none / flat credit | cost vs coverage | flat credit |
| Suspension with active shipments | none / wind-down per role | safety vs speed | wind-down |
| Admin control | single / second approver | accountability vs operations | single + review report |

### TABLE E — Four-flow readiness

| Delivery Flow | Outbound | Return | Station Transfers | COD | Settlement | Missing Requirements |
|---|---|---|---|---|---|---|
| 1 Los Baños→Los Baños | PARTIALLY IMPLEMENTED (supportable; not transaction-tested) | VERIFIED chain in isolated test; timers missing | n/a (same station) | PARTIALLY (overdue only displayed; refund-vs-remittance rule open) | VERIFIED (same provider) | timers; Rider return credit; pricing margin |
| 2 Los Baños→Bay | PARTIALLY IMPLEMENTED (direct link exists) | PARTIALLY / NOT TESTED live | PARTIALLY (no connection check) | PARTIALLY | VERIFIED (same provider) | F-12, weighted routing |
| 3 Los Baños→Silang | BLOCKED (no provider/links) | BLOCKED | code exists | NOT TESTED | 40/60 VERIFIED in code; ≥3 REQUIRES POLICY | provider+links data, acceptance timers, pricing |
| 4 Los Baños→Liloan | BLOCKED live / structural in demo | BLOCKED | MISSING (carrier, port scans) | NOT TESTED | REQUIRES POLICY | carrier record, insurance/declared value, zone pricing, ≥3 allocation |

### TABLE F — Development roadmap

| Priority | Recommended Work | Dependencies | Complexity | Acceptance Criteria |
|---|---|---|---|---|
| P0 | Staging worktree + `zefanya_staging` DB + masked data + per-tree `.env`; live tree on tag | owner approval | M | live served commit == tag; staging mail=log; no staging access to private files |
| P0 | Scheduled backup of DB + `storage/app/private` + `public`, restore rehearsal | none | S | restore to isolated schema + file tree reproduces counts and file hashes |
| P0 | Return/refund timers, overdue Admin queue, Buyer due dates | owner timer values | M | time-travel tests: one notice, one escalation, queue lists overdue |
| P0 | Pricing decision + zone card honoured | provider cost data, owner rates | M | quotes differ by zone; old orders unchanged |
| P1 | Manifest/connection validation (incl. reverse) | none | S–M | manifest without active connection rejected or Admin-authorized |
| P1 | Custody events + reconciler | staging | M | drift report empty for four routes in staging |
| P1 | Return-pickup Rider credit + API collect/failed | owner pay decision | M | API parity tests; credit once per return |
| P1 | ≥3 provider allocation A1 | owner percentages | M | allocation amounts recorded and payable |
| P1 | External carrier record + port scans + declared value | pricing | M | sea/air leg records carrier, booking, release scans |
| P1 | Isolated demo data: Cavite provider + links | none | S | Silang route plans and delivers in isolation only |
| P2 | Weighted routing; journal/statements; partial returns; dispute queue; COD overdue reminders; refund destination capture | earlier phases | M–L | per-item criteria |
| P3 | Partial-refund-without-return; ETA; provider statement export; Seller payout calendar | P2 | M | measured by §9 metrics |

---

## Sources
- Shopee PH Help Center, seller response/return process: https://help.shopee.ph/portal/4/article/81230-[Return-Refund]-What-happens-after-a-seller-receives-my-return%2Frefund-request%3F (fetched; process stated to be rolling out gradually to selected shops)
- Shopee PH Help Center, Returns Window and buyer confirmation times: https://help.shopee.ph/portal/4/article/80957-[Order-Protection]-What-is-Shopee-Guarantee (fetched page content is the Returns Window article)
- Lazada PH community, return/failed-delivery lead times (search result; older community posts, not confirmed as current): https://community.lazada.com.ph/article/what-is-the-lead-time-to-return-customer-returns-and-failed-deliveries-560500-hc ; Direct Return to Merchant policy (title only; page returned HTTP 500): https://community.lazada.com.ph/article/direct-return-to-merchant-policy-553500-hc
- RA 11967 text: https://lawphil.net/statutes/repacts/ra2023/ra_11967_2023.html (not legal advice; counsel to confirm)
- Internal: prior audit, `docs/audits/ZEFANYA_AUDIT_1_17_FINAL_END_TO_END_SYSTEM_INTEGRITY.md` §10, `docs/audits/ZEFANYA_BUYER_SELLER_LOGISTICS_BROWSER_AUDIT.md` §7–12, `docs/audits/POLICY_REVIEW_2026-11-11.md`, `docs/4-Flow.md`.

## Unknowns and blocked verification

Cloudflare tunnel ingress rules (remotely managed; token not read); actual provider operating costs; whether companies pay Riders for return collection off-system; Flutter app; mobile/tablet browser behaviour; whether the provider rate-card page tells providers their cards are not used; legal interpretation; real-transaction behaviour of the four routes (BLOCKED, isolated plan in the prior audit §18). No competitor superiority is claimed.
