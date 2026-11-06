// lib/config/dev_flags.dart
//
// One switch for every piece of tester-only UI in the app.
//
// The app currently ships two controls that are not features, they're
// scaffolding standing in for a backend that doesn't exist yet:
//
//   - login_screen.dart's "Login as: Buyer | Courier" selector, which
//     exists because there's no API response to read a role from
//   - pending_approval_screen.dart's "Simulate Approval (Test)" button,
//     which exists because there's no admin to do the approving
//
// Both are fine in a debug build and both are a privilege-escalation
// hole in a real one: the first lets anyone grant themselves courier
// access, the second lets anyone approve their own registration. They
// were each marked "TEMP"/"TEST-ONLY" in a comment, which protects
// nothing at build time. This does.
//
// Default: on in debug/profile, off in release. To force it off while
// still running a debug build (e.g. demoing to the class, or checking
// what a real user would actually see):
//
//   flutter run --dart-define=zefanya.devTools=false
//
// and to force it on in a release build, which you should basically
// never do:
//
//   flutter build apk --release --dart-define=zefanya.devTools=true

import 'package:flutter/foundation.dart';

/// Whether tester-only affordances are shown. Compile-time constant, so
/// anything behind `if (kShowDevTools)` is tree-shaken out of a release
/// build entirely rather than being hidden at runtime.
const bool kShowDevTools = bool.fromEnvironment(
  'zefanya.devTools',
  defaultValue: kDebugMode,
);

// ---------------------------------------------------------------------------
// Backend mode
// ---------------------------------------------------------------------------
//
// Which side of the mock/real boundary the app runs on. This is the ONE
// switch; every repository choice reads it (see data/repositories/
// repositories.dart) and nothing else in the app is allowed to ask "are we
// online?" — a screen that branches on the backend mode would have two
// behaviours to keep in step forever.
//
// Default is **off**, which means mock. That default is load-bearing rather
// than a convenience, though its reason has moved: the API exists now (Sanctum,
// routes/api.php) for buyers and for the catalogue, so most of the app has a
// real backend to talk to. What keeps the default at mock is the boot path —
// two of the nine loads in `main()` fetch over the network (orders, courier
// jobs) and have no endpoint yet, and `main()` awaits them before `runApp`, so
// a build that defaulted to live would reach no first frame at all. Turning it
// on is a deliberate act:
//
//   flutter run --dart-define=zefanya.useLiveApi=true \
//               --dart-define=zefanya.apiBaseUrl=https://api.example.test/api
//
// Compile-time rather than runtime so the mock data and the mock
// repositories are tree-shaken out of a live release build, and so a live
// build cannot be switched back to mock by editing a config file on a
// device.
const bool kUseLiveApi = bool.fromEnvironment(
  'zefanya.useLiveApi',
  defaultValue: false,
);

/// Capabilities that exist on one side of the mock/live boundary.
///
/// Chat is the first: the live API has no conversation endpoints yet, so every
/// chat entry point is hidden there rather than opening a thread that cannot
/// send. Kept as a named capability instead of sprinkling `!kUseLiveApi`
/// through screens, so the day the server grows chat this is the one line that
/// changes.
class FeatureFlags {
  FeatureFlags._();

  /// Whether chat is available in this build. Mock only for now.
  static const bool chatLive = !kUseLiveApi;
}

/// Base URL of the Laravel API, including the `/api` prefix.
///
/// Empty when unset. [ApiClient] treats an empty base URL as a configuration
/// error and throws *before* any request rather than sending one to a
/// relative path that would silently resolve against nothing.
///
/// Note there is no default pointing at a dev host: a hardcoded default would
/// make "point it at staging" a code change, and it would be the one string
/// most likely to get committed by accident.
const String kApiBaseUrl = String.fromEnvironment('zefanya.apiBaseUrl');

/// Whether the API config is complete enough to attempt a request.
///
/// Separate from [kUseLiveApi] because the two failures are different and
/// deserve different messages: "the backend isn't wired up" (expected, mock
/// mode) versus "you asked for live mode but didn't say where the API is"
/// (a misconfiguration someone has to fix).
bool get isApiConfigured => kUseLiveApi && kApiBaseUrl.isNotEmpty;

// ---------------------------------------------------------------------------
// Mock backend vocabulary
// ---------------------------------------------------------------------------
//
// The two values that exist only because the mock has no mail to send with.
// They live here rather than inside `MockAuthRepository` because *two* parties
// need them: the mock, which decides the answer, and the OTP screen, which has
// to tell a tester what to type in a dev-tools build. One constant with one
// owner — a screen carrying its own copy of `'123456'` would keep working after
// a change here and be wrong in the least noticeable way available.

/// The confirmation code a **mock** registration accepts.
///
/// Every other code is rejected exactly as the server rejects a wrong one, so
/// the failure path is demonstrable rather than merely written.
const String kMockOtpCode = '123456';

/// The handle a mock registration's confirmation is sent against.
///
/// The real one is the server's own row id (`register/buyer/verify-otp/{buyer}`)
/// and this stands in for it because a mock registration has no row. It is what
/// `AuthState.debugStartEmailVerification` puts on the session so the OTP screen
/// has something to send the code back against.
const String kMockVerificationId = 'mock-verification';

// ---------------------------------------------------------------------------
// Google sign-in
// ---------------------------------------------------------------------------
//
// Client IDs are configuration, not secrets: they identify the app to Google
// and are visible in every build. They are still supplied at build time rather
// than committed, because a real ID belongs to one Google Cloud project and
// one package/signing identity — the same code has to work for a teammate's
// debug build, a staging build, and the store build.
//
//   flutter run \
//     --dart-define=zefanya.googleServerClientId=... \
//     --dart-define=zefanya.googleIosClientId=...
//
// Android only needs the *web* (server) client ID. iOS also needs the iOS
// client ID and its reversed form in Info.plist — see
// mobile/docs/google_sign_in_contract.md.

/// The web OAuth client ID Google uses as the token audience on Android.
///
/// Empty by default, and [NativeGoogleSignInService] refuses before opening a
/// native sheet when it is. That is the honest failure: a Google flow with no
/// audience configured can only produce a token the backend must reject.
const String kGoogleServerClientId = String.fromEnvironment(
  'zefanya.googleServerClientId',
);

/// The iOS OAuth client ID. Android ignores it.
const String kGoogleIosClientId = String.fromEnvironment(
  'zefanya.googleIosClientId',
);

/// Google OAuth configuration supplied at build time via
/// `--dart-define-from-file=env.json` (keys
/// `zefanya.googleServerClientId` and `zefanya.googleIosClientId`).
///
/// Real client IDs identify one Google Cloud project and one
/// package/signing fingerprint, so they are supplied at build time and never
/// committed. With no server client ID the ID token has no audience to verify
/// against, so the Google flow cannot complete and the button must stay hidden.
class GoogleConfig {
  GoogleConfig({
    required this.serverClientId,
    required this.iosClientId,
  });

  /// Build-time values from the dart-defines. Empty when the build does not
  /// supply them.
  GoogleConfig.fromEnvironment()
      : serverClientId = String.fromEnvironment('zefanya.googleServerClientId'),
        iosClientId = String.fromEnvironment('zefanya.googleIosClientId');

  /// The web (server) OAuth client ID — the ID token audience on Android.
  final String serverClientId;

  /// The iOS OAuth client ID. Android ignores it.
  final String iosClientId;

  /// Whether a real Google client ID is configured. When `false` the Google
  /// sign-in button must not be shown anywhere: the flow cannot complete.
  bool get isConfigured => serverClientId.trim().isNotEmpty;

  /// The build-time configuration. Empty in builds that do not supply the
  /// dart-defines.
  static final GoogleConfig current = GoogleConfig.fromEnvironment();
}

/// The mock Google account, chosen at build time.
///
/// Mock mode has no Google account picker, so the tester says which identity
/// the fake sign-in should return. The default is an existing buyer; the mock
/// repository also knows `miguel.santos@gmail.com` (courier) and
/// `pending.buyer@gmail.com` (pending approval). Any other address exercises
/// the new-account role chooser and prefilled registration.
const String kMockGoogleEmail = String.fromEnvironment(
  'zefanya.mockGoogleEmail',
  defaultValue: 'tristan.araneta@gmail.com',
);
