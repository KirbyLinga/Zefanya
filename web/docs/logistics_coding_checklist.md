# Logistics / Sorting Center Coding Checklist

> **System context (for AI):** Zefanya is a Laravel 11 / PHP 8.x marketplace. The logistics surface covers sorting-center operators and couriers. As of 2026-09-22, logistics **registration and authentication are fully built** — `LogisticsProvider` model (`App\Models\Logistics\LogisticsProvider`), `logistics_providers` table (migrated 2026-09-21), `LogisticsProviderStatus` enum (cases: `PendingVerification`, `PendingApproval`, `Approved`, `Rejected`), `RegisterLogisticsController`, `VerifyLogisticsOtpController`, OTP notifications, and admin approve/reject routes all exist and pass 21 feature tests (`LogisticsRegistrationTest`). A `DashboardController` exists but its view is a stub. The **UI entry card** on the register-type page is **disabled ("Coming soon")** — the backend routes are live but the front-end trigger is intentionally hidden. No logistics auth guard, no courier management, no parcel/delivery/sorting tables exist yet. Per the settled architecture: Logistics owns the entire courier lifecycle; Admin must not manage couriers; Sellers end at READY FOR PICKUP and never touch delivery status.

> **Audit legend:** `[x]` = working (code + route + backing data verified) · `[ ]` = not implemented. *Italics* flag known defects, shells, or dependencies.

---

## Foundation / Registration

- [x] Logistics / sorting center registration backend *(`RegisterLogisticsController` + `StoreLogisticsRegistrationRequest`)*
- [x] Personal info fields (first/last name, middle initial, sex, email, contact number, birthday, age)
- [x] Address (PSGC API dropdowns + manual fallback; street/house number always required)
- [x] Business name
- [x] Upload valid ID *(required; jpg/jpeg/png/pdf ≤5 MB)*
- [x] Upload business / DTI permit *(required; jpg/jpeg/png/pdf ≤5 MB)*
- [x] Registration form validation *(email uniqueness across buyers + sellers + logistics)*
- [x] OTP email verification *(SHA-256 stored, logistics-specific `LogisticsRegistrationOtp` notification)*
- [x] Pending approval status *(`pending_verification` → `pending_approval` after OTP)*
- [x] Administrator approval *(`Admin\RegistrationController@approveLogistics`)*
- [x] Administrator rejection *(with rejection reason)*
- [x] Approval / rejection email notifications *(`LogisticsRegistrationDecision`)*
- [x] Account activation after approval *(status set to `approved`)*
- [ ] **UI entry card ("Register as Logistics")** — *intentionally disabled on register-type page ("Coming soon"); backend routes live*
- [x] `GET /register/logistics` — *redirects to register-type page (fixed from previous 500)*
- [x] `POST /register/logistics` *(throttled 5/min)*
- [x] OTP verify + resend routes
- [x] Pending page *(`Logistics.register-logistics-pending`)*

---

## Login & Authentication

- [x] `Logistics\DashboardController` exists *(referenced by `GET /logistics` route)*
- [ ] Logistics auth guard *(no `logistics` guard in `config/auth.php`; no `logistics_providers` guard/provider defined)*
- [ ] Logistics login page / route
- [ ] Approved-account verification, pending/rejected handling
- [ ] Logistics authentication middleware
- [ ] Logistics-only route protection
- [ ] Logout

---

## Logistics Dashboard

- [ ] Logistics dashboard page *(stub only — `DashboardController@index` references an unverified view)*
- [ ] All dashboard stats (pending couriers, active riders, parcels, deliveries)

---

## Rider / Courier Management

> Not started — no `courier_applications` or `couriers` tables.

- [ ] All courier management features (view applications, approve/reject, activate/deactivate, profile, notifications)

---

## Parcel Pickup Requests

> Not started — no `seller_orders`, `deliveries`, or pickup tables.

- [ ] All pickup request features

---

## Incoming Parcels

> Not started.

- [ ] All incoming parcel features

---

## Parcel Sorting

> Not started.

- [ ] All sorting features

---

## Delivery Assignment

> Not started.

- [ ] All delivery assignment features

---

## Delivery Monitoring

> Not started.

- [ ] All delivery monitoring features

---

## Delivery Status & Tracking

> Not started — no `delivery_status_history` table.

- [ ] All tracking / status update features

---

## Reports

> Not started.

- [ ] All logistics report features

---

## Chat / Messaging

> Not started — no conversation/message tables.

- [ ] All chat features

---

## Account Management

> Not started.

- [ ] All account management features (profile, business info, documents, address)

---

## Security & Authorization

- [ ] Logistics auth guard *(not defined in `config/auth.php`)*
- [ ] Logistics-only route protection
- [ ] Role-based access (logistics can manage couriers, parcels, deliveries)
- [ ] Logistics cannot access admin-only functions / seller products / buyer accounts
- [ ] Authorization policies *(no `app/Policies` exist project-wide)*
- [ ] Activity / action logging

---

## UI & Responsive

- [ ] All UI / responsive features (dashboard, courier mgmt, parcels, sorting, delivery, reports, chat, account)

---

## Testing

- [x] Logistics registration tests *(`LogisticsRegistrationTest` — 21 tests: route fix, store validation, cross-role email rejection, uploads, OTP flow, admin approve/reject, admin registrations view)*
- [x] Logistics login flow tests *(`LogisticsLoginFlowTest`)*
- [x] Logistics dashboard test *(`LogisticsDashboardTest`)*
- [ ] Courier application / approval tests
- [ ] Parcel / sorting / delivery / assignment / monitoring tests
- [ ] Reports / chat / account tests
- [ ] Authorization tests

---

## Complete Logistics / Sorting Center Flow

- [x] Register (backend)
- [x] Submit ID and business / DTI permit
- [x] Wait for administrator approval
- [x] Receive approval email
- [ ] Login *(no auth guard)*
- [ ] View logistics dashboard
- [ ] Review and approve courier applications
- [ ] Receive seller pickup request
- [ ] Receive + verify + sort incoming parcels
- [ ] Assign delivery to rider
- [ ] Monitor delivery
- [ ] Parcel delivered / failed
- [ ] Generate reports / chat / manage account
- [ ] Logout

---

## Known defects / open items (as of 2026-09-22)

1. **No logistics auth guard** — `config/auth.php` defines only `buyer`/`seller`/`admin` guards. A `logistics` guard + provider must be added before any authenticated logistics routes can be built.
2. **UI registration card is disabled** — the "Register as Logistics" card on `/register` is a non-clickable "Coming soon" `<div>`; the backend routes are live and fully tested.
3. **`DashboardController` view unverified** — `GET /logistics` exists; view content not audited.
4. **No courier tables** — no `courier_applications`, `couriers`, `deliveries`, `delivery_status_history`, or sorting tables exist.
5. **No Laravel Policies** anywhere in the project — required before courier management, parcel handling, or delivery assignment are built.
6. **Architecture boundary** — Logistics owns the ENTIRE courier lifecycle (application → approval → courier account → delivery assignment → monitoring). Admin must not manage couriers. Sellers must never assign couriers or touch delivery status after READY FOR PICKUP.
7. **All 127 tests pass** as of 2026-09-22, including 21 logistics registration tests.
