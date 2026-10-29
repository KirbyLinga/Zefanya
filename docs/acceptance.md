# End-to-End Multi-Role Acceptance Checklist (Phase D)

## 1. Overview
This document tracks the complete end-to-end multi-role story spanning all four marketplace actors:
**Buyer**, **Seller**, **Rider (Courier / Logistics)**, and **Admin**.

The story validates data integrity, business logic gates, and API communication across Laravel and Flutter on the real database schema.

---

## 2. Automated Multi-Role Test Suite

The automated feature test is located at:
[`web/tests/Feature/EndToEndAcceptanceTest.php`](file:///c:/Users/Lenovo/Documents/lavarel/Zefanya/web/tests/Feature/EndToEndAcceptanceTest.php)

### Story Verification Steps (Automated)

| Step | Actor | Action & Endpoint | Verified Rules & Assertions | Status |
|---|---|---|---|---|
| **1** | Buyer | `POST /register/buyer` | Multipart registration with valid valid ID upload, pending verification state created, verification ID returned. | **PASSED** |
| **2** | Buyer | `POST /register/buyer/verify-otp/{buyer}` | 6-digit OTP verification unlocks account, sets `email_verified_at`, issues Sanctum bearer token. | **PASSED** |
| **3** | Buyer | `POST /api/login/buyer` | Password authentication returns valid Sanctum bearer token and profile details. | **PASSED** |
| **4** | Buyer | `POST /api/v1/buyer/addresses` | Normalized PSGC address selection with Province (`Cebu`), City, Barangay; snapshot formatting. | **PASSED** |
| **5** | Buyer | `GET /api/products` | Catalog browsing shows seller business name (`Amihan Crafts Studio`), stock, price, and initial rating. | **PASSED** |
| **6** | Buyer | `POST /api/buyer/wishlist/{product}` & `GET /api/buyer/wishlist` | Toggling wishlist adds product to buyer wishlist item collection. | **PASSED** |
| **7** | Buyer | `POST /api/orders` | Applies voucher (`SAVE100`), calculates centavo discount allocation, places atomic COD order with stock locking. | **PASSED** |
| **8** | Seller | `PATCH /seller/orders/{order}/confirm` & `PATCH /seller/orders/{order}/ready` | Seller confirms order and marks `READY_FOR_PICKUP` with printed tracking number (`ZEF-CEB-998811`). | **PASSED** |
| **9** | Rider | `POST /api/register/courier` | Rider submits application with license, OR/CR, and ID photo; state is `pending_approval`. | **PASSED** |
| **10** | Admin | `POST /admin/registrations/logistics/{rider}/approve` | Administrator reviews and approves courier registration; status advances to `approved`. | **PASSED** |
| **11** | Rider | `POST /api/login/courier` & `GET /api/courier/jobs` | Rider logs in, fetches assigned dispatch feed showing delivery fee (₱85.00) and COD amount (₱2,300.00). | **PASSED** |
| **12** | Rider | `PATCH /api/courier/jobs/{job}/milestone` | Rider scans parcel waybill at seller pickup; status moves to `PICKED_UP`. | **PASSED** |
| **13** | System | Hub dispatch to `OUT_FOR_DELIVERY` | Hub pipeline assigns parcel for delivery, generates 6-digit OTP hash (`482913`). | **PASSED** |
| **14** | Rider & Buyer | `PATCH /api/courier/jobs/{job}/milestone` | Buyer presents delivery pass; Rider enters OTP and collects exact COD cash; status moves to `DELIVERED`, payment marked `paid`. | **PASSED** |
| **15** | Buyer | `PATCH /api/orders/{order}/items/{product}/review` & `GET /api/reviews/mine` | Buyer submits 5-star review with photo; verified purchase badge attached; product average rating updates to 5.0. | **PASSED** |

---

## 3. Automated Test Execution Results

### Backend Suite (`web/`)
- Command: `php artisan test`
- Tests: **303 passed** (0 failed)
- Assertions: **1,325 passing assertions**
- Acceptance Test: [`EndToEndAcceptanceTest.php`](file:///c:/Users/Lenovo/Documents/lavarel/Zefanya/web/tests/Feature/EndToEndAcceptanceTest.php) executed in 2.34s with 56 assertions.

### Mobile Suite (`mobile/`)
- Command: `flutter analyze`
  - Result: **0 issues found** (clean analysis).
- Command: `flutter test`
  - Result: **733 tests passed** (0 failed).

---

## 4. Manual Physical Device Verification Checklist

For hardware integrations that cannot be fully exercised in CI/headless runners, verify the following on a physical Android/iOS test device:

### A. Camera & Document Capture
- [ ] **Buyer ID Verification:** Tap upload ID button in registration flow; verify camera opens, captures image, previews photo, and compresses for upload.
- [ ] **Rider Registration Documents:** Capture Driver's License and OR/CR using device camera; confirm multi-photo preview before submitting.
- [ ] **Rider Proof of Delivery Photo:** Capture parcel delivery photo at buyer doorstep; verify file is stored and thumbnail renders in delivery history.
- [ ] **Buyer Product Review Photo:** Capture photo during review composition; confirm photo attachment displays in product review listing.

### B. Barcode / QR Scanning & Waybill Verification
- [ ] **Parcel QR Scan:** Use camera scanner on parcel label with barcode `ZEF-CEB-998811`; verify autofocus and instant recognition.
- [ ] **Buyer Delivery Pass QR:** Scan `ZEFANYA:DELIVERY:{jobId}:{otp}` QR presented on buyer device screen; verify OTP autofills in rider handover dialog.

### C. GPS & Location Services
- [ ] **Rider Real-Time Location:** Verify GPS permission prompt; ensure live coordinates update rider location during active transit.
- [ ] **Delivery Area Distance Calculation:** Verify distance calculation between seller pickup address and buyer address.

### D. Native Google Sign-In
- [ ] **Google OAuth Credential Manager:** Tap "Sign in with Google" on physical device; verify native account chooser sheet appears.
- [ ] **Backend Verification:** Confirm ID token exchanges successfully against `POST /api/v1/auth/google` returning Sanctum session.
