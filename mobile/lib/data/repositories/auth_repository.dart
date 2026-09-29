// lib/data/repositories/auth_repository.dart
//
// Signing in, registering, and finding out what the account is allowed to do.
//
// ## The shape of the answer, and why it is not a bool
//
// `login()` returns an [AuthResult], not a boolean or a thrown exception for
// "wrong password". The reason is the spec: an account can be perfectly valid
// and still not usable yet, because registration runs through *two* gates (email
// OTP, then admin approval). A `bool` cannot express "your credentials are
// correct but your account is not active", so the caller would have to invent
// that distinction — and the login screen would end up deciding, per call site,
// whether a failed login means "wrong password" or "wait for approval".
//
// [AuthResult] carries both facts: who you are, and what you're allowed to do.
// The screens then route on [AccountStatus], which they already do
// (`AuthGate` switches on exactly that).
//
// ## What this repository does *not* own
//
// The profile fields (name, address, avatar) stay in `AuthState` and its
// `shared_preferences` storage in this pass. That is a deliberate line: those
// are display data the app already persists, and moving them to the network
// would make every `updateProfile` call a request that can fail, which changes
// the edit-profile screen's behaviour — and this pass must not change
// behaviour. `docs/api-contract.md` §3 specifies the endpoints that will take
// them over.
//
// The *token* is different, and it is here, because it is the one thing that
// must live in secure storage rather than preferences.

import '../../state/auth_state.dart';
import '../api/api_exception.dart';
import '../dto/remote_status.dart';
import 'live_stub.dart';

/// What a sign-in attempt produced.
class AuthResult {
  const AuthResult({
    required this.role,
    required this.status,
    this.name,
    this.email,
    this.registrationStatus,
  });

  final UserRole role;
  final AccountStatus status;
  final String? name;
  final String? email;

  /// The server's own registration value, when it sent one.
  ///
  /// Kept alongside [status] rather than derived from it because the two
  /// pending gates collapse into one `AccountStatus` and the *copy* still
  /// differs — see `awaitsEmailVerification`.
  final RemoteRegistrationStatus? registrationStatus;

  /// True when the account exists and the credentials were accepted, but the
  /// registration isn't approved yet.
  bool get needsApproval => status == AccountStatus.pendingApproval;
}

abstract class AuthRepository {
  /// Signs in against the given role's guard.
  ///
  /// Role is an argument, not a return value, and that is a real decision: the
  /// mobile app has **no uninhabited session**. The web app has four guards and
  /// a person who is both a buyer and a seller; here, `AuthGate` routes on
  /// `role` before anything else, so a session without one is unusable. Asking
  /// the server "which role am I?" would therefore be a question this app could
  /// not act on — the app already knows which door the person walked through,
  /// because it drew two of them.
  Future<AuthResult> login({
    required UserRole role,
    required String email,
    required String password,
  });

  /// Submits a new registration, which always lands pending.
  Future<AuthResult> register({
    required UserRole role,
    required String email,
    required String password,
    required Map<String, Object?> profile,
  });

  /// Signs out, server-side if there is a server.
  Future<void> logout();

  /// Re-asks the server what this account's status is.
  ///
  /// Needed because approval happens *while the person is waiting* on the
  /// pending screen: without a refresh, the only way to discover it is a
  /// reinstall or a re-login. `docs/api-contract.md` §3.4.
  Future<AuthResult?> refresh();
}

// ---------------------------------------------------------------------------
// Mock
// ---------------------------------------------------------------------------

/// Mirrors today's behaviour exactly: any credentials are accepted, and the
/// role is whatever the (dev-gated) login selector chose.
///
/// There is no password check here and that is not an oversight — the mock has
/// no accounts to check against. What matters is that this repository *returns*
/// a status rather than setting one, so the login screen's routing is identical
/// in both modes.
class MockAuthRepository implements AuthRepository {
  const MockAuthRepository();

  @override
  Future<AuthResult> login({
    required UserRole role,
    required String email,
    required String password,
  }) async =>
      AuthResult(role: role, status: AccountStatus.active, email: email);

  @override
  Future<AuthResult> register({
    required UserRole role,
    required String email,
    required String password,
    required Map<String, Object?> profile,
  }) async =>
      AuthResult(
        role: role,
        status: AccountStatus.pendingApproval,
        email: email,
        // Mock registrations land on the *admin* gate, because there is no mail
        // to send an OTP from. Claiming `pending_verification` would make the
        // pending screen tell the person to check an inbox that will never
        // receive anything.
        registrationStatus: RemoteRegistrationStatus.pendingApproval,
      );

  @override
  Future<void> logout() async {}

  @override
  Future<AuthResult?> refresh() async => null;
}

// ---------------------------------------------------------------------------
// Live
// ---------------------------------------------------------------------------

class LiveAuthRepository extends LiveRepository implements AuthRepository {
  LiveAuthRepository(super.client);

  @override
  Future<AuthResult> login({
    required UserRole role,
    required String email,
    required String password,
  }) {
    if (!client.isConfigured) throw const ApiNotConfiguredException();
    // A separate login path per role, per the contract:
    // `POST /auth/buyer/login` and `POST /auth/courier/login`, not one endpoint
    // with a role field. One endpoint would be one guard, and token-per-guard
    // is the point of splitting them.
    return notWired('POST /auth/${role.name}/login', section: '§3.1');
  }

  @override
  Future<AuthResult> register({
    required UserRole role,
    required String email,
    required String password,
    required Map<String, Object?> profile,
  }) {
    if (!client.isConfigured) throw const ApiNotConfiguredException();
    return notWired('POST /auth/${role.name}/register', section: '§3.2');
  }

  @override
  Future<void> logout() {
    if (!client.isConfigured) throw const ApiNotConfiguredException();
    return notWired('POST /auth/logout', section: '§3.3');
  }

  @override
  Future<AuthResult?> refresh() {
    if (!client.isConfigured) throw const ApiNotConfiguredException();
    return notWired('GET /auth/me', section: '§3.4');
  }
}