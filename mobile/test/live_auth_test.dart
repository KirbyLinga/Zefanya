// test/live_auth_test.dart
//
// The live half of the auth repository, against a fake HTTP transport.
//
// ## What this covers that the server tests cannot
//
// The Laravel feature tests prove the endpoints behave. These prove the *app*
// sends what those endpoints expect and reads back what they answer — the two
// halves of one contract, checked from both sides. The requests are examined
// rather than assumed, because the failure mode this guards is quiet: a login
// that posts the right fields to the wrong path, or a registration that sends
// an ID *path* where the server wants a file part, both look like working code
// and differ only in what went over the wire.
//
// The multipart assertions are on the encoded body for the same reason: the
// file part exists only in those bytes, so "was the ID attached?" is a question
// about the request, not about the profile object.
//
// ## What is deliberately *not* here
//
// No live network. Every response is scripted, including the refusals, because
// the interesting cases are the unhappy ones — a shut gate, a spent code, a
// rejected token — and a real server cannot be asked to produce them on demand.

import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:zefanya/data/api/api_client.dart';
import 'package:zefanya/data/api/api_exception.dart';
import 'package:zefanya/data/api/token_store.dart';
import 'package:zefanya/data/dto/registration_payload.dart';
import 'package:zefanya/data/dto/remote_status.dart';
import 'package:zefanya/data/repositories/auth_repository.dart';
import 'package:zefanya/state/auth_state.dart';

/// A fake transport: records what was sent, answers from a script.
class _FakeServer {
  _FakeServer(this._script);

  final List<http.Response> _script;
  int _served = 0;

  final List<String> methods = [];
  final List<String> paths = [];
  final List<String> bodies = [];
  final List<Map<String, String>> headers = [];

  int get calls => paths.length;

  late final http.Client client =
      MockClient.streaming((request, bodyStream) async {
    methods.add(request.method);
    paths.add(request.url.path);
    headers.add(request.headers);
    // `latin1`, not `bytesToString()`: a multipart body contains the uploaded
    // file's bytes, and a JPEG is not valid UTF-8 — decoding it as text throws
    // before any assertion runs. Latin-1 maps every byte to one char, so the
    // ASCII parts (`name="upload_id"`) are still findable and the binary parts
    // survive intact for a `contains` check on the file's own bytes.
    bodies.add(latin1.decode(await bodyStream.toBytes()));

    final response = _next();
    return http.StreamedResponse(
      Stream.value(utf8.encode(response.body)),
      response.statusCode,
      headers: {'content-type': 'application/json; charset=utf-8'},
    );
  });

  /// The last scripted answer repeats, so a test that makes one request more
  /// than it planned fails on its own assertions rather than on an index error.
  http.Response _next() =>
      _script[_served < _script.length ? _served++ : _script.length - 1];
}

http.Response _json(Map<String, Object?> body, [int status = 200]) =>
    http.Response(jsonEncode(body), status);

/// The live repository, its token store, and the transport it talks through.
class _Live {
  _Live(List<http.Response> script)
      : server = _FakeServer(script),
        tokens = InMemoryTokenStore() {
    client = ApiClient(
      baseUrl: 'https://api.zefanya.test',
      tokenStore: tokens,
      httpClient: server.client,
    );
    repository = LiveAuthRepository(client);
  }

  final _FakeServer server;
  final InMemoryTokenStore tokens;
  late final ApiClient client;
  late final LiveAuthRepository repository;
}

RegistrationProfile _profile({String? govIdPath}) => RegistrationProfile(
      lastName: 'Dela Cruz',
      firstName: 'Juan',
      middleInitial: 'D',
      sex: 'Male',
      phone: '0917 123 4567',
      birthday: DateTime(1998, 4, 7),
      province: 'Cebu',
      municipality: 'Cebu City',
      barangay: 'Lahug',
      street: '12 Mabini Street',
      govIdPath: govIdPath,
    );

void main() {
  late Directory dir;
  late String idPath;

  setUp(() {
    dir = Directory.systemTemp.createTempSync('zef_live_auth');
    idPath = '${dir.path}${Platform.pathSeparator}gov_id.jpg';
    File(idPath).writeAsBytesSync([0xFF, 0xD8, 0xFF, 0xD9]);
  });

  tearDown(() => dir.deleteSync(recursive: true));

  // ── Registration ─────────────────────────────────────────────────────────

  test('registration posts the profile as multipart with the ID attached',
      () async {
    final live = _Live([
      _json({
        'ok': true,
        'verification_id': '42',
        'email': 'juan@example.com',
        'registration_status': 'pending_verification',
      }, 201),
    ]);

    final result = await live.repository.register(
      role: UserRole.buyer,
      email: 'juan@example.com',
      password: 'Password1!',
      profile: _profile(govIdPath: idPath),
    );

    expect(live.server.methods.single, 'POST');
    expect(live.server.paths.single, '/register/buyer');
    expect(
      live.server.headers.single['content-type'],
      contains('multipart/form-data'),
    );

    final body = live.server.bodies.single;
    // The file is a part. Sending its path as a JSON field would produce a 422
    // about a missing upload, from code that looked correct while reading it.
    expect(body, contains('name="upload_id"'));
    expect(body, contains('filename="gov_id.jpg"'));
    // And its *bytes*, not just its name: the fixture above is the four-byte
    // JPEG signature, so this fails if the part is ever sent empty.
    expect(body, contains(String.fromCharCodes([0xFF, 0xD8, 0xFF, 0xD9])));
    // The server's vocabulary, not the form's — `toBuyerFields` did the work.
    expect(body, contains('name="last_name"'));
    expect(body, contains('name="birthday"'));
    expect(body, contains('name="contact_no"'));
    expect(body, contains('name="sex"'));
    expect(body, contains('name="address_mode"'));
    expect(body, contains('name="password_confirmation"'));
    // The form's relaxed phone is tightened on the way out: the server's rule is
    // `^09\d{9}$`, and the spaces the form accepts would fail it.
    expect(body, contains('09171234567'));
    // Code slots are omitted rather than blanked: this app's picker has names
    // and no PSGC codes, so "no code" is stated once, not asserted twice.
    expect(body, isNot(contains('province_code')));

    expect(
      result.registrationStatus,
      RemoteRegistrationStatus.pendingVerification,
    );
    // The gate *and* a usable handle: `canConfirmEmail` is what the app's
    // pending screen gates its "Enter Code" button on, so a registration that
    // returned the gate without the id would leave that button unrenderable.
    expect(result.canConfirmEmail, isTrue);
    expect(result.verificationId, '42');
    expect(result.email, 'juan@example.com');
  });

  test('registration without an ID is refused without a request', () async {
    final live = _Live([_json({'ok': true})]);

    await expectLater(
      live.repository.register(
        role: UserRole.buyer,
        email: 'juan@example.com',
        password: 'Password1!',
        profile: _profile(),
      ),
      throwsA(
        isA<ValidationException>()
            .having((e) => e.forField('upload_id'), 'message', isNotNull),
      ),
    );

    // Nothing was sent. `upload_id` is required server-side, so a round-trip
    // could only come back with the same refusal, slower.
    expect(live.server.calls, 0);
  });

  test('a server validation failure arrives as field errors', () async {
    final live = _Live([
      _json({
        'message': 'Enter a valid PH mobile number (09XXXXXXXXX).',
        'errors': {
          'contact_no': ['Enter a valid PH mobile number (09XXXXXXXXX).'],
        },
      }, 422),
    ]);

    await expectLater(
      live.repository.register(
        role: UserRole.buyer,
        email: 'juan@example.com',
        password: 'Password1!',
        profile: _profile(govIdPath: idPath),
      ),
      throwsA(
        isA<ValidationException>().having(
          (e) => e.forField('contact_no'),
          'the server sentence, keyed by the server field name',
          'Enter a valid PH mobile number (09XXXXXXXXX).',
        ),
      ),
    );
  });

  // ── Confirming the code ──────────────────────────────────────────────────

  test('the code goes to the handle the server gave, as otp', () async {
    final live = _Live([
      _json({'ok': true, 'registration_status': 'pending_approval'}),
    ]);

    final result = await live.repository.verifyOtp(
      role: UserRole.buyer,
      verificationId: '42',
      code: ' 123456 ',
    );

    expect(live.server.methods.single, 'POST');
    expect(live.server.paths.single, '/register/buyer/verify-otp/42');
    // `otp`, not `code` — read off the web client that posts to the same route.
    expect(jsonDecode(live.server.bodies.single), {'otp': '123456'});
    // No session exists yet, so no token is attached: the route is public, and a
    // stale token would make this request look like a different caller's.
    expect(live.server.headers.single.containsKey('authorization'), isFalse);

    // The gate has moved and the handle is gone: the code is spent, so an app
    // still holding its id could offer a step that can no longer be performed.
    expect(result.registrationStatus, RemoteRegistrationStatus.pendingApproval);
    expect(result.canConfirmEmail, isFalse);
    expect(result.verificationId, isNull);
    expect(result.status, AccountStatus.pendingApproval);
  });

  test('a refused code surfaces the server sentence and changes nothing',
      () async {
    final live = _Live([
      _json({
        'ok': false,
        'message': "That code doesn't match. Please try again.",
      }, 422),
    ]);

    await expectLater(
      live.repository.verifyOtp(
        role: UserRole.buyer,
        verificationId: '42',
        code: '000000',
      ),
      // A 422 with no `errors` bag: the server said no without saying which
      // field was wrong, and the screen renders this sentence verbatim.
      throwsA(
        isA<ValidationException>().having(
          (e) => e.message,
          'the server sentence',
          "That code doesn't match. Please try again.",
        ),
      ),
    );
  });

  test('resend posts to the handle path and reads nothing back', () async {
    final live = _Live([
      _json({'ok': true, 'message': 'A new code has been sent.'}),
    ]);

    await live.repository.resendOtp(role: UserRole.buyer, verificationId: '42');

    expect(live.server.methods.single, 'POST');
    expect(live.server.paths.single, '/register/buyer/verify-otp/42/resend');
    expect(live.server.headers.single.containsKey('authorization'), isFalse);
  });

  // ── The courier half ─────────────────────────────────────────────────────

  test('the courier role names the missing endpoint instead of posting',
      () async {
    final live = _Live([_json({'ok': true})]);

    // Registration is still the web route — no API endpoint for courier signup yet.
    await expectLater(
      live.repository.register(
        role: UserRole.courier,
        email: 'rider@example.com',
        password: 'Password1!',
        profile: _profile(govIdPath: idPath),
      ),
      throwsA(
        isA<ApiNotImplementedException>()
            .having((e) => e.endpoint, 'endpoint', 'POST /register/logistics'),
      ),
    );
    await expectLater(
      live.repository.verifyOtp(
        role: UserRole.courier,
        verificationId: '9',
        code: '123456',
      ),
      throwsA(
        isA<ApiNotImplementedException>().having(
          (e) => e.endpoint,
          'endpoint',
          '/register/logistics/verify-otp/9',
        ),
      ),
    );

    // Only 0 requests sent — registration is not wired, login is separate.
    expect(live.server.calls, 0);
  });

  // ── Signing in ───────────────────────────────────────────────────────────

  test('login stores the token and returns who the account is', () async {
    final live = _Live([
      _json({
        'ok': true,
        'token': '1|plaintext',
        'role': 'buyer',
        'name': 'Juan D. Dela Cruz',
        'email': 'juan@example.com',
        'registration_status': 'approved',
      }),
    ]);

    final result = await live.repository.login(
      role: UserRole.buyer,
      email: 'juan@example.com',
      password: 'Password1!',
    );

    expect(live.server.paths.single, '/login/buyer');
    expect(
      jsonDecode(live.server.bodies.single),
      {'email': 'juan@example.com', 'password': 'Password1!'},
    );

    // Stored, not merely returned: this token is the only credential the app
    // has, and a result without it stored would be a session that looks signed
    // in and authenticates nothing.
    expect(await live.tokens.read(), '1|plaintext');
    expect(result.status, AccountStatus.active);
    expect(result.registrationStatus, RemoteRegistrationStatus.approved);
    expect(result.name, 'Juan D. Dela Cruz');
  });

  test('a shut gate is not reported as a credential failure', () async {
    final live = _Live([
      _json({
        'ok': false,
        'code': 'PENDING_APPROVAL',
        'registration_status': 'pending_approval',
        'message': 'Your account is waiting for approval.',
      }, 403),
    ]);

    await expectLater(
      live.repository.login(
        role: UserRole.buyer,
        email: 'juan@example.com',
        password: 'Password1!',
      ),
      throwsA(
        isA<PendingApprovalException>()
            .having((e) => e.registrationStatus, 'status', 'pending_approval')
            .having((e) => e.awaitingEmailVerification, 'email gate', isFalse)
            // The admin gate has no code to confirm, so there is nothing for a
            // handle to address and nothing the app could offer.
            .having((e) => e.verificationId, 'handle', isNull)
            .having((e) => e.canConfirmEmail, 'confirmable', isFalse),
      ),
    );

    // No token for an account that cannot use one.
    expect(await live.tokens.read(), isNull);
  });

  test('the email gate is told apart from the admin gate', () async {
    final live = _Live([
      _json({
        'ok': false,
        'code': 'PENDING_VERIFICATION',
        'registration_status': 'pending_verification',
        'verification_id': '42',
        'message': 'Confirm your email address to continue.',
      }, 403),
    ]);

    await expectLater(
      live.repository.login(
        role: UserRole.buyer,
        email: 'juan@example.com',
        password: 'Password1!',
      ),
      throwsA(
        isA<PendingApprovalException>()
            .having((e) => e.registrationStatus, 'status', 'pending_verification')
            .having((e) => e.awaitingEmailVerification, 'email gate', isTrue)
            // Carried rather than dropped: this is the handle the login screen
            // turns into the confirm step, and a refusal that lost it would
            // strand anyone who closed the app before typing their code.
            .having((e) => e.verificationId, 'handle', '42')
            .having((e) => e.canConfirmEmail, 'confirmable', isTrue),
      ),
    );

    // Still no token. The account may sign in, but it cannot *use* the app, and
    // a stored credential would be a session that authenticates into a 403.
    expect(await live.tokens.read(), isNull);
  });

  test('an email gate with no handle is not offered as a confirmable one',
      () async {
    // The gate open with nothing to send against — the shape the OTP screen has
    // to survive. `canConfirmEmail` is what the pending screen gates its confirm
    // step on, so this is the line between "we could not offer it" and "we
    // offered it and the submit had nothing to address".
    final live = _Live([
      _json({
        'ok': false,
        'code': 'PENDING_VERIFICATION',
        'registration_status': 'pending_verification',
        'message': 'Confirm your email address to continue.',
      }, 403),
    ]);

    await expectLater(
      live.repository.login(
        role: UserRole.buyer,
        email: 'juan@example.com',
        password: 'Password1!',
      ),
      throwsA(
        isA<PendingApprovalException>()
            .having((e) => e.awaitingEmailVerification, 'email gate', isTrue)
            .having((e) => e.verificationId, 'handle', isNull)
            .having((e) => e.canConfirmEmail, 'confirmable', isFalse),
      ),
    );
  });

  test('a 200 without a token is malformed, not a session', () async {
    final live = _Live([
      _json({'ok': true, 'role': 'buyer', 'registration_status': 'approved'}),
    ]);

    await expectLater(
      live.repository.login(
        role: UserRole.buyer,
        email: 'juan@example.com',
        password: 'Password1!',
      ),
      throwsA(isA<MalformedResponseException>()),
    );

    expect(await live.tokens.read(), isNull);
  });

  // ── Ending a session ─────────────────────────────────────────────────────

  test('logout revokes the token it was called with', () async {
    final live = _Live([_json({'ok': true, 'message': 'Signed out.'})]);
    await live.tokens.write('1|plaintext');

    await live.repository.logout(role: UserRole.buyer);

    expect(live.server.paths.single, '/logout');
    expect(live.server.headers.single['authorization'], 'Bearer 1|plaintext');
    expect(await live.tokens.read(), isNull);
  });

  test('a failed logout still forgets the token locally', () async {
    final live = _Live([_json({'message': 'The server had a problem.'}, 500)]);
    await live.tokens.write('1|plaintext');

    await expectLater(
        live.repository.logout(role: UserRole.buyer), throwsA(isA<ServerException>()));

    // Someone who asked to be signed out and is offline must not be left holding
    // a usable token because the request could not be sent.
    expect(await live.tokens.read(), isNull);
  });

  test('refresh forgets a rejected token and reports no session', () async {
    final live = _Live([_json({'message': 'Unauthenticated.'}, 401)]);
    await live.tokens.write('1|stale');

    expect(await live.repository.refresh(role: UserRole.buyer), isNull);
    expect(await live.tokens.read(), isNull);
  });

  test('refresh reads a valid token the same way login does', () async {
    final live = _Live([
      _json({
        'ok': true,
        'role': 'buyer',
        'name': 'Juan D. Dela Cruz',
        'email': 'juan@example.com',
        'registration_status': 'approved',
      }),
    ]);
    await live.tokens.write('1|good');

    final result = await live.repository.refresh(role: UserRole.buyer);

    expect(live.server.paths.single, '/me');
    expect(live.server.headers.single['authorization'], 'Bearer 1|good');
    expect(result!.status, AccountStatus.active);
    expect(result.email, 'juan@example.com');
  });

  // ── Courier auth ─────────────────────────────────────────────────────────

  test('courier login posts to /login/courier and stores the token', () async {
    final live = _Live([
      _json({
        'ok': true,
        'token': '2|courier-token',
        'role': 'courier',
        'name': 'Rico R. Reyes',
        'email': 'rico@example.com',
        'registration_status': 'approved',
      }),
    ]);

    final result = await live.repository.login(
      role: UserRole.courier,
      email: 'rico@example.com',
      password: 'password',
    );

    expect(live.server.paths.single, '/login/courier');
    expect(await live.tokens.read(), '2|courier-token');
    expect(result.status, AccountStatus.active);
    expect(result.role, UserRole.courier);
  });

  test('courier refresh calls /me/courier', () async {
    final live = _Live([
      _json({
        'ok': true,
        'role': 'courier',
        'name': 'Rico R. Reyes',
        'email': 'rico@example.com',
        'registration_status': 'approved',
      }),
    ]);
    await live.tokens.write('2|courier-token');

    final result = await live.repository.refresh(role: UserRole.courier);

    expect(live.server.paths.single, '/me/courier');
    expect(result!.role, UserRole.courier);
    expect(result.status, AccountStatus.active);
  });

  test('courier logout calls /logout/courier', () async {
    final live = _Live([_json({'ok': true, 'message': 'Signed out.'})]);
    await live.tokens.write('2|courier-token');

    await live.repository.logout(role: UserRole.courier);

    expect(live.server.paths.single, '/logout/courier');
    expect(await live.tokens.read(), isNull);
  });
}
