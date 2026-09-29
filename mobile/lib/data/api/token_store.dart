// lib/data/api/token_store.dart
//
// Where the bearer token lives.
//
// ## Why an interface and not just a call to flutter_secure_storage
//
// Two reasons, and the second is the one that bit:
//
//  1. **Tests.** A widget or unit test cannot use the platform keystore — it
//     throws on a missing channel in the test binding. Without an injectable
//     store, every test that touches an authenticated path would have to mock
//     a plugin channel; with it, tests pass [InMemoryTokenStore] and the
//     production code is unaware.
//  2. **Keychain vs Keystore asymmetry.** `flutter_secure_storage` is a real
//     dependency with real platform behaviour (iOS Keychain entries that
//     survive app uninstall unless cleared, Android Keystore that can be
//     invalidated by a device credential change). Keeping it behind one small
//     interface means the app's *auth* logic never depends on those details —
//     and a future decision to move to a different vault is one file.
//
// ## What is stored, and what is deliberately not
//
// Only the token. Not the role, not the profile, not the expiry.
//
// That is on purpose: everything else about a session is the *server's*
// answer and should come back from the API on each cold start rather than
// being re-asserted from local disk. A locally-stored `role: courier` is a
// privilege claim this app would be trusting itself about, which is exactly
// the class of bug `config/dev_flags.dart` exists to close (see the
// "Login as: Buyer | Courier" selector it gates). The token is the only thing
// here that must be secret; the rest must merely be *current*.

import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Reads and writes the bearer token.
abstract class TokenStore {
  /// The stored token, or null when there is no session.
  Future<String?> read();

  /// Replaces the stored token.
  Future<void> write(String token);

  /// Forgets the token. Called on logout, and on a `401` — a token the server
  /// has rejected is worse than no token, because it keeps producing 401s.
  Future<void> clear();
}

/// Production implementation, backed by the platform keystore/keychain.
class SecureTokenStore implements TokenStore {
  SecureTokenStore({FlutterSecureStorage? storage})
      : _storage = storage ?? const FlutterSecureStorage();

  final FlutterSecureStorage _storage;

  /// Namespaced, so this app's token can't collide with another app's on a
  /// shared iOS keychain (where the default accessibility group is per-team,
  /// not per-app).
  static const _key = 'zefanya.bearer_token';

  @override
  Future<String?> read() => _storage.read(key: _key);

  @override
  Future<void> write(String token) => _storage.write(key: _key, value: token);

  @override
  Future<void> clear() => _storage.delete(key: _key);
}

/// A store that forgets everything when the process ends.
///
/// For tests, and for the one legitimate production case: a build with no
/// secure storage available (a desktop debug target) where the alternative is
/// a crash. Deliberately *not* used as a fallback behind [SecureTokenStore] —
/// silently degrading a security property is worse than failing, so any
/// fallback has to be chosen explicitly here rather than discovered later.
class InMemoryTokenStore implements TokenStore {
  String? _token;

  @override
  Future<String?> read() async => _token;

  @override
  Future<void> write(String token) async => _token = token;

  @override
  Future<void> clear() async => _token = null;
}