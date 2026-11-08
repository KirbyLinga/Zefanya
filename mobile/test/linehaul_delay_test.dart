import 'dart:async';
import 'dart:convert';
import 'support/linehaul_history_fixture.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:zefanya/data/api/api_client.dart';
import 'package:zefanya/data/api/api_exception.dart';
import 'package:zefanya/data/api/token_store.dart';
import 'package:zefanya/data/repositories/linehaul_repository.dart';
import 'package:zefanya/data/repositories/repositories.dart';
import 'package:zefanya/screens/courier/linehaul_portal.dart';
import 'package:zefanya/state/auth_state.dart';

Map<String, Object?> _delay() => {
  'id': 1,
  'reason': 'Heavy traffic',
  'new_eta': '2026-11-08T05:00:00+00:00',
  'reported_at': '2026-11-08T04:00:00+00:00',
};

class _Backend {
  bool capability = true;
  bool failOnce = false;
  Completer<void>? sending;
  String status = 'In Transit';
  Map<String, Object?>? delay;
  final bodies = <Map<String, dynamic>>[];
  final paths = <String>[];
  Future<http.Response> handle(http.Request request) async {
    if (request.method == 'POST') {
      paths.add(request.url.path);
      final body = jsonDecode(request.body) as Map<String, dynamic>;
      bodies.add(body);
      await sending?.future;
      if (failOnce && bodies.length == 1) {
        return http.Response(
          jsonEncode({'message': 'Connection interrupted. Try again.'}),
          503,
          headers: {'content-type': 'application/json'},
        );
      }
      delay = {
        'id': 1,
        'reason': body['reason'],
        'new_eta': body['new_eta'],
        'reported_at': DateTime.now().toUtc().toIso8601String(),
      };
    }
    final trip = {
      'id': 12,
      'status': status,
      'origin': 'Pila Station Hub',
      'destination': 'Laguna Province Hub',
      'offer_version': 3,
      'can_report_delay': capability && status == 'In Transit',
      'delay': delay,
      'seal_number': 'BH-0000771',
      'items': [
        {'tracking_number': 'TRK-001', 'loaded': true, 'received': false},
      ],
    };
    return http.Response(
      jsonEncode({
        'data': request.url.path.endsWith('/duty')
            ? {
                'setup_required': false,
                'on_duty': false,
                'available': false,
                'status': 'Off Duty',
              }
            : request.url.path.endsWith('/history')
            ? historyFixture([trip])
            : request.method == 'GET'
            ? [trip]
            : trip,
      }),
      200,
      headers: {'content-type': 'application/json'},
    );
  }
}

Future<void> _mount(WidgetTester tester, _Backend backend) async {
  SharedPreferences.setMockInitialValues({});
  tester.view.physicalSize = const Size(360, 640);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  final client = ApiClient(
    baseUrl: 'https://example.test/api',
    tokenStore: InMemoryTokenStore(),
    httpClient: MockClient(backend.handle),
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
}

Future<void> _open(WidgetTester tester) async {
  await tester.scrollUntilVisible(
    find.text('Report delay'),
    180,
    scrollable: find.byType(Scrollable).first,
  );
  await tester.pumpAndSettle();
  await tester.tap(find.text('Report delay'));
  await tester.pumpAndSettle();
}

Future<void> _fill(WidgetTester tester) async {
  await tester.tap(find.byType(DropdownButtonFormField<String>));
  await tester.pumpAndSettle();
  await tester.tap(find.text('Heavy traffic').last);
  await tester.pumpAndSettle();
  final scroll = find
      .descendant(
        of: find.byType(AlertDialog),
        matching: find.byType(Scrollable),
      )
      .first;
  await tester.scrollUntilVisible(
    find.text('1 hour from now'),
    120,
    scrollable: scroll,
  );
  await tester.pumpAndSettle();
  await tester.tap(find.text('1 hour from now'));
  await tester.pumpAndSettle();
}

void main() {
  testWidgets('Back cannot discard or duplicate a report while it is sending', (
    tester,
  ) async {
    final sending = Completer<void>();
    final backend = _Backend()..sending = sending;
    await _mount(tester, backend);
    await _open(tester);
    await _fill(tester);
    await tester.tap(find.text('Send delay report'));
    await tester.pump();
    await tester.binding.handlePopRoute();
    await tester.pump();
    expect(find.text('Sending…'), findsOneWidget);
    expect(find.text('Discard changes?'), findsNothing);
    expect(backend.bodies.length, 1);
    final cancel = tester.widget<TextButton>(
      find.widgetWithText(TextButton, 'Keep trip unchanged'),
    );
    expect(cancel.onPressed, isNull);
    sending.complete();
    await tester.pumpAndSettle();
    expect(find.text('Report a delay'), findsNothing);
    expect(find.text('Discard changes?'), findsNothing);
    expect(backend.bodies.length, 1);
    expect(tester.takeException(), isNull);
  });

  testWidgets(
    'Back protects an unsent reason; Stay retains it and Discard never posts',
    (tester) async {
      final backend = _Backend();
      await _mount(tester, backend);
      await _open(tester);
      await tester.enterText(
        find.byKey(const ValueKey('delay-reason')),
        'Road blocked',
      );
      await tester.pumpAndSettle();
      await tester.binding.handlePopRoute();
      await tester.pumpAndSettle();
      expect(find.text('Discard changes?'), findsOneWidget);
      expect(backend.bodies, isEmpty);
      await tester.tap(find.text('Stay'));
      await tester.pumpAndSettle();
      expect(find.text('Road blocked'), findsOneWidget);
      await tester.tap(find.text('Keep trip unchanged'));
      await tester.pumpAndSettle();
      expect(find.text('Discard changes?'), findsOneWidget);
      await tester.tap(find.text('Discard'));
      await tester.pumpAndSettle();
      expect(find.text('Report a delay'), findsNothing);
      expect(find.text('In Transit'), findsOneWidget);
      expect(backend.bodies, isEmpty);
      expect(tester.takeException(), isNull);
    },
  );

  testWidgets(
    'ETA alone is unsaved input and clean Back exits without confirmation',
    (tester) async {
      final backend = _Backend();
      await _mount(tester, backend);
      await _open(tester);
      await tester.binding.handlePopRoute();
      await tester.pumpAndSettle();
      expect(find.text('Discard changes?'), findsNothing);
      expect(find.text('Report a delay'), findsNothing);
      await _open(tester);
      final scroll = find
          .descendant(
            of: find.byType(AlertDialog),
            matching: find.byType(Scrollable),
          )
          .first;
      await tester.scrollUntilVisible(
        find.text('1 hour from now'),
        120,
        scrollable: scroll,
      );
      await tester.tap(find.text('1 hour from now'));
      await tester.pumpAndSettle();
      await tester.binding.handlePopRoute();
      await tester.pumpAndSettle();
      expect(find.text('Discard changes?'), findsOneWidget);
      await tester.tap(find.text('Stay'));
      await tester.pumpAndSettle();
      final eta = tester.widget<TextFormField>(
        find.byKey(const ValueKey('delay-eta')),
      );
      expect(eta.controller!.text, isNotEmpty);
      expect(backend.bodies, isEmpty);
      expect(tester.takeException(), isNull);
    },
  );

  test(
    'delay timestamps require timezone and valid data; Unicode reasons follow server character count',
    () {
      expect(
        LinehaulDelay.fromJson(_delay()).newEta.toUtc(),
        DateTime.utc(2026, 11, 8, 5),
      );
      expect(
        LinehaulDelay.fromJson({
          ..._delay(),
          'reason': List.filled(500, '🚚').join(),
        }).reason.runes.length,
        500,
      );
      for (final bad in [
        {'reason': ''},
        {'new_eta': 'bad'},
        {'new_eta': '2026-11-08T05:00:00'},
        {'reported_at': null},
      ]) {
        expect(
          () => LinehaulDelay.fromJson({..._delay(), ...bad}),
          throwsA(isA<MalformedResponseException>()),
        );
      }
    },
  );

  testWidgets('reason and future ETA are required; cancel never posts', (
    tester,
  ) async {
    final backend = _Backend();
    await _mount(tester, backend);
    await _open(tester);
    await tester.tap(find.text('Send delay report'));
    await tester.pumpAndSettle();
    expect(find.text('Enter a delay reason'), findsOneWidget);
    expect(find.text('Choose a future ETA'), findsOneWidget);
    expect(backend.bodies, isEmpty);
    await tester.tap(find.text('Keep trip unchanged'));
    await tester.pumpAndSettle();
    expect(find.text('In Transit'), findsOneWidget);
    expect(backend.bodies, isEmpty);
    expect(tester.takeException(), isNull);
  });

  testWidgets(
    'report sends UTC ETA/version and retains In Transit, parcels and seal',
    (tester) async {
      final backend = _Backend();
      await _mount(tester, backend);
      await _open(tester);
      await _fill(tester);
      final before = DateTime.now();
      await tester.tap(find.text('Send delay report'));
      await tester.pumpAndSettle();
      expect(backend.paths, ['/api/courier/trips/12/delay']);
      expect(backend.bodies.single['reason'], 'Heavy traffic');
      expect(backend.bodies.single['offer_version'], 3);
      final eta = DateTime.parse(backend.bodies.single['new_eta'] as String);
      expect(eta.isUtc, isTrue);
      expect(eta.difference(before).inMinutes, inInclusiveRange(59, 60));
      expect(find.text('In Transit'), findsOneWidget);
      await tester.scrollUntilVisible(
        find.text('Delayed'),
        180,
        scrollable: find.byType(Scrollable).first,
      );
      await tester.pumpAndSettle();
      expect(find.text('Heavy traffic'), findsOneWidget);
      expect(find.text('Seal: BH-0000771'), findsOneWidget);
      expect(tester.takeException(), isNull);
    },
  );

  testWidgets('failed send retains the exact reason and ETA for a safe retry', (
    tester,
  ) async {
    final backend = _Backend()..failOnce = true;
    await _mount(tester, backend);
    await _open(tester);
    await _fill(tester);
    await tester.tap(find.text('Send delay report'));
    await tester.pumpAndSettle();
    expect(find.byType(AlertDialog), findsOneWidget);
    expect(find.text('Connection interrupted. Try again.'), findsOneWidget);
    await tester.tap(find.text('Send delay report'));
    await tester.pumpAndSettle();
    expect(backend.bodies.length, 2);
    expect(backend.bodies[1], backend.bodies[0]);
    expect(find.byType(AlertDialog), findsNothing);
    expect(find.text('In Transit'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets(
    'old server hides report and completed history labels its last report correctly',
    (tester) async {
      final backend = _Backend()..capability = false;
      await _mount(tester, backend);
      expect(find.text('Report delay'), findsNothing);
      backend.status = 'Completed';
      backend.delay = _delay();
      await tester.tap(find.byTooltip('Refresh trips'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('History'));
      await tester.pumpAndSettle();
      await tester.scrollUntilVisible(
        find.text('Last delay report'),
        180,
        scrollable: find.byType(Scrollable).first,
      );
      await tester.pumpAndSettle();
      expect(find.text('Delayed'), findsNothing);
      expect(find.text('Report delay'), findsNothing);
      expect(
        find.text('Reported ETA has passed. Update Logistics.'),
        findsNothing,
      );
      expect(tester.takeException(), isNull);
    },
  );
}
