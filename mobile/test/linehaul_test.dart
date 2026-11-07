import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:zefanya/data/api/api_client.dart';
import 'package:zefanya/data/api/token_store.dart';
import 'package:zefanya/data/repositories/auth_repository.dart';
import 'package:zefanya/data/repositories/linehaul_repository.dart';
import 'package:zefanya/data/repositories/repositories.dart';
import 'package:zefanya/screens/courier/linehaul_portal.dart';
import 'package:zefanya/state/auth_state.dart';

http.Response answer(Object? data, {int status = 200}) => http.Response(
  jsonEncode({'success': status == 200, 'data': data}),
  status,
  headers: {'content-type': 'application/json; charset=utf-8'},
);

Map<String, Object?> trip(String status, {bool loaded = false}) => {
  'id': 12,
  'status': status,
  'origin': 'Calamba Station Hub with a long location name',
  'destination': 'Laguna Province Hub',
  'seal_number': null,
  'items': [
    {
      'id': 7,
      'tracking_number': 'TRK-001',
      'loaded': loaded,
      'received': false,
    },
  ],
};

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  setUp(() => SharedPreferences.setMockInitialValues({}));

  test(
    'Courier login, refresh and logout use separate role endpoints and token',
    () async {
      final requests = <http.Request>[];
      final tokens = InMemoryTokenStore();
      final client = ApiClient(
        baseUrl: 'https://example.test/api',
        tokenStore: tokens,
        httpClient: MockClient((request) async {
          requests.add(request);
          if (request.url.path.endsWith('/login')) {
            return answer({'token': 'courier-token'});
          }
          return answer({
            'name': 'Courier One',
            'email': 'courier@example.com',
            'status': 'active',
          });
        }),
      );
      final auth = LiveAuthRepository(client);
      final result = await auth.login(
        role: UserRole.linehaul,
        email: 'courier@example.com',
        password: 'test-password',
      );
      expect(result.role, UserRole.linehaul);
      expect(result.status, AccountStatus.active);
      expect(await tokens.read(), 'courier-token');
      expect(
        (await auth.refresh(role: UserRole.linehaul))!.role,
        UserRole.linehaul,
      );
      await auth.logout(role: UserRole.linehaul);
      expect(requests.map((r) => r.url.path).toList(), [
        '/api/courier/login',
        '/api/courier/profile',
        '/api/courier/profile',
        '/api/courier/logout',
      ]);
      expect(requests[1].headers['Authorization'], 'Bearer courier-token');
      expect(await tokens.read(), isNull);
    },
  );

  test(
    'repository sends tracking, scan method and seal but never receives on behalf of Logistics',
    () async {
      final requests = <http.Request>[];
      final client = ApiClient(
        baseUrl: 'https://example.test/api',
        tokenStore: InMemoryTokenStore(),
        httpClient: MockClient((request) async {
          requests.add(request);
          return request.method == 'GET'
              ? answer([trip('Loading')])
              : answer(trip('Loading', loaded: true));
        }),
      );
      final repository = LinehaulRepository(client);
      expect((await repository.trips()).single.items.single.loaded, isFalse);
      expect(
        (await repository.act(
          '12',
          'scan',
          body: {'tracking_number': 'TRK-001', 'method': 'camera'},
        )).items.single.loaded,
        isTrue,
      );
      await repository.act('12', 'depart', body: {'seal_number': 'BH-0004521'});
      expect(jsonDecode(requests[1].body), {
        'tracking_number': 'TRK-001',
        'method': 'camera',
      });
      expect(jsonDecode(requests[2].body), {'seal_number': 'BH-0004521'});
      expect(requests.map((r) => r.url.path), [
        '/api/courier/trips',
        '/api/courier/trips/12/scan',
        '/api/courier/trips/12/depart',
      ]);
    },
  );

  testWidgets(
    'server refusal keeps trip Loading on a small screen with large text',
    (tester) async {
      tester.view.physicalSize = const Size(360, 640);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      var status = 'Assigned';
      final client = ApiClient(
        baseUrl: 'https://example.test/api',
        tokenStore: InMemoryTokenStore(),
        httpClient: MockClient((request) async {
          if (request.method == 'GET') return answer([trip(status)]);
          if (request.url.path.endsWith('/accept')) {
            status = 'Loading';
            return answer(trip(status));
          }
          return http.Response(
            jsonEncode({'message': 'Scan every parcel before departure.'}),
            422,
            headers: {'content-type': 'application/json'},
          );
        }),
      );
      await tester.pumpWidget(
        MultiProvider(
          providers: [
            Provider<Repositories>.value(value: Repositories.live(client)),
            ChangeNotifierProvider(create: (_) => AuthState()),
          ],
          child: MaterialApp(
            builder: (context, child) => MediaQuery(
              data: MediaQuery.of(
                context,
              ).copyWith(textScaler: const TextScaler.linear(1.3)),
              child: child!,
            ),
            home: const LinehaulPortal(),
          ),
        ),
      );
      await tester.pumpAndSettle();
      await tester.ensureVisible(find.text('Accept trip'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Accept trip'));
      await tester.pumpAndSettle();
      expect(find.text('Loading'), findsOneWidget);
      await tester.ensureVisible(find.text('Depart'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Depart'));
      await tester.pumpAndSettle();
      await tester.enterText(find.byType(TextField), 'BH-0004521');
      await tester.tap(find.widgetWithText(FilledButton, 'Depart').last);
      await tester.pumpAndSettle();
      expect(find.text('Scan every parcel before departure.'), findsOneWidget);
      expect(find.text('Loading'), findsOneWidget);
      expect(find.text('In Transit'), findsNothing);
      expect(tester.takeException(), isNull);
      await tester.pump(const Duration(seconds: 4));
      await tester.pumpAndSettle();
    },
  );

  testWidgets('trip loading failure offers Retry instead of an empty success', (
    tester,
  ) async {
    var fail = true;
    final client = ApiClient(
      baseUrl: 'https://example.test/api',
      tokenStore: InMemoryTokenStore(),
      httpClient: MockClient(
        (request) async => fail
            ? http.Response(
                jsonEncode({'message': 'Temporarily unavailable'}),
                503,
              )
            : answer([]),
      ),
    );
    await tester.pumpWidget(
      MultiProvider(
        providers: [
          Provider<Repositories>.value(value: Repositories.live(client)),
          ChangeNotifierProvider(create: (_) => AuthState()),
        ],
        child: const MaterialApp(home: LinehaulPortal()),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text('Retry'), findsOneWidget);
    expect(find.textContaining('No trips assigned'), findsNothing);
    fail = false;
    await tester.tap(find.text('Retry'));
    await tester.pumpAndSettle();
    expect(find.textContaining('No trips assigned'), findsOneWidget);
  });
}
