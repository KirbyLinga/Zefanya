# Zefanya mobile (buyer and rider app)

Flutter app for Zefanya buyers and riders. It runs in two modes:

- **Mock mode** (default): offline, sample data, no backend needed.
- **Live mode**: talks to the Laravel backend in `../web` through `/api`.

## Requirements

- Flutter SDK with Dart `^3.12` (`flutter doctor` clean)
- Android device or emulator (Google Play services for Google sign-in)
- For live mode: the backend in `../web` reachable over **HTTPS**
  (cleartext HTTP is not enabled), e.g. through a Cloudflare Tunnel or ngrok

## First run

```bash
cd mobile
flutter pub get
cp env.example.json env.json   # Windows: Copy-Item env.example.json env.json
flutter run --dart-define-from-file=env.json
```

`env.json` is git-ignored. Never commit it or put real keys in
`env.example.json`.

| Key | Meaning |
|---|---|
| `USE_LIVE_API` | `"true"` for live mode, `"false"` for mock mode |
| `zefanya.apiBaseUrl` | Backend API URL including `/api`, e.g. `https://<host>/api` |
| `zefanya.googleServerClientId` / `zefanya.googleIosClientId` | Google sign-in client IDs (optional) |
| `zefanya.devTools` | `"true"` shows developer-only tools; keep `"false"` for demos |

## Live mode: backend steps

Run these in `../web` on the machine serving the API:

```bash
composer install
npm ci && npm run build
cp .env.example .env && php artisan key:generate   # first time only
php artisan migrate        # includes the payment_cancellation_refunds and shipping_rates tables
php artisan storage:link
```

Then set `USE_LIVE_API` to `"true"` and `zefanya.apiBaseUrl` to the HTTPS URL
in `env.json`.

Rider features in live mode use these endpoints (all bearer-token, rider
ability): jobs, availability, pickup confirm / failed pickup / finish stop,
deliveries, COD (`/rider/cod`), return pickups, payouts, waybills, proofs,
history and profile. The server owns every rule (attempt limits, cancellation,
COD confirmation, custody); the app only displays and requests.

## Build an APK

```bash
flutter build apk --release --dart-define-from-file=env.json
# -> build/app/outputs/flutter-apk/app-release.apk
```

Release builds are currently signed with the **debug key**
(`android/app/build.gradle.kts`). That is fine for testing and demos, not for
a Play Store release.

## Checks

```bash
flutter analyze
flutter test
```
