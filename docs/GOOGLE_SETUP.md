# Google sign-in setup — S2.2

Zefanya supports "Continue with Google" on the login screen. Real Google
accounts use `NativeGoogleSignInService`, which needs a Google Cloud OAuth
client ID for the platform. Without one, the button is hidden and the reason
is logged in debug — never a button that cannot work.

## Build-time configuration

Client IDs are supplied via `--dart-define-from-file=env.json` (or individual
`--dart-define` flags). They identify one Google Cloud project and one
package/signing fingerprint, so they are never committed.

| Dart-define key | Purpose | Example |
|-----------------|---------|---------|
| `zefanya.googleServerClientId` | Web (server) OAuth client ID — the ID token audience on Android | `123456789.apps.googleusercontent.com` |
| `zefanya.googleIosClientId` | iOS OAuth client ID (Android ignores it) | `123456789-abc.apps.googleusercontent.com` |
| `zefanya.mockGoogleEmail` | Mock identity for dev/test builds | `tristan.araneta@gmail.com` |

Example `mobile/env.json` (not committed):

```json
{
  "zefanya.googleServerClientId": "123456789.apps.googleusercontent.com",
  "zefanya.googleIosClientId": "123456789-abc.apps.googleusercontent.com",
  "zefanya.mockGoogleEmail": "tristan.araneta@gmail.com",
  "zefanya.useLiveApi": "false",
  "zefanya.apiBaseUrl": "",
  "zefanya.devTools": "true"
}
```

Run commands:

```powershell
# Debug build over LAN (mock mode, default)
flutter run --dart-define-from-file=env.json

# Release build against HTTPS tunnel (live mode)
flutter build apk --release --dart-define-from-file=env.json
```

## Google Cloud console steps

1. Create a project in the [Google Cloud Console](https://console.cloud.google.com/).
2. Enable the Google Sign-In API.
3. Create **OAuth 2.0 Client IDs**:
   - **Web application** — this is the *server* client ID. It is used as the
     `aud` (audience) when verifying ID tokens on Android. Set
     `zefanya.googleServerClientId` to this value.
   - **Android application** — package name `com.zefanya.app` plus the
     SHA-1 fingerprint of your signing certificate. Debug and release
     fingerprints differ, so you need two Android clients (or reuse the web
     client ID for Android, which is what Zefanya does).
   - **iOS application** — bundle ID `com.zefanya.app`. Set
     `zefanya.googleIosClientId` to this value.
4. Add test users while the OAuth consent screen is in **Testing** mode.
   Without test users, sign-in fails for accounts not listed as test users.

## SHA-1 commands

Debug and release fingerprints differ:

```powershell
# Debug SHA-1
keytool -list -v -alias androiddebugkey -keystore "%USERPROFILE%\.android\debug.keystore"

# Release SHA-1 (your release keystore)
keytool -list -v -alias <release-alias> -keystore <path-to-release-keystore>
```

## Emulator requirements

The emulator needs Google Play services installed. A plain AOSP emulator
cannot complete the Google sign-in flow.

## Backend configuration

The Laravel endpoint `POST /auth/google` verifies the ID token's signature,
issuer, expiry, and audience. The backend `GOOGLE_CLIENT_IDS` env var must
include the web client ID (the ID token audience). If empty, the endpoint
returns 503 (fail-closed).

## Error mapping

| Error | User-facing behavior |
|-------|---------------------|
| User cancels the account sheet | Silent — no snackbar, no navigation |
| No client ID configured | Button hidden in live mode; friendly message shown if reached |
| Network failure | "No internet connection. Please check your network and try again." |
| Timeout | "The request timed out. Please try again." |
| Account conflict (409) | "This Google account is already linked to a different account. Please sign in with email and password instead." |
| Other config/device errors | Service-specific message from `NativeGoogleSignInService` |

## Never commit real IDs

Client IDs are configuration, not secrets, but they are tied to one
Google Cloud project and one package/signing fingerprint. The same code
must work for a teammate's debug build, a staging build, and the store
build — so they are supplied at build time, never in source control.
