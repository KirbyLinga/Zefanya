import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:zefanya/data/api/api_client.dart';
import 'package:zefanya/data/api/token_store.dart';
import 'package:zefanya/data/repositories/repositories.dart';
import 'package:zefanya/screens/courier/linehaul_portal.dart';
import 'package:zefanya/services/courier_location_service.dart';
import 'package:zefanya/state/auth_state.dart';

class _Location implements CourierLocationSource {
  bool denied = false;
  int requests = 0;
  @override
  Future<CourierPosition> current({bool requestPermission = true}) async {
    if (requestPermission) requests++;
    if (denied) {
      throw const CourierLocationException(
        'Location access is needed for Courier duty.',
      );
    }
    return const CourierPosition(14.2, 121.3, 10);
  }
}

class _Backend {
  bool on = false;
  bool available = false;
  bool? passed;
  final posts = <String>[];
  Map<String, Object?>? checklist;
  Future<http.Response> handle(http.Request request) async {
    if (request.method == 'POST') {
      posts.add(request.url.path);
      if (request.url.path.endsWith('/on')) {
        final body = jsonDecode(request.body) as Map<String, dynamic>;
        checklist = Map<String, Object?>.from(body['checklist'] as Map);
        passed = checklist!.values.every((value) => value == true);
        on = passed!;
        available = on;
      } else if (request.url.path.endsWith('/off')) {
        on = available = false;
      } else if (request.url.path.endsWith('/heartbeat')) {
        available = on;
      }
    }
    return http.Response(
      jsonEncode({
        'data': request.url.path.endsWith('/trips')
            ? []
            : {
                'setup_required': false,
                'on_duty': on,
                'available': available,
                'status': !on
                    ? 'Off Duty'
                    : available
                    ? 'On Duty'
                    : 'Location unknown',
                'last_check_passed': passed,
              },
      }),
      200,
      headers: {'content-type': 'application/json'},
    );
  }
}

Future<void> _mount(
  WidgetTester tester,
  _Backend backend,
  _Location location,
) async {
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
        home: LinehaulPortal(locationSource: location),
      ),
    ),
  );
  await tester.pumpAndSettle();
  await tester.tap(find.text('Account'));
  await tester.pumpAndSettle();
}

Future<void> _tap(WidgetTester tester, String text) async {
  await tester.ensureVisible(find.text(text).last);
  await tester.pumpAndSettle();
  await tester.tap(find.text(text).last);
  await tester.pumpAndSettle();
}

Future<void> _answer(WidgetTester tester, {bool failed = false}) async {
  for (var index = 0; index < 5; index++) {
    final field = find.byType(DropdownButtonFormField<bool>).at(index);
    await tester.ensureVisible(field);
    await tester.pumpAndSettle();
    await tester.tap(field);
    await tester.pumpAndSettle();
    await tester.tap(find.text(failed && index == 2 ? 'Not OK' : 'OK').last);
    await tester.pumpAndSettle();
  }
}

void main() {
  testWidgets(
    'permission denial blocks duty without a request and offers settings',
    (tester) async {
      final backend = _Backend();
      final location = _Location()..denied = true;
      await _mount(tester, backend, location);
      expect(location.requests, 0);
      await _tap(tester, 'Go On Duty');
      expect(backend.posts, isEmpty);
      expect(
        find.text('Location access is needed for Courier duty.'),
        findsOneWidget,
      );
      expect(find.text('Open location settings'), findsOneWidget);
      expect(tester.takeException(), isNull);
      await tester.pumpWidget(const SizedBox.shrink());
    },
  );

  testWidgets(
    'all five answers required; a failed item needs a note and stays Off Duty',
    (tester) async {
      final backend = _Backend();
      final location = _Location();
      await _mount(tester, backend, location);
      await _tap(tester, 'Go On Duty');
      await _tap(tester, 'Submit checklist');
      expect(find.text('Answer this item.'), findsNWidgets(5));
      expect(backend.posts, isEmpty);
      await _answer(tester, failed: true);
      await _tap(tester, 'Submit checklist');
      expect(find.text('Explain the Not OK items.'), findsOneWidget);
      await tester.enterText(
        find.byType(TextFormField).last,
        'Brake pedal feels soft.',
      );
      await _tap(tester, 'Submit checklist');
      expect(backend.checklist!['brakes'], false);
      expect(backend.on, false);
      expect(
        find.text(
          'Checklist not passed. You remain Off Duty; Logistics has been notified.',
        ),
        findsOneWidget,
      );
      expect(tester.takeException(), isNull);
      await tester.pumpWidget(const SizedBox.shrink());
    },
  );

  testWidgets(
    'passed checklist starts duty and location reporting pauses in background',
    (tester) async {
      final backend = _Backend();
      final location = _Location();
      await _mount(tester, backend, location);
      await _tap(tester, 'Go On Duty');
      await _answer(tester);
      await _tap(tester, 'Submit checklist');
      expect(backend.on, true);
      expect(find.text('Go Off Duty'), findsOneWidget);
      final before = backend.posts.length;
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.paused);
      await tester.pump(const Duration(minutes: 6));
      expect(backend.posts.length, before);
      backend.available = false;
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await tester.pumpAndSettle();
      expect(backend.posts.last, '/api/courier/duty/heartbeat');
      expect(location.requests, 1);
      await _tap(tester, 'Go Off Duty');
      expect(backend.on, false);
      expect(tester.takeException(), isNull);
      await tester.pumpWidget(const SizedBox.shrink());
    },
  );
}
