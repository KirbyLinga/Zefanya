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

import '../../config/dev_flags.dart';
import '../../state/auth_state.dart';
import '../api/api_exception.dart';
import '../dto/registration_payload.dart';
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
    this.verificationId,
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

  /// The server's handle for confirming this registration's email, when it gave
  /// one, and `null` when there is nothing to confirm.
  ///
  /// **Opaque, and treated as such.** The route is keyed on the server's own row
  /// id (`POST /register/buyer/verify-otp/{buyer}`), so this app neither parses
  /// it nor builds one out of what it does know: an email is not an id, and a
  /// client that assembled its own handle would be guessing at a URL segment
  /// whose only correct value comes from the server. It is carried so
  /// [AuthRepository.verifyOtp] has something to send back, and for nothing
  /// else. A `String` rather than an int for the same reason — the shape is the
  /// server's business.
  final String? verificationId;

  /// Whether this answer opened an email gate the app could act on.
  ///
  /// Both halves are needed: `pending_verification` says there is a code to
  /// confirm, and [verificationId] is what a confirmation would be sent
  /// *against*. A screen checking only the status would render a code field
  /// whose submit could never be addressed to anything.
  bool get canConfirmEmail =>
      registrationStatus == RemoteRegistrationStatus.pendingVerification &&
      verificationId != null;
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
  ///
  /// [profile] is a [RegistrationProfile] — this app's vocabulary, not the
  /// server's. It used to be a `Map<String, Object?>`, which is the same
  /// mistake one level further out: the map's *keys* would have been the wire
  /// contract, spelled by whichever screen built it, with nothing checking them
  /// until a request failed in production. `registration_payload.dart` owns
  /// that vocabulary instead, and only the live implementation converts it.
  ///
  /// There is deliberately no `passwordConfirmation` parameter. The form will
  /// not submit until the two entries match, so passing both through here would
  /// create exactly the state the server's `confirmed` rule exists to catch —
  /// and a state this app has already made unreachable is not worth a parameter
  /// that implies it is reachable. See [RegistrationProfile.toBuyerFields].
  Future<AuthResult> register({
    required UserRole role,
    required String email,
    required String password,
    required RegistrationProfile profile,
  });

  /// Confirms the emailed code that gates a registration.
  ///
  /// The two registration gates are not the same kind of wait, and this is the
  /// only one the person can resolve alone: [register] answers
  /// `pending_verification` with a [AuthResult.verificationId], and this call
  /// trades that handle plus six digits for the account's next status — usually
  /// `pending_approval`, the email gate closing and the human queue opening.
  ///
  /// A wrong or an expired code is a **rejection, not a failure**: the server
  /// answers `422 {ok: false, message}`. It therefore does not arrive as a
  /// [ValidationException] with an empty field map, which would claim the server
  /// named a field when all it said was no — see the note on the mock.
  ///
  /// Returns [AuthResult] rather than `void` because confirming can change what
  /// the account may do, and a screen that had to *assume* it worked would be
  /// showing a status it invented in order to show one.
  Future<AuthResult> verifyOtp({
    required UserRole role,
    required String verificationId,
    required String code,
  });

  /// Sends a fresh code, invalidating the previous one.
  ///
  /// Returns nothing because the server's reply carries nothing: `{ok: true,
  /// message}` and no new status or handle. A screen's copy for this is the
  /// server's own sentence for that reason — "a new code has been sent" is a
  /// claim only the party that sent the mail is in a position to make.
  ///
  /// Separate from [verifyOtp] rather than a flag on it: two requests with two
  /// different answers, and the one that fails to *send* must not read like the
  /// one that failed to *match*.
  Future<void> resendOtp({
    required UserRole role,
    required String verificationId,
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

  /// [password] is ignored here for the same reason the login one is: the mock
  /// has no accounts to check it against. What changed is that it is now a real
  /// password arriving from the form rather than an empty string standing in
  /// for one — and [profile] is the form's actual answers, so the *call path*
  /// is the real one even though the answer is pre-decided.
  @override
  Future<AuthResult> register({
    required UserRole role,
    required String email,
    required String password,
    required RegistrationProfile profile,
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

  /// Accepts [kMockOtpCode] and rejects everything else the way the server does.
  ///
  /// The rejection is [RequestRejectedException], not [ValidationException], and
  /// that is a faithful copy rather than a shortcut: a wrong code comes back as
  /// `422 {ok: false, message: '...'}` with **no** `errors` map, where a
  /// validation failure is a field bag. A field-keyed exception here would claim
  /// the server had said *which* field was wrong when all it said was no — and
  /// the screen would then highlight a field the real server never pointed at.
  ///
  /// The comparison is trimmed because the value comes from a text field, where
  /// a trailing space is a typing artefact rather than a wrong code.
  @override
  Future<AuthResult> verifyOtp({
    required UserRole role,
    required String verificationId,
    required String code,
  }) async {
    if (code.trim() != kMockOtpCode) {
      throw const RequestRejectedException(
        statusCode: 422,
        message: "That code doesn't match. Please try again.",
      );
    }
    // Confirmed, so the account leaves the email gate and joins the review
    // queue: the same answer `register()` gives, reached from the other side.
    return AuthResult(
      role: role,
      status: AccountStatus.pendingApproval,
      registrationStatus: RemoteRegistrationStatus.pendingApproval,
    );
  }

  /// A no-op, and the one place in mock mode that says the opposite of what
  /// happened.
  ///
  /// Nothing sends an email, so "a new code has been sent" is fiction. It is
  /// tolerable for the same reason the pending screen's reviewer copy is: the
  /// only builds that can reach this in mock mode are dev-tools builds
  /// ([kShowDevTools]), whose purpose is demonstrating a flow that has no
  /// backend yet. A real build cannot arrive here at all, because `register()`
  /// never answers `pending_verification` in mock mode.
  @override
  Future<void> resendOtp({
    required UserRole role,
    required String verificationId,
  }) async {}

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
    required RegistrationProfile profile,
  }) {
    if (!client.isConfigured) throw const ApiNotConfiguredException();
    // The *mapping* already exists and is tested
    // (`RegistrationProfile.toBuyerFields`), so what is missing here is only the
    // transport: a multipart POST that sends those fields plus the ID image at
    // `profile.govIdPath`. Left unwritten on purpose — request code that has
    // never run against the server is not a feature, and this class's job is to
    // name the endpoint that is missing rather than to guess at its shape.
    //
    // A naming note, because the guess would be wrong: this app says `courier`
    // where the server says `logistics` (`register/logistics`,
    // `logistics_providers`), and the server's route is `POST /register/buyer` —
    // not `POST /auth/buyer/register`. The string below is therefore this app's
    // own vocabulary and is not presented as the server's URL.
    return notWired('POST /auth/${role.name}/register', section: '§3.2');
  }

  /// The server's route for a role's confirmation, taken from
  /// `web/routes/web.php` rather than from `docs/api-contract.md`.
  ///
  /// Two asymmetries are recorded here instead of being smoothed over: this app
  /// says `courier` where the server says `logistics`, and the server's path
  /// segment is named after its own model (`{buyer}`, `{logistics_provider}`)
  /// while what this app holds is an opaque [AuthResult.verificationId]. No
  /// contract section number is attached, deliberately — the document it would
  /// point at is not in this repository, whereas the route file is. See
  /// memory-bank/techContext.md.
  static String verifyOtpRoute(UserRole role) => role == UserRole.buyer
      ? 'POST /register/buyer/verify-otp/{buyer}'
      : 'POST /register/logistics/verify-otp/{logistics_provider}';

  @override
  Future<AuthResult> verifyOtp({
    required UserRole role,
    required String verificationId,
    required String code,
  }) {
    if (!client.isConfigured) throw const ApiNotConfiguredException();
    // Unwritten on purpose, like every other live method here. Even the body's
    // shape is a guess — the web modal posts a field named `otp`, and `code` is
    // just as plausible — and a guess that has never run is not a feature.
    return notWired(verifyOtpRoute(role));
  }

  @override
  Future<void> resendOtp({
    required UserRole role,
    required String verificationId,
  }) {
    if (!client.isConfigured) throw const ApiNotConfiguredException();
    return notWired('${verifyOtpRoute(role)}/resend');
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