import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:zefanya/data/api/api_client.dart';
import 'package:zefanya/data/api/api_exception.dart';
import 'package:zefanya/data/api/token_store.dart';
import 'package:zefanya/data/repositories/linehaul_repository.dart';
import 'package:zefanya/data/repositories/linehaul_history.dart';
import 'package:zefanya/screens/courier/linehaul_history_screen.dart';

Map<String, Object?> entry({String status = 'Declined'}) => {
  'id': 99,
  'history_key': 'decline:1',
  'recorded_at': '2026-11-08T04:00:00Z',
  'status': status,
  'origin': 'Original origin',
  'destination': 'Original destination',
  'parcel_count': 5,
  'decline_reason': 'Vehicle problem',
  'declined_at': '2026-11-08T04:00:00Z',
  'items': [],
  'trip_pay': {'status': 'ineligible', 'amount_minor': 0},
};
Map<String, Object?> report({int page = 1}) => {
  'page': page,
  'last_page': 2,
  'total': 21,
  'timezone': 'Asia/Manila',
  'trips': [entry()],
};

Future<void> mount(
  WidgetTester tester,
  Future<http.Response> Function(http.Request) respond,
) async {
  tester.view.physicalSize = const Size(360, 640);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  final client = ApiClient(
    baseUrl: 'https://example.test/api',
    tokenStore: InMemoryTokenStore(),
    httpClient: MockClient(respond),
  );
  await tester.pumpWidget(
    MaterialApp(
      builder: (context, child) => MediaQuery(
        data: MediaQuery.of(
          context,
        ).copyWith(textScaler: const TextScaler.linear(1.3)),
        child: child!,
      ),
      home: LinehaulHistoryScreen(repository: LinehaulRepository(client)),
    ),
  );
  await tester.pumpAndSettle();
}

void main() {
  test(
    'history rejects duplicate keys, invalid counts, unzoned dates and active trips',
    () {
      expect(LinehaulHistoryPage(report()).rows.single.trip.status, 'Declined');
      for (final bad in [
        {...report(), 'total': -1},
        {
          ...report(),
          'trips': [entry(), entry()],
        },
        {
          ...report(),
          'trips': [
            {...entry(), 'recorded_at': '2026-11-08 04:00:00'},
          ],
        },
        {
          ...report(),
          'trips': [entry(status: 'Loading')],
        },
      ]) {
        expect(
          () => LinehaulHistoryPage(bad),
          throwsA(isA<MalformedResponseException>()),
        );
      }
    },
  );

  testWidgets(
    'declined snapshot, dates and full-history pagination fit small large-text screen',
    (tester) async {
      final queries = <Map<String, String>>[];
      await mount(tester, (request) async {
        expect(request.method, 'GET');
        queries.add(request.url.queryParameters);
        return http.Response(
          jsonEncode({
            'data': report(
              page: int.parse(request.url.queryParameters['page']!),
            ),
          }),
          200,
          headers: {'content-type': 'application/json'},
        );
      });
      await tester.scrollUntilVisible(
        find.text('Decline reason: Vehicle problem'),
        100,
        scrollable: find.byType(Scrollable).first,
      );
      expect(
        find.text('Original origin → Original destination'),
        findsOneWidget,
      );
      expect(find.text('No trip pay: ₱0.00'), findsOneWidget);
      await tester.scrollUntilVisible(
        find.text('Next'),
        100,
        scrollable: find.byType(Scrollable).first,
      );
      await tester.drag(find.byType(ListView), const Offset(0, -150));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Next'));
      await tester.pumpAndSettle();
      expect(queries.last['page'], '2');
      await tester.scrollUntilVisible(
        find.text('Today'),
        -100,
        scrollable: find.byType(Scrollable).first,
      );
      await tester.drag(find.byType(ListView), const Offset(0, 300));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Today'));
      await tester.pumpAndSettle();
      expect(queries.last['from'], queries.last['to']);
      expect(queries.last['page'], '1');
      expect(tester.takeException(), isNull);
    },
  );

  testWidgets('history refusal has retry without fabricated empty data', (
    tester,
  ) async {
    var calls = 0;
    await mount(tester, (request) async {
      expect(request.method, 'GET');
      calls++;
      return calls == 1
          ? http.Response(
              jsonEncode({'message': 'History unavailable'}),
              503,
              headers: {'content-type': 'application/json'},
            )
          : http.Response(
              jsonEncode({
                'data': {...report(), 'total': 0, 'last_page': 1, 'trips': []},
              }),
              200,
              headers: {'content-type': 'application/json'},
            );
    });
    expect(find.text('No history in this date range.'), findsNothing);
    await tester.scrollUntilVisible(
      find.text('Retry'),
      100,
      scrollable: find.byType(Scrollable).first,
    );
    await tester.drag(find.byType(ListView), const Offset(0, -150));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Retry'));
    await tester.pumpAndSettle();
    expect(calls, 2);
    expect(find.text('No history in this date range.'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });
}
