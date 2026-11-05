Branch: feature/s1-lite  HEAD: ef7caf1  Date: 2026-11-05
Stage: S4 IN PROGRESS (4.1 intro gate DONE, 4.2 banners DONE, 4.3 brand mark DONE, 4.4 loaders: replace CircularProgressIndicator with ZefanyaLoader, add skeletons to Orders list, add loader/skeleton tests).
Base check: branch cut from integration/hardened @ 6a9605a.

## Test counts (HEAD ef7caf1)
- php artisan test: 439 passed, 0 failed (2082 assertions)
- flutter test: 762 passed, 0 failed
- flutter analyze: No issues found

## S1-lite done
- 1.1: docs/feature-status.md rewritten — 35 mobile screens table.
- 1.2: docs/spec-conformance.md — 101 High-priority cases across B1-B14 + R1-R7.

## S2 done
- 2.1 MEDIA: single MediaPicker (permission before launch, denial→Open Settings, cameraOnly/cameraOrGallery modes, PDF for documents, resize ≤1280px JPEG q80, EXIF/GPS stripped, ≤5MB, upload progress UI ready). Server-side DIAGNOSIS-media.md. Test: media_picker_test.dart (refusal mapping, messages).
- 2.2 GOOGLE SIGN-IN: GoogleConfig from env.json dart-defines; button hidden when not configured (+debug log); friendly "not available" message; error mapping (cancelled=silent, config, network, timeout, account-conflict 409). Tests: google_sign_in_test.dart (button shown in mock mode, tap doesn't crash). Docs: GOOGLE_SETUP.md.
- 2.3 RELEASE SAFETY: cleartext traffic only in debug/profile manifests; API base URL from API_BASE_URL env.json; app label "Zefanya"; env.json ignored, env.example.json committed. Docs: DEMO_SETUP.md. Release APK built (~78 MB), no R8/plugin warnings.

## S3-lite done
- 3.1 demo:seed — DemoSeeder seeds accounts (Buyer 1-2 Approved, Seller 1-3, Logistics 1-2, Rider 1-3), ADDR-1 to ADDR-4, categories, products P-01 to P-08, vouchers (incl. expired, not-started, exhausted), shipping rates, delivery areas AREA-A/B/C, sample orders per status (delivered via OrderDeliveryService). Idempotent via updateOrCreate on natural keys. `demo:seed` command refuses non-local/demo env.
- 3.3 mock parity — mock repositories support all demo flows (auth, catalog, cart, orders, addresses, reviews, chat, courier, returns). 762 Flutter tests pass.
- 3.4 docs/demo-script.md — 7 min per role (buyer, rider, seller, admin), mock-mode + live for LIVE-READY features, failure plans, pre-demo checklist, build instructions.
- 3.5 docs/limitations-for-presentation.md — one page: push notifications, deep links, payment gateway, chat, flash-sale creation, live tracking, each with one sentence.

## S4 in progress
- 4.1 Intro gate: landing_screen.dart with two-beat animation, reduced motion support, tests exist.
- 4.2 Banners: PromoCarousel on Home, auto-advance, manual swipe, dots.
- 4.3 Brand mark: ZefanyaMark (static) and ZefanyaLoader (animated) widgets.
- 4.4 Loaders TODO: replace CircularProgressIndicator (5 remaining), skeletons on Orders list, loader/skeleton tests.

## Open
- API admin logistics approve/reject has no success test; Admin lacks HasApiTokens. Recorded for S6 route/test sweep.

