// test/live_courier_test.dart
//
// The live courier repository against a fake HTTP transport, using
// WebZefanya's rider API contract (/api/rider/...).
//
// `RiderMobileApiTest` (WebZefanya) proves the endpoints answer correctly;
// this proves the app asks the right questions in the right order — scan
// verification first, then the action with the token it returned — and
// reads the answers the way the rest of the app expects.

import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:zefanya/data/api/api_client.dart';
import 'package:zefanya/data/api/api_exception.dart';
import 'package:zefanya/data/api/token_store.dart';
import 'package:zefanya/data/repositories/courier_repository.dart';
import 'package:zefanya/state/courier_state.dart';
import 'package:shared_preferences/shared_preferences.dart';

class _FakeServer {
  _FakeServer(this._script);
  final List<http.Response> _script;
  int _served = 0;

  final List<String> methods = [];
  final List<String> paths = [];
  final List<Map<String, String>> queries = [];
  final List<String> bodies = [];
  final List<Map<String, String>> headers = [];

  late final http.Client client =
      MockClient.streaming((request, bodyStream) async {
    methods.add(request.method);
    paths.add(request.url.path);
    queries.add(request.url.queryParameters);
    headers.add(request.headers);
    bodies.add(latin1.decode(await bodyStream.toBytes()));

    final response =
        _script[_served < _script.length ? _served++ : _script.length - 1];
    return http.StreamedResponse(
      Stream.value(utf8.encode(response.body)),
      response.statusCode,
      headers: {'content-type': 'application/json; charset=utf-8'},
    );
  });
}

http.Response _ok(Object? data, [int status = 200]) => http.Response.bytes(
      utf8.encode(jsonEncode({'success': true, 'data': data})),
      status,
      headers: {'content-type': 'application/json; charset=utf-8'},
    );

http.Response _error(int status, String message, [Map<String, Object?>? errors]) =>
    http.Response(jsonEncode({'message': message, 'errors': errors}), status);

Map<String, Object?> _parcel({
  int id = 12,
  String leg = 'delivery',
  String status = 'OUT_FOR_DELIVERY',
  bool cod = false,
  int codMinor = 0,
}) =>
    {
      'id': id,
      'order_id': 7,
      'leg': leg,
      'tracking_number': 'TRK-$id',
      'status': status,
      'seller_name': 'Willow & Bloom Atelier',
      'pickup_area': 'Cebu City',
      'buyer_name': 'Marites Santos',
      'buyer_phone': '09171234567',
      'delivery_address': '12 Mabini Street, Quezon City',
      'delivery_area': 'Quezon City',
      'item_count': 2,
      'weight': '0.5 kg',
      'payment_method': cod ? 'cod' : 'online',
      'requires_cod_collection': cod,
      'cod_amount_minor': codMinor,
    };

Map<String, Object?> _jobs({
  List<Map<String, Object?>> pickups = const [],
  List<Map<String, Object?>> deliveries = const [],
}) =>
    {'online': true, 'pickups': pickups, 'deliveries': deliveries};

http.Response _verified(int id, List<String> actions) => _ok({
      'context': 'delivery',
      'parcel': {'id': id},
      'actions': [
        for (final key in actions) {'key': key, 'verification_token': 'tok-$key'},
      ],
    });

class _Live {
  _Live(List<http.Response> script, {String baseUrl = 'https://api.zefanya.test'})
      : server = _FakeServer(script),
        tokens = InMemoryTokenStore() {
    client = ApiClient(
      baseUrl: baseUrl,
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

/// Loads one delivery so the repository knows its tracking number.
Future<_Live> _withJob(List<http.Response> then, {String status = 'OUT_FOR_DELIVERY'}) async {
  final live = _Live([
    _ok(_jobs(deliveries: [_parcel(status: status)])),
    ...then,
  ]);
  await live.tokens.write('1|rider');
  await live.repo.assignedJobs();
  return live;
}

void main() {
  test('is server-authoritative', () {
    expect(_Live([_ok(_jobs())]).repo.isServerAuthoritative, isTrue);
  });

  test('asks GET /rider/jobs with the token', () async {
    final live = _Live([_ok(_jobs())]);
    await live.tokens.write('1|rider');

    await live.repo.assignedJobs();

    expect(live.server.methods.single, 'GET');
    expect(live.server.paths.single, '/rider/jobs');
    expect(live.server.headers.single['authorization'], 'Bearer 1|rider');
  });

  test('maps pickups and deliveries into DeliveryJob objects', () async {
    final live = _Live([
      _ok(_jobs(
        pickups: [_parcel(id: 3, leg: 'pickup', status: 'READY_FOR_PICKUP')],
        deliveries: [_parcel(id: 12, cod: true, codMinor: 129900)],
      )),
    ]);

    final jobs = await live.repo.assignedJobs();

    expect(jobs.map((j) => j.id), ['3', '12']);
    final delivery = jobs.last;
    expect(delivery.shopName, 'Willow & Bloom Atelier');
    expect(delivery.trackingNumber, 'TRK-12');
    expect(delivery.serverStatus, 'OUT_FOR_DELIVERY');
    // Server amount, minor units → pesos; never recomputed.
    expect(delivery.codAmount, 1299.0);
    expect(delivery.prepaid, isFalse);
    expect(delivery.hasCod, isTrue);
    // The server never sends the delivery code to the rider.
    expect(delivery.deliveryOtp, isEmpty);
  });

  test('returns an empty list when no jobs are assigned', () async {
    expect(await _Live([_ok(_jobs())]).repo.assignedJobs(), isEmpty);
  });

  test('a 401 throws UnauthorizedException', () async {
    final live = _Live([_error(401, 'Unauthenticated.')]);
    await expectLater(live.repo.assignedJobs(), throwsA(isA<UnauthorizedException>()));
  });

  test('throws ApiNotConfiguredException when no base URL', () async {
    final live = _Live([_ok(_jobs())], baseUrl: '');
    await expectLater(
      () async => live.repo.assignedJobs(),
      throwsA(isA<ApiNotConfiguredException>()),
    );
  });

  test('start delivery verifies the scan first, then sends its token', () async {
    final live = await _withJob(
      [_verified(12, ['start_delivery']), _ok(_parcel())],
      status: 'ASSIGNED_TO_RIDER',
    );

    await live.repo.advanceMilestone('12', 'OUT_FOR_DELIVERY');

    expect(live.server.paths.skip(1), ['/rider/scan/verify', '/rider/deliveries/12/start']);
    expect(jsonDecode(live.server.bodies[1]), {'tracking_number': 'TRK-12', 'method': 'manual'});
    expect(jsonDecode(live.server.bodies[2]), {'verification_token': 'tok-start_delivery'});
  });

  test('pickup uses the typed tracking number and the pickup endpoint', () async {
    final live = _Live([_verified(3, ['confirm_pickup']), _ok(_parcel(id: 3))]);

    await live.repo.advanceMilestone('3', 'PICKED_UP', trackingNumber: 'TRK-3');

    expect(live.server.paths, ['/rider/scan/verify', '/rider/pickups/3/confirm']);
  });

  test('a failed delivery sends WebZefanya reason values', () async {
    final live = await _withJob([_verified(12, ['mark_failed']), _ok(_parcel())]);

    await live.repo.advanceMilestone('12', 'DELIVERY_FAILED', failureReason: 'Buyer not available');

    expect(live.server.paths.last, '/rider/deliveries/12/failed');
    expect(jsonDecode(live.server.bodies.last), {
      'verification_token': 'tok-mark_failed',
      'failure_reason': 'recipient_unavailable',
    });
  });

  test('an unmapped reason is sent as other with notes', () async {
    final live = await _withJob([_verified(12, ['mark_failed']), _ok(_parcel())]);

    await live.repo.advanceMilestone('12', 'DELIVERY_FAILED', failureReason: 'Payment not ready');

    expect(jsonDecode(live.server.bodies.last), {
      'verification_token': 'tok-mark_failed',
      'failure_reason': 'other',
      'failure_notes': 'Payment not ready',
    });
  });

  test('steps a rider cannot set are not sent', () async {
    final live = await _withJob([]);

    for (final step in ['PLACED', 'CONFIRMED', 'AT_SORTING_CENTER', 'SORTED', 'ASSIGNED_TO_RIDER', 'DELIVERED']) {
      await live.repo.advanceMilestone('12', step);
    }

    expect(live.server.paths, ['/rider/jobs']);
  });

  test('a parcel that no longer offers the action is a 409 conflict', () async {
    final live = await _withJob([_verified(12, ['digital_waybill'])]);

    await expectLater(
      live.repo.advanceMilestone('12', 'OUT_FOR_DELIVERY'),
      throwsA(isA<RequestRejectedException>().having((e) => e.isConflict, 'conflict', isTrue)),
    );
    // Nothing was attempted past the scan.
    expect(live.server.paths.last, '/rider/scan/verify');
  });

  test('a server 409 surfaces as a conflict', () async {
    final live = await _withJob([
      _verified(12, ['mark_failed']),
      _error(409, 'This parcel is not out for delivery.'),
    ]);

    await expectLater(
      live.repo.advanceMilestone('12', 'DELIVERY_FAILED', failureReason: 'Wrong address'),
      throwsA(isA<RequestRejectedException>().having((e) => e.isConflict, 'conflict', isTrue)),
    );
  });

  test('delivery code request never reads a code back', () async {
    final live = await _withJob([
      _verified(12, ['proof_of_delivery']),
      _ok(null),
    ]);

    await live.repo.requestDeliveryCode('12');

    expect(live.server.paths.last, '/rider/deliveries/12/otp');
    expect(jsonDecode(live.server.bodies.last), {'verification_token': 'tok-proof_of_delivery'});
  });

  test('proof of delivery is multipart with photo, code and exact cash', () async {
    final dir = Directory.systemTemp.createTempSync('zef_pod');
    addTearDown(() => dir.deleteSync(recursive: true));
    final photo = '${dir.path}${Platform.pathSeparator}proof.jpg';
    File(photo).writeAsBytesSync([0xFF, 0xD8, 0xFF, 0xD9]);

    final live = await _withJob([_verified(12, ['proof_of_delivery']), _ok(_parcel(status: 'DELIVERED'))]);

    await live.repo.completeDelivery('12', proofPhotoPath: photo, deliveryOtp: ' 123456 ', codAmount: 1299);

    expect(live.server.paths.last, '/rider/deliveries/12/deliver');
    final body = live.server.bodies.last;
    expect(body, contains('name="proof_photo"'));
    expect(body, contains('name="delivery_otp"\r\n\r\n123456'));
    expect(body, contains('name="cod_amount"\r\n\r\n1299.00'));
    expect(body, contains('name="verification_token"\r\n\r\ntok-proof_of_delivery'));
  });

  test('a wrong code comes back as a field error', () async {
    final dir = Directory.systemTemp.createTempSync('zef_pod');
    addTearDown(() => dir.deleteSync(recursive: true));
    final photo = '${dir.path}${Platform.pathSeparator}proof.jpg';
    File(photo).writeAsBytesSync([0xFF, 0xD8, 0xFF, 0xD9]);

    final live = await _withJob([
      _verified(12, ['proof_of_delivery']),
      _error(422, 'The delivery code is incorrect.', {
        'delivery_otp': ['The delivery code is incorrect. Ask the buyer for the latest code.'],
      }),
    ]);

    await expectLater(
      live.repo.completeDelivery('12', proofPhotoPath: photo, deliveryOtp: '000000'),
      throwsA(isA<ValidationException>()),
    );
  });

  test('no tracking number known means no request', () async {
    final live = _Live([_ok(_jobs())]);

    await expectLater(
      live.repo.advanceMilestone('99', 'OUT_FOR_DELIVERY'),
      throwsA(isA<ValidationException>()),
    );
    expect(live.server.paths, isEmpty);
  });

  // ── Server-only rider data (RiderServerData) ─────────────────────────────

  test('earnings read the server totals and ledger, never a local rate', () async {
    final live = _Live([
      _ok({
        'summary': {'monthMinor': 8500},
        'totals': {'today_minor': 6000, 'week_minor': 6000, 'all_time_minor': 8500, 'jobs': 2},
        'cod': {'holding_minor': 150000},
        'recent': [
          {
            'id': 5,
            'job': 'delivery',
            'amount_minor': 6000,
            'seller_order_id': 12,
            'tracking_number': 'TRK-12',
            'seller_name': 'Willow & Bloom Atelier',
            'earned_at': '2026-11-06T02:00:00+00:00',
            'paid': false,
          },
          {'id': 4, 'job': 'pickup', 'amount_minor': 2500, 'earned_at': 'not a date'},
        ],
      }),
    ]);
    await live.tokens.write('1|rider');

    final snapshot = await live.repo.earnings(limit: 20);

    expect(live.server.paths.single, '/rider/earnings');
    expect(snapshot.today, 60);
    expect(snapshot.week, 60);
    expect(snapshot.allTime, 85);
    expect(snapshot.codHolding, 1500);
    // A row without a readable time is dropped rather than dated "now".
    expect(snapshot.recent, hasLength(1));
    expect(snapshot.recent.single.amount, 60);
    expect(snapshot.recent.single.sellerOrderId, '12');
  });

  test('an incident is multipart with the type, reason and damage photo', () async {
    final dir = Directory.systemTemp.createTempSync('zef_incident');
    addTearDown(() => dir.deleteSync(recursive: true));
    final photo = '${dir.path}${Platform.pathSeparator}damage.jpg';
    File(photo).writeAsBytesSync([0xFF, 0xD8, 0xFF, 0xD9]);
    final live = _Live([_ok({'incident_id': 1, 'type': 'damaged'}, 201)]);
    await live.tokens.write('1|rider');

    await live.repo.reportIncident('12', type: 'damaged', reason: ' Crushed box ', description: 'Box crushed in transit.', photoPath: photo);

    expect(live.server.paths.single, '/rider/jobs/12/incidents');
    final body = live.server.bodies.single;
    expect(body, contains('name="type"\r\n\r\ndamaged'));
    expect(body, contains('name="reason"\r\n\r\nCrushed box'));
    expect(body, contains('name="photo"'));
  });

  test('the registration provider list is read without a token', () async {
    final live = _Live([
      _ok({
        'providers': [
          {'id': 3, 'name': 'Bilis Hatid Express'},
          {'id': 'x', 'name': 'Broken row'},
        ],
      }),
    ]);
    await live.tokens.write('1|leftover');

    final providers = await live.repo.registrationProviders();

    expect(live.server.paths.single, '/rider/providers');
    expect(live.server.headers.single.containsKey('authorization'), isFalse);
    expect(providers.map((p) => (p.id, p.name)), [(3, 'Bilis Hatid Express')]);
  });

  test("the provider list asks for the rider's barangay when one is chosen", () async {
    final live = _Live([_ok({'barangay': '1374040001', 'providers': const []})]);

    await live.repo.registrationProviders(barangayCode: '1374040001');
    await live.repo.registrationProviders(barangayCode: ' ');

    expect(live.server.paths, ['/rider/providers', '/rider/providers']);
    expect(live.server.queries.first, {'barangay': '1374040001'});
    // Blank means "no address yet": the full list, no filter.
    expect(live.server.queries.last, isEmpty);
  });

  // ── Availability, failed pickups and pickup stops ────────────────────────

  Map<String, Object?> stopsJobs({int attempts = 0, bool online = false}) => {
        'online': online,
        'pickups': [
          {
            ..._parcel(id: 21, leg: 'pickup', status: 'READY_FOR_PICKUP'),
            'pickup_session_id': 5,
            'pickup_attempts': attempts,
          },
          {
            ..._parcel(id: 22, leg: 'pickup', status: 'PICKED_UP'),
            'pickup_session_id': 5,
            'pickup_attempts': 0,
          },
        ],
        'deliveries': const [],
        'stops': [
          {
            'id': 5,
            'status': 'IN_PROGRESS',
            'seller_name': 'Willow & Bloom Atelier',
            'pickup_address': '12 Mabini Street',
            'collected': 1,
            'total': 2,
            'remaining': 1,
            'earned_minor': 3000,
            'potential_minor': 3500,
            'can_finish': true,
          },
        ],
        'pickup_failure': {
          'max_attempts': 2,
          'reasons': [
            {'value': 'recipient_unavailable', 'label': 'Recipient unavailable'},
            {'value': 'other', 'label': 'Other'},
          ],
        },
      };

  test('jobs read the stops, attempts, reasons and server availability', () async {
    final live = _Live([_ok(stopsJobs(attempts: 1, online: true))]);
    await live.tokens.write('1|rider');

    final jobs = await live.repo.assignedJobs();
    final meta = live.repo.lastJobsMeta!;

    expect(jobs.map((j) => (j.id, j.pickupSessionId, j.pickupAttempts)),
        [('21', '5', 1), ('22', '5', 0)]);
    expect(meta.online, isTrue);
    expect(meta.maxPickupAttempts, 2);
    expect(meta.failureReasons.map((r) => r.value), ['recipient_unavailable', 'other']);
    final stop = meta.stops.single;
    expect((stop.id, stop.collected, stop.total, stop.remaining, stop.canFinish), ('5', 1, 2, 1, true));
    expect(stop.earned, 30);
  });

  test('availability posts {online} and adopts what the server recorded', () async {
    final live = _Live([_ok({'online': false, 'changed': true, 'jobs_flagged_for_reassignment': 0})]);
    await live.tokens.write('1|rider');

    final recorded = await live.repo.setAvailability(online: true);

    expect(live.server.paths.single, '/rider/availability');
    expect(jsonDecode(live.server.bodies.single), {'online': true});
    // The server's answer wins over what was asked.
    expect(recorded, isFalse);
  });

  test('a failed pickup sends the reason, notes and the expected attempt', () async {
    final live = _Live([
      _ok({'attempt': 2, 'attempts_remaining': 0, 'cancelled': true, 'parcel': _parcel(id: 21, leg: 'pickup', status: 'CANCELLED')}),
    ]);
    await live.tokens.write('1|rider');

    final result = await live.repo.reportPickupFailure('21', reason: 'other', expectedAttempt: 2, notes: ' Shop closed ');

    expect(live.server.paths.single, '/rider/pickups/21/failed');
    expect(jsonDecode(live.server.bodies.single), {
      'failure_reason': 'other',
      'expected_attempt': 2,
      'failure_notes': 'Shop closed',
    });
    // Cancellation is the server's answer, read as-is.
    expect((result.attempt, result.attemptsRemaining, result.cancelled), (2, 0, true));
  });

  test('a duplicate failed pickup is the server 409, surfaced as a conflict', () async {
    final live = _Live([_error(409, 'Pickup attempt #1 is already recorded. Refresh your pickups.')]);
    await live.tokens.write('1|rider');

    await expectLater(
      live.repo.reportPickupFailure('21', reason: 'other', expectedAttempt: 1),
      throwsA(isA<RequestRejectedException>().having((e) => e.isConflict, 'isConflict', isTrue)),
    );
  });

  test('finishing a stop posts to the stop and reads the finished stop', () async {
    final live = _Live([
      _ok({'stop': {'id': 5, 'status': 'COMPLETED', 'seller_name': 'Willow', 'collected': 1, 'total': 1, 'can_finish': false}}),
    ]);
    await live.tokens.write('1|rider');

    final stop = await live.repo.finishPickupStop('5');

    expect(live.server.methods.single, 'POST');
    expect(live.server.paths.single, '/rider/pickups/stops/5/finish');
    expect(stop!.status, 'COMPLETED');
    expect(stop.canFinish, isFalse);
  });

  group('CourierState (live)', () {
    setUp(() => SharedPreferences.setMockInitialValues({}));

    test('adopts server availability and stops, and reports attempt + 1', () async {
      final live = _Live([
        _ok(stopsJobs(attempts: 1, online: true)), // refreshJobs
        _ok({'summary': const {}, 'totals': const {}, 'cod': const {}, 'recent': const []}), // earnings
        _ok({'attempt': 2, 'attempts_remaining': 0, 'cancelled': true}), // report
        _ok(stopsJobs(attempts: 2)), // refresh after report
        _ok({'summary': const {}, 'totals': const {}, 'cod': const {}, 'recent': const []}),
      ]);
      await live.tokens.write('1|rider');
      final courier = CourierState(repository: live.repo);

      await courier.refreshJobs();
      expect(courier.isOnline, isTrue);
      expect(courier.pickupStops.single.id, '5');
      expect(courier.stopFor(courier.jobById('21')!)!.remaining, 1);
      expect(courier.pickupFailureReasons, hasLength(2));

      final result = await courier.reportPickupFailure('21', reason: 'other');

      expect(result!.cancelled, isTrue);
      final report = live.server.paths.indexOf('/rider/pickups/21/failed');
      expect(jsonDecode(live.server.bodies[report])['expected_attempt'], 2);
    });

    test('a second tap while a report is in flight sends nothing', () async {
      final live = _Live([
        _ok(stopsJobs()),
        _ok({'summary': const {}, 'totals': const {}, 'cod': const {}, 'recent': const []}),
        _ok({'attempt': 1, 'attempts_remaining': 1, 'cancelled': false}),
        _ok(stopsJobs(attempts: 1)),
        _ok({'summary': const {}, 'totals': const {}, 'cod': const {}, 'recent': const []}),
      ]);
      await live.tokens.write('1|rider');
      final courier = CourierState(repository: live.repo);
      await courier.refreshJobs();

      final first = courier.reportPickupFailure('21', reason: 'other');
      final second = await courier.reportPickupFailure('21', reason: 'other');
      await first;

      expect(second, isNull);
      expect(live.server.paths.where((p) => p == '/rider/pickups/21/failed'), hasLength(1));
    });

    test('a failed availability change leaves the switch unchanged', () async {
      final live = _Live([
        _ok(stopsJobs(online: false)),
        _ok({'summary': const {}, 'totals': const {}, 'cod': const {}, 'recent': const []}),
        _error(503, 'Service unavailable'),
      ]);
      await live.tokens.write('1|rider');
      final courier = CourierState(repository: live.repo);
      await courier.refreshJobs();

      final problem = await courier.setOnline(true);

      expect(problem, isNotNull);
      expect(courier.isOnline, isFalse);
      expect(courier.availabilityBusy, isFalse);
      // Assigned jobs are untouched by the failed change.
      expect(courier.jobById('21'), isNotNull);
    });
  });
}
