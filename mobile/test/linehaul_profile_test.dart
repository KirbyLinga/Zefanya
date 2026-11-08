import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:zefanya/data/api/api_client.dart';
import 'package:zefanya/data/api/api_exception.dart';
import 'package:zefanya/data/api/token_store.dart';
import 'package:zefanya/data/repositories/linehaul_repository.dart';
import 'package:zefanya/screens/courier/linehaul_profile_screen.dart';

Map<String, Object?> _profile() => {
  'name': 'Ernesto Villareal',
  'email': 'ernesto@example.com',
  'status': 'active',
  'email_verified': true,
  'company': {'name': 'Bilis Hatid Express Inc. with a longer company name'},
  'home_hub': {'name': 'Quezon City Province Hub with a longer station name'},
};

http.Response _answer(Object? data, {int status = 200}) => http.Response(
  jsonEncode({'data': data}),
  status,
  headers: {'content-type': 'application/json; charset=utf-8'},
);

Future<void> _mount(WidgetTester tester, http.Client backend) async {
  tester.view.physicalSize = const Size(360, 640);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  final tokens = InMemoryTokenStore();
  await tokens.write('courier-token');
  final client = ApiClient(
    baseUrl: 'https://example.test/api',
    tokenStore: tokens,
    httpClient: backend,
  );
  await tester.pumpWidget(
    MaterialApp(
      builder: (context, child) => MediaQuery(
        data: MediaQuery.of(
          context,
        ).copyWith(textScaler: const TextScaler.linear(1.3)),
        child: child!,
      ),
      home: LinehaulProfileScreen(repository: LinehaulRepository(client)),
    ),
  );
  await tester.pumpAndSettle();
}

void main() {
  test(
    'profile parsing refuses absent company, hub, identity or unrecognized status',
    () {
      expect(
        LinehaulProfile.fromJson(_profile()).company,
        contains('Bilis Hatid'),
      );
      for (final key in [
        'name',
        'email',
        'company',
        'home_hub',
        'email_verified',
        'status',
      ]) {
        final row = _profile()..remove(key);
        expect(
          () => LinehaulProfile.fromJson(row),
          throwsA(isA<MalformedResponseException>()),
        );
      }
      for (final update in [
        {
          'company': {'name': ''},
        },
        {
          'home_hub': {'name': null},
        },
        {'status': 'suspended'},
        {'name': '   '},
        {'email_verified': 'yes'},
      ]) {
        expect(
          () => LinehaulProfile.fromJson({..._profile(), ...update}),
          throwsA(isA<MalformedResponseException>()),
        );
      }
    },
  );

  testWidgets(
    'profile shows server data and vehicle-record entry at narrow large text size',
    (tester) async {
      final requests = <http.Request>[];
      await _mount(
        tester,
        MockClient((request) async {
          requests.add(request);
          return _answer(_profile());
        }),
      );
      expect(find.text('Ernesto Villareal'), findsOneWidget);
      expect(find.text('ernesto@example.com'), findsOneWidget);
      final profileScroll = find
          .descendant(
            of: find.byType(ListView),
            matching: find.byType(Scrollable),
          )
          .first;
      await tester.scrollUntilVisible(
        find.text('Your company'),
        200,
        scrollable: profileScroll,
      );
      expect(find.text('Your company'), findsOneWidget);
      await tester.scrollUntilVisible(
        find.text('Vehicle & documents'),
        180,
        scrollable: profileScroll,
      );
      expect(
        find.textContaining('Changes need company review'),
        findsOneWidget,
      );
      expect(find.text('Vehicle records'), findsOneWidget);
      expect(requests.single.url.path, '/api/courier/profile');
      expect(requests.single.headers['authorization'], 'Bearer courier-token');
      expect(requests.single.method, 'GET');
      expect(tester.takeException(), isNull);
    },
  );

  testWidgets('failed profile retries without displaying cached identity', (
    tester,
  ) async {
    var calls = 0;
    await _mount(
      tester,
      MockClient((request) async {
        calls++;
        return calls == 1 ? _answer(null, status: 503) : _answer(_profile());
      }),
    );
    expect(find.text('Your profile could not be loaded'), findsOneWidget);
    expect(find.text('Ernesto Villareal'), findsNothing);
    await tester.tap(find.text('Retry'));
    await tester.pumpAndSettle();
    expect(calls, 2);
    expect(find.text('Ernesto Villareal'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('revoked access on refresh removes previous profile', (
    tester,
  ) async {
    var calls = 0;
    await _mount(
      tester,
      MockClient((request) async {
        calls++;
        return calls == 1 ? _answer(_profile()) : _answer(null, status: 403);
      }),
    );
    expect(find.text('Ernesto Villareal'), findsOneWidget);
    await tester.drag(find.byType(ListView), const Offset(0, 400));
    await tester.pumpAndSettle();
    expect(calls, 2);
    expect(find.text('Your profile could not be loaded'), findsOneWidget);
    expect(find.text('Ernesto Villareal'), findsNothing);
    expect(tester.takeException(), isNull);
  });
}
