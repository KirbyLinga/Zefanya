import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:zefanya/data/api/api_client.dart';
import 'package:zefanya/data/api/api_exception.dart';
import 'package:zefanya/data/api/token_store.dart';
import 'package:zefanya/data/repositories/linehaul_repository.dart';
import 'package:zefanya/data/repositories/linehaul_earnings.dart';
import 'package:zefanya/screens/courier/linehaul_earnings_screen.dart';

Map<String, Object?> report({int page = 1}) => {
  'timezone': 'Asia/Manila',
  'earned_minor': 200000,
  'earned_trips': 2,
  'unconfirmed_trips': 1,
  'completed_trips': 3,
  'page': page,
  'last_page': 2,
  'trips': [
    {
      'trip_id': page,
      'completed_at': '2026-11-08T05:00:00Z',
      'result': 'Completed',
      'trip_pay': {'status': 'earned', 'amount_minor': 100000},
    },
  ],
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
      home: LinehaulEarningsScreen(repository: LinehaulRepository(client)),
    ),
  );
  await tester.pumpAndSettle();
}

void main() {
  test('earnings totals validate count and integer money consistency', () {
    expect(LinehaulEarnings(report()).earnedMinor, 200000);
    for (final bad in [
      {...report(), 'earned_minor': -1},
      {...report(), 'earned_minor': '200000'},
      {...report(), 'earned_trips': 4},
      {...report(), 'timezone': 'UTC'},
      {...report(), 'page': 0},
    ]) {
      expect(
        () => LinehaulEarnings(bad),
        throwsA(isA<MalformedResponseException>()),
      );
    }
  });

  test('paid dates are optional and attached only to confirmed earnings', () {
    final unpaid = LinehaulEarnings(report());
    expect(unpaid.payoutRecordingAvailable, isFalse);
    expect(unpaid.trips.single.paidOn, isNull);

    final base = report();
    final row = (base['trips'] as List).single as Map;
    final paid = LinehaulEarnings({
      ...base,
      'payout_recording_available': true,
      'trips': [
        {...row, 'paid_on': '2026-11-09'},
      ],
    });
    expect(paid.payoutRecordingAvailable, isTrue);
    expect(paid.trips.single.paidOn, DateTime.utc(2026, 11, 9));
    expect(
      () => LinehaulEarningRow({...row, 'paid_on': '2026-11-31'}),
      throwsA(isA<MalformedResponseException>()),
    );
  });

  testWidgets(
    'Philippine presets pagination and partial totals fit narrow large text',
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
      expect(queries.single['from'], queries.single['to']);
      await tester.scrollUntilVisible(
        find.text('₱2,000.00'),
        100,
        scrollable: find.byType(Scrollable).first,
      );
      expect(find.textContaining('excluded from this amount'), findsOneWidget);
      await tester.scrollUntilVisible(
        find.text('Next').hitTestable(),
        100,
        scrollable: find.byType(Scrollable).first,
      );
      await tester.tap(find.text('Next'));
      await tester.pumpAndSettle();
      expect(queries.last['page'], '2');
      await tester.scrollUntilVisible(
        find.text('This week').hitTestable(),
        -100,
        scrollable: find.byType(Scrollable).first,
      );
      await tester.tap(find.text('This week'));
      await tester.pumpAndSettle();
      expect(queries.last['page'], '1');
      expect(DateTime.parse(queries.last['from']!).weekday, DateTime.monday);
      expect(tester.takeException(), isNull);
    },
  );

  testWidgets(
    'failed earnings hides totals and retry recovers without credit writes',
    (tester) async {
      var calls = 0;
      await mount(tester, (request) async {
        expect(request.method, 'GET');
        calls++;
        return calls == 1
            ? http.Response(
                jsonEncode({'message': 'Earnings temporarily unavailable'}),
                503,
                headers: {'content-type': 'application/json'},
              )
            : http.Response(
                jsonEncode({'data': report()}),
                200,
                headers: {'content-type': 'application/json'},
              );
      });
      expect(find.text('₱2,000.00'), findsNothing);
      await tester.scrollUntilVisible(
        find.text('Retry'),
        100,
        scrollable: find.byType(Scrollable).first,
      );
      await tester.drag(find.byType(ListView), const Offset(0, -200));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Retry'));
      await tester.pumpAndSettle();
      expect(calls, 2);
      expect(find.text('₱2,000.00'), findsOneWidget);
      expect(tester.takeException(), isNull);
    },
  );
}
