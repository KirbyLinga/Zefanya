# Courier profile progress

## 1. Live profile visibility

Implemented in this stage. Account → Company & profile opens a read-only profile using the existing authenticated `/api/courier/profile` endpoint. The screen reads the server rather than reusing locally saved identity details. It displays name, email, account status, email verification, company and home hub using the shared Zefanya typography, colors, cards and loading/error states. Long names remain scrollable at 360 × 640 and 1.3 text scale. Retry and pull-to-refresh are supported; revoked access removes previously displayed details.

The response is an explicit allowlist with private/no-store caching. Caller-supplied driver/company/hub selectors are rejected; there is no profile-by-ID endpoint. No password, token, OTP, company registration file, coordinates or private document location is returned.

Verification: 70 related PHP tests / 1,071 assertions passed; 12 Courier mobile tests passed. Analysis reports the same eight existing issues, none in the new profile code. The real local demo account returned Demo Pila Courier, Demo Laguna Express and Pila Station Hub; the verification token was logged out. No dev migration or demo trip mutation occurred. No web frontend source changed, so a web build was unnecessary. Pint remains waived/unrun.

## 2. Vehicle, license and private documents — OPEN

The C1 source is `../docs/qa/csv/C1 Profile & Vehicle.csv`. This stage does **not** complete COU-PROF-001: license, vehicle and the three document previews are still absent. COU-PROF-002–005 and 008 remain open. Profile ownership and suspended-account refusal have automated coverage supporting 006–007, but actual device document selection/camera acceptance is unverified.

Continue with additive storage, Off Duty edits, capacity/plate/license validation, private license/OR-CR/NBI documents, replacement safety and owning-Logistics re-verification. Plate checks must include existing Rider records and address concurrent cross-role registration; do not claim a query-only check guarantees uniqueness. Expiry today must be accepted and yesterday refused. Files must be validated by actual content, capped at 5 MB, and readable only by their Courier or owning authorized Logistics. Editing must not let a Courier approve documents or change company, home hub, activation or account verification.

Prepare and test the complete submission/review workflow before requesting the separate local migration exception. Preserve the existing demo trips and duty readiness rules. Do not fabricate license numbers, plates, documents, passed reviews or GPS locations for the demo account.
