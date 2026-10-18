// test/live_courier_test.dart
//
// The live courier repository, against a fake HTTP transport.
//
// `CourierJobApiTest` proves the endpoint answers correctly; this proves
// the app asks the right question and reads the answer the way the rest
// of the app expects.

import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:zefanya/data/api/api_client.dart';
import 'package:zefanya/data/api/api_exception.dart';
import 'package:zefanya/data/api/token_store.dart';
import 'package:zefanya/data/repositories/courier_repository.dart';

class _FakeServer {
  _FakeServer(this._script);
  final List<http.Response> _script;
  int _served = 0;

  final List<String> methods = [];
  final List<String> paths = [];
  final List<Map<String, String>> headers = [];

  late final http.Client client =
      MockClient.streaming((request, bodyStream) async {
    methods.add(request.method);
    paths.add(request.url.path);
    headers.add(request.headers);

    final response =
        _script[_served < _script.length ? _served++ : _script.length - 1];
    return http.StreamedResponse(
      Stream.value(utf8.encode(response.body)),
      response.statusCode,
      headers: {'content-type': 'application/json; charset=utf-8'},
    );
  });

  String header(String name) => headers.isEmpty
      ? ''
      : headers.first.entries
          .firstWhere(
            (e) => e.key.toLowerCase() == name.toLowerCase(),
            orElse: () => const MapEntry('', ''),
          )
          .value;
}

http.Response _list(List<Object?> rows) =>
    http.Response.bytes(
      utf8.encode(jsonEncode({'data': rows})),
      200,
      headers: {'content-type': 'application/json; charset=utf-8'},
    );

Map<String, Object?> _jobJson({
  String id = '12',
  String orderId = '7',
  String shopName = 'Willow & Bloom Atelier',
  String pickupAddress = '12 Mabini Street, Lahug, Cebu City, Cebu',
  String buyerName = 'Marites Santos',
  String deliveryArea = 'Quezon City (8.4 km)',
  String packageSize = '1 item · ₱1,299.00',
  double fee = 85.0,
}) =>
    {
      'id': id,
      'order_id': orderId,
      'shop_name': shopName,
      'pickup_address': pickupAddress,
      'buyer_name': buyerName,
      'delivery_area': deliveryArea,
      'package_size': packageSize,
      'fee': fee,
    };

class _Live {
  _Live(List<http.Response> script)
      : server = _FakeServer(script),
        tokens = InMemoryTokenStore() {
    client = ApiClient(
      baseUrl: 'https://api.zefanya.test',
      tokenStore: tokens,
      httpClient: server.client,
    );
    repo = LiveCourierRepository(client);
  }

  final _FakeServer server;
  final InMemoryTokenStore tokens;
  late final ApiClient client;
  late final LiveCourierRepository repo;
}

void main() {
  test('asks GET /courier/jobs with the token', () async {
    final live = _Live([_list([_jobJson()])]);
    await live.tokens.write('courier-token');

    await live.repo.assignedJobs();

    expect(live.server.methods.single, 'GET');
    expect(live.server.paths.single, endsWith('/courier/jobs'));
    expect(live.server.header('authorization'), 'Bearer courier-token');
  });

  test('maps the list into DeliveryJob objects', () async {
    final live = _Live([
      _list([
        _jobJson(id: '12', shopName: 'Willow & Bloom', fee: 85.0),
        _jobJson(id: '13', orderId: '8', shopName: 'Tahanan Ceramics', fee: 120.0),
      ]),
    ]);
    await live.tokens.write('t');

    final jobs = await live.repo.assignedJobs();

    expect(jobs.length, 2);
    expect(jobs[0].id, '12');
    expect(jobs[0].shopName, 'Willow & Bloom');
    expect(jobs[0].orderId, '7');
    expect(jobs[0].buyerName, 'Marites Santos');
    expect(jobs[0].deliveryArea, 'Quezon City (8.4 km)');
    expect(jobs[0].fee, 85.0);
    expect(jobs[0].courierName, isNull);
    expect(jobs[1].id, '13');
    expect(jobs[1].shopName, 'Tahanan Ceramics');
    expect(jobs[1].fee, 120.0);
  });

  test('returns an empty list when no jobs assigned', () async {
    final live = _Live([_list(const [])]);
    await live.tokens.write('t');

    expect(await live.repo.assignedJobs(), isEmpty);
  });

  test('a 401 throws UnauthorizedException', () async {
    final live = _Live([
      http.Response('{"message":"Unauthenticated."}', 401),
    ]);
    await live.tokens.write('stale');

    await expectLater(
      live.repo.assignedJobs(),
      throwsA(isA<UnauthorizedException>()),
    );
  });

  test('throws ApiNotConfiguredException when no base URL', () async {
    final repo = LiveCourierRepository(
      ApiClient(baseUrl: '', tokenStore: InMemoryTokenStore()),
    );

    await expectLater(
      () async => repo.assignedJobs(),
      throwsA(isA<ApiNotConfiguredException>()),
    );
  });

  // ---------------------------------------------------------------------------
  // advanceMilestone
  // ---------------------------------------------------------------------------

  test('advanceMilestone sends PATCH to /courier/jobs/{id}/milestone', () async {
    final live = _Live([
      http.Response('{}', 200),
    ]);
    await live.tokens.write('courier-token');

    await live.repo.advanceMilestone('12', 'PICKED_UP');

    expect(live.server.methods.single, 'PATCH');
    expect(live.server.paths.single, endsWith('/courier/jobs/12/milestone'));
    expect(live.server.header('authorization'), 'Bearer courier-token');
  });

  test('advanceMilestone sends the milestone value in the body', () async {
    final body = <String>[];
    final server = _FakeServer([http.Response('{}', 200)]);
    // Capture the raw body by replacing the server's client with one that
    // records it. Re-use the existing _FakeServer but capture upstream.
    final bodyCaptureClient = MockClient.streaming((request, bodyStream) async {
      final bytes = await bodyStream.toBytes();
      body.add(utf8.decode(bytes));
      server.methods.add(request.method);
      server.paths.add(request.url.path);
      server.headers.add(request.headers);
      return http.StreamedResponse(
        Stream.value(utf8.encode('{}')),
        200,
        headers: {'content-type': 'application/json; charset=utf-8'},
      );
    });

    final tokens = InMemoryTokenStore();
    await tokens.write('t');
    final client = ApiClient(
      baseUrl: 'https://api.zefanya.test',
      tokenStore: tokens,
      httpClient: bodyCaptureClient,
    );
    final repo = LiveCourierRepository(client);

    await repo.advanceMilestone('99', 'OUT_FOR_DELIVERY');

    expect(body.single, contains('"milestone"'));
    expect(body.single, contains('OUT_FOR_DELIVERY'));
  });

  test('advanceMilestone throws on 422', () async {
    final live = _Live([
      http.Response('{"message":"Invalid milestone"}', 422),
    ]);
    await live.tokens.write('t');

    await expectLater(
      live.repo.advanceMilestone('12', 'INVALID'),
      throwsA(isA<ApiException>()),
    );
  });

  test('advanceMilestone throws ApiNotConfiguredException when no base URL', () async {
    final repo = LiveCourierRepository(
      ApiClient(baseUrl: '', tokenStore: InMemoryTokenStore()),
    );

    await expectLater(
      () async => repo.advanceMilestone('1', 'PICKED_UP'),
      throwsA(isA<ApiNotConfiguredException>()),
    );
  });
}
