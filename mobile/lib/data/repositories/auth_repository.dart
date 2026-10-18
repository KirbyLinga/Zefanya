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
import '../api/api_client.dart';
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
  ///
  /// [role] defaults to [UserRole.buyer] for backward compatibility.
  /// Pass the session's role so the correct revocation endpoint is called.
  Future<void> logout({UserRole role = UserRole.buyer});

  /// Re-asks the server what this account's status is.
  ///
  /// Needed because approval happens *while the person is waiting* on the
  /// pending screen: without a refresh, the only way to discover it is a
  /// reinstall or a re-login. `docs/api-contract.md` §3.4.
  ///
  /// [role] is optional and defaults to [UserRole.buyer] so existing call
  /// sites that predate courier auth are unaffected.
  Future<AuthResult?> refresh({UserRole role = UserRole.buyer});
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
  Future<void> logout({UserRole role = UserRole.buyer}) async {}

  @override
  Future<AuthResult?> refresh({UserRole role = UserRole.buyer}) async => null;
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
  }) async {
    if (!client.isConfigured) throw const ApiNotConfiguredException();

    // One path per role: buyers authenticate at /login/buyer and couriers at
    // /login/courier. Both paths are now real — see web/routes/api.php.
    final path = role == UserRole.buyer ? '/login/buyer' : '/login/courier';

    final body = await client.postJson(
      path,
      authenticated: false,
      body: {'email': email.trim(), 'password': password},
    );

    // Kept before the result is read: every `200` from this route carries a
    // token, and a caller holding a result whose token was dropped is a session
    // that looks signed in and authenticates nothing.
    //
    // A missing token is a malformed answer rather than a failed sign-in — the
    // status was 200, so the server believed it signed someone in.
    final token = body['token'];
    if (token is! String || token.isEmpty) {
      throw const MalformedResponseException(
        message: 'The server accepted the sign-in but sent no token.',
      );
    }
    await client.adoptToken(token);

    // A shut gate never reaches here: the server answers `403` with its own
    // `code` and `registration_status`, which `ApiClient` turns into
    // `PendingApprovalException` so the screen can route to the right gate
    // instead of reading as a wrong password. That exception does not carry the
    // `verification_id` the same response includes, so a returning account that
    // still has to confirm its email cannot be handed the confirm screen from
    // the login screen yet — a gap recorded in memory-bank/progress.md.
    return _resultFrom(body, role: role);
  }

  @override
  Future<AuthResult> register({
    required UserRole role,
    required String email,
    required String password,
    required RegistrationProfile profile,
  }) async {
    // The courier form still does not match `StoreLogisticsRegistrationRequest`
    // (see this file's header and memory-bank/progress.md), so there is nothing
    // to send for that role: a mapping that can only produce a 422 is worse than
    // none.
    if (role != UserRole.buyer) return notWired('POST /register/logistics');

    if (!client.isConfigured) throw const ApiNotConfiguredException();

    final idPath = profile.govIdPath;
    if (idPath == null || idPath.trim().isEmpty) {
      // Refused here rather than by the server. `upload_id` is `required`, so a
      // round-trip would come back with a 422 saying the same thing — and this
      // is the one field the form cannot enforce, because the file is evidence
      // that has to be provided by hand.
      throw const ValidationException(
        fieldErrors: {'upload_id': 'Upload a photo or PDF of your ID.'},
      );
    }

    // The ID document is a multipart part, not a field — which is exactly why
    // `toBuyerFields` is named for fields and the path travels separately. Every
    // value is stringified because multipart has no types: a `DateTime` or an
    // `int` in this map would be sent as its `toString()` anyway, and doing it
    // here means the shape is decided in one place rather than by the encoder.
    final body = await client.postMultipart(
      '/register/buyer',
      authenticated: false,
      fields: profile
          .toBuyerFields(email: email, password: password)
          .map((key, value) => MapEntry(key, '$value')),
      files: {'upload_id': idPath},
    );

    // The answer carries the handle the confirm route is keyed on, and the gate
    // — `pending_verification`, not the admin gate a browser registration would
    // also start at. The app stores both: the gate picks the screen, the handle
    // is what the screen sends the code against.
    return _resultFrom(body, role: role);
  }

  /// The confirm path for a role, with the opaque handle filled in.
  ///
  /// The paths come from `web/routes/api.php`: the route file is in this
  /// repository and is therefore the authority, so no contract § is cited for
  /// it. Two asymmetries are recorded here rather than smoothed over — this app
  /// says `courier` where the server says `logistics`, and only the buyer half
  /// is mounted so far.
  static String verifyOtpPath(UserRole role, String verificationId) =>
      role == UserRole.buyer
          ? '/register/buyer/verify-otp/$verificationId'
          : '/register/logistics/verify-otp/$verificationId';

  @override
  Future<AuthResult> verifyOtp({
    required UserRole role,
    required String verificationId,
    required String code,
  }) async {
    if (role != UserRole.buyer) {
      return notWired(verifyOtpPath(role, verificationId));
    }
    if (!client.isConfigured) throw const ApiNotConfiguredException();

    // The field is `otp`, not `code` — read off the frozen web client
    // (`resources/js/buyer/buyer-register-modal.js`), which posts `{otp: code}`
    // to the same route. This is the one payload detail the app could have
    // guessed wrong, and it is settled by a file rather than by a coin flip.
    //
    // Sent unauthenticated on purpose: the route is public because the person
    // has no token yet — that is the whole reason they are here — and a token
    // left over from a previous session would otherwise be attached by default
    // and answered as a different caller.
    final body = await client.postJson(
      verifyOtpPath(role, verificationId),
      authenticated: false,
      body: {'otp': code.trim()},
    );

    // The answer is the admin gate, with no handle: the code is spent, and the
    // app clears `verificationId` from this result rather than keeping a
    // reference to a step it can no longer perform.
    return _resultFrom(body, role: role);
  }

  @override
  Future<void> resendOtp({
    required UserRole role,
    required String verificationId,
  }) async {
    if (role != UserRole.buyer) {
      // A bare call rather than a `return`: `notWired` throws, and Dart rejects
      // `return <Never>` from an `async` method whose type is `Future<void>`
      // (there is no value slot for it to occupy, unlike `Future<AuthResult>`).
      notWired('${verifyOtpPath(role, verificationId)}/resend');
    }
    if (!client.isConfigured) throw const ApiNotConfiguredException();

    // Nothing is read from the answer. The server says only that it sent one,
    // and the screen's own sentence is that copy; a new code means the previous
    // one is dead, which is a fact the caller has no use for beyond not typing
    // the old one again.
    await client.postJson(
      '${verifyOtpPath(role, verificationId)}/resend',
      authenticated: false,
    );
  }

  @override
  Future<void> logout({UserRole role = UserRole.buyer}) async {
    if (!client.isConfigured) throw const ApiNotConfiguredException();

    // One path per role: a buyer's token is minted at /login/buyer and revoked
    // at /logout; a courier's is minted at /login/courier and revoked at
    // /logout/courier. Using the wrong path gets a 403 (wrong account type)
    // rather than a 200, meaning the token would not be revoked server-side —
    // and an unrevoked token is worse than a failed revoke attempt.
    final path = role == UserRole.buyer ? '/logout' : '/logout/courier';

    try {
      await client.postJson(path);
    } finally {
      // Local state is cleared whatever the server answered: someone who asked
      // to be signed out while offline must not be left holding a usable token.
      await client.forgetToken();
    }
  }

  @override
  Future<AuthResult?> refresh({UserRole role = UserRole.buyer}) async {
    if (!client.isConfigured) throw const ApiNotConfiguredException();

    // Each role has its own /me endpoint so the controller can enforce that the
    // token belongs to the right kind of account. Calling the wrong one would
    // get a 403 rather than a 401, which would look like "account not approved"
    // rather than "this token is from a different role".
    final path = role == UserRole.buyer ? '/me' : '/me/courier';

    try {
      final body = await client.getJson(path);
      return _resultFrom(body, role: role);
    } on UnauthorizedException {
      // A token the server has rejected is worse than no token — it keeps
      // producing 401s — so the answer is to forget it, and `null` is the
      // caller's word for "no session".
      await client.forgetToken();
      return null;
    }
    // A gate (`403`) is deliberately not caught here. The token is *valid* and
    // the account is not usable yet, and flattening that into `null` would read
    // as "no session" — the same small lie the two-gate pending screen exists to
    // correct.
  }

  /// One reader for every answer that describes an account.
  ///
  /// Sign-in, registration and confirmation all end at the same two questions —
  /// who is this, and which gate are they behind — so they share one mapper
  /// rather than three that could disagree about what
  /// `registration_status: 'pending_approval'` means.
  ///
  /// [unwrapMap] because the server may or may not wrap the body in a `data`
  /// envelope, and [RemoteRegistrationStatus.parse] because an unrecognised
  /// status must fail *safe*: `accountStatusFor` sends `unknown` to the pending
  /// state rather than to active, on the grounds that granting access on doubt
  /// is the one direction that cannot be walked back.
  AuthResult _resultFrom(Map<String, Object?> body, {required UserRole role}) {
    final payload = unwrapMap(body);
    final remote = RemoteRegistrationStatus.parse(payload['registration_status']);

    return AuthResult(
      role: role,
      status: accountStatusFor(remote),
      registrationStatus: remote,
      name: payload['name']?.toString(),
      email: payload['email']?.toString(),
      // Opaque, and kept as a string: it is a URL segment whose only correct
      // value comes from the server, so this app neither parses it nor builds
      // one out of something it does know (an email is not an id).
      verificationId: payload['verification_id']?.toString(),
    );
  }
}