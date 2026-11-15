import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:zefanya/data/api/api_client.dart';
import 'package:zefanya/data/api/token_store.dart';
import 'package:zefanya/data/dto/registration_payload.dart';
import 'package:zefanya/data/google_identity.dart';
import 'package:zefanya/data/repositories/google_auth_repository.dart';
import 'package:zefanya/state/auth_state.dart';

class _FakeServer {
  _FakeServer(this._script);

  final List<http.Response> _script;
  int _served = 0;

  final List<String> methods = [];
  final List<String> paths = [];
  final List<String> bodies = [];
  final List<Map<String, String>> headers = [];

  late final http.Client client = MockClient.streaming((request, bodyStream) async {
    methods.add(request.method);
    paths.add(request.url.path);
    headers.add(request.headers);
    bodies.add(latin1.decode(await bodyStream.toBytes()));

    final response = _next();
    return http.StreamedResponse(
      Stream.value(utf8.encode(response.body)),
      response.statusCode,
      headers: {'content-type': 'application/json; charset=utf-8'},
    );
  });

  http.Response _next() =>
      _script[_served < _script.length ? _served++ : _script.length - 1];
}

http.Response _json(Map<String, Object?> body, [int status = 200]) =>
    http.Response(jsonEncode(body), status);

class _LiveGoogle {
  _LiveGoogle(List<http.Response> script)
      : server = _FakeServer(script),
        tokens = InMemoryTokenStore() {
    client = ApiClient(
      baseUrl: 'https://api.zefanya.test',
      tokenStore: tokens,
      httpClient: server.client,
    );
    repository = LiveGoogleAuthRepository(client);
  }

  final _FakeServer server;
  final InMemoryTokenStore tokens;
  late final ApiClient client;
  late final LiveGoogleAuthRepository repository;
}

void main() {
  late Directory dir;
  late String idPath;

  setUp(() {
    dir = Directory.systemTemp.createTempSync('zef_live_google_auth');
    idPath = '${dir.path}${Platform.pathSeparator}gov_id.jpg';
    File(idPath).writeAsBytesSync([0xFF, 0xD8, 0xFF, 0xD9]);
  });

  tearDown(() => dir.deleteSync(recursive: true));

  const identity = GoogleIdentity(
    email: 'miguel.santos@gmail.com',
    displayName: 'Miguel Santos',
    idToken: 'valid-google-id-token',
  );

  test('signIn with needs_role returns GoogleAuthOutcome with null result', () async {
    final live = _LiveGoogle([
      _json({'ok': true, 'needs_role': true, 'email': 'new@gmail.com'}),
    ]);

    final outcome = await live.repository.signIn(identity);

    expect(live.server.paths.single, '/auth/google');
    expect(outcome.needsRole, isTrue);
    expect(outcome.result, isNull);
  });

  test('signIn for existing buyer adopts token and returns active AuthResult', () async {
    final live = _LiveGoogle([
      _json({
        'ok': true,
        'token': '3|buyer-google-token',
        'role': 'buyer',
        'email': 'miguel.santos@gmail.com',
        'name': 'Miguel Santos',
        'registration_status': 'approved',
      }),
    ]);

    final outcome = await live.repository.signIn(identity);

    expect(outcome.needsRole, isFalse);
    expect(outcome.result!.role, UserRole.buyer);
    expect(outcome.result!.status, AccountStatus.active);
    expect(await live.tokens.read(), '3|buyer-google-token');
  });

  test('signIn for existing courier adopts token and returns active courier AuthResult', () async {
    final live = _LiveGoogle([
      _json({
        'ok': true,
        'token': '4|courier-google-token',
        'role': 'courier',
        'email': 'miguel.santos@gmail.com',
        'name': 'Miguel Santos',
        'registration_status': 'approved',
      }),
    ]);

    final outcome = await live.repository.signIn(identity);

    expect(outcome.needsRole, isFalse);
    expect(outcome.result!.role, UserRole.courier);
    expect(outcome.result!.status, AccountStatus.active);
    expect(await live.tokens.read(), '4|courier-google-token');
  });

  test('register for courier posts multipart to /auth/google with role courier and files', () async {
    final live = _LiveGoogle([
      _json({
        'ok': true,
        'token': '5|courier-new-token',
        'role': 'courier',
        'email': 'miguel.santos@gmail.com',
        'registration_status': 'pending_approval',
      }, 201),
    ]);

    final riderProfile = RegistrationProfile(
      lastName: 'Santos',
      firstName: 'Miguel',
      middleInitial: 'A',
      sex: 'Male',
      phone: '0919 123 4567',
      birthday: DateTime(1994, 6, 10),
      province: 'Metro Manila',
      municipality: 'Makati City',
      barangay: 'Poblacion',
      street: '789 Buendia Ave',
      govIdPath: idPath,
      riderCompany: 'Fast Courier',
      riderVehicleType: 'Motorcycle',
      riderPlate: 'MIG-999',
      riderLicenseNumber: 'N03-99-999999',
      riderLicenseExpiry: DateTime(2028, 1, 1),
      riderLicensePath: idPath,
      riderOrCrPath: idPath,
    );

    final result = await live.repository.register(
      identity: identity,
      role: UserRole.courier,
      profile: riderProfile,
    );

    expect(live.server.methods.single, 'POST');
    expect(live.server.paths.single, '/auth/google');
    expect(result.role, UserRole.courier);
    expect(result.status, AccountStatus.pendingApproval);
    expect(await live.tokens.read(), '5|courier-new-token');
  });
}
