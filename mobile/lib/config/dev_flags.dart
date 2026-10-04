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

