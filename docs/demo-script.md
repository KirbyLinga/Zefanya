# Demo and recording in the newer checkout

Run commands from `mobile/`. This guide applies to this checkout's `/api/buyer` and `/api/rider` backend, not the original checkout.

## Offline rehearsal

1. Run `flutter run --dart-define=USE_LIVE_API=false` (add `-d chrome` for the browser).
2. Use the mock buyer login with a valid email and password. Browse Home, search and categories; open a product, add it to the cart, check out with COD, and open Orders.
3. Open Profile, Settings and Appearance; demonstrate the theme switch. Log out through Settings, confirm, then press Back: the buyer shell must remain closed.
4. Log in with the mock rider role. Open pickup/delivery jobs, earnings and Profile. These are mock demonstrations, not claims of a real parcel delivery.
5. On Windows press Win+Alt+R to start/stop Game Bar recording of the selected app window (if Game Bar is unavailable, use Snipping Tool's Record control). Start at the launch screen, perform steps 2–4, stop, and play the saved video to confirm the entire run and readable text. Hide notifications and avoid showing passwords while recording.

## Local live browser

Start this checkout's Laravel server separately; use its existing MySQL configuration. Do not migrate or replace the development database as a demo step.

`flutter run -d chrome --dart-define=USE_LIVE_API=true --dart-define=zefanya.apiBaseUrl=http://127.0.0.1:8000/api`

Use actual approved buyer/rider accounts. Login, browse catalogue images, open Orders, and log out. Public images are served directly by the existing storage route; no storage link is required. Localhost on a phone refers to the phone itself and will not reach the PC.

## APK builds and installation

- Mock: `flutter build apk --release --dart-define=USE_LIVE_API=false`. Save the generated `build/app/outputs/flutter-apk/app-release.apk` as `demo-mock.apk` before building another mode.
- Live: obtain the active HTTPS backend URL for **this checkout**, including `/api`. Build with `flutter build apk --release --dart-define=USE_LIVE_API=true --dart-define=zefanya.apiBaseUrl=YOUR_HTTPS_API_BASE_URL`. Save as `demo-live.apk`.
- Install one mode at a time using `adb install -r demo-mock.apk` or `adb install -r demo-live.apk`. The package identity is shared, so installing one replaces the other. Clear app data when changing modes to remove stale sessions and mock data.
- Do not commit APKs, credentials, `env.json`, `.env`, database dumps or private documents.

The friend's tunnel is currently off and no HTTPS URL is available. A usable live APK and physical-device acceptance remain OPEN. Older APKs from the original checkout were not rebuilt or validated against this backend.
