// lib/config/app_info.dart
//
// The version shown in Settings > About. Kept as a constant rather than read
// from package_info_plus: the app has no other use for the package, and one
// hand-maintained string is cheaper than a dependency whose value would still
// need a fallback in tests. Keep in step with `pubspec.yaml`'s `version:`.

/// Marketing version of this build, without the build number.
const String kAppVersion = '1.0.0';

/// Taps on Settings > About needed to reveal Developer options.
const int kDeveloperOptionsTapCount = 7;
