# WebZefanya Security Audit

| | |
|---|---|
| **Date** | 2026-11-07 |
| **Scope** | `WebZefanya/` (authoritative Laravel 13 backend for zefanya.shop): web routes, Sanctum API, services, config, Git history, dependencies. `Zefanya/mobile/` was read only to confirm how it calls the API. |
| **Code audited** | Branch `feature/sanctum-api-auth` @ `792cbdfd` **plus the uncommitted working tree** (66 changed/untracked entries at audit start). Most `app/Http/Controllers/Api/**` controllers, `app/Http/Requests/Api/`, `app/Http/Resources/` and their tests are **untracked**. The deployed server may not have them yet. |
| **Method** | Static review of routes, middleware, policies, controllers and services; read-only Git history scans with all values masked; `composer audit --locked`; `npm audit --package-lock-only`; full PHPUnit suite on in-memory SQLite. |
| **Not done** | No code, config, `.env`, migration, DB or Git changes. No requests to the live site. No `key:generate`, no history rewrite. |

Legend: **PASS** · **PARTIAL** · **FAIL** · **NOT VERIFIED**. Priority: **P0** critical · **P1** high · **P2** medium · **P3** low.

> **Remediation status (2026-11-07, uncommitted):** two code-level fixes are in the working tree (a third, admin 2FA by default, was reverted; see below); the rest of this report describes the state *before* them.
>
> - **F-03 / checklist #4 (reverted by owner decision, 2026-11-07):** for the school capstone, admin 2FA enrolment is **optional in every environment, including production**: `config/admin.php` defaults `ADMIN_REQUIRE_2FA` to `false`, and `.env.example` ships `ADMIN_REQUIRE_2FA=false`. Admins sign in with email and password. TOTP still applies to any admin who enrols, and setting `ADMIN_REQUIRE_2FA=true` makes it mandatory again. F-03 remains an accepted risk. Tests: `tests/Unit/AdminTwoFactorConfigTest.php`, `tests/Feature/Admin/AdminTwoFactorTest.php`.
> - **F-09 / checklist #6 (hosts part):** `bootstrap/app.php` registers `trustHosts()` from `config('app.trusted_hosts')` (`TRUSTED_HOSTS`, default `zefanya.shop`) plus APP_URL's host, each with subdomains. Test: `tests/Feature/Security/TrustedHostTest.php`. **Deployment check:** if the Cloudflare Tunnel rewrites the Host header (e.g. to `localhost`), add that host to `TRUSTED_HOSTS`, or the site will return 400s. `TRUSTED_PROXIES` still has to be verified on the server.
> - **Checklist #7:** `composer update "symfony/*" --with-dependencies`: symfony/http-foundation 8.1.11 (plus related Symfony packages and doctrine/lexer 3.0.3). `composer audit` now reports **no advisories**.
>
> Still open (ops on the server, not code): #1–#3 and #5 (credential rotation, production `.env`, demo accounts) and history cleanup.

---

## 1. Executive summary

WebZefanya has strong application-level security. Every buyer, seller, logistics, rider and admin workflow sampled enforces ownership and role checks on the server, usually inside a service and under a database row lock:

- **Prices and fees:** prices, shipping fees and vouchers are resolved by the server.
- **Vouchers and stock:** usage limits and stock are re-checked under row locks.
- **Delivery handoff:** codes are hashed, expire, and lock after 5 tries; COD must be collected exactly.
- **Sanctum tokens:** carry role abilities, expire after 60 minutes, and the account's status is re-checked on every request.
- **Identity documents:** stored on a private disk; viewing is limited to admins and logged.

The full test suite passes: **3,708 tests, 24,644 assertions, 0 failures, 1 skipped**.

The serious risks sit outside the application code:

1. **Credentials are in the history of a public GitHub repo (P0).** `KirbyLinga/Zefanya` answers unauthenticated API requests with HTTP 200, so it is public.
   - A real `APP_KEY`, DB username and DB password were committed in `mobile/.env`.
   - A hardcoded super-admin seed password was committed in `AdminSeeder`.
   - The local `.env` and the local admin no longer use these values. **Whether production ever used them is NOT VERIFIED.**
2. **Admin 2FA is off by default (P1).** The admin role can open every ID document and record refunds, payouts and settlements.
3. **Production runtime settings cannot be verified from this repository (P1, NOT VERIFIED).**
   - The local `.env` has `APP_ENV=local`, `APP_DEBUG=true`, `SESSION_SECURE_COOKIE=false` and `SESSION_ENCRYPT=false`.
   - `cloudflared` and `php` run as Windows services on this machine, and the tunnel's routing is managed remotely, so I couldn't confirm which checkout serves zefanya.shop.
   - If it is this one, finding 3 becomes P0.

**Verdict on expanded public testing:** not yet. It is safe once the P0 rotation is done and the P1 deployment checks are confirmed (section 11, steps 1–5). The application code itself showed no exploitable authorization, injection or price-tampering flaw in this audit.

---

## 2. Security scorecard

| # | Check | Status | Evidence (short) |
|---|---|---|---|
| 1 | API secret exposure | **PASS** | No secrets in tracked files (pattern scan of HEAD tree, values masked). `.env` is ignored. Mobile `env.json` is ignored. Google client IDs come from env. |
| 2 | Secrets in Git history | **FAIL** | `mobile/.env` with real `APP_KEY`/DB credentials (`4e60367d`→removed `cfbac3e5`) and a hardcoded super-admin seed password (`0eed64a0`→removed `9f3a8c85`). Reachable from `origin/master`; repo is public. |
| 3 | DB credential exposure | **PARTIAL** | DB password leaked in history (local value differs). Leaked DB username equals the current local one. Production DB user and grants NOT VERIFIED. |
| 4 | Record-level access controls | **PASS** | Policies (`BuyerOrderPolicy`, `SellerOrderPolicy`, `ConversationPolicy`, `VoucherPolicy`, `StoreSalePolicy`, `advance-shipment` gate) plus service-level `owner()`/`assert*()` under `lockForUpdate`. |
| 5 | Sensitive data encryption | **PARTIAL** | OTPs are HMAC/bcrypt-hashed; delivery code stored with an `encrypted` cast; admin TOTP secret encrypted. ID documents, licences, permits and PII (phone, birthday, address) are **not** encrypted at rest. `SESSION_ENCRYPT=false` locally. |
| 6 | Server-side authentication | **PARTIAL** | Session guards per role; Sanctum with abilities, expiry and status re-check (`ApiRole.php:59-76`). Admin 2FA is **optional** (`config/admin.php:44`, default `false`). |
| 7 | Object ownership authorization | **PASS** | Every flagged model-bound method was traced to an ownership check (section 3.2). |
| 8 | Field and price tampering | **PASS** | Server-side prices; price drift re-checked under lock (`CheckoutService.php:208`); aggregate stock check (`:237`); voucher consumption (`:303`); client shipping fees rejected (`Api/Buyer/CheckoutController.php:129`); refund capped at amount paid. |
| 9 | Session cookie security | **PARTIAL** | Code defaults are secure in production (`config/session.php:50,172`), but the local `.env` sets `SESSION_SECURE_COOKIE=false` explicitly, which overrides that default. Production value NOT VERIFIED. HttpOnly on; SameSite=lax. |
| 10 | Password hashing | **PASS** | `hashed` cast on Admin, Buyer, Seller, LogisticsProvider, Rider, User (bcrypt). One `PasswordRule` for all registrations (8–72 bytes, 4 character classes). Activation/reset tokens stored hashed. |
| 11 | Login/API rate limiting | **PASS** | Named limiters (`AppServiceProvider.php:60-127`) on every login, registration, OTP, reset, Google and 2FA route; per-email OTP lockout (5 fails → 15 min, `VerifiesEmailOtp.php:62,84,110`). Depends on a correct `TRUSTED_PROXIES` (see #19). |
| 12 | Bot protection | **FAIL** | No CAPTCHA, Turnstile or honeypot on any registration, login, password-reset or Google flow. |
| 13 | SQL injection prevention | **PASS** | All `*Raw` calls use bindings or class constants; no user input is interpolated (`Admin/RegistrationController.php:194` unions built with the query builder). |
| 14 | Input validation | **PASS** | FormRequests or inline `validate()` on every write sampled. Price `min:1/max:1,000,000`, stock `0–99,999`, money as regex/integer centavos. |
| 15 | XSS prevention | **PARTIAL** | Blade escaping throughout (only `{!! $qrSvg !!}`, which the server generates). JS sinks use `escapeHtml`. CSP is **Report-Only** with `'unsafe-inline'` (`SecurityHeaders.php:31`). |
| 16 | File upload security | **PARTIAL** | `mimes`/`image`/`max` on every upload, random names, private disk for IDs, proofs, chat, returns and refunds. Public review photos keep **EXIF** (`ProductReviewService.php:230`); no re-encoding. |
| 17 | API data exposure | **PASS** | Explicit field lists (`Api/Buyer/ProfileController`, `RiderParcelResource`). Buyer phone and address only on the rider's delivery leg; delivery code only to the owning buyer. |
| 18 | Security headers | **PARTIAL** | XFO, nosniff, Referrer-Policy and Permissions-Policy on web and API. CSP only report-only. HSTS only when `production && isSecure()`. |
| 19 | HTTPS & trusted proxies | **PARTIAL** | No wildcard proxy (`bootstrap/app.php`, `config/trustedproxy.php:32`); `forceScheme('https')` in production (`AppServiceProvider.php:144-146`). No `trustHosts()`; symfony/http-foundation 8.1.10 has an open host-related advisory. Production `TRUSTED_PROXIES` NOT VERIFIED. |
| 20 | Dependency vulnerabilities | **PARTIAL** | Composer: 7 advisories (symfony http-foundation/http-kernel/routing/yaml, all fixed in 8.1.11). npm: 0 in production dependencies; 4 in dev tooling (2 critical, 2 high). |

**Totals:** PASS 9 · PARTIAL 9 · FAIL 2 · NOT VERIFIED 0 (as whole checks; production-only items are listed in section 3.4).

---

## 3. Confirmed vulnerabilities, possible risks and unverified items

### 3.1 Confirmed findings

| ID | P | Vulnerability | Evidence | Status |
|---|---|---|---|---|
| F-01 | **P0** | Real app/DB credentials in public Git history | `mobile/.env` added `4e60367d` (2026-09-25), deleted `cfbac3e5`; contained in `origin/master`, `origin/feature/sanctum-api-auth` and 8 other remote branches; repo public | FAIL |
| F-02 | **P0** | Hardcoded super-admin seed password in public history | `database/seeders/AdminSeeder.php` added `0eed64a0` (2026-10-11), removed `9f3a8c85` (2026-10-17). Current seeder reads `ADMIN_SEED_*` env (`AdminSeeder.php:19-44`) | FAIL (history) |
| F-03 | **P1** | Admin 2FA off by default | `config/admin.php:44` `env('ADMIN_REQUIRE_2FA', false)`; `EnsureAdminTwoFactor` passes everyone when false; local `.env` doesn't enable it | FAIL (default) |
| F-04 | **P2** | No bot protection | No CAPTCHA/Turnstile/honeypot anywhere (`routes/web.php:41-160`, `routes/api.php` rider register) | FAIL |
| F-05 | **P2** | CSP not enforced | `SecurityHeaders.php:31` sends `Content-Security-Policy-Report-Only` with `script-src 'self' 'unsafe-inline'` | PARTIAL |
| F-06 | **P2** | Buyer email change needs no re-authentication | `Buyer/AccountEmailController.php:35-51`: an OTP to the *new* address is enough; the old address is told only afterwards | PARTIAL |
| F-07 | **P2** | Public review photos keep EXIF metadata | `ProductReviewService.php:230` stores the original on the `public` disk; no EXIF stripping anywhere in `app/` | FAIL |
| F-08 | **P2** | Identity documents and PII unencrypted at rest | IDs, licences, OR/CR, permits, SEC certificates on `local` disk in plain files; no `encrypted` casts on buyer/rider/seller PII | PARTIAL |
| F-09 | **P2** | No trusted-host allow-list; symfony advisory open | No `trustHosts()` in `bootstrap/app.php`; `symfony/http-foundation` v8.1.10 (`PKSA-b2gg-vk1g-6d5q` "Generated URLs Can Point to a Host Chosen by the Client") | PARTIAL |
| F-10 | **P2** | Login limits are per email+IP only | `AppServiceProvider.php:60-66,71-84`: 5/min per email+IP, 25/min per IP; nothing caps attempts per account across many IPs | PARTIAL |
| F-11 | **P3** | Dev-tool npm advisories | `concurrently`→`shell-quote` (critical), `vite`→`launch-editor` (high), `source-map-js` (high). Not in production dependencies | PARTIAL |
| F-12 | **P3** | API login timing can reveal which emails exist | `Api/Buyer/LoginController.php:75-90` (and the rider one): no `Hash::check` when the email is unknown | PARTIAL |
| F-13 | **P3** | Demo seeder passwords committed and guarded only by `APP_ENV` | `DemoRidersSeeder.php:20,84`, `DeliveryScenarioSeeder.php:54,195` | PARTIAL |
| F-14 | **P3** | Docs out of date on fleet authorization | `docs/2-Design.md:242` says "any provider if no courier"; the code limits it to the allocated carrier plus origin area (`SellerOrderFleetPolicy.php:50`, `LogisticsHubService.php:249`) | Docs only |

### 3.2 Possible risks (not exploitable in code alone)

| ID | P | Risk | Why it matters |
|---|---|---|---|
| R-01 | **P1** | The production `.env` may mirror the insecure local `.env` | If the machine running `cloudflared`/`php` serves zefanya.shop from this checkout:<br>• `APP_DEBUG=true` exposes debug pages.<br>• `APP_ENV=local` turns off `forceScheme`, HSTS and the production default for secure cookies, and lets the demo seeders run.<br>• `SESSION_SECURE_COOKIE=false` sends session cookies over plain HTTP. |
| R-02 | **P1** | Demo accounts with published passwords may exist in a shared DB | The seeders' only guard is `APP_ENV==='production'`, and their passwords are in a public repo. |
| R-03 | **P2** | A wrong `TRUSTED_PROXIES` value behind Cloudflare Tunnel | If empty or wrong, every client appears as the tunnel connector's IP. The per-IP limits (25 logins/min, 10 OTP verifications/min) then apply to *all users at once*, so one person can lock everyone out. If too broad, IPs can be spoofed. The local value is non-empty; correctness NOT VERIFIED. |
| R-04 | **P3** | Moderators hold financial powers | `routes/admin.php:115-160` (`admin.role:admin,moderator`): record and void refunds, settle return charges, suspend accounts, approve hubs. Probably intended. Confirm it matches the role model; **no change recommended without a business decision**. |

### 3.3 Mobile/API readiness notes (not vulnerabilities)

- The Flutter buyer registration posts to `/register/buyer` and `/register/buyer/verify-otp/{id}` (`Zefanya/mobile/lib/data/repositories/auth_repository.dart:480-505`). In WebZefanya these exist only as **web** routes, which need CSRF plus a signed URL (`routes/web.php:49-70`). There is no `/api/buyer/register`, so mobile buyer registration won't work against WebZefanya as written.
- Rider registration has a proper API path (`routes/api.php` `/api/rider/register`, signed OTP routes).
- Release Android builds don't allow cleartext; only `debug/` and `profile/` manifests set `usesCleartextTraffic="true"`. `kApiBaseUrl` isn't checked for `https://`.

### 3.4 NOT VERIFIED (needs access I didn't have or wasn't permitted)

| Item | Why not verified | How to verify (read-only) |
|---|---|---|
| Production `APP_KEY` / DB password equal the leaked values | No production access | On the production host, compare hashes: `php -r 'echo hash("sha256", getenv("APP_KEY"));'` against the hash of the leaked value. Never print the raw value. |
| Production admin still uses the historical seed password | No production access | On production: `php artisan tinker`, then `Hash::check(<historical>, Admin::where(...)->value('password'))`. Locally: the admin row with the historical email exists, **0 password matches**. |
| Demo accounts with known passwords in the local MySQL DB | My read-only credential check against the live DB was **blocked by the tool's permission policy** | Owner runs the same `Hash::check` count per table (`admins`, `buyers`, `sellers`, `logistics_providers`, `riders`). |
| Which checkout and `.env` serve zefanya.shop | Tunnel ingress is token-managed (`cloudflared tunnel run --token-file`) | Cloudflare Zero Trust dashboard → Tunnels → Public hostnames → origin service. |
| Production `APP_DEBUG`, `APP_ENV`, `SESSION_*`, `TRUSTED_PROXIES`, `ADMIN_REQUIRE_2FA`, `LOG_LEVEL`, `MAIL_MAILER` | Same | `php artisan about --only=environment` on the production host. |
| Production MySQL user privileges | No DB admin access | `SHOW GRANTS FOR CURRENT_USER();` |

---

## 4. Git history and credential risks (Task 4)

**Scan:** `git log --all` across 274 refs (branches, remotes, local Cline checkpoints). File-name scan plus content-pattern scan (`-G`) for API keys, private keys, `APP_KEY=base64:`, `*_PASSWORD=`, Google client IDs. Vendor and node_modules excluded. All values masked.

| Commit(s) | File | Content (masked) | Reachable from | Still active? |
|---|---|---|---|---|
| `4e60367d` → `cfbac3e5` (2026-09-25) | `mobile/.env` | `APP_KEY` (base64), `DB_USERNAME`, `DB_PASSWORD`, `DB_DATABASE`; Redis/Mail passwords were `null` | `origin/master`, `origin/feature/sanctum-api-auth`, `origin/feature/s1-lite`, `origin/mobile-dev`, `origin/integrate-latest-web`, +5 more; current `HEAD` | **Local:** `APP_KEY` differs, `DB_PASSWORD` differs, `DB_USERNAME`/`DB_DATABASE` **same** (hash comparison). **Production: NOT VERIFIED.** |
| `0eed64a0` → `9f3a8c85` (2026-10-11 → 10-17) | `database/seeders/AdminSeeder.php` | Literal super-admin password plus email | Same remote branches | **Local:** account exists, password **no longer matches**. **Production: NOT VERIFIED.** |
| `d57d659c`, `66f16f03` | docs / `GoogleSignIn.xcconfig.example` | Placeholder client IDs (`123456789…`) | — | Not secrets |
| `419f3166`, `3698cbe4`, `8ff38263` | `.env.example`, handoff docs | `REDIS_PASSWORD=null`, `MAIL_PASSWORD=null` | — | Not secrets |
| tracked HEAD | `DemoRidersSeeder.php:20`, `DeliveryScenarioSeeder.php:54` | Demo passwords | All branches | Dev-only by design (see F-13 / R-02) |

**Impact if production ever used the leaked `APP_KEY`:**
- Forge signed URLs: OTP pages, resubmission links, local-disk temporary URLs.
- Decrypt or forge encrypted cookies.
- Decrypt `code_encrypted` delivery codes and encrypted admin TOTP secrets.
- Recompute registration OTP HMACs.

**Recommended actions, in two separate tracks:**

- **A. Rotation (do first; history cleanup doesn't un-leak anything)**
  1. On every environment that ever used the leaked values: generate a new `APP_KEY` during a maintenance window. Put the old key in `APP_PREVIOUS_KEYS` only if existing encrypted data must stay readable, then re-encrypt and remove it. Also re-issue admin 2FA secrets, which are encrypted with the old key.
  2. Change the MySQL password for the leaked username everywhere, and stop using `root` for the app (create a least-privilege user).
  3. Rotate the seeded super-admin: reset its password, and delete or rename the historical email account per `docs/2-Design.md` §4.6.1.
  4. Invalidate sessions and Sanctum tokens after rotating the key (clear the `sessions` table; `personal_access_tokens` expire within 60 minutes anyway).
- **B. History cleanup (optional, coordinated, after A)**
  - Use `git filter-repo --path mobile/.env --invert-paths` plus a `--replace-text` rule for the seeder password, run on a fresh mirror clone, then force-push all branches and tags, and have every collaborator re-clone.
  - Ask GitHub Support to purge cached views, PR refs and forks.
  - Delete or prune the local `cline/checkpoints/*` refs, which also contain the commits.
  - *I didn't do any of this; it needs owner coordination.*

---

## 5. Buyer security findings

| Workflow | Result | Evidence |
|---|---|---|
| Registration | PASS | `throttle:register` (5/min per email+IP, 30/min per IP); `PasswordRule`; ID on private disk (`RegisterBuyerController.php:32`); re-registration deletes the old file. |
| OTP | PASS | Signed temporary URLs (`routes/web.php:61-70`); HMAC-hashed code, 10-minute expiry; per-email lockout (5 fails → 15 min) that a resend can't reset (`VerifiesEmailOtp.php`). |
| Google login | PASS | Socialite state; verified email required; one `google_id` per account; email-linking only when `email_verified_at` is set (`GoogleAuthController.php:216-240`); only buyer/seller/logistics roles (`:56-60`); `throttle:google-auth`. |
| Products / catalog | PASS | Public reads throttled at 120/min; server prices. |
| Cart | PASS | All writes scoped by `buyer_id` (`CartService.php:121,168,188`); quantity 1–999. |
| Vouchers | PASS | Locked re-resolve at checkout; `times_used` incremented (`CheckoutService.php:303`); `per_buyer_limit` (`VoucherSessionService.php:275`). |
| Checkout / COD | PASS | Cart rows and prices from the DB, locked (`CheckoutService.php:154-246`); client shipping fees rejected (`Api/Buyer/CheckoutController.php:129`); fingerprint and quote token. |
| Orders / tracking | PASS | `BuyerOrderPolicy`; API `abort_unless(order.buyer_id === user)` (`Api/Buyer/OrderController.php:16,21-22`). |
| Cancellation / returns | PASS | `authorizeForUser(...,'cancelSellerOrder'/'view')` plus the `shipment.order_id` match (`Api/Buyer/OrderActionController.php:36-37,59-60,87-89`). |
| Refunds (buyer side) | PASS | Recorded only by admin or moderator; capped at the amount paid. |
| Notifications | PASS | `$buyer->notifications()->find()` (`Api/Buyer/NotificationController.php`). |
| Messaging | PASS | `ConversationPolicy::view`; image route checks the conversation-message link (`Api/Buyer/MessageController.php:66-74`). |
| Account | PARTIAL | Password change needs the current password plus an OTP (good). **Email change has no re-authentication (F-06).** Profile photo on the private disk. |
| Delivery code display | PASS | `activeCodeFor()` only for the owning buyer's shipment; code encrypted at rest (`DeliveryOtp.php:11,15`). |

## 6. Seller security findings

| Workflow | Result | Evidence |
|---|---|---|
| Login / session | PASS | `throttle:login` (`routes/seller.php:27`); `seller.approved` logs out unapproved accounts. |
| Product management / stock | PASS | `authorizeOwnership`; validation (`StoreProductRequest.php:301-337`); stock changes through `StockService`. |
| Store categories | PASS | `StoreCategoryService.php:26,82` plus `assign()` ownership. |
| Discounts / vouchers / store sales | PASS | `SellerVoucherRequest::authorize` and `StoreSaleRequest::authorize` → `VoucherPolicy`/`StoreSalePolicy`; value bounds validated. |
| Fulfillment | PASS | `SellerOrderPolicy::markReady` before `SellerOrderStatusService::transition`. |
| Review replies | PASS | `SellerReplyRequest::authorize` plus `assertSellerOwns` under lock. |
| Earnings / payouts | PASS | Idempotency key; minimum ₱100; balance check; admin pay locks seller plus request (`SellerPayoutRequestService.php:62-76,157-171`). |
| Debts / settlements | PASS (route-level) | Settlements require `admin.role:super_admin` (`routes/admin.php:89-92`). Service internals were sampled, not read line by line; the suite passes. |
| Return decisions | PASS | Return photo stream authorized via `SellerOrderPolicy::view` (`Seller/ReturnController.php`). |

## 7. Logistics and rider security findings

| Workflow | Result | Evidence |
|---|---|---|
| Rider approval | PASS | `RiderRegistrationService::decide` locks the provider, re-checks it's approved (`authorizeProvider`, `:367-370`) and scopes the rider to the provider (`:178-180`). The JSON API at `routes/logistics.php:185` lacks `logistics.approved` but is protected in the service. |
| Hubs / coverage / areas | PASS | Admin approval for hubs and location changes; provider-scoped area services. |
| Parcel custody / allocation | PASS | `advance-shipment` gate: custody → courier → delivery courier → allocated `carrier_provider_id` plus origin area (`SellerOrderFleetPolicy.php`, `LogisticsHubService.php:249`). |
| Transfers / handoffs / manifests | PASS | `assertOriginProvider`/`assertDestinationProvider`/`assertOriginCustody`, scan token verification (`ParcelHandoffService`). |
| Shipping allocations / rates | PASS | Rates snapshotted at quote; locked re-validation at checkout. |
| COD remittances | PASS (route-level) | Logistics confirm is session plus `logistics.approved`; admin confirm/reject is `admin.role:admin`. Internals sampled. |
| Returns | PASS | `ParcelReturnService::owner()` on every logistics action; buyer collection limited to the provider's own active riders. |
| Deductions / return charges | PASS (route-level) | Admin/moderator only. |
| Rider pay | PASS | `payCourier` requires the courier to belong to the provider, locks rows, matches the expected amount (`RiderEarningService`). |
| Rider registration / auth | PASS | `throttle:register`; signed OTP routes; `resume` requires email plus password; activation token random 32 bytes, stored as SHA-256, 24-hour expiry, single use (`Logistics/RiderController.php:210-216`, `Rider/ActivationController.php`). |
| Token refresh | PASS | Old token deleted in the same transaction; new token carries the `rider` ability and a 60-minute expiry (`Api/Rider/TokenController.php:33-35`); expired tokens can't refresh (`ApiRole.php:59`). |
| Pickup / delivery start | PASS | `assertPickupAssigned`/`assertDeliveryAssigned` plus single-use scan verification tokens. |
| Delivery OTP | PASS | `random_int`, bcrypt hash, 10-minute expiry, 5-attempt cap, tied to courier and buyer, consumed under lock (`DeliveryOtpService.php:38-47,120-136`); send throttled at 5/min. |
| Proof of delivery | PASS | `mimes:jpg,jpeg,png,webp`, 5 MB, private disk, server-side MIME detection (`DeliveryProofImage::storeMime`); viewable only by the submitting rider or the owning provider. |
| COD collection | PASS | Amount must equal `payment.amount_minor` exactly, under lock (`RiderOperationsService.php:599-617`). |
| Incidents | PASS | `authorizeReporter`/`authorizeProviderForParcel`; one open incident per parcel; private photos. |
| Earnings | PASS | Scoped to the authenticated rider. |
| Suspended-provider wind-down | PASS | `api.role:rider,wind-down` admits only the allow-listed routes; pickups hidden; services refuse anything except finishing parcels already out (`ApiRole.php`, `Api/Rider/JobController.php`). |

## 8. Admin security findings

| Workflow | Result | Evidence |
|---|---|---|
| Login | PARTIAL | `throttle:login`; session regenerated; **2FA optional (F-03)**. TOTP implementation, recovery codes and `admin-2fa` throttle are present. |
| Role permissions | PASS (R-04 to review) | `EnsureAdminRole` (super_admin bypass; role list); finance under `admin`; settlements under `super_admin`. |
| Identity documents | PASS | `admin.role:admin` only (moderators excluded); whitelisted types and keys; `DocumentAccessLog::record` before streaming; download with nosniff (`Admin/DocumentController.php:46-63`, `routes/admin.php:109-112`). |
| Approvals | PASS | `admin.role:admin,moderator`; decision services. |
| Refunds | PASS | Amount ≤ amount paid, one per return, reason required for partial refunds, under lock (`ParcelReturnService.php:202-220`); proof on the private disk. |
| Financial reconciliation | PASS (route-level) | Remittances and payouts under `admin`; settlements under `super_admin`; idempotent services. |
| Account suspension | PASS | Status change is re-checked on every API request (`ApiRole.php:76`) and in web middleware (`buyer.active`, `seller.approved`, `logistics.approved`, `rider.active`). |
| Audit logs | PASS | `OperationsAuditLog::record` in rider/provider decisions; `admin_document_access_logs`; `delivery_handover`/return events. |

## 9. Sanctum API security

| Control | Result | Evidence |
|---|---|---|
| Bearer-only (no session fallback) | PASS | `ApiRole` rejects `TransientToken` (`ApiRole.php:55-57`); Sanctum `guard => ['web']` has no session on `/api` routes. |
| Role isolation | PASS | Model class **and** `tokenCan('buyer'|'rider')` (`ApiRole.php:63-65`); a buyer token can't reach rider routes. |
| Expiry | PASS | `config/sanctum.php:53` → 60 min; `expires_at` set at issue; scheduled pruning (`routes/console.php`). |
| Status re-check each request | PASS | `accountIsLoginable` (verified email, approved/active, operational rider). |
| Logout / revocation | PASS | Deletes the current token. |
| Throttles | PASS | Every API route has a route-level throttle (10–120/min); `api-login` per email+IP. |
| Ownership | PASS | See sections 5 and 7. |
| Response shape | PASS | Consistent JSON 401/403 (`bootstrap/app.php` renderable). |
| CORS | PASS | No `config/cors.php` → framework default without credentials; bearer tokens aren't sent automatically by browsers. |
| Deployment status | NOT VERIFIED | Most API controllers and resources are **untracked**. Commit and deploy them deliberately, with their tests (`tests/Feature/Buyer/Buyer*ApiTest.php`, `tests/Feature/Rider/RiderMobile*ApiTest.php`). |

## 10. Dependency audit results

**Composer (`composer audit --locked`): 7 advisories.**
- `symfony/http-foundation` v8.1.10: CVE-2026-118514, PKSA-pr6q-2w5s-kzzf, PKSA-nxjs-s6hn-vb6m, PKSA-b2gg-vk1g-6d5q.
- `symfony/http-kernel` (PKSA-3gxv-7xhq-mgmz), `symfony/routing` (CVE-2026-118516), `symfony/yaml` v8.1.6 (PKSA-nktx-f1n1-b4yd).
- All are fixed in **≥ 8.1.11**.
- Current with no advisories: `laravel/framework` v13.35.0, `laravel/sanctum` v4.3.3, `laravel/socialite` v5.31.0, `firebase/php-jwt` v7.2.0, `league/commonmark` 2.10.6.

**npm (`npm audit --package-lock-only`):**
- Production dependencies: **0**.
- Full tree: 4 (2 critical, 2 high), all in **dev tooling**: `concurrently`→`shell-quote`, `vite`→`launch-editor`, `source-map-js`. They don't ship to users but affect developer machines.

**Fix:**
```bash
composer update "symfony/*" --with-dependencies
npm audit fix
```
Then rebuild assets and rerun the suite.

---

## 11. Prioritized remediation checklist

| # | P | Action | Type |
|---|---|---|---|
| 1 | P0 | Confirm on the production host whether `APP_KEY` / DB password match the leaked values (hash comparison). Rotate if they ever did. Move the app off the `root` DB user. | Ops |
| 2 | P0 | Confirm the production super-admin doesn't use the historical seed password; rotate and remove or rename the historical-email account. | Ops |
| 3 | P1 | Confirm which checkout/.env serves zefanya.shop. Production must have `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true` (or unset), `SESSION_ENCRYPT=true`, `LOG_LEVEL=warning`, `MAIL_MAILER=smtp`. | Ops |
| 4 | P1 | Set `ADMIN_REQUIRE_2FA=true` in production and enrol all admins. Optionally make the default `true` when `APP_ENV=production`. | Config / small code |
| 5 | P1 | Check that no demo-seeded accounts with published passwords exist in any shared DB; remove or reset them. | Ops |
| 6 | P2 | Verify `TRUSTED_PROXIES` equals the cloudflared connector's address (log `$request->ip()` once). Add `$middleware->trustHosts(at: ['zefanya.shop'])`. | Config / code |
| 7 | P2 | `composer update "symfony/*"` to ≥ 8.1.11. | Dependency |
| 8 | P2 | Add Cloudflare Turnstile (or similar) to the 4 web registrations, unified login, forgot-password and the rider API registration. Use Turnstile/Play Integrity for mobile. | Code |
| 9 | P2 | Require `current_password` in `AccountEmailController::request` (the OTP to the new address stays). | Code |
| 10 | P2 | Strip EXIF from public review photos (re-encode with GD before `store('reviews/...','public')`). | Code |
| 11 | P2 | Move the CSP from Report-Only to enforced once reports are clean; plan to remove `'unsafe-inline'` (nonces). | Code |
| 12 | P2 | Add a per-account login limiter (e.g. 20/hour by email, any IP). | Code |
| 13 | P2 | Encrypt identity documents at rest (encrypt on write, decrypt in `DocumentController`/`RiderApplicationController`), or use an encrypted volume or S3 SSE-KMS. | Code / Ops |
| 14 | P3 | Run a dummy `Hash::check` when the email is unknown in API login controllers. | Code |
| 15 | P3 | `npm audit fix` (dev tooling). | Dependency |
| 16 | P3 | Review moderator financial powers (R-04) as a business decision. | Decision |
| 17 | P3 | Fix `docs/2-Design.md:242` fleet-policy description. | Docs |
| 18 | P3 | Add `/api/buyer/register` + OTP endpoints (or change the mobile flow) before shipping mobile buyer registration. | Code (readiness) |
| 19 | — | History cleanup (section 4, track B), coordinated with all collaborators. | Ops |

## 12. Exact files requiring changes

| Fix | Files |
|---|---|
| #4 2FA default | `config/admin.php:44` (optional), production `.env` |
| #6 trusted hosts / proxies | `bootstrap/app.php` (`withMiddleware`), production `.env` (`TRUSTED_PROXIES`) |
| #7 symfony | `composer.lock` |
| #8 bot protection | `resources/views/**/register*` and login modal views, `app/Http/Requests/{Buyer,Seller,Logistics,Rider}/Store*RegistrationRequest.php`, `app/Http/Controllers/Auth/UnifiedLoginController.php`, `app/Http/Controllers/Auth/PasswordResetController.php`, new `app/Rules/Turnstile.php`, `config/services.php` |
| #9 email re-auth | `app/Http/Controllers/Buyer/AccountEmailController.php:35-51`, buyer account email view |
| #10 EXIF | `app/Services/Buyer/ProductReviewService.php:230` (and seller logo/banner at `SellerStoreProfileService.php:41`, product images at `ProductPublishingService.php:352` if desired) |
| #11 CSP | `app/Http/Middleware/SecurityHeaders.php:31` |
| #12 per-account limiter | `app/Providers/AppServiceProvider.php:60-84` |
| #13 document encryption | `RegisterBuyerController.php:32`, `RegisterSellerController.php:27-33`, `RegisterLogisticsController.php:28-29`, `RiderRegistrationService.php:57`, `RegistrationResubmissionService.php:52`, `Logistics/RiderController.php:63-64`, `Admin/DocumentController.php`, `Logistics/RiderApplicationController.php` |
| #14 timing | `app/Http/Controllers/Api/Buyer/LoginController.php:73-92`, `app/Http/Controllers/Api/Rider/LoginController.php` (same method) |
| #17 docs | `docs/2-Design.md:242` |
| #18 mobile registration | `routes/api.php`, new `app/Http/Controllers/Api/Buyer/RegistrationController.php`, or `Zefanya/mobile/lib/data/repositories/auth_repository.dart:480-505` |

## 13. Tests needed for each fix

| Fix | Regression tests |
|---|---|
| #1–#3, #5 (ops) | Post-deploy smoke test: `php artisan about` shows production/debug off; a response over HTTPS has a `Secure` session cookie and an HSTS header; old signed OTP URLs return 403 after key rotation. |
| #4 2FA | Feature: with `admin.require_two_factor=true`, an unenrolled admin is redirected from `/admin/dashboard`, `/admin/documents/...` and finance routes to enrolment; an enrolled admin needs the TOTP before `Auth::login`; recovery code is single-use (extend `tests/Feature/Admin/*TwoFactor*`). |
| #6 proxies / hosts | Extend `tests/Feature/Security/TrustedProxyTest.php`: a request with a forged `Host` gets 400/404 and doesn't generate links to that host; a forged `X-Forwarded-For` from an untrusted peer doesn't change `$request->ip()`; a trusted peer's header is honoured. |
| #7 symfony | Full suite plus `HttpsUrlGenerationTest`. |
| #8 bot protection | Registration/login/reset without a token → 422; with a mocked valid token → success; existing registration and login tests updated to send a fake token (`Http::fake` for siteverify). |
| #9 email re-auth | Wrong or missing `current_password` → error and no OTP sent (`Notification::assertNothingSent`); correct → OTP to the new address; old-address notice still sent after verify. |
| #10 EXIF | Upload a JPEG with GPS EXIF → the stored public file has no EXIF GPS (`exif_read_data` empty); image dimensions preserved; invalid image still rejected. |
| #11 CSP | `SecurityHeadersTest`: enforced `Content-Security-Policy` header present on web and API; the chat-widget `frame-ancestors 'self'` exception kept; browser smoke test on checkout, scanner and chat. |
| #12 per-account limiter | 21st failed login for one email from rotating IPs → 429; successful login unaffected for other emails. |
| #13 encryption | Stored ID bytes ≠ uploaded bytes; admin stream returns the original bytes plus an access-log row; rider document stream for the owning provider works; other provider → 404. |
| #14 timing | Unit: unknown-email path calls `Hash::check` (spy) and returns the same 401 body as a wrong password. |
| #18 mobile registration | API registration → 201 with a signed verify URL; verify with the wrong code ×5 → 429 lock; resend limited to 3/10 min; Flutter `auth_repository` tests against the new paths. |

Coverage today (keep green): 3,708 tests passing, including `tests/Feature/Security/{SecurityHeadersTest,TrustedProxyTest}.php`, `tests/Feature/Http/PublicStorageTest.php`, and the untracked `tests/Feature/Buyer/Buyer{Commerce,Shipment}ApiTest.php` and `tests/Feature/Rider/RiderMobile{,Ops}ApiTest.php`.

---

## Appendix A: verification of the previous `Zefanya/web` audit (Task 3)

| Old finding | In WebZefanya | Evidence | Status |
|---|---|---|---|
| APP_KEY / DB password in Git history | **Confirmed**, and the repo is public | Section 4 | FAIL |
| Admin/seller login unthrottled | Not present | `routes/admin.php:40`, `routes/seller.php:27`, `routes/web.php:143` → `throttle:login` | PASS |
| `trustProxies('*')` | Not present | `bootstrap/app.php` comment, `config/trustedproxy.php:32` default none; production value NOT VERIFIED | PASS (code) |
| Voucher `times_used` never incremented | Not present | `CheckoutService.php:303`; refunded on cancel (`SellerOrderStatusService.php:526-527`) | PASS |
| Duplicate lines bypass stock | Not present | Cart rows unique per buyer/variant; aggregate check `CheckoutService.php:237` under lock | PASS |
| Tokens never expire / ignore status | Not present | `sanctum.php:53`, `ApiRole.php:59-76` | PASS |
| Pending Google buyer gets API token | Not present | API tokens only via `/api/*/login` after `accountIsLoginable`; Google is web-session only | PASS |
| Phone OTP / PII in logs | Not present | No `Log::` call with email/otp/phone/token/password in `app/` | PASS |
| Missing security headers | Mostly fixed | `SecurityHeaders.php` on web and API; CSP report-only | PARTIAL |
| Session cookie not Secure | Code fixed; local `.env` overrides | `config/session.php:172` vs local `.env` | PARTIAL |
| Vulnerable dependencies | Reduced | 7 symfony advisories remain | PARTIAL |
| Moderators can approve and view IDs | Fixed for IDs | Documents `admin.role:admin` only; moderators can approve (intended) | PASS |
| Courier can do seller transitions | Not present | Rider API exposes only pickup/start/deliver/fail with assignment and scan-token checks | PASS |
| Email change without re-auth | **Confirmed** (web) | `AccountEmailController.php:35` | PARTIAL (F-06) |
| No per-account OTP cap | Not present | `VerifiesEmailOtp.php:62,84,110` | PASS |
| No bot protection | **Confirmed** | — | FAIL (F-04) |
| IDs unencrypted at rest | **Confirmed** | Private disk, plain files | PARTIAL (F-08) |
| Public review photos keep EXIF | **Confirmed** | `ProductReviewService.php:230` | FAIL (F-07) |
| Raw model JSON in seller API | Not present | Explicit arrays and resources; `RiderApplicationController::index` uses an explicit column list for the owning provider | PASS |
| Admin ID links broken (unsigned) | Not present | `Admin/DocumentController::stream` | PASS |
| Weak registration password policy | Not present | `PasswordRule` everywhere | PASS |
| `{!! $title !!}` latent XSS | Not present | Only server-generated `{!! $qrSvg !!}` | PASS |
| Mobile https enforcement | Partial | Release manifest has no cleartext; `kApiBaseUrl` not validated | PARTIAL (P3) |

## Appendix B: audit notes

- The working tree changed during the audit: some `resources/views/Buyer/**` files were modified by someone else. This audit made **no** edits apart from creating this report.
- One read-only check (counting local accounts that use published demo passwords) was **blocked by the tool's permission policy** and is listed as NOT VERIFIED (section 3.4).
- "PASS (route-level)" means the route's role gate and the service's entry checks were verified and the tests pass, but every branch of that service wasn't read line by line.
