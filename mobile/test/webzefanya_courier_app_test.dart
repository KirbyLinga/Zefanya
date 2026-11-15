// test/webzefanya_courier_app_test.dart
//
// Phase 3 Courier Mobile against WebZefanya's Courier API, with in-memory
// fakes. The real end-to-end run against the isolated WebZefanya build is
// tool/courier_e2e (it needs a running isolated server and is not part of the
// unit suite).

import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:zefanya/config/backend_profile.dart';
import 'package:zefanya/data/api/api_client.dart';
import 'package:zefanya/data/api/api_exception.dart';
import 'package:zefanya/data/api/token_store.dart';
import 'package:zefanya/data/api/webzefanya_contract.dart';
import 'package:zefanya/data/repositories/auth_repository.dart';
import 'package:zefanya/data/repositories/courier_transfer_repository.dart';
import 'package:zefanya/screens/courier/webzefanya_courier_portal.dart';
import 'package:zefanya/state/auth_state.dart';

http.Response _json(Object? body, [int status = 200]) => http.Response.bytes(
  utf8.encode(jsonEncode(body)),
  status,
  headers: {'content-type': 'application/json; charset=utf-8'},
);

Map<String, Object?> _ok(Object? data) => {
  'success': true,
  'message': 'ok',
  'data': data,
  'errors': null,
};

Map<String, Object?> _transfer(
  String type,
  int id, {
  String role = 'assigned',
  String status = 'DISPATCHED',
}) => {
  'type': type,
  'id': id,
  'role': role,
  'status': status,
  'origin': {'hub_id': 1, 'name': 'Calamba Station'},
  'destination': {
    'hub_id': 2,
    'name': 'Los Baños Station',
    if (type == 'handoff') 'provider': 'Partner Express',
  },
  'parcel_count': 3,
  'vehicle_type': 'Van',
  'plate_number': 'CVA 1001',
  'assigned_at': '2026-11-14T08:00:00+08:00',
  'dispatched_at': status == 'DRAFT' ? null : '2026-11-14T09:00:00+08:00',
  'received_at': null,
};

/// A client that knows the Courier routes, as a build with
/// `zefanya.webzefanya.courierApp=true` does.
final Expando<TokenStore> _stores = Expando();

ApiClient _courierClient(MockClient http, {TokenStore? tokens}) {
  final store = tokens ?? InMemoryTokenStore();
  final client = ApiClient(
    baseUrl: 'https://wz.test/api',
    tokenStore: store,
    httpClient: http,
    routePolicy: const WebZefanyaContract(
      routes: [...kWebZefanyaRoutes, ...kWebZefanyaCourierRoutes],
    ),
  );
  _stores[client] = store;
  return client;
}

extension on ApiClient {
  Future<bool> hasToken() async => (await _stores[this]!.read()) != null;
}

void main() {
  setUp(() => SharedPreferences.setMockInitialValues({}));

  group('contract', () {
    test(
      'Courier routes match the snapshot exported from the isolated WebZefanya build',
      () {
        final snapshot =
            jsonDecode(
                  File(
                    'test/contract/webzefanya_courier_api_snapshot.json',
                  ).readAsStringSync(),
                )
                as Map;
        final fromSnapshot = {
          for (final r in snapshot['routes'] as List)
            '${r['method']} ${r['path']}',
        };
        expect({for (final r in kWebZefanyaCourierRoutes) '$r'}, fromSnapshot);
        expect(snapshot['route_count'], kWebZefanyaCourierRoutes.length);
      },
    );

    test(
      'a default build has no Courier routes and offers no Courier sign-in',
      () {
        expect(kWebZefanyaCourierApp, isFalse);
        expect(const BackendCapabilities.webzefanya().courierLogin, isFalse);
        expect(
          kWebZefanyaBuildRoutes.any((r) => r.path.startsWith('/courier')),
          isFalse,
        );
        expect(
          const WebZefanyaContract.fromEnvironment().supports(
            'POST',
            '/courier/login',
          ),
          isFalse,
        );
      },
    );

    test(
      'with Courier routes, only reads and sign-in/activation/renewal pass in read-only mode',
      () {
        const contract = WebZefanyaContract(
          routes: [...kWebZefanyaRoutes, ...kWebZefanyaCourierRoutes],
        );
        for (final call in [
          ('POST', '/courier/activate'),
          ('POST', '/courier/login'),
          ('POST', '/courier/logout'),
          ('POST', '/courier/token/refresh'),
          ('GET', '/courier/profile'),
          ('GET', '/courier/transfers'),
          ('GET', '/courier/transfers/history?page=2'),
        ]) {
          expect(
            () => contract.check(call.$1, call.$2),
            returnsNormally,
            reason: '$call',
          );
        }
        // No transfer-changing Courier route exists or may be sent.
        for (final call in [
          ('POST', '/courier/transfers/1/accept'),
          ('POST', '/courier/transfers/1/depart'),
          ('PATCH', '/courier/profile'),
          ('POST', '/courier/duty'),
        ]) {
          expect(
            () => contract.check(call.$1, call.$2),
            throwsA(isA<ApiNotImplementedException>()),
            reason: '$call',
          );
        }
      },
    );
  });

  group('sign-in', () {
    test(
      'a WebZefanya Courier signs in with login_id, then reads the Courier profile and renews at /courier/token/refresh',
      () async {
        final calls = <String>[];
        Map<String, Object?>? loginBody;
        final client = _courierClient(
          MockClient((request) async {
            calls.add('${request.method} ${request.url.path}');
            if (request.url.path == '/api/courier/login') {
              loginBody = jsonDecode(request.body) as Map<String, Object?>;
              return _json(
                _ok({
                  'token': '1|abc',
                  'token_type': 'Bearer',
                  'expires_at': '2099-01-01T00:00:00Z',
                  'session': 4,
                  'role': 'courier',
                }),
              );
            }
            if (request.url.path == '/api/courier/profile') {
              expect(request.headers['Authorization'], 'Bearer 1|abc');
              return _json(
                _ok({
                  'account_id': 9,
                  'login_id': 'ZC-ABCD2345',
                  'name': 'Juan Courier',
                  'status': 'active',
                  'provider': {'id': 1, 'name': 'Ninja'},
                }),
              );
            }
            return _json({'message': 'unexpected'}, 404);
          }),
        );

        final result = await LiveAuthRepository(client).login(
          role: UserRole.linehaul,
          email: ' ZC-ABCD2345 ',
          password: 'secret-123',
        );

        expect(loginBody, {
          'login_id': 'ZC-ABCD2345',
          'password': 'secret-123',
          'device_name': kCourierDeviceName,
        });
        expect(calls, ['POST /api/courier/login', 'GET /api/courier/profile']);
        expect(result.role, UserRole.linehaul);
        expect(result.status, AccountStatus.active);
        expect(result.name, 'Juan Courier');
        expect(client.renewPath, '/courier/token/refresh');
      },
    );

    test(
      'activation posts the code with a confirmed password and adopts the session',
      () async {
        Map<String, Object?>? body;
        final client = _courierClient(
          MockClient((request) async {
            if (request.url.path == '/api/courier/activate') {
              body = jsonDecode(request.body) as Map<String, Object?>;
              return _json(
                _ok({
                  'token': '2|new',
                  'expires_at': '2099-01-01T00:00:00Z',
                  'session': 1,
                }),
              );
            }
            if (request.url.path == '/api/courier/profile') {
              return _json(
                _ok({
                  'account_id': 9,
                  'login_id': 'ZC-ABCD2345',
                  'name': 'Juan Courier',
                  'status': 'active',
                }),
              );
            }
            return _json({}, 404);
          }),
        );

        final result = await LiveAuthRepository(client).activateCourier(
          loginId: 'ZC-ABCD2345',
          activationCode: 'ABCDE-12345',
          password: 'chosen-pass-1',
        );

        expect(body, {
          'login_id': 'ZC-ABCD2345',
          'activation_code': 'ABCDE-12345',
          'password': 'chosen-pass-1',
          'password_confirmation': 'chosen-pass-1',
          'device_name': kCourierDeviceName,
        });
        expect(result.status, AccountStatus.active);
        expect(await client.hasToken(), isTrue);
      },
    );

    test(
      'the uniform server refusal is shown as is and no token is kept',
      () async {
        final client = _courierClient(
          MockClient(
            (request) async => _json({
              'success': false,
              'message':
                  'Login ID or access code is incorrect, or the code has expired.',
              'data': null,
              'errors': {
                'activation_code': [
                  'Login ID or access code is incorrect, or the code has expired.',
                ],
              },
            }, 422),
          ),
        );

        await expectLater(
          LiveAuthRepository(client).activateCourier(
            loginId: 'ZC-X',
            activationCode: 'BAD',
            password: 'chosen-pass-1',
          ),
          throwsA(isA<ApiException>()),
        );
        expect(await client.hasToken(), isFalse);
      },
    );
  });

  group('transfers', () {
    test(
      'assignments and history are parsed read-only; bad rows are dropped',
      () async {
        final client = _courierClient(
          MockClient((request) async {
            switch (request.url.path) {
              case '/api/courier/transfers':
                return _json(
                  _ok({
                    'current': [_transfer('manifest', 7)],
                    'upcoming': [
                      _transfer('handoff', 7, status: 'ACCEPTED'),
                      {'type': 'other', 'id': 1},
                      'junk',
                    ],
                  }),
                );
              case '/api/courier/transfers/history':
                expect(request.url.queryParameters['page'], '2');
                return _json(
                  _ok({
                    'data': [
                      {
                        'type': 'manifest',
                        'id': 3,
                        'role': 'reassigned_away',
                        'vehicle_type': 'Van',
                        'plate_number': 'CVA 1001',
                        'assigned_at': '2026-11-01T08:00:00Z',
                        'replaced_at': '2026-11-01T09:00:00Z',
                      },
                    ],
                    'meta': {
                      'current_page': 2,
                      'last_page': 3,
                      'per_page': 20,
                      'total': 41,
                    },
                  }),
                );
            }
            return _json({}, 404);
          }),
        );
        final repo = CourierTransferRepository(client);

        final assigned = await repo.assignments();
        expect(assigned.current.single.key, 'manifest-7');
        expect(assigned.upcoming.single.key, 'handoff-7');
        expect(assigned.upcoming.single.destinationProvider, 'Partner Express');
        expect(
          assigned.upcoming.single.statusLabel,
          'Waiting for the dispatch scan',
        );

        final page = await repo.history(page: 2);
        expect(page.hasMore, isTrue);
        expect(page.total, 41);
        final away = page.items.single;
        expect(away.reassignedAway, isTrue);
        expect(away.statusLabel, 'Reassigned to another Courier');
        expect(away.originName, isNull);
      },
    );
  });

  group('portal', () {
    Future<(AuthState, ApiClient)> signedIn(
      Future<http.Response> Function(http.Request) handler,
    ) async {
      final client = _courierClient(
        MockClient((request) async {
          if (request.url.path == '/api/courier/login') {
            return _json(
              _ok({
                'token': '1|abc',
                'expires_at': '2099-01-01T00:00:00Z',
                'session': 1,
              }),
            );
          }
          if (request.url.path == '/api/courier/profile' &&
              request.method == 'GET' &&
              request.headers['x-probe'] == null) {
            return handler(request);
          }
          return handler(request);
        }),
      );
      final auth = AuthState(repository: LiveAuthRepository(client));
      return (auth, client);
    }

    Widget host(AuthState auth, CourierTransferRepository repo) =>
        ChangeNotifierProvider<AuthState>.value(
          value: auth,
          child: MaterialApp(home: WebZefanyaCourierPortal(repository: repo)),
        );

    testWidgets(
      'shows current and upcoming transfers and no action that changes them',
      (tester) async {
        final (auth, client) = await signedIn(
          (request) async => switch (request.url.path) {
            '/api/courier/profile' => _json(
              _ok({
                'account_id': 1,
                'login_id': 'ZC-1',
                'name': 'Juan',
                'provider': {'name': 'Ninja'},
              }),
            ),
            '/api/courier/transfers' => _json(
              _ok({
                'current': [_transfer('manifest', 7)],
                'upcoming': [_transfer('manifest', 8, status: 'DRAFT')],
              }),
            ),
            '/api/courier/transfers/history' => _json(
              _ok({
                'data': [],
                'meta': {'current_page': 1, 'last_page': 1, 'total': 0},
              }),
            ),
            _ => _json({}, 404),
          },
        );
        await tester.runAsync(
          () => auth.login(
            role: UserRole.linehaul,
            email: 'ZC-1',
            password: 'secret-123',
          ),
        );

        await tester.pumpWidget(host(auth, CourierTransferRepository(client)));
        await tester.runAsync(
          () => Future<void>.delayed(const Duration(milliseconds: 50)),
        );
        await tester.pumpAndSettle();

        expect(
          find.byKey(const ValueKey('courier-transfer-manifest-7')),
          findsOneWidget,
        );
        expect(
          find.byKey(const ValueKey('courier-transfer-manifest-8')),
          findsOneWidget,
        );
        expect(find.textContaining('On the road'), findsWidgets);
        for (final word in [
          'Accept',
          'Depart',
          'Arrive',
          'Seal',
          'Dispatch',
          'Receive',
          'Earnings',
          'Duty',
        ]) {
          expect(
            find.widgetWithText(ElevatedButton, word),
            findsNothing,
            reason: word,
          );
          expect(
            find.widgetWithText(TextButton, word),
            findsNothing,
            reason: word,
          );
        }
      },
    );

    testWidgets('a 401 (access revoked) signs the Courier out locally', (
      tester,
    ) async {
      var revoked = false;
      final (auth, client) = await signedIn((request) async {
        if (revoked && request.url.path.startsWith('/api/courier/')) {
          return _json({
            'success': false,
            'message': 'Please sign in again.',
            'data': null,
            'errors': {
              'token': ['Please sign in again.'],
            },
          }, 401);
        }
        return switch (request.url.path) {
          '/api/courier/profile' => _json(
            _ok({
              'account_id': 1,
              'login_id': 'ZC-1',
              'status': 'active',
              'name': 'Juan',
            }),
          ),
          '/api/courier/transfers' => _json(
            _ok({'current': [], 'upcoming': []}),
          ),
          '/api/courier/transfers/history' => _json(
            _ok({
              'data': [],
              'meta': {'current_page': 1, 'last_page': 1, 'total': 0},
            }),
          ),
          _ => _json({}, 404),
        };
      });
      await tester.runAsync(
        () => auth.login(
          role: UserRole.linehaul,
          email: 'ZC-1',
          password: 'secret-123',
        ),
      );
      await tester.pumpWidget(
        ScaffoldMessenger(child: host(auth, CourierTransferRepository(client))),
      );
      await tester.runAsync(
        () => Future<void>.delayed(const Duration(milliseconds: 50)),
      );
      await tester.pumpAndSettle();
      expect(auth.status, AccountStatus.active);

      revoked = true;
      await tester.tap(find.byKey(const ValueKey('courier-refresh')));
      for (var k = 0; k < 40 && auth.status != AccountStatus.loggedOut; k++) {
        await tester.runAsync(
          () => Future<void>.delayed(const Duration(milliseconds: 25)),
        );
        await tester.pump();
      }
      await tester.pump();
      expect(find.byKey(const ValueKey('courier-blocked')), findsOneWidget);

      expect(auth.status, AccountStatus.loggedOut);
      expect(await client.hasToken(), isFalse);
    });

    testWidgets('a reply that arrives after sign-out is dropped', (
      tester,
    ) async {
      final gate = Completer<void>();
      final (auth, client) = await signedIn((request) async {
        if (request.url.path == '/api/courier/transfers') {
          await gate.future;
          return _json(
            _ok({
              'current': [_transfer('manifest', 99)],
              'upcoming': [],
            }),
          );
        }
        return switch (request.url.path) {
          '/api/courier/profile' => _json(
            _ok({
              'account_id': 1,
              'login_id': 'ZC-1',
              'status': 'active',
              'name': 'Juan',
            }),
          ),
          '/api/courier/transfers/history' => _json(
            _ok({
              'data': [],
              'meta': {'current_page': 1, 'last_page': 1, 'total': 0},
            }),
          ),
          '/api/courier/logout' => _json(_ok(null)),
          _ => _json({}, 404),
        };
      });
      await tester.runAsync(
        () => auth.login(
          role: UserRole.linehaul,
          email: 'ZC-1',
          password: 'secret-123',
        ),
      );
      await tester.pumpWidget(host(auth, CourierTransferRepository(client)));
      await tester.pump();

      // The Courier leaves the portal (signs out) before the slow reply lands.
      await tester.pumpWidget(const MaterialApp(home: SizedBox()));
      gate.complete();
      await tester.runAsync(
        () => Future<void>.delayed(const Duration(milliseconds: 50)),
      );
      await tester.pumpAndSettle();
      expect(tester.takeException(), isNull);
      expect(
        find.byKey(const ValueKey('courier-transfer-manifest-99')),
        findsNothing,
      );
    });
  });
}
