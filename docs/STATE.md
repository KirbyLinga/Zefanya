Branch: feature/s1-lite  HEAD: a6b91b8  Date: 2026-11-05
Stage: S4 DONE, S5-lite IN PROGRESS (5.1 avatar, 5.2 settings, 5.4 cart count, 5.5 chat entry mock only, 5.6).
Base check: branch cut from integration/hardened @ 6a9605a.

## Test counts (HEAD a6b91b8)
- php artisan test: 439 passed, 0 failed (2082 assertions)
- flutter test: 765 passed, 0 failed
- flutter analyze: 5 info-level suggestions only (sized_box_for_whitespace, no errors/warnings)

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

## S4 done
- 4.1 Intro gate: landing_screen.dart with two-beat animation, reduced motion support, tests exist.
- 4.2 Banners: PromoCarousel on Home, auto-advance, manual swipe, dots.
- 4.3 Brand mark: ZefanyaMark (static) and ZefanyaLoader (animated) widgets.
- 4.4 Loaders: replaced CircularProgressIndicator with ZefanyaLoader in 4 screens (registration, address form, QR scanner, edit profile); added skeletons to Orders list with isLoading flag in OrderState; added loader/skeleton tests.

## S5-lite in progress
- 5.1 Avatar: AvatarPicker (Take photo, Choose from gallery, Remove) built on MediaPicker.
- 5.2 Settings: gear icon on Profile opens SettingsScreen with working items only.
- 5.4 Cart Count: replace quantity-sum count with distinct product count.
- 5.5 Chat Entry: FeatureFlags.chatLive = false in live mode, chat icon in Home header in mock mode.
- 5.6: Long product descriptions collapse after 3 lines with "Read more"; destructive actions ask for confirmation.

## Open
- API admin logistics approve/reject has no success test; Admin lacks HasApiTokens. Recorded for S6 route/test sweep.

