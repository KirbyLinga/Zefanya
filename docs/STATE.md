# State

Branch: feature/s1-lite  HEAD: 70710e0  Date: 2026-11-05
Stage: S2 DONE (2.1 MEDIA, 2.2 GOOGLE SIGN-IN, 2.3 RELEASE SAFETY). Next: S3-lite (3.1 demo:seed idempotent, 3.3 mock parity, 3.4 docs/demo-script.md, 3.5 limitations page).
Base check: branch cut from integration/hardened @ 6a9605a.

## Test counts (HEAD 70710e0)
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

## Open
- API admin logistics approve/reject has no success test; Admin lacks HasApiTokens. Recorded for S6 route/test sweep.

