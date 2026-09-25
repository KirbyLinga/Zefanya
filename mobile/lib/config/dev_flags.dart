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
