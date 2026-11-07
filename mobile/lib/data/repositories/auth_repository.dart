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
  }) async {
    final lower = email.trim().toLowerCase();
    final resolvedRole = lower.contains('courier') || lower.contains('rider')
        ? UserRole.courier
        : (lower.contains('buyer') ? UserRole.buyer : role);
    return AuthResult(role: resolvedRole, status: AccountStatus.active, email: email);
  }

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

/// Rider registration recovery (live mode only). A separate interface, like
/// `QuotesCheckout`, so the mock and the test doubles of [AuthRepository] need
/// not implement a server-only call.
abstract class ResumesRiderRegistration {
  /// Fresh signed verification links for an unverified rider; returns the new
  /// opaque [AuthResult.verificationId].
  Future<String> resumeRiderRegistration({
    required String email,
    required String password,
  });
}

class LiveAuthRepository extends LiveRepository
    implements AuthRepository, ResumesRiderRegistration {
  LiveAuthRepository(super.client);

  @override
  Future<AuthResult> login({
    required UserRole role,
    required String email,
    required String password,
  }) async {
    if (!client.isConfigured) throw const ApiNotConfiguredException();

    // One path per role, per WebZefanya routes/api.php: /api/buyer/login and
    // /api/rider/login. Each mints a token with only that role's ability.
    final path = '${_prefix(role)}/login';

    final Map<String, Object?> body;
    try {
      body = await client.postJson(
        path,
        authenticated: false,
        body: {'email': email.trim(), 'password': password},
      );
    } on UnauthorizedException {
      // On the login route a 401 means wrong credentials (WebZefanya does not
      // say which field), not an expired session.
      throw const UnauthorizedException(message: 'Incorrect email or password.');
    }

    // Kept before the result is read: every `200` from this route carries a
    // token, and a caller holding a result whose token was dropped is a session
    // that looks signed in and authenticates nothing.
    //
    // A missing token is a malformed answer rather than a failed sign-in — the
    // status was 200, so the server believed it signed someone in.
    // WebZefanya wraps it: {success, message, data: {token, ...}, errors}.
    final payload = unwrapMap(body);
    final token = payload['token'];
    if (token is! String || token.isEmpty) {
      throw const MalformedResponseException(
        message: 'The server accepted the sign-in but sent no token.',
      );
    }
    await client.adoptToken(
      token,
      // A token minted this instant: when the answer omits `expires_at`, the
      // server's default lifetime (`sanctum.expiration`, 60 minutes) applies.
      expiresAt: DateTime.tryParse('${payload['expires_at']}')?.toLocal() ??
          DateTime.now().add(const Duration(minutes: 60)),
    );
    client.renewPath = _renewPath(role);

    // A shut gate never reaches here: the server answers `403` with its own
    // `code` and `registration_status`, which `ApiClient` turns into
    // `PendingApprovalException` so the screen can route to the right gate
    // instead of reading as a wrong password. That exception does not carry the
    // `verification_id` the same response includes, so a returning account that
    // still has to confirm its email cannot be handed the confirm screen from
    // the login screen yet — a gap recorded in memory-bank/progress.md.
    //
    // WebZefanya's login body carries the token only, not the account, so the
    // account (name, status) is read from the role's profile endpoint.
    return _resultFrom(await client.getJson('${_prefix(role)}/profile'), role: role);
  }

  /// Posts to one of the rider's signed URLs.
  ///
  /// A link past its `expires` time is not sent at all, and a 403 from the
  /// `signed` middleware (expired or invalid signature) means the same thing:
  /// both become [VerificationLinkExpiredException], whose remedy is
  /// [resumeRiderRegistration].
  Future<Map<String, Object?>> _signed(
    RiderVerificationHandle handle,
    String url, {
    Object? body,
  }) async {
    if (handle.isExpired) throw const VerificationLinkExpiredException();
    try {
      return await client.postSigned(url, body: body);
    } on ForbiddenException catch (e) {
      throw VerificationLinkExpiredException(cause: e);
    }
  }

  /// WebZefanya's recovery for an unverified rider whose links expired:
  /// `POST /api/rider/registration/resume` with the account's email and
  /// password answers with fresh `verify_url` / `resend_url` (the server's
  /// `register` rate limit applies). No new code is sent by this call.
  ///
  /// Returns the new opaque handle for [AuthResult.verificationId].
  @override
  Future<String> resumeRiderRegistration({
    required String email,
    required String password,
  }) async {
    if (!client.isConfigured) throw const ApiNotConfiguredException();
    final body = await client.postJson(
      '/rider/registration/resume',
      authenticated: false,
      body: {'email': email.trim(), 'password': password},
    );
    final handle = riderRegistrationResultFrom(body, email: email).verificationId;
    if (handle == null) {
      throw const MalformedResponseException(
        message: 'The server resumed the registration but sent no verification link.',
      );
    }
    return handle;
  }

  /// `/buyer` or `/rider`: WebZefanya's per-role API prefix.
  static String _prefix(UserRole role) =>
      role == UserRole.buyer ? '/buyer' : '/rider';

  /// The role's token-renewal route. Only riders have one on WebZefanya
  /// (`POST /api/rider/token/refresh`); a buyer token is not renewed.
  static String? _renewPath(UserRole role) =>
      role == UserRole.courier ? '/rider/token/refresh' : null;

  @override
  Future<AuthResult> register({
    required UserRole role,
    required String email,
    required String password,
    required RegistrationProfile profile,
  }) async {
    if (role == UserRole.courier) {
      if (!client.isConfigured) throw const ApiNotConfiguredException();

      // WebZefanya's StoreRiderRegistrationRequest: the two documents are
      // required file parts; there is no government-ID upload for riders.
      final license = profile.riderLicensePath?.trim() ?? '';
      final orCr = profile.riderOrCrPath?.trim() ?? '';
      if (license.isEmpty || orCr.isEmpty) {
        throw ValidationException(fieldErrors: {
          if (license.isEmpty) 'drivers_license': "Upload a photo or PDF of your driver's license.",
          if (orCr.isEmpty) 'or_cr': 'Upload a photo or PDF of your vehicle OR/CR.',
        });
      }
      if (profile.riderProviderId == null) {
        throw const ValidationException(
          fieldErrors: {'logistics_provider_id': 'Select a logistics provider.'},
        );
      }

      final body = await client.postMultipart(
        '/rider/register',
        authenticated: false,
        fields: profile
            .toRiderFields(email: email, password: password)
            .map((key, value) => MapEntry(key, '$value')),
        files: {'drivers_license': license, 'or_cr': orCr},
      );

      return riderRegistrationResultFrom(body, email: email);
    }

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
    if (role == UserRole.courier) {
      final handle = RiderVerificationHandle.parse(verificationId);
      if (handle == null) return notWired(verifyOtpPath(role, verificationId));
      final body = await _signed(handle, handle.verifyUrl, body: {'otp': code.trim()});
      return _resultFrom(body, role: role);
    }
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
    if (role == UserRole.courier) {
      final handle = RiderVerificationHandle.parse(verificationId);
      if (handle != null) {
        await _signed(handle, handle.resendUrl);
        return;
      }
    }
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
    // WebZefanya revokes only the current token and answers 204.
    final path = '${_prefix(role)}/logout';

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
    final path = '${_prefix(role)}/profile';
    // A restored session: the token's expiry is unknown here, so a rider's
    // token is renewed before this first request goes out.
    client.renewPath = _renewPath(role);

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

  AuthResult _resultFrom(Map<String, Object?> body, {required UserRole role}) =>
      authResultFrom(body, role: role);
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
///
/// Public because the Google repository reads the same answer shape. The role
/// is still an argument: the email/password paths know it from the route, and
/// the Google path parses it from the server before calling here.
AuthResult authResultFrom(
  Map<String, Object?> body, {
  required UserRole role,
}) {
  final payload = unwrapMap(body);
  final remote = RemoteRegistrationStatus.parse(
    payload['registration_status'] ?? _webZefanyaStatus(payload['status']),
  );
  final name = payload['name']?.toString() ??
      [payload['first_name'], payload['last_name']]
          .whereType<Object>()
          .map((part) => part.toString().trim())
          .where((part) => part.isNotEmpty)
          .join(' ');

  return AuthResult(
    role: role,
    status: accountStatusFor(remote),
    registrationStatus: remote,
    name: name.isEmpty ? null : name,
    email: payload['email']?.toString(),
    // Opaque, and kept as a string: it is a URL segment whose only correct
    // value comes from the server, so this app neither parses it nor builds
    // one out of something it does know (an email is not an id).
    verificationId: payload['verification_id']?.toString(),
  );
}

/// WebZefanya's account `status` column, in this app's registration terms.
///
/// The profile endpoints only answer for accounts that passed the server's
/// login gate, where `approved` (buyers) and `active` (riders) both mean
/// usable. Anything else stays unrecognised and so fails safe to pending.
String? _webZefanyaStatus(Object? status) => switch (status?.toString()) {
      'approved' || 'active' => 'approved',
      'pending' => 'pending_approval',
      'rejected' => 'rejected',
      _ => null,
    };

/// The two signed URLs WebZefanya returns after a rider registers, carried
/// through the app as one opaque [AuthResult.verificationId].
///
/// Rider OTP routes are keyed by a server signature, not by an id the app
/// could build, so the handle *is* the pair of URLs. Joined with a newline,
/// which a URL cannot contain.
class RiderVerificationHandle {
  const RiderVerificationHandle(this.verifyUrl, this.resendUrl);

  final String verifyUrl;
  final String resendUrl;

  String encode() => '$verifyUrl\n$resendUrl';

  /// When the links stop working: the signed URLs' own `expires` parameter
  /// (Unix seconds), read without altering the URL. Null if absent.
  DateTime? get expiresAt {
    final raw = Uri.tryParse(resendUrl)?.queryParameters['expires'] ??
        Uri.tryParse(verifyUrl)?.queryParameters['expires'];
    final seconds = raw == null ? null : int.tryParse(raw);
    return seconds == null
        ? null
        : DateTime.fromMillisecondsSinceEpoch(seconds * 1000, isUtc: true);
  }

  /// True once [expiresAt] has passed; the request would only get a 403.
  bool get isExpired {
    final at = expiresAt;
    return at != null && !DateTime.now().toUtc().isBefore(at);
  }

  static RiderVerificationHandle? parse(String handle) {
    final parts = handle.split('\n');
    if (parts.length != 2 || parts.any((p) => !p.startsWith('http'))) return null;
    return RiderVerificationHandle(parts[0], parts[1]);
  }
}

/// Reads `POST /api/rider/register`: `{message, data: {rider_id, status,
/// email_masked, verify_url, resend_url}}`. A new rider must confirm the
/// emailed code first, so the result opens the email gate when the server
/// sent the signed URLs, and the approval gate otherwise.
AuthResult riderRegistrationResultFrom(
  Map<String, Object?> body, {
  required String email,
}) {
  final data = unwrapMap(body);
  final handle = switch ((data['verify_url'], data['resend_url'])) {
    (final String verify, final String resend) =>
      RiderVerificationHandle(verify, resend).encode(),
    _ => null,
  };
  final remote = handle != null
      ? RemoteRegistrationStatus.pendingVerification
      : RemoteRegistrationStatus.pendingApproval;

  return AuthResult(
    role: UserRole.courier,
    status: accountStatusFor(remote),
    registrationStatus: remote,
    email: email.trim(),
    verificationId: handle,
  );
}
