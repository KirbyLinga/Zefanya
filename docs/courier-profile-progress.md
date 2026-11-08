# Courier profile progress

## 1. Live profile visibility

Implemented in this stage. Account → Company & profile opens a read-only profile using the existing authenticated `/api/courier/profile` endpoint. The screen reads the server rather than reusing locally saved identity details. It displays name, email, account status, email verification, company and home hub using the shared Zefanya typography, colors, cards and loading/error states. Long names remain scrollable at 360 × 640 and 1.3 text scale. Retry and pull-to-refresh are supported; revoked access removes previously displayed details.

The response is an explicit allowlist with private/no-store caching. Caller-supplied driver/company/hub selectors are rejected; there is no profile-by-ID endpoint. No password, token, OTP, company registration file, coordinates or private document location is returned.

Verification: 70 related PHP tests / 1,071 assertions passed; 12 Courier mobile tests passed. Analysis reports the same eight existing issues, none in the new profile code. The real local demo account returned Demo Pila Courier, Demo Laguna Express and Pila Station Hub; the verification token was logged out. No dev migration or demo trip mutation occurred. No web frontend source changed, so a web build was unnecessary. Pint remains waived/unrun.

## 2. Vehicle, license and private documents — implemented and enabled locally

The C1 source is `../docs/qa/csv/C1 Profile & Vehicle.csv`. Account → Company & profile → Vehicle records now reads/saves professional license/codes/expiry, truck type/plate/capacity and three private documents. Courier edits require Off Duty and no active trip. All three initial documents are required; later replacements retain unchanged documents. JPG/PNG content and PDF MIME are checked, <=5 MB each. Expiry today is accepted and yesterday refused in Manila time. Duplicate plates are normalized and checked across Rider/Courier application writes under a shared row lock. Stale submissions/reviews are refused.

Logistics → Couriers lists only the owning company's drivers. Open a Courier to view records, download documents, confirm/correct capacity and approve or request corrections with a note. Changes return to Pending review and prevent readiness until approved with a nonexpired license. Review targets a specific version and cannot be overwritten by replay. File routes require authenticated ownership/company authorization; private paths are never returned. Images preview in the app; PDFs use native open/share/save after authenticated download. Actual camera/Files/PDF handling on a phone remains unverified.

Presentation compatibility: **any account without a vehicle submission retains its prior access**; no-submission is not document approval. Mandatory onboarding/activation of every provisioned Courier remains a separate item. No real license, plate, documents, passed review, checklist or GPS has been fabricated. Existing demo data/trips were not advanced. Local schema activation is complete under the specific owner exception below. COU-PROF-001–008 have relevant automated coverage, but real deployment/device/manual acceptance and concurrent MySQL requests remain OPEN; do not mark the workbook's manual cases passed.

Final verification: 193 PHP tests / 2,081 assertions; full mobile `03:29 +901: All tests passed!`; final focused `00:11 +19: All tests passed!`; analysis same eight existing issues; web build 8.72s. Eighteen security faults detected and restored (mutation-proof.md); temporary branch deleted. Pint waived/unrun. No existing dependency version upgraded; file_selector 1.1.0 and its three missing platform implementations were added.

## 3. Local activation — DONE under the owner's explicit exception

Owner approved running **only** `2026_11_08_200000_create_linehaul_profiles.php` against the verified local `zefanya_friend_20261107` database. Completed in 397.78ms, alone in its migration batch. Both tables exist, mutex id 1, zero vehicle records, one Courier; batch #1 remains RECEIVED and #2–4 remain DRAFT. No other pending migration was run. Actual API `setup_required: false`, `editable: false`, `profile: null`; the demo account still has no real documents submitted. Existing account/trip/order/stock/payment values remain unchanged.

The existing PHP defaults are upload_max_filesize=2M and post_max_size=8M. From the project root, the new local launcher is:

```powershell
.\tools\serve-local.ps1 -Php C:\PHP\php.exe
```

It binds 127.0.0.1:8000 with process-only 5M uploads / 20M requests. First stop only the identified existing local backend, then use this launcher; don't run both on the same port or edit global php.ini/.env. Launcher syntax/startup and unauthenticated route 401 were verified on temporary port 18000; that owned test process was stopped. The old preview/backend processes had ended, and both ports were confirmed free; the launcher now runs on 8000 and the recompiled preview on 5175. Actual demo login/vehicle GET returned setup pending before migration and `setup_required: false` afterward, with `no-store, private`; test tokens were logged out. Browser preflight returned 204. Real non-saving HTTP probes accepted three simultaneous 5 MB image parts through file validation and rejected oversized parts. Required expiry was intentionally omitted, so every probe returned 422 before file storage/submission; reconfirmed zero vehicle records. A hosting deployment still needs equivalent PHP limits.

The demo Courier already has a draft active assignment: finish its handover and go Off Duty before editing vehicle records. Do not discard the assignment or fabricate documents to bypass that rule. Next: user-supplied real documents and a Courier → owning Logistics review rehearsal. GPS/background/push/pay and other Courier modules remain separately open.
