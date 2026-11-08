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
import 'package:zefanya/services/courier_directions.dart';
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

Future<void> _mount(
  WidgetTester tester,
  _Directions directions,
  Map<String, Object?> trip,
  List<String> posts,
) async {
  SharedPreferences.setMockInitialValues({});
  tester.view.physicalSize = const Size(360, 640);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  final client = ApiClient(
    baseUrl: 'https://example.test/api',
    tokenStore: InMemoryTokenStore(),
    httpClient: MockClient((request) async {
      if (request.method == 'POST') posts.add(request.url.path);
      return http.Response(
        jsonEncode({
          'data': request.url.path.endsWith('/trips')
              ? [trip]
              : {
                  'setup_required': false,
                  'on_duty': false,
                  'available': false,
                  'status': 'Off Duty',
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
        home: LinehaulPortal(directions: directions),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

void main() {
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
      await tester.scrollUntilVisible(
        find.text('Rizal Avenue, Calamba, Laguna'),
        160,
        scrollable: find.byType(Scrollable).first,
      );
      expect(find.text('Completed'), findsOneWidget);
      expect(find.text('Directions to destination'), findsNothing);
      expect(directions.requests, isEmpty);
      expect(tester.takeException(), isNull);
    },
  );
}
