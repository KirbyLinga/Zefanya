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
import 'package:zefanya/services/courier_directions.dart';
import 'package:zefanya/services/courier_location_service.dart';
import 'package:zefanya/state/auth_state.dart';

Map<String, Object?> _trip({
  String? address = 'Rizal Avenue, Calamba, Laguna',
  String status = 'In Transit',
}) => {
  'id': 12,
  'status': status,
  'origin': 'Calamba Station',
  'destination': 'Laguna Province Hub',
  'origin_address': 'Mabini Street, Calamba, Laguna',
  'destination_address': address,
  'seal_number': 'BH-0004521',
  'items': [
    {'id': 1, 'tracking_number': 'TRK-001', 'loaded': true, 'received': false},
  ],
};

class _Directions implements CourierDirections {
  bool opened = true;
  bool fail = false;
  final requests = <(String, String)>[];
  @override
  Future<bool> open(String name, String address) async {
    requests.add((name, address));
    if (fail) throw Exception('Map app unavailable');
    return opened;
  }
}

class _ActionLocation implements CourierLocationSource {
  int reads = 0;

  @override
  Future<CourierPosition> current({bool requestPermission = true}) async {
    reads++;
    return const CourierPosition(14.3, 121.4, 15);
  }
}

class _TripLocation implements CourierTripLocationSource {
  final points = StreamController<CourierPosition>();

  @override
  bool get supported => true;

  @override
  Stream<CourierPosition> watchTrip({required bool Function() canStart}) {
    expect(canStart(), isTrue);
    return points.stream;
  }
}

Future<void> _mount(
  WidgetTester tester,
  _Directions directions,
  Map<String, Object?> trip,
  List<String> posts, {
  void Function(http.Request)? onAction,
  http.Response? Function(http.Request)? actionResponse,
  CourierLocationSource? locationSource,
  CourierTripLocationSource? tripLocationSource,
}) async {
  SharedPreferences.setMockInitialValues({});
  tester.view.physicalSize = const Size(360, 640);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  final client = ApiClient(
    baseUrl: 'https://example.test/api',
    tokenStore: InMemoryTokenStore(),
    httpClient: MockClient((request) async {
      if (request.method == 'POST') {
        posts.add(request.url.path);
        onAction?.call(request);
        final response = actionResponse?.call(request);
        if (response != null) return response;
      }
      return http.Response(
        jsonEncode({
          'data':
              request.method == 'POST' &&
                  onAction != null &&
                  !request.url.path.contains('/duty/')
              ? trip
              : request.url.path.endsWith('/trips')
              ? [trip]
              : request.url.path.endsWith('/history')
              ? historyFixture([trip])
              : {
                  'setup_required': false,
                  'on_duty': tripLocationSource != null,
                  'available': tripLocationSource != null,
                  'status': tripLocationSource != null ? 'On Duty' : 'Off Duty',
                  'trip_location_sharing': tripLocationSource != null,
                },
        }),
        200,
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
        home: LinehaulPortal(
          directions: directions,
          locationSource: locationSource ?? const DeviceCourierLocationSource(),
          tripLocationSource:
              tripLocationSource ?? const DeviceCourierLocationSource(),
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

void main() {
  testWidgets(
    'five parcels stay in one scanner session with server-confirmed counts',
    (tester) async {
      final posts = <String>[];
      var failFirstScan = true;
      final items = List.generate(
        5,
        (i) => <String, Object?>{
          'id': i + 1,
          'tracking_number': 'TRK-$i',
          'loaded': false,
          'received': false,
        },
      );
      final trip = {..._trip(status: 'Loading'), 'items': items};
      await _mount(
        tester,
        _Directions(),
        trip,
        posts,
        actionResponse: (request) {
          final code = (jsonDecode(request.body) as Map)['tracking_number'];
          if (code == 'TRK-0' && failFirstScan) {
            failFirstScan = false;
            return http.Response('{"message":"Temporarily unavailable"}', 503);
          }
          final matches = items.where(
            (item) => item['tracking_number'] == code,
          );
          if (matches.isEmpty) {
            return http.Response(
              '{"message":"Parcel is not in this trip."}',
              422,
              headers: {'content-type': 'application/json'},
            );
          }
          matches.single['loaded'] = true;
          return http.Response(
            jsonEncode({'data': trip}),
            200,
            headers: {'content-type': 'application/json'},
          );
        },
      );
      final scan = find.text('Scan or enter parcel');
      expect(scan.hitTestable(), findsOneWidget);
      expect(find.text('Depart'), findsNothing);
      await tester.tap(scan);
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 350));

      Future<void> enter(String code) async {
        await tester.enterText(
          find.byKey(const ValueKey('qr-manual-entry')),
          code,
        );
        await tester.tap(find.byKey(const ValueKey('qr-manual-confirm')));
        await tester.pump();
        await tester.pump(const Duration(milliseconds: 100));
      }

      await enter('');
      expect(posts, isEmpty);
      expect(
        find.textContaining('Enter a valid parcel tracking number.'),
        findsOneWidget,
      );
      await enter('WRONG');
      expect(
        find.textContaining('Parcel is not in this trip.'),
        findsOneWidget,
      );
      expect(find.textContaining('0 of 5 parcels confirmed'), findsOneWidget);
      await enter('TRK-0');
      expect(find.textContaining('0 of 5 parcels confirmed'), findsOneWidget);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await tester.pump();
      await tester.tap(find.text('Retry scan'));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 100));
      expect(find.textContaining('1 of 5 parcels confirmed'), findsOneWidget);
      expect(find.text('Retry scan'), findsNothing);
      for (var i = 1; i < 5; i++) {
        await enter('TRK-$i');
        expect(
          find.textContaining('${i + 1} of 5 parcels confirmed'),
          findsOneWidget,
        );
      }
      await enter('TRK-4');
      expect(find.textContaining('Parcel already scanned.'), findsOneWidget);
      expect(posts, hasLength(7));
      await tester.tap(find.byKey(const ValueKey('qr-close')));
      await tester.pumpAndSettle();
      expect(find.text('Depart').hitTestable(), findsOneWidget);
    },
  );

  testWidgets('explicit trip sharing sends a scoped fix while paused', (
    tester,
  ) async {
    final location = _TripLocation();
    addTearDown(() => unawaited(location.points.close()));
    final scopedReports = <Object?>[];
    await _mount(
      tester,
      _Directions(),
      {..._trip(), 'offer_version': 2},
      <String>[],
      locationSource: _ActionLocation(),
      tripLocationSource: location,
      onAction: (request) {
        final payload = jsonDecode(request.body) as Map;
        if (payload.containsKey('trip_id')) scopedReports.add(payload);
      },
    );
    expect(location.points.hasListener, isFalse);
    final share = find.text('Share trip location');
    await tester.scrollUntilVisible(
      share,
      150,
      scrollable: find.byType(Scrollable).first,
    );
    await tester.drag(find.byType(ListView).first, const Offset(0, -120));
    await tester.pumpAndSettle();
    await tester.tap(share);
    await tester.pump();
    expect(location.points.hasListener, isTrue);

    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.paused);
    location.points.add(const CourierPosition(14.5, 121.3, 20));
    await tester.pump();
    expect(scopedReports.single, {
      'trip_id': '12',
      'offer_version': 2,
      'latitude': 14.5,
      'longitude': 121.3,
      'accuracy': 20,
    });
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pumpAndSettle();
    await tester.pumpWidget(const SizedBox.shrink());
    expect(location.points.hasListener, isFalse);
  });

  testWidgets(
    'configured arrival reads a fresh position and submits it with the action',
    (tester) async {
      final posts = <String>[];
      final location = _ActionLocation();
      final trip = {..._trip(), 'arrival_location': 'required'};
      final payloads = <Object?>[];
      await _mount(
        tester,
        _Directions(),
        trip,
        posts,
        locationSource: location,
        onAction: (request) {
          payloads.add(jsonDecode(request.body));
          trip['status'] = 'Arrived';
          trip['arrival_location'] = 'off';
        },
      );
      final button = find.text('Report arrival');
      expect(button.hitTestable(), findsOneWidget);
      await tester.tap(button);
      await tester.pumpAndSettle();
      expect(location.reads, 1);
      expect(posts, ['/api/courier/trips/12/arrive']);
      expect(payloads.single, {
        'location': {'latitude': 14.3, 'longitude': 121.4, 'accuracy': 15},
      });
      expect(find.text('Arrived'), findsOneWidget);
    },
  );

  testWidgets(
    'replacement scans a parcel and continues with the current offer',
    (tester) async {
      final posts = <String>[];
      final payloads = <Object?>[];
      final item = {
        'id': 1,
        'tracking_number': 'TRK-001',
        'loaded': false,
        'received': false,
      };
      final trip = {
        ..._trip(),
        'needs_rescan': true,
        'offer_version': 2,
        'items': [item],
      };
      await _mount(
        tester,
        _Directions(),
        trip,
        posts,
        onAction: (request) {
          payloads.add(jsonDecode(request.body));
          if (request.url.path.endsWith('/takeover-scan')) {
            item['loaded'] = true;
          } else if (request.url.path.endsWith('/takeover-continue')) {
            trip['needs_rescan'] = false;
          }
        },
      );
      Future<void> scrollTo(String label) async {
        await tester.scrollUntilVisible(
          find.text(label),
          160,
          scrollable: find.byType(Scrollable).first,
        );
        await tester.pumpAndSettle();
      }

      expect(find.text('Rescan parcel').hitTestable(), findsOneWidget);
      expect(
        tester
            .widget<FilledButton>(
              find.widgetWithText(FilledButton, 'Continue trip'),
            )
            .onPressed,
        isNull,
      );
      expect(find.text('Report arrival'), findsNothing);
      await tester.tap(find.text('Rescan parcel'));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 350));
      await tester.enterText(
        find.byKey(const ValueKey('qr-manual-entry')),
        'TRK-001',
      );
      await tester.tap(find.byKey(const ValueKey('qr-manual-confirm')));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 100));
      expect(find.textContaining('1 of 1 parcels confirmed'), findsOneWidget);
      await tester.tap(find.byKey(const ValueKey('qr-close')));
      await tester.pumpAndSettle();
      await scrollTo('Continue trip');
      await tester.tap(find.text('Continue trip'));
      await tester.pumpAndSettle();
      await scrollTo('Report arrival');
      expect(posts, [
        '/api/courier/trips/12/takeover-scan',
        '/api/courier/trips/12/takeover-continue',
      ]);
      expect(payloads, [
        {'tracking_number': 'TRK-001', 'method': 'manual', 'offer_version': 2},
        {'offer_version': 2},
      ]);
      expect(tester.takeException(), isNull);
    },
  );

  testWidgets('recent reassignment notice opens History and can be dismissed', (
    tester,
  ) async {
    final posts = <String>[];
    await _mount(tester, _Directions(), {
      ..._trip(status: 'Reassigned'),
      'items': [],
      'reassignment_reason': 'Engine failure',
      'reassigned_at': DateTime.now()
          .toUtc()
          .subtract(const Duration(hours: 2))
          .toIso8601String(),
      'trip_pay': {'status': 'ineligible', 'amount_minor': 0},
    }, posts);

    const notice = 'Trip #12 was reassigned';
    expect(find.text(notice), findsOneWidget);
    expect(find.text('Continue trip'), findsNothing);
    await tester.tap(find.text('View trip history'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Reassigned'));
    await tester.pumpAndSettle();
    expect(
      find.text('Reassigned by Logistics: Engine failure'),
      findsOneWidget,
    );
    await tester.tap(find.text('Trips'));
    await tester.pumpAndSettle();
    await tester.tap(find.byTooltip('Dismiss reassignment notice'));
    await tester.pumpAndSettle();
    await tester.tap(find.byTooltip('Refresh trips'));
    await tester.pumpAndSettle();
    expect(find.text(notice), findsNothing);
    expect(posts, isEmpty);
    expect(tester.takeException(), isNull);
  });

  testWidgets('older reassignment stays in History with no trip actions', (
    tester,
  ) async {
    await _mount(tester, _Directions(), {
      ..._trip(status: 'Reassigned'),
      'items': [],
      'reassignment_reason': 'Engine failure',
      'reassigned_at': DateTime.now()
          .toUtc()
          .subtract(const Duration(days: 3))
          .toIso8601String(),
      'trip_pay': {'status': 'ineligible', 'amount_minor': 0},
    }, []);
    expect(find.text('Trip #12'), findsNothing);
    expect(find.text('Trip #12 was reassigned'), findsNothing);
    await tester.tap(find.text('History'));
    await tester.pumpAndSettle();
    expect(find.text('Reassigned'), findsOneWidget);
    await tester.tap(find.text('Reassigned'));
    await tester.pumpAndSettle();
    await tester.scrollUntilVisible(
      find.text('Reassigned by Logistics: Engine failure'),
      160,
      scrollable: find.byType(Scrollable).first,
    );
    expect(find.text('Reassigned'), findsWidgets);
    expect(find.text('Report arrival'), findsNothing);
    expect(find.text('Continue trip'), findsNothing);
    expect(tester.takeException(), isNull);
  });

  test(
    'trip pay distinguishes unknown, earned and zero without accepting malformed money',
    () {
      expect(
        LinehaulTripPay.fromJson({
          'status': 'earned',
          'amount_minor': 100000,
        }).amountMinor,
        100000,
      );
      expect(
        LinehaulTripPay.fromJson({
          'status': 'not_configured',
          'amount_minor': null,
        }).amountMinor,
        isNull,
      );
      expect(
        LinehaulTripPay.fromJson({
          'status': 'ineligible',
          'amount_minor': 0,
        }).amountMinor,
        0,
      );
      for (final value in [
        {'status': 'earned', 'amount_minor': -1},
        {'status': 'earned', 'amount_minor': 0},
        {'status': 'earned', 'amount_minor': '100000'},
        {'status': 'earned', 'amount_minor': 1000.1},
        {'status': 'not_configured', 'amount_minor': 100000},
        {'status': 'ineligible', 'amount_minor': 100000},
      ]) {
        expect(
          () => LinehaulTripPay.fromJson(value),
          throwsA(isA<MalformedResponseException>()),
        );
      }
    },
  );

  testWidgets(
    'earned trip pay is separate from payout on a small history screen',
    (tester) async {
      final posts = <String>[];
      await _mount(tester, _Directions(), {
        ..._trip(status: 'Completed'),
        'trip_pay': {'status': 'earned', 'amount_minor': 100000},
      }, posts);
      await tester.tap(find.text('History'));
      await tester.pumpAndSettle();
      expect(find.text('Completed'), findsOneWidget);
      await tester.tap(find.text('Trip #12').hitTestable());
      await tester.pumpAndSettle();
      await tester.scrollUntilVisible(
        find.text('Earned trip pay: ₱1,000.00'),
        140,
        scrollable: find.byType(Scrollable).first,
      );
      expect(
        find.textContaining('Payout recording is not available'),
        findsOneWidget,
      );
      expect(posts, isEmpty);
      expect(tester.takeException(), isNull);
    },
  );

  test(
    'trip details accept older APIs and reject invalid weight or unzoned times',
    () {
      expect(LinehaulTrip.fromJson(_trip()).recordedWeightGrams, isNull);
      for (final weight in [0, -1, 3.6, '3600']) {
        expect(
          () => LinehaulTrip.fromJson({
            ..._trip(),
            'recorded_weight_grams': weight,
          }),
          throwsA(isA<MalformedResponseException>()),
        );
      }
      expect(
        () => LinehaulTrip.fromJson({
          ..._trip(),
          'departed_at': '2026-11-08 12:00:00',
        }),
        throwsA(isA<MalformedResponseException>()),
      );
      final trip = LinehaulTrip.fromJson({
        ..._trip(),
        'recorded_weight_grams': 3600,
        'departed_at': '2026-11-08T12:00:00+08:00',
      });
      expect(trip.recordedWeightGrams, 3600);
      expect(trip.departedAt!.toUtc(), DateTime.utc(2026, 11, 8, 4));
    },
  );

  testWidgets(
    'recorded trip details fit small screens without inventing schedule or pay',
    (tester) async {
      final posts = <String>[];
      await _mount(tester, _Directions(), {
        ..._trip(),
        'parcel_count': 1,
        'recorded_weight_grams': 3600,
        'accepted_at': '2026-11-08T03:00:00Z',
        'departed_at': '2026-11-08T04:00:00Z',
        'scheduled_departure_at': '2026-11-08T03:30:00Z',
      }, posts);
      final details = find.text('Trip details');
      await tester.scrollUntilVisible(
        details,
        120,
        scrollable: find.byType(Scrollable).first,
      );
      expect(find.text('Recorded parcel weight: 3.6 kg'), findsNothing);
      await Scrollable.ensureVisible(tester.element(details), alignment: .55);
      await tester.pumpAndSettle();
      expect(details.hitTestable(), findsOneWidget);
      await tester.tap(details.hitTestable());
      await tester.pumpAndSettle();
      await tester.scrollUntilVisible(
        find.text('Recorded parcel weight: 3.6 kg'),
        120,
        scrollable: find.byType(Scrollable).first,
      );
      expect(find.text('1 parcel in this batch'), findsOneWidget);
      expect(find.textContaining('Accepted:'), findsOneWidget);
      expect(find.textContaining('Departed:'), findsOneWidget);
      expect(find.textContaining('Planned departure:'), findsOneWidget);
      expect(find.textContaining('Scheduled departure:'), findsNothing);
      expect(posts, isEmpty);
      expect(tester.takeException(), isNull);
    },
  );

  test(
    'directions encode hub text into a fixed HTTPS Maps host without session data',
    () {
      const address = 'Rizal & Mabini #2, Calamba?next=https://example.test/ñ';
      final uri = DeviceCourierDirections.destinationUri(
        'Laguna Hub',
        address,
      )!;
      expect(uri.scheme, 'https');
      expect(uri.host, 'www.google.com');
      expect(uri.path, '/maps/dir/');
      expect(uri.queryParameters, {
        'api': '1',
        'destination': 'Laguna Hub, $address',
        'travelmode': 'driving',
      });
      expect(uri.userInfo, isEmpty);
      expect(uri.fragment, isEmpty);
      expect(DeviceCourierDirections.destinationUri('Hub', '   '), isNull);
      expect(DeviceCourierDirections.destinationUri('', 'Calamba'), isNull);
      expect(
        DeviceCourierDirections.destinationUri(
          'Hub',
          List.filled(3000, 'x').join(),
        ),
        isNull,
      );
    },
  );

  test(
    'old API without addresses remains readable but malformed address types fail',
    () {
      final legacy = _trip()
        ..remove('origin_address')
        ..remove('destination_address');
      expect(LinehaulTrip.fromJson(legacy).destinationAddress, isNull);
      expect(
        () => LinehaulTrip.fromJson({..._trip(), 'destination_address': 123}),
        throwsA(isA<MalformedResponseException>()),
      );
    },
  );

  testWidgets(
    'Maps uses saved destination; returning preserves the trip with no status POST',
    (tester) async {
      final directions = _Directions();
      final posts = <String>[];
      await _mount(tester, directions, _trip(), posts);
      await tester.scrollUntilVisible(
        find.text('Directions to destination'),
        160,
        scrollable: find.byType(Scrollable).first,
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('Directions to destination'));
      await tester.pumpAndSettle();
      expect(directions.requests, [
        ('Laguna Province Hub', 'Rizal Avenue, Calamba, Laguna'),
      ]);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.paused);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await tester.pumpAndSettle();
      expect(find.text('Trip #12'), findsOneWidget);
      expect(find.text('In Transit'), findsOneWidget);
      expect(posts, isEmpty);
      expect(tester.takeException(), isNull);
    },
  );

  for (final throwsError in [false, true]) {
    testWidgets('map-launch refusal is recoverable; throws=$throwsError', (
      tester,
    ) async {
      final directions = _Directions()
        ..opened = false
        ..fail = throwsError;
      final posts = <String>[];
      await _mount(tester, directions, _trip(), posts);
      await tester.scrollUntilVisible(
        find.text('Directions to destination'),
        160,
        scrollable: find.byType(Scrollable).first,
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('Directions to destination'));
      await tester.pumpAndSettle();
      expect(find.textContaining('Could not open Maps'), findsOneWidget);
      expect(find.text('In Transit'), findsOneWidget);
      expect(posts, isEmpty);
      await tester.pump(const Duration(seconds: 4));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Directions to destination'));
      await tester.pumpAndSettle();
      expect(directions.requests.length, 2);
      await tester.pump(const Duration(seconds: 4));
      await tester.pumpAndSettle();
      expect(tester.takeException(), isNull);
    });
  }

  testWidgets('missing address does not offer directions', (tester) async {
    final directions = _Directions();
    await _mount(tester, directions, _trip(address: null), []);
    await tester.scrollUntilVisible(
      find.textContaining('Destination address unavailable'),
      160,
      scrollable: find.byType(Scrollable).first,
    );
    expect(find.text('Directions to destination'), findsNothing);
    expect(directions.requests, isEmpty);
    expect(tester.takeException(), isNull);
  });

  testWidgets(
    'completed history keeps saved addresses without a directions action',
    (tester) async {
      final directions = _Directions();
      await _mount(tester, directions, _trip(status: 'Completed'), []);
      await tester.tap(find.text('History'));
      await tester.pumpAndSettle();
      expect(find.text('Completed'), findsOneWidget);
      await tester.tap(find.text('Trip #12').hitTestable());
      await tester.pumpAndSettle();
      await tester.scrollUntilVisible(
        find.text('Rizal Avenue, Calamba, Laguna'),
        160,
        scrollable: find.byType(Scrollable).first,
      );
      expect(find.text('Completed'), findsWidgets);
      expect(find.text('Directions to destination'), findsNothing);
      expect(directions.requests, isEmpty);
      expect(tester.takeException(), isNull);
    },
  );
}
