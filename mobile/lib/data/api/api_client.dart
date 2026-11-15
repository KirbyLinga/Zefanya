// lib/data/api/api_client.dart
//
// The app's single door to the network.
//
// ## What this file is responsible for
//
// Four things, and deliberately nothing else:
//
//   1. **Where requests go** — one base URL, one place it is read from.
//   2. **Who the caller is** — attaching `Authorization: Bearer <token>`, and
//      *not* attaching it when a caller says the route is public.
//   3. **Turning HTTP into types** — every status code is mapped to one of
//      `api_exception.dart`'s types exactly once, here.
//   4. **Not hanging** — a timeout on every request, so a dead connection
//      becomes a `NetworkException` a screen can render rather than a spinner
//      that never resolves.
//
// What it is *not* responsible for: knowing anything about products, orders or
// vouchers. It does not parse a `Product`; that is a DTO mapper's job (see
// `dto/`). Keeping the client generic is what lets a live repository be
// written — and tested against a fake — without this file changing.
//
// ## Endpoints are called from the repositories, not from here
//
// Nothing below knows what a product or an order is; that is a DTO mapper's job
// (see `dto/`). What changed with the API pass is that the client is no longer
// hypothetical: `POST /login/buyer`, `POST /register/buyer` (multipart, because
// the ID document is a file part), the two OTP routes and `GET /me` are live,
// and `LiveAuthRepository` is the thing that calls them. The other live
// repositories still throw `ApiNotImplementedException` naming their endpoint,
// so the day the next one lands, only that repository changes.

import 'package:flutter/foundation.dart' show visibleForTesting;
import 'dart:async';
import 'dart:convert';
import 'dart:typed_data';
import 'dart:io';

import 'package:http/http.dart' as http;

import 'api_exception.dart';
import 'token_store.dart';

/// Decides whether a request may leave the device. [check] throws to refuse
/// and returns normally to allow it. [path] is relative to the API base.
abstract interface class ApiRoutePolicy {
  void check(String method, String path);
}

class ApiClient {
  ApiClient({
    required String baseUrl,
    required TokenStore tokenStore,
    http.Client? httpClient,
    this.timeout = const Duration(seconds: 20),
    this.routePolicy,
  }) : // Trailing slash trimmed here so a caller can pass either form. Two
       // consecutive slashes produce `//products`, which Laravel routes do
       // not match — and it fails as a 404, which reads like a missing route
       // rather than like a typo in a config string.
       baseUrl = baseUrl.endsWith('/')
           ? baseUrl.substring(0, baseUrl.length - 1)
           : baseUrl,
       _tokens = tokenStore,
       _client = httpClient ?? http.Client();

  /// e.g. `https://api.zefanya.test/api` — includes the `/api` prefix.
  final String baseUrl;

  /// Consulted before *every* request leaves the device (JSON, multipart,
  /// binary, renewal and signed-link calls all pass through the one dispatch).
  ///
  /// The WebZefanya profile installs the verified route contract here, so an
  /// endpoint WebZefanya does not have fails locally with
  /// `ApiNotImplementedException` instead of reaching the server as a 404. It
  /// is a client-side safety gate, not server authorization.
  final ApiRoutePolicy? routePolicy;

  final TokenStore _tokens;
  final http.Client _client;

  /// Per-request budget. A phone on a weak connection can take a while to
  /// fail; the point of this is that it *fails* rather than spinning forever.
  final Duration timeout;

  /// Whether a base URL was supplied at all.
  ///
  /// Every request re-checks this and throws [ApiNotConfiguredException]
  /// rather than attempting a relative URI, which would resolve against
  /// nothing and surface as a confusing socket error.
  bool get isConfigured => baseUrl.isNotEmpty;

  /// [url]'s path relative to the configured API, or null when [url] is not
  /// inside it.
  ///
  /// "Inside" is exact: the same scheme, host and port, and a path that is the
  /// API path itself or continues it across a `/` boundary. `/apibuyer/login`
  /// is **not** inside `/api`, and neither is another host or port.
  @visibleForTesting
  String? relativePathOf(Uri url) => _relativePathOrNull(url);

  bool _originMatches(Uri url) {
    final base = Uri.parse(baseUrl);
    int port(Uri u) => u.hasPort ? u.port : (u.isScheme('https') ? 443 : 80);
    return url.scheme.toLowerCase() == base.scheme.toLowerCase() &&
        url.host.toLowerCase() == base.host.toLowerCase() &&
        port(url) == port(base);
  }

  String? _relativePathOrNull(Uri url) {
    final base = Uri.parse(baseUrl);
    int port(Uri u) => u.hasPort ? u.port : (u.isScheme('https') ? 443 : 80);
    if (url.scheme.toLowerCase() != base.scheme.toLowerCase() ||
        url.host.toLowerCase() != base.host.toLowerCase() ||
        port(url) != port(base)) {
      return null;
    }
    final prefix = base.path.endsWith('/')
        ? base.path.substring(0, base.path.length - 1)
        : base.path;
    final path = url.path;
    if (prefix.isEmpty) return path.isEmpty ? '/' : path;
    if (path == prefix) return '/';
    if (path.startsWith('$prefix/')) return path.substring(prefix.length);
    return null;
  }

  /// The only signed links this app follows: a rider's registration
  /// verify / resend-code links (WebZefanya `rider/registration/{id}/...`).
  static final RegExp _signedRoute = RegExp(
    r'^/rider/registration/[^/]+/(verify-otp|resend-otp)$',
  );

  static final RegExp _signedRouteLoose = RegExp(
    r'^(/api)?/rider/registration/[^/]+/(verify-otp|resend-otp)$',
  );

  Uri _uri(String path, [Map<String, String>? query]) {
    final normalized = path.startsWith('/') ? path : '/$path';
    final parsed = Uri.parse('$baseUrl$normalized');
    return query == null || query.isEmpty
        ? parsed
        : parsed.replace(
            queryParameters: {...parsed.queryParameters, ...query},
          );
  }

  Future<Map<String, String>> _headers({required bool authenticated}) async {
    final epoch = _sessionEpoch;
    final headers = <String, String>{
      'Accept': 'application/json',
      'Content-Type': 'application/json',
    };
    if (authenticated) {
      await _renewIfDue();
      final token = await _readToken(epoch);
      // No token is not an error at this layer: a public route that some
      // caller marked authenticated by mistake will get a 401 from the server,
      // which is the correct answer and is mapped below. Throwing here instead
      // would make the client's behaviour depend on local storage, which is a
      // worse thing to debug.
      if (token != null && token.isNotEmpty) {
        headers['Authorization'] = 'Bearer $token';
      }
    }
    return headers;
  }

  /// `GET [path]` for a binary body (a private image), with the same token,
  /// timeout and error mapping as every JSON call. A non-2xx answer throws the
  /// same typed [ApiException] a JSON call would.
  Future<Uint8List> getBytes(String path) async {
    if (!isConfigured) throw const ApiNotConfiguredException();
    final request = http.Request('GET', _uri(path))
      ..headers.addAll(await _headers(authenticated: true));
    final response = await _dispatch(request);
    final status = response.statusCode;
    if (status >= 200 && status < 300) return response.bodyBytes;
    _interpret(response);
    throw ServerException(statusCode: status);
  }

  /// `GET [path]`, decoded as a JSON object.
  ///
  /// [bearerToken] sends that exact token instead of the stored one (and skips
  /// renewal): a sign-in reads its own new account, and a sign-out revokes its
  /// own captured token, even if another session has since been stored.
  Future<Map<String, Object?>> getJson(
    String path, {
    Map<String, String>? query,
    bool authenticated = true,
    String? bearerToken,
  }) => _send(
    'GET',
    path,
    query: query,
    authenticated: authenticated,
    body: null,
    bearerToken: bearerToken,
  );

  /// `POST [path]` with a JSON body. See [getJson] for [bearerToken].
  Future<Map<String, Object?>> postJson(
    String path, {
    Object? body,
    bool authenticated = true,
    String? bearerToken,
  }) => _send(
    'POST',
    path,
    authenticated: authenticated,
    body: body,
    bearerToken: bearerToken,
  );

  /// `POST` to a URL the server signed for this client (WebZefanya's rider
  /// registration `verify_url` / `resend_url`), unauthenticated.
  ///
  /// The URL is sent exactly as the server issued it: Laravel's `signed`
  /// middleware checks a signature over the whole URL, so changing any part
  /// of it (scheme, host, path or query) would invalidate it. The only check
  /// made here is that it points at this app's own API host, so a URL from a
  /// response can never send the app's request somewhere else.
  Future<Map<String, Object?>> postSigned(
    String signedUrl, {
    Object? body,
  }) async {
    if (!isConfigured) throw const ApiNotConfiguredException();

    final target = Uri.tryParse(signedUrl);
    // The exact configured origin always. Under the WebZefanya route policy the
    // path must also sit inside the configured API (a `/` boundary) and be a
    // registration link; the demo profile keeps its existing behaviour, where
    // the server may issue `/api/...` links against a base URL that omits
    // `/api`, so it checks the registration route with that optional prefix.
    final relative = target == null ? null : _relativePathOrNull(target);
    final routeOk = target == null
        ? false
        : routePolicy != null
        ? relative != null && _signedRoute.hasMatch(relative)
        : _originMatches(target) && _signedRouteLoose.hasMatch(target.path);
    if (target == null ||
        !target.isScheme('http') && !target.isScheme('https') ||
        !routeOk) {
      throw const MalformedResponseException(
        message: 'The server sent a verification link for a different address.',
      );
    }
    final request = http.Request('POST', target)
      ..headers.addAll(await _headers(authenticated: false));
    if (body != null) request.body = jsonEncode(body);

    return _interpret(await _dispatch(request));
  }

  /// `PUT [path]` with a JSON body.
  Future<Map<String, Object?>> putJson(
    String path, {
    Object? body,
    bool authenticated = true,
  }) => _send('PUT', path, authenticated: authenticated, body: body);

  /// `PATCH [path]` with a JSON body.
  ///
  /// `PATCH` rather than `PUT` is the app's default for updates because every
  /// update this app makes is partial: a review edit changes stars and text,
  /// not the purchase it belongs to. A `PUT` would require the client to know
  /// and resend fields it does not own.
  Future<Map<String, Object?>> patchJson(
    String path, {
    Object? body,
    bool authenticated = true,
  }) => _send('PATCH', path, authenticated: authenticated, body: body);

  /// `DELETE [path]`.
  Future<Map<String, Object?>> deleteJson(
    String path, {
    bool authenticated = true,
  }) => _send('DELETE', path, authenticated: authenticated, body: null);

  // -------------------------------------------------------------------------
  // The request path
  // -------------------------------------------------------------------------

  Future<Map<String, Object?>> _send(
    String method,
    String path, {
    required bool authenticated,
    Object? body,
    Map<String, String>? query,
    String? bearerToken,
  }) async {
    if (!isConfigured) throw const ApiNotConfiguredException();

    final request = http.Request(method, _uri(path, query))
      ..headers.addAll(
        bearerToken != null
            ? {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'Authorization': 'Bearer $bearerToken',
              }
            : await _headers(authenticated: authenticated),
      );
    if (body != null) request.body = jsonEncode(body);

    return _interpret(await _dispatch(request));
  }

  /// Sends [request] and turns every transport failure into a type.
  ///
  /// Shared by the JSON and the multipart paths so the two cannot disagree
  /// about what a dropped connection is called: the timeout/socket/client trio
  /// below is one policy, and a second copy of it is how one endpoint ends up
  /// throwing a raw `SocketException` at a screen.
  Future<http.Response> _dispatch(http.BaseRequest request) async {
    final policy = routePolicy;
    if (policy != null) {
      // Protected WebZefanya traffic must not expose credentials over HTTP,
      // including through redirects returned by an HTTPS endpoint.
      if (!request.url.isScheme('https') || request.url.userInfo.isNotEmpty) {
        throw ApiNotImplementedException(
          request.method,
          contractSection: 'WebZefanya requires HTTPS without URL credentials',
          message: 'A secure API address is required.',
        );
      }
      request.followRedirects = false;
      final relative = _relativePathOrNull(request.url);
      if (relative == null) {
        throw ApiNotImplementedException(
          '${request.method} ${request.url.path}',
          contractSection: 'outside the configured API (origin or path)',
          message:
              'That request is outside the configured API and was not sent.',
        );
      }
      policy.check(request.method, relative);
    }
    try {
      final streamed = await _client.send(request).timeout(timeout);
      return await http.Response.fromStream(streamed).timeout(timeout);
    } on TimeoutException catch (e) {
      // The one failure a user experiences as "nothing is happening", so it
      // has to become an error rather than a pending future.
      throw NetworkException(
        message: 'The server took too long to respond. Please try again.',
        cause: e,
      );
    } on SocketException catch (e) {
      throw NetworkException(
        message: 'Could not reach the server. Check your connection.',
        cause: e,
      );
    } on http.ClientException catch (e) {
      // Also covers a closed connection and a malformed status line, both of
      // which http surfaces as ClientException.
      throw NetworkException(
        message: 'The connection was interrupted. Please try again.',
        cause: e,
      );
    }
  }

  /// `POST [path]` as `multipart/form-data` — fields plus files.
  ///
  /// Registration is the only request in this app that carries a file, and a
  /// JSON body cannot carry one: the server validates `upload_id` as
  /// `file|mimes:jpg,jpeg,png,pdf|max:5120`, so it has to arrive as a real
  /// part. Kept as its own method rather than folded into [postJson] so that a
  /// caller cannot send a file's *path* where the file belongs — a mistake that
  /// reads as a 422 about a missing upload rather than as a client bug.
  ///
  /// [files] maps the server's part name to a path on disk. A path that cannot
  /// be read is a [ValidationException] against that part, because the one way
  /// it happens in practice is a file the picker handed over and something else
  /// has since removed — which the person can fix by choosing it again, and
  /// which must not arrive as an unhandled `FileSystemException`.
  Future<Map<String, Object?>> postMultipart(
    String path, {
    required Map<String, String> fields,
    Map<String, String> files = const {},
    Map<String, ({List<int> bytes, String filename})> byteFiles = const {},
    bool authenticated = true,
  }) async {
    if (!isConfigured) throw const ApiNotConfiguredException();

    final request = http.MultipartRequest('POST', _uri(path))
      ..headers.addAll(await _headers(authenticated: authenticated));
    // `_headers` sets `Content-Type: application/json` for the JSON paths, which
    // would tell the server to decode this body as JSON and drop every part.
    // `MultipartRequest` sets its own content type — with the boundary it just
    // generated — so this one has to come back off.
    request.headers.remove('Content-Type');
    request.fields.addAll(fields);
    for (final entry in byteFiles.entries) {
      request.files.add(
        http.MultipartFile.fromBytes(
          entry.key,
          entry.value.bytes,
          filename: entry.value.filename,
        ),
      );
    }

    for (final entry in files.entries) {
      try {
        request.files.add(
          await http.MultipartFile.fromPath(entry.key, entry.value),
        );
      } on FileSystemException catch (e) {
        throw ValidationException(
          fieldErrors: {
            entry.key: 'That file could not be read. Please choose it again.',
          },
          cause: e,
        );
      }
    }

    return _interpret(await _dispatch(request));
  }

  /// Replaces the stored bearer token.
  ///
  /// Here rather than in a login screen because the client owns the vault:
  /// `TokenStore`'s reason for existing is that exactly one layer knows where a
  /// token lives, and a screen that wrote it directly would make that two.
  ///
  /// [expiresAt] is the server's `expires_at` for this token, when it sent
  /// one; it decides when [renewPath] is used. Unknown (a token restored from
  /// storage after a restart) means "renew before the next request".
  ///
  /// With [epoch] (from [beginSession]), the token is stored only if no newer
  /// session has started since: a sign-in whose answer arrives after a sign-out
  /// or another sign-in returns `false` and stores nothing.
  Future<bool> adoptToken(String token, {DateTime? expiresAt, int? epoch}) {
    final owner = epoch ?? _sessionEpoch;
    return _withCredentials(() async {
      if (owner != _sessionEpoch) return false;
      await _tokens.write(token);
      if (owner != _sessionEpoch) {
        // A platform write cannot be cancelled. Remove its stale result
        // before releasing the queue to any newer credential mutation.
        await _tokens.clear();
        return false;
      }
      _expiresAt = expiresAt;
      _renewBlockedUntil = null;
      return true;
    });
  }

  /// Forgets the token — on logout, and when the server has rejected it.
  ///
  /// With [epoch], it forgets only if that session is still the current one,
  /// so a late cleanup can never erase a newer session's token.
  Future<bool> forgetToken({int? epoch}) {
    final owner = epoch ?? _sessionEpoch;
    return _withCredentials(() async {
      if (owner != _sessionEpoch) return false;
      _expiresAt = null;
      renewPath = null;
      await _tokens.clear();
      return true;
    });
  }

  /// Sign-out: in one serialized step, returns the stored token and clears it,
  /// but only while [epoch] is current. Queued at call time, so it runs before
  /// any credential write requested later (a newer sign-in cannot be taken).
  Future<String?> takeToken({required int epoch}) {
    if (epoch != _sessionEpoch) return Future.value(null);
    // Ownership is checked when queued, not when executed: a newer login
    // cannot cancel this logout's cleanup. Its writes queue behind this take.
    return _withCredentials(() async {
      final token = await _tokens.read();
      if (epoch == _sessionEpoch) {
        _expiresAt = null;
        renewPath = null;
      }
      await _tokens.clear();
      return token;
    });
  }

  // -------------------------------------------------------------------------
  // Session ownership
  // -------------------------------------------------------------------------

  /// Increases on every sign-in, activation and sign-out. Operations capture
  /// it when they start; a credential change is applied only if it is still
  /// the same, so a late reply from an older session can never overwrite,
  /// renew or erase a newer one.
  int get sessionEpoch => _sessionEpoch;
  int _sessionEpoch = 0;

  /// Starts a new session generation and returns it. Every operation that
  /// began under an earlier generation becomes stale.
  int beginSession() {
    _renewing = null;
    _renewBlockedUntil = null;
    return ++_sessionEpoch;
  }

  /// Sets [renewPath] only while [epoch] is current.
  bool setRenewPath(String? path, {required int epoch}) {
    if (epoch != _sessionEpoch) return false;
    renewPath = path;
    return true;
  }

  /// Credential mutations (write, clear, take, renewal write) run one at a
  /// time, in the order they were requested, and each re-checks ownership
  /// inside the critical section. Reads also join the queue so they cannot
  /// observe a partially completed platform credential write or clear.
  Future<void>? _credentialTail;

  Future<T> _withCredentials<T>(Future<T> Function() action) {
    final previous = _credentialTail;
    final run = previous == null ? action() : previous.then((_) => action());
    _credentialTail = run.then<void>((_) {}, onError: (_) {});
    return run;
  }

  Future<String?> _readToken(int epoch) => _withCredentials(() async {
    if (epoch != _sessionEpoch) throw const SessionSupersededException();
    final token = await _tokens.read();
    if (epoch != _sessionEpoch) throw const SessionSupersededException();
    return token;
  });

  // -------------------------------------------------------------------------
  // Token renewal
  // -------------------------------------------------------------------------

  /// The signed-in role's token-renewal route (`/rider/token/refresh`), or
  /// null when that role has none. Set by the auth repository after sign-in
  /// and on session restore; cleared by [forgetToken].
  ///
  /// WebZefanya tokens live for `sanctum.expiration` (60 minutes) and an
  /// expired token cannot be renewed, so a rider mid-shift would otherwise be
  /// signed out every hour. The server rotates the token: same ability, same
  /// lifetime, old token deleted.
  String? renewPath;

  DateTime? _expiresAt;
  DateTime? _renewBlockedUntil;
  Future<void>? _renewing;

  /// How long before expiry the token is traded in.
  static const Duration renewWindow = Duration(minutes: 10);

  /// Renews the token when it is close to expiry (or its expiry is unknown).
  ///
  /// Best-effort: a failed renewal never fails the request that triggered it.
  /// If the token really has expired, that request gets the server's 401 and
  /// the normal signed-out path runs. Concurrent requests share one renewal.
  Future<void> _renewIfDue() {
    final path = renewPath;
    if (path == null) return Future.value();
    final now = DateTime.now();
    final blocked = _renewBlockedUntil;
    if (blocked != null && now.isBefore(blocked)) return Future.value();
    final expiresAt = _expiresAt;
    if (expiresAt != null && expiresAt.difference(now) > renewWindow) {
      return Future.value();
    }
    final epoch = _sessionEpoch;
    return _renewing ??= _renew(path).whenComplete(() {
      if (epoch == _sessionEpoch) _renewing = null;
    });
  }

  Future<void> _renew(String path) async {
    final epoch = _sessionEpoch;
    final token = await _readToken(epoch);
    if (token == null || token.isEmpty) return;

    // Built by hand rather than through [_send]: [_send] would call
    // [_headers], which would call back into this renewal.
    final request = http.Request('POST', _uri(path))
      ..headers.addAll({
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        'Authorization': 'Bearer $token',
      });

    try {
      final payload = unwrapMap(_interpret(await _dispatch(request)));
      final fresh = payload['token'];
      if (fresh is String && fresh.isNotEmpty) {
        // Stored only if this is still the same session and the token it
        // renewed is still the stored one: a renewal that lost to a sign-in,
        // sign-out or another renewal must not replace the newer token.
        await _withCredentials(() async {
          if (epoch != _sessionEpoch) return;
          final stored = await _tokens.read();
          if (epoch != _sessionEpoch || stored != token) return;
          await _tokens.write(fresh);
          if (epoch != _sessionEpoch) {
            await _tokens.clear();
            return;
          }
          _expiresAt =
              DateTime.tryParse('${payload['expires_at']}')?.toLocal() ??
              DateTime.now().add(renewWindow * 2);
          _renewBlockedUntil = null;
        });
        return;
      }
    } on ApiException {
      // Fall through: keep the current token and let the request decide.
    }
    // Do not retry before every request while the server or network is down.
    if (epoch == _sessionEpoch) {
      _renewBlockedUntil = DateTime.now().add(const Duration(minutes: 1));
    }
  }

  /// Turns a raw response into either a decoded body or the right exception.
  ///
  /// The single place status codes become types. Everything above this line is
  /// transport; everything below it is payload.
  Map<String, Object?> _interpret(http.Response response) {
    final status = response.statusCode;
    final decoded = _decodeBody(response);

    if (status >= 200 && status < 300) {
      // 204 No Content is a complete answer (WebZefanya's logout uses it).
      if (status == 204) return const <String, Object?>{};
      if (decoded == null) {
        throw const MalformedResponseException(
          message: 'The server sent an empty or unreadable response.',
        );
      }
      return decoded;
    }

    final message = _messageFrom(decoded);

    if (status == 401) {
      throw UnauthorizedException(
        message: message ?? 'Your session has expired. Please sign in again.',
      );
    }
    if (status == 403) {
      if (_looksPendingApproval(decoded)) {
        throw PendingApprovalException(
          registrationStatus: _registrationStatusFrom(decoded),
          // The email gate's handle, carried rather than dropped on the floor.
          // A 403 that says "confirm your email" and hands back no handle is a
          // refusal the app cannot act on at all — the login screen turns this
          // field into the confirm step, and without it someone who closed the
          // app before typing their code has no way back in.
          verificationId: _stringOrNull(decoded?['verification_id']),
          message: message ?? 'Your account is still being reviewed.',
        );
      }
      throw ForbiddenException(
        message: message ?? 'You do not have access to this.',
      );
    }
    if (status == 404) {
      throw NotFoundException(
        message: message ?? 'That is no longer available.',
      );
    }
    if (status == 422) {
      throw ValidationException(
        fieldErrors: _fieldErrorsFrom(decoded),
        message: message ?? 'Please check the highlighted fields.',
      );
    }
    if (status >= 500) {
      throw ServerException(
        statusCode: status,
        message: message ?? 'The server had a problem. Please try again.',
      );
    }
    throw RequestRejectedException(
      statusCode: status,
      message: message ?? 'That request could not be completed.',
      retryAfter: status == 429 ? _retryAfter(response, decoded) : null,
    );
  }

  /// A 429's wait: WebZefanya's `retry_after` (seconds) in the body, else the
  /// standard `Retry-After` header (Laravel's throttle middleware sets it).
  static Duration? _retryAfter(
    http.Response response,
    Map<String, Object?>? decoded,
  ) {
    final raw = decoded?['retry_after'] ?? response.headers['retry-after'];
    final seconds = switch (raw) {
      final num n => n.toInt(),
      final String s => int.tryParse(s.trim()),
      _ => null,
    };
    return seconds == null || seconds <= 0 ? null : Duration(seconds: seconds);
  }

  /// Decodes a JSON body, tolerating the shapes a real server sends.
  ///
  /// Returns null when the body is empty or not JSON. A body that is a JSON
  /// *array* is wrapped under `data` rather than rejected, so a caller that
  /// expects an object still works if the server answers a list endpoint with
  /// a bare list — see [unwrapList], which is what a repository would call.
  Map<String, Object?>? _decodeBody(http.Response response) {
    final raw = response.body;
    if (raw.trim().isEmpty) return null;
    Object? parsed;
    try {
      parsed = jsonDecode(raw);
    } catch (_) {
      return null;
    }
    if (parsed is Map<String, Object?>) return parsed;
    if (parsed is Map) return parsed.cast<String, Object?>();
    if (parsed is List) return {'data': parsed};
    return null;
  }

  String? _messageFrom(Map<String, Object?>? body) {
    final message = body?['message'];
    return message is String && message.trim().isNotEmpty
        ? message.trim()
        : null;
  }

  /// A trimmed, non-empty string from a decoded body, or `null`.
  ///
  /// Stringified rather than type-checked because `verification_id` is a string
  /// on the way out of the server's route binding and could arrive as a number
  /// from anywhere else, and the only thing this app ever does with a handle is
  /// send it back in a URL segment — where both spellings behave identically.
  String? _stringOrNull(Object? value) {
    final text = value?.toString().trim();
    if (text == null || text.isEmpty) return null;
    return text;
  }

  /// Whether a 403 means "your registration isn't approved yet".
  ///
  /// Three signals accepted, because the contract has to be checkable against
  /// whatever the Laravel side actually emits and all three are plausible
  /// spellings of the same fact. `docs/api-contract.md` §2 specifies the first
  /// one as required.
  bool _looksPendingApproval(Map<String, Object?>? body) {
    if (body == null) return false;
    final code =
        (body['code'] ?? body['error_code'])?.toString().toUpperCase() ?? '';
    if (code.contains('PENDING')) return true;
    final status = body['registration_status']?.toString().toLowerCase() ?? '';
    return status.startsWith('pending');
  }

  /// The server's registration status string, or the closest honest fallback.
  ///
  /// Falls back to `pending_approval` rather than null because the exception
  /// carries a non-nullable field: the app *knows* the account is not approved
  /// (that is what the 403 meant), and the only uncertainty is which gate.
  /// The admin gate is the safer of the two wrong answers, because it promises
  /// a review rather than demanding an action.
  String _registrationStatusFrom(Map<String, Object?>? body) {
    final status = body?['registration_status']?.toString();
    if (status != null && status.trim().isNotEmpty) return status.trim();
    final code = (body?['code'] ?? body?['error_code'])?.toString() ?? '';
    return code.toLowerCase().contains('verification')
        ? 'pending_verification'
        : 'pending_approval';
  }

  /// Laravel's validation shape: `{"errors": {"field": ["msg", ...]}}`.
  ///
  /// Flattened to one message per field — the app renders one line per input,
  /// and the first rule that failed is the one worth showing. The rest of each
  /// array is dropped deliberately: nothing renders it, so there is no reason
  /// to carry it.
  Map<String, String> _fieldErrorsFrom(Map<String, Object?>? body) {
    final errors = body?['errors'];
    if (errors is! Map) return const {};
    return {
      for (final entry in errors.entries)
        entry.key
            .toString(): entry.value is List && (entry.value as List).isNotEmpty
            ? (entry.value as List).first.toString()
            : entry.value?.toString() ?? 'This value is not valid.',
    };
  }
}

/// Unwraps Laravel API Resource's `{"data": ...}` envelope.
///
/// The contract specifies the envelope on every success response, so this
/// exists to be used rather than re-implemented per repository. Tolerant about
/// its absence: if the body has no `data` key, the body itself is returned, so
/// a server that stops wrapping doesn't break parsing.
Map<String, Object?> unwrapMap(Map<String, Object?> body) {
  final data = body['data'];
  if (data is Map<String, Object?>) return data;
  if (data is Map) return data.cast<String, Object?>();
  return body;
}

/// [unwrapMap] for a list endpoint — an empty list when there is nothing,
/// never null.
///
/// "No products" and "a malformed products response" must not look the same to
/// a screen: the first is an empty state, the second is an error. A missing
/// `data` key yields an empty list, because the contract requires the key and
/// an empty state is a far better failure than a crash — but a *non-list*
/// `data` (a string, a number) throws [MalformedResponseException] rather than
/// being silently coerced, because that shape means the endpoint is wrong
/// rather than empty.
List<Map<String, Object?>> unwrapList(Map<String, Object?> body) {
  final data = body['data'] ?? body;
  if (data is List) {
    return [
      for (final entry in data)
        if (entry is Map) entry.cast<String, Object?>(),
    ];
  }
  if (data is Map) return [data.cast<String, Object?>()];
  throw const MalformedResponseException(
    message: 'Expected a list of records, got something else.',
  );
}
