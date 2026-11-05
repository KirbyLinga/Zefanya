# Demo setup — S2.3

Zefanya supports two demo builds:

1. **demo-mock** (default): runs entirely offline against mock repositories. No
   network needed. Recommended for the presentation.
2. **demo-live**: runs against a live Laravel API over an HTTPS tunnel.

Both use `--dart-define-from-file=env.json` (see `mobile/env.example.json`).

## Prerequisites

- Flutter SDK installed (`flutter doctor` clean)
- An Android device or emulator (Play Services needed for Google sign-in)
- For demo-live: a running Laravel backend reachable via an HTTPS tunnel
  (e.g. ngrok, Cloudflare Tunnel), plus storage:link run and DB seeded

## Debug build over LAN (demo-mock)

```powershell
# From the repo root
cd mobile

# Copy the example config (no real client IDs needed for mock mode)
Copy-Item env.example.json env.json

# Edit env.json if you want Google sign-in (mock mode ignores client IDs):
#   zefanya.useLiveApi=false   (default)
#   zefanya.devTools=true      (shows dev-only affordances)

flutter run --dart-define-from-file=env.json
```

The app label is `Zefanya`. Mock mode is the default (`kUseLiveApi=false`),
so no backend is required and no first-frame network fetch blocks startup.

## Release build against an HTTPS tunnel (demo-live)

```powershell
cd mobile

# env.json must point at the live backend:
#   zefanya.useLiveApi=true
#   zefanya.apiBaseUrl=https://<tunnel-host>/api
#   zefanya.devTools=false

# Debug tunnel build (for LAN testing against a public tunnel URL)
flutter run --release --dart-define-from-file=env.json

# Release APK for distribution
flutter build apk --release --dart-define-from-file=env.json
```

### Build result (verified)

```
flutter build apk --release --dart-define-from-file=env.json
  → build/app/outputs/flutter-apk/app-release.apk  (~78 MB)
  → No R8 or plugin warnings
  → flutter analyze: No issues found
```

### Release safety checklist

- `android/app/src/main/AndroidManifest.xml`: `INTERNET` + `CAMERA` +
  `ACCESS_COARSE_LOCATION` + `ACCESS_FINE_LOCATION` only
- `android/app/src/debug/AndroidManifest.xml`:
  `usesCleartextTraffic="true"` (debug only)
- `android/app/src/profile/AndroidManifest.xml`:
  `usesCleartextTraffic="true"` (profile only)
- Main manifest: no `usesCleartextTraffic` (release blocks cleartext)
- App label: `Zefanya`
- `API_BASE_URL` comes from `zefanya.apiBaseUrl` dart-define (no hardcoded
  dev host)
- Google client IDs come from `zefanya.googleServerClientId` /
  `zefanya.googleIosClientId` (never committed — see `mobile/env.example.json`)

## Backend prerequisites for demo-live

Run from `web/`:

```powershell
php artisan storage:link
php artisan migrate --seed
php artisan demo:seed   # (S3.1, when implemented)
```

The backend must be reachable over HTTPS (mixed-content blocks HTTP from a
release WebView). Use an HTTPS tunnel pointing at `php artisan serve`.

## What must be rehearsed on a phone

Per the SCHEDULE OVERRIDE §10.2, the following cannot be fully automated and
must be rehearsed on a physical device:

- Google sign-in with a real account (needs Play Services + SHA-1 registered)
- Camera/photo picker permission flow (avatar, review, proof, documents)
- Delivery pass QR scan (camera, flashlight toggle, manual entry fallback)
- Push notifications / deep links (documented limitations — not wired)
- Full 7-minute buyer/rider/seller/admin walkthrough (docs/demo-script.md)
