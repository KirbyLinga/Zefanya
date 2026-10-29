// lib/data/api/api_exception.dart
//
// Every way a request can fail, as a type.
//
// ## Why typed rather than "an error string"
//
// The alternative is what most first-cut clients do: throw one `Exception`
// carrying a status code, and let every screen switch on the number. That
// puts the *HTTP* vocabulary (`401`, `422`) into UI code, and it means each
// screen re-decides what a 403 means — so the pending-approval case gets
// handled well on the login screen, forgotten on Orders, and the buyer sees a
// generic failure where the app knew perfectly well what was wrong.
//
// A type per outcome inverts that: the mapping happens once, in `ApiClient`,
// and a screen handling `PendingApprovalException` is making a *product*
// decision rather than an HTTP one.
//
// ## The `sealed` matters
//
// `sealed` makes the set closed, so a `switch` over these in a caller is
// exhaustively checked at compile time. Adding a case here without handling it
// where it matters becomes a build error rather than a silent fall-through to
// "generic failure" — which is the failure this file is trying to prevent.

/// Base for everything this layer throws.
sealed class ApiException implements Exception {
  /// Named rather than positional, so every subclass can narrow a default
  /// without restating the parameter — `{super.message = '...'}`. The first
  /// draft used optional positionals, which does not work against a required
  /// positional super parameter and is why the analyzer rejected it.
  const ApiException({required this.message, this.cause});

  /// Human-readable, and safe to show: these strings are written to be read by
  /// a person, not to leak a stack trace or a raw response body.
  final String message;

  /// The underlying error when there was one (a `SocketException`, a decode
  /// error). For logging only — never rendered.
  final Object? cause;

  /// Whether the same request could plausibly succeed if tried again.
  ///
  /// This is what decides whether a screen shows a **Retry** button, so it is
  /// a real behaviour rather than metadata. A dropped connection or a 500 is
  /// worth retrying; a 422 is not — the request itself was wrong and will be
  /// wrong again, and offering Retry there teaches the user to mash a button
  /// that cannot work.
  bool get isRetryable;

  @override
  String toString() => '$runtimeType: $message';
}

/// The request never reached the server, or the reply never came back —
/// offline, DNS, connection reset, timeout.
///
/// Distinct from a `ServerException` because the user's remedy is different:
/// check your connection versus wait for the service.
final class NetworkException extends ApiException {
  const NetworkException({
    super.message = 'Could not reach the server. Check your connection.',
    super.cause,
  });

  @override
  bool get isRetryable => true;
}

/// `401` — the token is missing, expired, or was revoked.
///
/// Deliberately *not* retryable: retrying with the same dead token produces
/// the same 401. The remedy is to clear the session and send the person to
/// login, which is a navigation decision, not a retry.
final class UnauthorizedException extends ApiException {
  const UnauthorizedException({
    super.message = 'Your session has expired. Please sign in again.',
    super.cause,
  });

  @override
  bool get isRetryable => false;
}

/// `403` where the server says the account itself is not approved yet.
///
/// ## Why this gets its own type
///
/// The spec has two registration gates — email OTP (`pending_verification`)
/// and admin approval (`pending_approval`) — and the app already has a screen
/// for exactly one of them (`PendingApprovalScreen`). Without this type, a
/// 403 arriving on any *other* screen (Catalog, Orders, Cart) can only be
/// rendered as "something went wrong", when the app knows precisely what is
/// wrong and has a screen that says so.
///
/// [registrationStatus] carries the server's own string
/// (`pending_approval` / `pending_verification`) so the screen can pick its
/// copy without guessing which gate the person is at.
final class PendingApprovalException extends ApiException {
  const PendingApprovalException({
    required this.registrationStatus,
    this.verificationId,
    super.message = 'Your account is still being reviewed.',
    super.cause,
  });

  final String registrationStatus;

  /// The server's handle for confirming this registration's email, when the
  /// refusal carried one, and `null` otherwise.
  ///
  /// Only the email gate has one (`PENDING_VERIFICATION`), and the server sends
  /// it precisely so the app can finish that confirmation: without it, someone
  /// told to confirm their email has nothing to address a code *to*, and the
  /// only thing the app could offer is the registration form again — which
  /// would open a second account rather than confirm the first.
  ///
  /// Opaque, exactly like `AuthResult.verificationId`: the route is keyed on
  /// the server's own row id, so this app never parses one or builds one of its
  /// own out of what it does know.
  final String? verificationId;

  /// True when the server's own value looks like the email-OTP gate rather
  /// than the admin gate. Tolerant matching, because this string is the
  /// server's to name and a screen showing "an admin will review this" to
  /// someone who hasn't confirmed their email yet is a small lie with a real
  /// cost: they wait for a person who was never going to be asked.
  bool get awaitingEmailVerification =>
      registrationStatus.toLowerCase().contains('verification');

  /// Whether this refusal is one the app can actually *act* on.
  ///
  /// Both halves, for the same reason `AuthResult.canConfirmEmail` needs both:
  /// the status says there is a code to enter, and [verificationId] is what an
  /// entry would be sent against. A screen gating its confirm step on the
  /// status alone would offer an action whose submit could not be addressed to
  /// anything — which is worse than not offering it, because it looks like it
  /// worked.
  bool get canConfirmEmail =>
      awaitingEmailVerification && verificationId != null;

  @override
  bool get isRetryable => false;
}

/// `403` for any other reason — the account is fine, this action isn't
/// allowed (a buyer hitting a courier-only route, say).
final class ForbiddenException extends ApiException {
  const ForbiddenException({
    super.message = 'You do not have access to this.',
    super.cause,
  });

  @override
  bool get isRetryable => false;
}

/// `404` — the thing is genuinely gone.
///
/// Retryable is false deliberately: refreshing a deleted order changes
/// nothing, and the correct response is to stop showing it rather than to
/// offer the user a button that will 404 again.
final class NotFoundException extends ApiException {
  const NotFoundException({
    super.message = 'That is no longer available.',
    super.cause,
  });

  @override
  bool get isRetryable => false;
}

/// `422` with per-field messages.
///
/// [fieldErrors] is keyed by the field name the *server* uses
/// (`shipping_address.barangay`, `items.0.quantity`) because translating those
/// into widget keys is the form's job — it's the only layer that knows its own
/// structure. Kept as a map rather than collapsed into [message] so a form can
/// point at the offending input instead of showing one banner nobody can act
/// on.
final class ValidationException extends ApiException {
  const ValidationException({
    required this.fieldErrors,
    super.message = 'Please check the highlighted fields.',
    super.cause,
  });

  final Map<String, String> fieldErrors;

  /// The first message for [field], if the server flagged it.
  String? forField(String field) => fieldErrors[field];

  @override
  bool get isRetryable => false;
}

/// `5xx` — the server broke.
///
/// Retryable: the one failure that is nobody's fault and often transient, and
/// therefore the case a Retry button is genuinely for.
final class ServerException extends ApiException {
  const ServerException({
    required this.statusCode,
    super.message = 'The server had a problem. Please try again.',
    super.cause,
  });

  final int statusCode;

  @override
  bool get isRetryable => true;
}

/// A `4xx` this build has no specific case for.
///
/// Exists so that adding a new status code on the server is a *degraded*
/// experience rather than an unhandled crash, and so the type set stays
/// `sealed`.
final class RequestRejectedException extends ApiException {
  const RequestRejectedException({
    required this.statusCode,
    super.message = 'That request could not be completed.',
    super.cause,
  });

  final int statusCode;

  @override
  bool get isRetryable => false;
}

/// A `2xx` whose body could not be understood as the shape the caller asked
/// for — bad JSON, or a body that decoded but is missing a required field.
///
/// Retryable, and that is a considered choice rather than an oversight: the
/// most common cause in practice is a truncated response or a captive-portal
/// HTML page returned with a 200, both transient. A genuinely wrong schema
/// will simply fail again, which costs one retry; treating it as permanent
/// would leave the screen dead with no recourse.
final class MalformedResponseException extends ApiException {
  const MalformedResponseException({
    super.message = 'The server sent something we could not read.',
    super.cause,
  });

  @override
  bool get isRetryable => true;
}

/// Live mode was requested but the API location is missing.
///
/// Not an HTTP failure at all — a *configuration* failure, thrown before a
/// request is attempted. Separate from [NetworkException] because the two read
/// identically to a user ("couldn't reach the server") while having completely
/// different causes, and the person who needs to fix this one is a developer,
/// not someone holding the phone.
final class ApiNotConfiguredException extends ApiException {
  const ApiNotConfiguredException({
    super.message = 'The app is set to use the live API but no base URL is '
        'configured. See lib/config/dev_flags.dart.',
    super.cause,
  });

  @override
  bool get isRetryable => false;
}

/// An endpoint the Laravel side has not built yet.
///
/// Thrown by the live repositories, whose paths and payloads are specified in
/// `docs/api-contract.md` but which cannot work until that API exists. An
/// explicit type rather than an `UnimplementedError` so a live build fails
/// with a sentence naming the missing endpoint, and so mock mode is provably
/// unaffected by code that has never run.
final class ApiNotImplementedException extends ApiException {
  const ApiNotImplementedException(this.endpoint, {this.contractSection})
      : super(
          message: 'The live API for this is not built yet — '
              'see docs/api-contract.md.',
        );

  /// e.g. `GET /products`.
  final String endpoint;

  /// Which part of the contract specifies it, e.g. `§5.2`.
  final String? contractSection;

  @override
  bool get isRetryable => false;
}