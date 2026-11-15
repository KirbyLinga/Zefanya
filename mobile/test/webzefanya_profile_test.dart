// test/webzefanya_profile_test.dart
//
// The WebZefanya profile of the live repositories, against a fake transport.
// WebZefanya itself is never contacted: every request below goes to an
// in-memory `MockClient`, and the writes guard is exercised explicitly.

import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:zefanya/config/backend_profile.dart';
import 'package:zefanya/data/api/api_exception.dart';
import 'package:zefanya/data/api/token_store.dart';
import 'package:zefanya/data/api/webzefanya_contract.dart';
import 'package:zefanya/data/repositories/courier_repository.dart';
import 'package:zefanya/data/repositories/repositories.dart';
import 'package:zefanya/state/courier_state.dart';

class _Wire {
  _Wire(this.respond);

  final http.Response Function(http.BaseRequest request, String body) respond;
  final List<String> requests = [];
  final List<String> bodies = [];

  late final http.Client client = MockClient.streaming((request, stream) async {
    final body = latin1.decode(await stream.toBytes());
    requests.add('${request.method} ${request.url.path}');
    bodies.add(body);
    final response = respond(request, body);
    return http.StreamedResponse(
      Stream.value(response.bodyBytes),
      response.statusCode,
      headers: {'content-type': 'application/json; charset=utf-8'},
    );
  });
}

http.Response _ok(Object? data) => http.Response.bytes(
  utf8.encode(jsonEncode({'success': true, 'data': data})),
  200,
  headers: {'content-type': 'application/json; charset=utf-8'},
);

http.Response _json(int status, Object? body) => http.Response.bytes(
  utf8.encode(jsonEncode(body)),
  status,
  headers: {'content-type': 'application/json; charset=utf-8'},
);

Map<String, Object?> _parcel(int id, {String status = 'OUT_FOR_DELIVERY'}) => {
  'id': id,
  'tracking_number': 'TRK-$id',
  'status': status,
};

({Repositories repos, _Wire wire}) _webzefanya(
  http.Response Function(http.BaseRequest, String) respond, {
  bool allowWrites = false,
}) {
  final wire = _Wire(respond);
  final client = guardedWebZefanyaClient(
    baseUrl: 'https://wz.test/api',
    tokenStore: InMemoryTokenStore()..write('token'),
    httpClient: wire.client,
    allowWrites: allowWrites,
  );
  return (repos: Repositories.webzefanya(client), wire: wire);
}

void main() {
  group('backend profile configuration', () {
    test('the demo fork is the default and typos never select WebZefanya', () {
      expect(backendProfileFromName(null), BackendProfile.courierDemo);
      expect(backendProfileFromName(''), BackendProfile.courierDemo);
      expect(backendProfileFromName('webzefany'), BackendProfile.courierDemo);
      expect(
        backendProfileFromName('courier-demo'),
        BackendProfile.courierDemo,
      );
      expect(backendProfileFromName(' WebZefanya '), BackendProfile.webzefanya);
      expect(BackendProfile.webzefanya.wireName, 'webzefanya');
    });

    test('each profile has its own base URL', () {
      expect(
        apiBaseUrlFor(
          BackendProfile.courierDemo,
          demoUrl: 'https://demo/api',
          webzefanyaUrl: 'https://wz/api',
        ),
        'https://demo/api',
      );
      expect(
        apiBaseUrlFor(
          BackendProfile.webzefanya,
          demoUrl: 'https://demo/api',
          webzefanyaUrl: 'https://wz/api',
        ),
        'https://wz/api',
      );
    });

    test('capabilities reflect the supported workflows of each backend', () {
      const demo = BackendCapabilities.demo();
      expect(demo.riderReturnCollect, isTrue);
      expect(demo.courierLogin, isFalse);

      const wz = BackendCapabilities.webzefanya();
      expect(wz.isWebZefanya, isTrue);
      expect(wz.riderReturnCollect, isFalse);
      expect(wz.riderCodLedger, isFalse);
      expect(wz.riderPayouts, isFalse);
      expect(wz.riderWaybill, isFalse);
      expect(wz.riderProofPhotos, isFalse);
      expect(wz.riderPickupFailure, isFalse);
      expect(wz.courierLogin, kWebZefanyaCourierApp);
      expect(wz.buyerReviewSubmit, isFalse);
      expect(wz.buyerDeliveryDispute, isFalse);
    });
  });

  group('demo mode backward compatibility', () {
    test('mock mode ignores the profile and keeps full demo capabilities', () {
      final mock = Repositories.forMode(
        useLive: false,
        profile: BackendProfile.webzefanya,
      );
      expect(mock.client, isNull);
      expect(mock.capabilities.riderReturnCollect, isTrue);
      expect(mock.capabilities.isWebZefanya, isFalse);
      expect(mock.courier.isServerAuthoritative, isFalse);
      expect(mock.linehaul, isNotNull);
    });

    test(
      'the default live profile is the demo fork with no endpoint guard',
      () {
        final live = Repositories.forMode(
          useLive: true,
          baseUrl: 'https://demo.test/api',
          tokenStore: InMemoryTokenStore(),
        );
        expect(live.capabilities.isWebZefanya, isFalse);
        expect(live.courier, isA<LiveCourierRepository>());
        expect(live.courier, isNot(isA<WebZefanyaCourierRepository>()));
        expect(live.client!.routePolicy, isNull);
        expect(live.linehaul, isNull);
        expect(live.capabilities.courierLogin, isFalse);
      },
    );

    test(
      'the WebZefanya profile uses the guarded read-only Courier portal when enabled',
      () {
        final wz = Repositories.forMode(
          useLive: true,
          baseUrl: 'https://wz.test/api',
          tokenStore: InMemoryTokenStore(),
          profile: BackendProfile.webzefanya,
        );
        expect(wz.capabilities.isWebZefanya, isTrue);
        expect(wz.courier, isA<WebZefanyaCourierRepository>());
        expect(wz.client!.routePolicy, isA<WebZefanyaContract>());
        expect(wz.linehaul != null, kWebZefanyaCourierApp);
      },
    );

    test('a default CourierState keeps every demo capability on', () {
      final state = CourierState();
      expect(state.capabilities.riderPickupFailure, isTrue);
    });
  });

  group('rider jobs and return pickups', () {
    test('return pickups come from the jobs response, read-only', () async {
      final t = _webzefanya(
        (request, _) => _ok({
          'online': true,
          'winding_down': false,
          'pickups': <Object?>[],
          'deliveries': [_parcel(11)],
          'return_pickups': [
            {
              'tracking_number': 'RET-77',
              'status_label': 'Assigned return pickup',
            },
            {'tracking_number': '', 'status_label': 'ignored'},
          ],
        }),
      );
      final repo = t.repos.courier as WebZefanyaCourierRepository;

      final jobs = await repo.assignedJobs();
      final returns = await repo.returnPickups();

      expect(jobs, hasLength(1));
      expect(returns, hasLength(1));
      expect(returns.single.trackingNumber, 'RET-77');
      expect(returns.single.statusLabel, 'Assigned return pickup');
      expect(returns.single.readOnly, isTrue);
      // Only the jobs read hit the network; /rider/return-pickups was never
      // asked for (WebZefanya has no such route).
      expect(t.wire.requests, ['GET /api/rider/jobs']);
    });

    test(
      'CourierState lists the return pickups through the jobs refresh',
      () async {
        final t = _webzefanya(
          (request, _) => _ok({
            'online': true,
            'winding_down': false,
            'pickups': <Object?>[],
            'deliveries': <Object?>[],
            'return_pickups': [
              {
                'tracking_number': 'RET-9',
                'status_label': 'Assigned return pickup',
              },
            ],
          }),
        );
        final state = CourierState(
          repository: t.repos.courier,
          capabilities: t.repos.capabilities,
        );
        await state.refreshJobs();
        expect(state.returnPickups.single.trackingNumber, 'RET-9');
        expect(state.returnPickups.single.readOnly, isTrue);
        expect(state.returnsFailure, isNull);
        // No COD / payouts reads: WebZefanya has no routes for them.
        expect(
          t.wire.requests.where(
            (r) => r.contains('/cod') || r.contains('/payouts'),
          ),
          isEmpty,
        );
      },
    );
  });

  group('unsupported endpoint prevention', () {
    test('unsupported Rider calls fail locally and send nothing', () async {
      final t = _webzefanya((request, _) => _ok({}), allowWrites: true);
      final repo = t.repos.courier as RiderServerData;

      Future<void> expectBlocked(Future<Object?> Function() call) async {
        await expectLater(call(), throwsA(isA<ApiNotImplementedException>()));
      }

      await expectBlocked(() => repo.cash());
      await expectBlocked(() => repo.payouts());
      await expectBlocked(() => repo.waybill('1'));
      await expectBlocked(() => repo.proofPhoto('1'));
      await expectBlocked(
        () => repo.collectReturn('1', trackingNumber: 'TRK-1'),
      );
      await expectBlocked(
        () => repo.reportPickupFailure('1', reason: 'x', expectedAttempt: 1),
      );
      await expectBlocked(() => repo.finishPickupStop('1'));
      expect(t.wire.requests, isEmpty);
    });

    test(
      'a route the contract lacks is refused by the client itself',
      () async {
        final t = _webzefanya((request, _) => _ok({}), allowWrites: true);
        await expectLater(
          t.repos.client!.getJson('/rider/cod/1'),
          throwsA(isA<ApiNotImplementedException>()),
        );
        await expectLater(
          t.repos.client!.postJson('/courier/duty/on'),
          throwsA(isA<ApiNotImplementedException>()),
        );
        expect(t.wire.requests, isEmpty);
        expect(await t.repos.client!.getJson('/rider/cod'), {
          'success': true,
          'data': <String, Object?>{},
        });
        expect(t.wire.requests, ['GET /api/rider/cod']);
      },
    );

    test('buyer: reviews list is empty without a request', () async {
      final t = _webzefanya((request, _) => _ok({}));
      expect(await t.repos.review.all(), isEmpty);
      expect(t.wire.requests, isEmpty);
    });

    test('writes never leave the device unless the build opts in', () async {
      final blocked = _webzefanya((request, _) => _ok({'online': true}));
      final repo = blocked.repos.courier as RiderServerData;
      await expectLater(
        repo.setAvailability(online: true),
        throwsA(isA<ApiNotImplementedException>()),
      );
      expect(blocked.wire.requests, isEmpty);

      final enabled = _webzefanya(
        (request, _) => _ok({'online': true}),
        allowWrites: true,
      );
      final enabledRepo = enabled.repos.courier as RiderServerData;
      expect(await enabledRepo.setAvailability(online: true), isTrue);
      expect(enabled.wire.requests, ['POST /api/rider/availability']);
    });
  });

  group('delivery completion', () {
    test(
      'scan token then complete with proof photo and COD without an OTP',
      () async {
        final photo = File('${Directory.systemTemp.path}/wz_pod_test.jpg')
          ..writeAsBytesSync([0xFF, 0xD8, 0xFF, 0xD9]);
        addTearDown(() {
          if (photo.existsSync()) photo.deleteSync();
        });
        // A stand-in for WebZefanya's contract: delivery needs the scan token,
        // proof photo and exact COD, with no buyer code.
        final issued = <String>{};
        var n = 0;
        final t = _webzefanya((request, body) {
          final path = request.url.path;
          if (path.endsWith('/rider/jobs')) {
            return _ok({
              'online': true,
              'pickups': <Object?>[],
              'deliveries': [_parcel(11)],
              'return_pickups': <Object?>[],
            });
          }
          if (path.endsWith('/scan/verify')) {
            final token = 'tok-${++n}';
            issued.add(token);
            return _ok({
              'parcel': {'id': 11},
              'actions': [
                {'key': 'proof_of_delivery', 'verification_token': token},
              ],
            });
          }
          if (path.endsWith('/deliveries/11/deliver')) {
            final ok =
                issued.any(body.contains) &&
                body.contains('name="proof_photo"');
            return ok
                ? _ok({'status': 'DELIVERED'})
                : _json(422, {'message': 'Incomplete delivery request.'});
          }
          return _json(404, {'message': 'unexpected'});
        }, allowWrites: true);
        final repo = t.repos.courier as WebZefanyaCourierRepository;

        await repo.assignedJobs(); // remembers the tracking number
        await repo.completeDelivery(
          '11',
          proofPhotoPath: photo.path,
          recipientCategory: 'buyer',
          codAmount: 1500,
        );

        expect(t.wire.requests, [
          'GET /api/rider/jobs',
          'POST /api/rider/scan/verify',
          'POST /api/rider/deliveries/11/deliver',
        ]);
        final deliver = t.wire.bodies.last;
        expect(deliver, isNot(contains('delivery_otp')));
        expect(deliver, contains('name="verification_token"'));
        expect(deliver, contains('tok-1'));
        expect(deliver, contains('name="proof_photo"'));
        expect(deliver, contains('1500.00'));
        // The demo fork's acknowledgement fields are not sent.
        expect(deliver, contains('name="recipient_accepted"\r\n\r\n1'));
        expect(deliver, contains('name="recipient_category"\r\n\r\nbuyer'));
      },
    );

    test('delivery is not sent when the parcel scan is refused', () async {
      final t = _webzefanya((request, body) {
        final path = request.url.path;
        if (path.endsWith('/rider/jobs')) {
          return _ok({
            'online': true,
            'pickups': <Object?>[],
            'deliveries': [_parcel(11)],
            'return_pickups': <Object?>[],
          });
        }
        if (path.endsWith('/scan/verify')) {
          return _json(409, {'message': 'Parcel changed.'});
        }
        return _ok({});
      }, allowWrites: true);
      final repo = t.repos.courier as WebZefanyaCourierRepository;
      await repo.assignedJobs();

      final photo = File('${Directory.systemTemp.path}/wz_pod_refused.jpg')
        ..writeAsBytesSync([0xFF, 0xD8, 0xFF, 0xD9]);
      addTearDown(() {
        if (photo.existsSync()) photo.deleteSync();
      });
      await expectLater(
        repo.completeDelivery(
          '11',
          proofPhotoPath: photo.path,
          recipientCategory: 'buyer',
        ),
        throwsA(isA<RequestRejectedException>()),
      );
      expect(t.wire.requests.where((r) => r.contains('/deliver')), isEmpty);
    });

    test(
      'with writes off, no scan or delivery request leaves the device',
      () async {
        final t = _webzefanya((request, _) => _ok({}));
        final repo = t.repos.courier as WebZefanyaCourierRepository;
        final photo = File('${Directory.systemTemp.path}/wz_pod_blocked.jpg')
          ..writeAsBytesSync([0xFF, 0xD8, 0xFF, 0xD9]);
        addTearDown(() {
          if (photo.existsSync()) photo.deleteSync();
        });
        await expectLater(
          repo.completeDelivery(
            '11',
            proofPhotoPath: photo.path,
            recipientCategory: 'buyer',
          ),
          throwsA(isA<ValidationException>()),
        ); // no tracking number known yet
        expect(t.wire.requests, isEmpty);

        // With a known parcel, the scan POST itself is blocked by the guard.
        final known = _webzefanya((request, _) {
          return _ok({
            'online': true,
            'pickups': <Object?>[],
            'deliveries': [_parcel(11)],
            'return_pickups': <Object?>[],
          });
        });
        final knownRepo = known.repos.courier as WebZefanyaCourierRepository;
        await knownRepo.assignedJobs();
        await expectLater(
          knownRepo.completeDelivery(
            '11',
            proofPhotoPath: photo.path,
            recipientCategory: 'buyer',
          ),
          throwsA(isA<ApiNotImplementedException>()),
        );
        expect(known.wire.requests, ['GET /api/rider/jobs']);
      },
    );
  });

  group('authentication expiry, network errors and retry', () {
    test(
      'a 401 surfaces as UnauthorizedException and is not retried',
      () async {
        final t = _webzefanya(
          (request, _) => _json(401, {'message': 'Unauthenticated.'}),
        );
        final repo = t.repos.courier;
        await expectLater(
          repo.assignedJobs(),
          throwsA(isA<UnauthorizedException>()),
        );
        expect(t.wire.requests, hasLength(1));
      },
    );

    test(
      'a network failure is a NetworkException and a retry succeeds',
      () async {
        var calls = 0;
        final flaky = MockClient.streaming((request, stream) async {
          calls++;
          if (calls == 1) throw http.ClientException('connection reset');
          return http.StreamedResponse(
            Stream.value(
              utf8.encode(
                jsonEncode({
                  'success': true,
                  'data': {
                    'online': true,
                    'pickups': <Object?>[],
                    'deliveries': [_parcel(3)],
                    'return_pickups': <Object?>[],
                  },
                }),
              ),
            ),
            200,
            headers: {'content-type': 'application/json; charset=utf-8'},
          );
        });
        final client = guardedWebZefanyaClient(
          baseUrl: 'https://wz.test/api',
          tokenStore: InMemoryTokenStore()..write('token'),
          httpClient: flaky,
        );
        final repos = Repositories.webzefanya(client);
        final state = CourierState(
          repository: repos.courier,
          capabilities: repos.capabilities,
        );

        await expectLater(
          state.refreshJobs(),
          throwsA(isA<NetworkException>()),
        );
        expect(state.loadFailure, isA<NetworkException>());
        expect(state.loadFailure!.isRetryable, isTrue);

        await state.refreshJobs(); // the retry
        expect(state.loadFailure, isNull);
        expect(state.assignedJobs, hasLength(1));
      },
    );

    test(
      'a 409 stale state is a rejected request the caller can re-read',
      () async {
        final t = _webzefanya(
          (request, _) => _json(409, {'message': 'This parcel changed.'}),
          allowWrites: true,
        );
        final repo = t.repos.courier;
        await expectLater(
          repo.advanceMilestone(
            '5',
            'OUT_FOR_DELIVERY',
            trackingNumber: 'TRK-5',
          ),
          throwsA(isA<RequestRejectedException>()),
        );
      },
    );
  });
}
