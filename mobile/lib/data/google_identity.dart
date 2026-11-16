// lib/data/google_identity.dart
//
// What a successful native Google sign-in hands the app.
//
// This is deliberately *not* a session. The ID token is proof of a Google
// identity, and nothing more: it says who Google thinks the person is, not
// whether Zefanya has an account for them or what that account may do. The
// repository exchanges it for an auth result (or for a `needs_role` answer
// when there is no account yet), and only that exchange can sign someone in.
//
// The token is kept in memory for the length of one flow. It is never written
// to SharedPreferences: it is short-lived, and a persisted token would outlive
// the flow that can still use it.

class GoogleIdentity {
  const GoogleIdentity({
    required this.idToken,
    required this.email,
    this.displayName,
    this.photoUrl,
  });

  /// The OpenID Connect ID token the backend verifies with Google.
  ///
  /// Opaque to this app. It is not parsed, decoded, or trusted for a claim —
  /// the server is the only party that can verify the signature and audience.
  final String idToken;

  /// The address Google reports for this account. Used to prefill the
  /// registration form and to choose the mock account; the backend must still
  /// verify it from the token rather than trust this field.
  final String email;

  final String? displayName;
  final String? photoUrl;

  /// A name the UI can show without a second null check.
  String get displayNameOrEmail {
    final name = displayName?.trim();
    return name == null || name.isEmpty ? email : name;
  }
}
