import 'dart:convert';
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

Map<String, Object?> _offer({
  String status = 'Assigned',
  bool canDecline = true,
}) => {
  'id': 12,
  'status': status,
  'offer_version': 3,
  'can_decline': canDecline,
  'origin': 'Pila Station Hub',
  'destination': 'Laguna Province Hub',
  if (status == 'Declined') ...{
    'decline_reason': 'Vehicle problem',
    'declined_at': '2026-11-08T12:00:00Z',
    'parcel_count': 2,
  },
  'items': status == 'Declined'
      ? []
      : [
          {
            'tracking_number': 'TRK-PRIVATE',
            'loaded': false,
            'received': false,
          },
        ],
};

class _Backend {
  String status = 'Assigned';
  bool capability = true;
  bool refuse = false;
  final bodies = <Map<String, dynamic>>[];
  Future<http.Response> handle(http.Request request) async {
    Object? data;
    if (request.method == 'POST') {
      bodies.add(jsonDecode(request.body) as Map<String, dynamic>);
      if (refuse) {
        return http.Response(
          jsonEncode({
            'message': 'Validation failed',
            'errors': {
              'trip': ['This offer was already accepted.'],
            },
          }),
          422,
          headers: {'content-type': 'application/json'},
        );
      }
      status = 'Declined';
      data = _offer(status: status, canDecline: false);
    } else if (request.url.path.endsWith('/trips')) {
      data = [
        _offer(status: status, canDecline: capability && status == 'Assigned'),
      ];
    } else {
      data = {
        'setup_required': false,
        'on_duty': false,
        'available': false,
        'status': 'Off Duty',
      };
    }
    return http.Response(
      jsonEncode({'data': data}),
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

Future<void> _openDecline(WidgetTester tester) async {
  await tester.scrollUntilVisible(
    find.text('Decline trip'),
    180,
    scrollable: find.byType(Scrollable).first,
  );
  await tester.pumpAndSettle();
  await tester.tap(find.text('Decline trip'));
  await tester.pumpAndSettle();
}

void main() {
  test(
    'declined DTO needs an immutable reason/date/count and no current parcel details',
    () {
      expect(
        LinehaulTrip.fromJson(
          _offer(status: 'Declined', canDecline: false),
        ).parcelCount,
        2,
      );
      for (final bad in [
        {'decline_reason': ''},
        {'declined_at': 'invalid'},
        {'parcel_count': -1},
        {'offer_version': '3'},
        {'can_decline': true},
        {
          'items': [
            {'tracking_number': 'PRIVATE'},
          ],
        },
      ]) {
        expect(
          () => LinehaulTrip.fromJson({
            ..._offer(status: 'Declined', canDecline: false),
            ...bad,
          }),
          throwsA(isA<MalformedResponseException>()),
        );
      }
    },
  );

  testWidgets('missing reason is blocked; keeping the trip sends nothing', (
    tester,
  ) async {
    final backend = _Backend();
    await _mount(tester, backend);
    await _openDecline(tester);
    await tester.tap(find.text('Confirm decline'));
    await tester.pumpAndSettle();
    expect(find.text('Enter a reason for declining'), findsOneWidget);
    expect(backend.bodies, isEmpty);
    await tester.tap(find.text('Keep trip'));
    await tester.pumpAndSettle();
    expect(backend.status, 'Assigned');
    expect(backend.bodies, isEmpty);
    expect(tester.takeException(), isNull);
  });

  testWidgets(
    'preset reason sends the exact offer version and moves the snapshot to history',
    (tester) async {
      final backend = _Backend();
      await _mount(tester, backend);
      await _openDecline(tester);
      await tester.tap(find.byType(DropdownButtonFormField<String>));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Vehicle problem').last);
      await tester.pumpAndSettle();
      await tester.tap(find.text('Confirm decline'));
      await tester.pumpAndSettle();
      expect(backend.bodies, [
        {'reason': 'Vehicle problem', 'offer_version': 3},
      ]);
      expect(find.text('No trips assigned'), findsOneWidget);
      await tester.tap(find.text('History'));
      await tester.pumpAndSettle();
      expect(find.text('Declined'), findsOneWidget);
      await tester.scrollUntilVisible(
        find.text('Reason: Vehicle problem'),
        180,
        scrollable: find.byType(Scrollable).first,
      );
      await tester.pumpAndSettle();
      expect(find.text('2 parcels in original offer'), findsOneWidget);
      expect(find.text('Parcel details'), findsNothing);
      expect(find.text('Decline trip'), findsNothing);
      expect(find.text('Directions to destination'), findsNothing);
      expect(tester.takeException(), isNull);
    },
  );

  testWidgets('server refusal preserves Assigned and shows the actual reason', (
    tester,
  ) async {
    final backend = _Backend()..refuse = true;
    await _mount(tester, backend);
    await _openDecline(tester);
    await tester.enterText(find.byType(TextFormField), 'Vehicle problem');
    await tester.tap(find.text('Confirm decline'));
    await tester.pumpAndSettle();
    expect(find.text('This offer was already accepted.'), findsOneWidget);
    expect(backend.status, 'Assigned');
    expect(find.text('Assigned'), findsOneWidget);
    await tester.pump(const Duration(seconds: 4));
    await tester.pumpAndSettle();
    expect(tester.takeException(), isNull);
  });

  testWidgets(
    'pending server setup and accepted offers never advertise decline',
    (tester) async {
      final backend = _Backend()..capability = false;
      await _mount(tester, backend);
      expect(find.text('Decline trip'), findsNothing);
      expect(backend.bodies, isEmpty);
      backend.status = 'Loading';
      await tester.tap(find.byTooltip('Refresh trips'));
      await tester.pumpAndSettle();
      expect(find.text('Loading'), findsOneWidget);
      expect(find.text('Decline trip'), findsNothing);
      expect(tester.takeException(), isNull);
    },
  );
}
