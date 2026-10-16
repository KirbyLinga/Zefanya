# Pass 1 — Traceability: spec TC-RID-001…032 vs code

Date: 2026-10-16. Method: code read + route:list + grep; runtime probes blocked where routes do not exist. Status: IMPLEMENTED = works today; PARTIAL = pattern exists but not for riders; MISSING = nothing.

| ID | Status | Evidence (file:line) | Proposed fix | Risk |
|----|--------|----------------------|--------------|------|
| 001 | MISSING | `routes/logistics.php:15-38` — no rider routes; no RiderController | New migration + model fields + `logistics.riders.store` + activation mail | Medium — new table/guard surface |
| 002 | MISSING | No Add Rider form (`resources/views/Logistics/*` has no Riders/) | Blade form with per-field `@error` + FormRequest | Low |
| 003 | MISSING | Same as 002 | Same as 002, preserve old() except files | Low |
| 004 | MISSING | Letters-only regex exists only in Seller/Buyer requests, not wired for riders | Reuse `^[\p{L} .'\-]+$/u` in shared rider request | Low — shared fix, rerun buyer/seller tests |
| 005 | MISSING | min2/max50 only in Buyer request; couriers.name has no validation | Shared rule min:2 max:50 | Low |
| 006 | PARTIAL | Buyer request `before_or_equal:-18 years` uses app UTC (`config/app.php:68`), not Asia/Manila; no rider DOB field | Manila-aware age check in shared module; leap-day covered by Carbon | Low |
| 007 | MISSING | No rider email field/validation | trim+lowercase, RFC + dot-domain regex (buyer precedent) | Low |
| 008 | MISSING | Cross-role email check exists seller→buyer only (`StoreSellerRegistrationRequest:44-57`); nothing for riders | Global check across buyers/sellers/providers/riders + DB citext/unique | Medium — needs DB constraint |
| 009 | MISSING | `^09\d{9}$` exists for other roles; no rider mobile | Shared rule + `+63`→`09` normalize (buyer `normalizePhoneNumber:66-77`) | Low |

| 012 | MISSING | No license field; regex `^[A-Z]\d{2}-\d{2}-\d{6}$` nowhere in repo | New rule; decision: uppercase input, reject lowercase (log in DECISIONS) | Low |
| 013 | MISSING | No license uniqueness | DB unique + friendly message | Medium |
| 014 | PARTIAL | No expiry field; timezone still UTC app-wide | Manila-aware `>= today` check | Low |
| 015 | MISSING | No plate field; contract planned per-provider unique but never built | Global unique per spec A14 (conflicts with contract — log decision) | Medium |
| 016 | MISSING | Upload precedent `mimes:jpg,jpeg,png,pdf max:5120` (logistics request:65-66) but no rider uploads | Reuse mimes+max, add magic-byte check | Low |
| 017 | MISSING | Same as 016 | Same as 016, message lists JPG/PNG/PDF | Low |
| 018 | MISSING | `max:5120` == 5,242,880 bytes mathematically but boundary never tested; no magic bytes | Byte-exact test with fixtures 4.9/5.0/5.1MB | Low |
| 019 | MISSING | No rider store → no idempotency; buyer/seller stores also lack guards (known-risk) | DB transaction + unique constraints + button disable; single email | Medium |
| 020 | MISSING | No token table, no set-password page | `rider_activation_tokens` (hashed, 24h, single-use) + A5 rules | High — new auth surface |
| 021 | MISSING | Password complexity exists buyer/seller (`StoreSellerRegistrationRequest:65`) but not wired to rider activation | Reuse same regex on activation | Low |
| 022 | MISSING | Same as 020 (`confirmed` rule precedent everywhere) | `confirmed` + mismatch message | Low |
| 023 | MISSING | OTP expiry precedent 10min (`LogisticsProvider::issueOtp:99`); no 24h token | `expires_at = issued +24h`, clock via Carbon::setTestNow | Low |
| 024 | MISSING | No resend route; OTP resend precedent (`VerifyLogisticsOtpController`) exists | `resend` issues new token, invalidates old | Medium |
| 025 | MISSING | No single-use invalidation | Delete/burn token on success | Low |
| 026 | MISSING | `UnifiedLoginController:27-57` has no rider guard; denial precedent = approved-only | Rider guard + Pending Activation denial without oracle | Medium |
| 027 | MISSING | No rider dashboard | `rider.dashboard` shows rider name + company business_name | Medium |
| 028 | MISSING | Company scoping precedent: `CourierAssignmentService:145-146` + `isClaimedBy`; no rider list | `where(logistics_provider_id)` on every query + ID-direct 403/404 | Medium |
| 029 | PARTIAL | `Register-Type.blade.php:23-52` offers Buyer/Seller/Logistics only (no Rider — good); Add Rider address does not exist so no redirect yet | No public rider route; `GET /logistics/riders/*` → login when guest | Low |
| 030 | MISSING | `auth:seller`/`auth:buyer` groups exist but no rider endpoint to protect | `auth:logistics`+`logistics.approved` on rider routes; direct POST denied | Low |
| 031 | PARTIAL | `EnsureLogisticsApproved:28-40` already denies pending (logout+redirect) — works for future rider routes if placed inside group | Put rider routes INSIDE existing group; add direct-POST test | Low |
| 032 | MISSING | No rider session, no rider-blocked-from-other-panels logic | `auth:rider` + role middleware; 403/redirect on logistics/seller/admin | Medium |

## Missing foundations (must build in Pass 2–6)

1. Migration(s): rider identity columns (first/last/dob/email/mobile/license_no/license_expiry/plate/vehicle_type/license_path/orcr_path), status enum Pending Activation/Active, password, company FK; `rider_activation_tokens` (rider_id, token_hash, expires_at, used_at); DB unique constraints email/mobile/license/plate (global per spec).
2. Auth: `rider` guard/provider, `rider` middleware, login (UnifiedLogin + logout + redirect maps), rider dashboard, activation set-password pages.
3. Add Rider UI + API: `logistics.riders.{index,create,store,show,resend}` inside `auth:logistics`+`logistics.approved`; shared validator module (Manila dates, normalization).
4. Mail: `RiderActivationMail`/Notification via Notifiable; `Notification::fake()` capture; exactly-once send.
5. Test kit: seed script (Logistics A/B, buyer, seller, Rider 1/2, spares), fixture generator (10 files w/ magic bytes + exact sizes), mail helper, clock helper (Carbon::setTestNow + Asia/Manila), role helpers (`actingAs` per guard).
6. Docs: DECISIONS.md (license lowercase, plate/license normalization, global-vs-per-provider unique, jpeg handling), results.csv skeleton.

## Verdict

0 IMPLEMENTED, 4 PARTIAL (006 age pattern, 014 expiry pattern, 029 no-rider-card, 031 pending-denial middleware), 28 MISSING. Nothing in the 32 passes today. Proceed to Pass 2 red baseline.

| 010 | MISSING | Same as 009 | Same as 009 (11-digit exact after normalize) | Low |
| 011 | MISSING | No phone uniqueness on logistics; seller scope is per-role only | Canonical column + unique constraint + `+639…` equivalence test | Medium — backfill existing rows |
