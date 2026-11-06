// lib/services/google_sign_in_service.dart
//
// The native half of "Continue with Google": open Google's account sheet and
// return the identity it proves. Exchanging that identity for a Zefanya
// session is the repository's job (google_auth_repository.dart), not this
// file's.
//
// The split matters in tests and in mock mode. A widget test cannot open a
// native account sheet, and a mock build has no Google Cloud project behind
// it, so both use [MockGoogleSignInService]. The screen is handed the
// interface and cannot tell which one it has — the same rule the rest of the
// repository bundle follows.

import 'package:google_sign_in/google_sign_in.dart' hide GoogleIdentity;

import '../config/dev_flags.dart' show kMockGoogleEmail;
import '../data/google_identity.dart';

/// A Google sign-in failure with a sentence safe to show.
///
/// [cancelled] is separate from the message because cancellation is not an
/// error: the person closed the sheet, and the login screen should do nothing
/// rather than show a snackbar for a decision they just made.
class GoogleSignInFailure implements Exception {
  const GoogleSignInFailure(this.message, {this.cancelled = false});

  final String message;
  final bool cancelled;

  @override
  String toString() => message;
}

abstract class GoogleSignInService {
  /// Opens the platform's Google account flow and returns the proven identity.
  ///
  /// Throws [GoogleSignInFailure] when the flow cannot complete. A cancelled
  /// flow is that same type with `cancelled: true`.
  Future<GoogleIdentity> signIn();

  /// Signs the current Google account out, so the next tap shows the chooser.
  Future<void> signOut();
}

/// Real Google Sign-In. Used only in live builds.
class NativeGoogleSignInService implements GoogleSignInService {
  NativeGoogleSignInService({
    this.clientId = '',
    this.serverClientId = '',
  });

  final String clientId;
  final String serverClientId;

  /// `GoogleSignIn.initialize` is once per process. Keeping the future here
  /// means two taps cannot race two initialisations; a failed initialisation
  /// is cleared so a retry is possible after the configuration is fixed.
  Future<void>? _initializing;

  Future<void> _ensureInitialized() async {
    final existing = _initializing;
    if (existing != null) {
      await existing;
      return;
    }

    final future = GoogleSignIn.instance.initialize(
      clientId: clientId.trim().isEmpty ? null : clientId.trim(),
      serverClientId: serverClientId.trim().isEmpty
          ? null
          : serverClientId.trim(),
    );
    _initializing = future;
    try {
      await future;
    } catch (_) {
      _initializing = null;
      rethrow;
    }
  }

  @override
  Future<GoogleIdentity> signIn() async {
    if (serverClientId.trim().isEmpty) {
      throw const GoogleSignInFailure(
        'Google sign-in is not configured in this build. '
        'Add the Google server client ID, or use email and password.',
      );
    }

    try {
      await _ensureInitialized();
      if (!GoogleSignIn.instance.supportsAuthenticate()) {
        throw const GoogleSignInFailure(
          'Google sign-in is not available on this device. '
          'Use email and password instead.',
        );
      }

      final account = await GoogleSignIn.instance.authenticate();
      final token = account.authentication.idToken;
      if (token == null || token.isEmpty) {
        throw const GoogleSignInFailure(
          'Google did not return a sign-in token. Please try again.',
        );
      }

      return GoogleIdentity(
        idToken: token,
        email: account.email,
        displayName: account.displayName,
        photoUrl: account.photoUrl,
      );
    } on GoogleSignInFailure {
      rethrow;
    } on GoogleSignInException catch (error) {
      if (error.code == GoogleSignInExceptionCode.canceled) {
        throw const GoogleSignInFailure(
          'Google sign-in was cancelled.',
          cancelled: true,
        );
      }
      throw GoogleSignInFailure(_messageFor(error));
    } catch (_) {
      throw const GoogleSignInFailure(
        'Google sign-in could not be completed. Please try again.',
      );
    }
  }

  @override
  Future<void> signOut() async {
    // Best-effort: signing out of the app must not fail because Google's SDK
    // was never initialised (a live build that never tapped the button) or
    // because the device is offline.
    try {
      await GoogleSignIn.instance.signOut();
    } catch (_) {
      // The app session is still cleared by AuthState; this is only the
      // Google account chooser's state.
    }
  }

  String _messageFor(GoogleSignInException error) => switch (error.code) {
    GoogleSignInExceptionCode.clientConfigurationError =>
      'Google sign-in is not configured correctly for this build. '
          'Check the Google client ID and app signing certificate.',
    GoogleSignInExceptionCode.providerConfigurationError =>
      'Google sign-in is unavailable on this device right now. '
          'Please try again later.',
    GoogleSignInExceptionCode.uiUnavailable =>
      'Google sign-in needs a screen that is not available right now. '
          'Please try again.',
    GoogleSignInExceptionCode.interrupted =>
      'Google sign-in was interrupted. Please try again.',
    GoogleSignInExceptionCode.userMismatch =>
      'A different Google account is already signed in. '
          'Sign out and try again.',
    _ => 'Google sign-in could not be completed. Please try again.',
  };
}

/// Deterministic stand-in for tests and mock builds.
///
/// It returns [identity] when one was supplied, otherwise the build-time
/// `zefanya.mockGoogleEmail` identity. No platform channel is touched, so the
/// flow works on an emulator with no Google Play services and in widget tests.
class MockGoogleSignInService implements GoogleSignInService {
  const MockGoogleSignInService({this.identity});

  final GoogleIdentity? identity;

  @override
  Future<GoogleIdentity> signIn() async {
    final supplied = identity;
    if (supplied != null) return supplied;

    return GoogleIdentity(
      idToken: 'mock-google-id-token',
      email: kMockGoogleEmail,
      displayName: mockGoogleDisplayName(kMockGoogleEmail),
    );
  }

  @override
  Future<void> signOut() async {}
}

/// "tristan.araneta@gmail.com" -> "Tristan Araneta".
///
/// Only for the mock identity: a real account's display name comes from
/// Google. Kept public so the test that picks a mock address can assert the
/// same spelling the service produces.
String mockGoogleDisplayName(String email) {
  final local = email.split('@').first;
  final parts = local
      .split(RegExp(r'[._-]+'))
      .where((part) => part.isNotEmpty)
      .map((part) => '${part[0].toUpperCase()}${part.substring(1)}')
      .toList();
  return parts.isEmpty ? 'Google User' : parts.join(' ');
}
