import 'dart:convert';
import 'support/linehaul_history_fixture.dart';
import 'dart:io';
import 'dart:ui' as ui;
import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:zefanya/data/api/api_client.dart';
import 'package:zefanya/data/api/token_store.dart';
import 'package:zefanya/data/repositories/repositories.dart';
import 'package:zefanya/screens/courier/linehaul_portal.dart';
import 'package:zefanya/state/auth_state.dart';
import 'package:zefanya/theme/app_theme.dart';
import 'package:zefanya/widgets/floating_nav_bar.dart';

const _capture = bool.fromEnvironment('CAPTURE_COURIER_DESIGN');
final _boundary = GlobalKey();

Future<void> _picture(WidgetTester tester, String name) async {
  if (!_capture) return;
  await tester.runAsync(() async {
    final boundary =
        _boundary.currentContext!.findRenderObject()! as RenderRepaintBoundary;
    final image = await boundary.toImage(pixelRatio: 2);
    final data = await image.toByteData(format: ui.ImageByteFormat.png);
    final file = File('build/courier-design/$name.png');
    await file.create(recursive: true);
    await file.writeAsBytes(data!.buffer.asUint8List());
    image.dispose();
  });
}

Future<void> _mount(
  WidgetTester tester,
  Brightness brightness,
  Widget screen, {
  bool empty = false,
  bool Function()? unavailable,
  Map<String, Object?>? activeTrip,
  Map<String, Object?>? earnings,
  Size size = const Size(360, 640),
  double textScale = 1.3,
  EdgeInsets safeArea = EdgeInsets.zero,
}) async {
  AppColors.apply(brightness);
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  addTearDown(() => AppColors.apply(Brightness.light));
  final client = ApiClient(
    baseUrl: 'https://example.test/api',
    tokenStore: InMemoryTokenStore(),
    httpClient: MockClient(
      (request) async => unavailable?.call() == true
          ? http.Response(
              '{"message":"Connection interrupted"}',
              503,
              headers: {'content-type': 'application/json'},
            )
          : http.Response(
              jsonEncode({
                'data': request.url.path.endsWith('/duty')
                    ? {
                        'setup_required': false,
                        'on_duty': false,
                        'available': false,
                        'status': 'Off Duty',
                      }
                    : request.url.path.endsWith('/earnings')
                    ? earnings ??
                          {
                            'page': 1,
                            'last_page': 1,
                            'timezone': 'Asia/Manila',
                            'earned_minor': 0,
                            'earned_trips': 0,
                            'completed_trips': 1,
                            'unconfirmed_trips': 1,
                            'trips': [],
                          }
                    : request.url.path.endsWith('/history')
                    ? historyFixture(
                        empty
                            ? []
                            : [
                                {
                                  'id': 11,
                                  'status': 'Completed',
                                  'origin': 'Lumban Station Hub',
                                  'destination': 'Pila Station Hub',
                                  'items': [
                                    {
                                      'tracking_number': 'TRK-DEMO-002',
                                      'loaded': true,
                                      'received': true,
                                    },
                                  ],
                                },
                              ],
                      )
                    : empty
                    ? []
                    : [
                        activeTrip ??
                            {
                              'id': 12,
                              'status': 'Assigned',
                              'origin': 'Pila Station Hub',
                              'destination': 'Laguna Province Hub',
                              'items': [
                                {
                                  'id': 1,
                                  'tracking_number': 'TRK-DEMO-001',
                                  'loaded': false,
                                  'received': false,
                                },
                              ],
                            },
                        {
                          'id': 11,
                          'status': 'Completed',
                          'origin': 'Lumban Station Hub',
                          'destination': 'Pila Station Hub',
                          'items': [
                            {
                              'id': 2,
                              'tracking_number': 'TRK-DEMO-002',
                              'loaded': true,
                              'received': true,
                            },
                          ],
                        },
                      ],
              }),
              200,
              headers: {'content-type': 'application/json'},
            ),
    ),
  );
  final auth = AuthState()
    ..name = 'Demo Pila Courier'
    ..email = 'courier@example.test';
  await tester.pumpWidget(
    MultiProvider(
      providers: [
        Provider<Repositories>.value(value: Repositories.live(client)),
        ChangeNotifierProvider<AuthState>.value(value: auth),
      ],
      child: MaterialApp(
        theme: brightness == Brightness.dark ? AppTheme.dark : AppTheme.light,
        builder: (context, child) => MediaQuery(
          data: MediaQuery.of(context).copyWith(
            textScaler: TextScaler.linear(textScale),
            padding: safeArea,
            viewPadding: safeArea,
          ),
          child: RepaintBoundary(
            key: _boundary,
            child: ColoredBox(color: AppColors.background, child: child!),
          ),
        ),
        home: screen,
      ),
    ),
  );
  await tester.pumpAndSettle();
}

Map<String, Object?> _transportTrip(String status, {bool takeover = false}) {
  final ready = const ['In Transit', 'Arrived'].contains(status) && !takeover;
  return {
    'id': 12,
    'status': status == 'Ready' ? 'Loading' : status,
    'origin': 'Pila Regional Consolidation Hub',
    'destination': 'Laguna North Distribution and Receiving Hub',
    'destination_address':
        'Building 3, Receiving Bay 17, Mabini Avenue, Calamba City, Laguna',
    'scheduled_departure_at': '2026-11-08T03:30:00Z',
    'offer_version': 3,
    'needs_rescan': takeover,
    if (takeover) 'reassignment_reason': 'Original vehicle interrupted',
    'can_report_breakdown': status == 'In Transit' && !takeover,
    'items': [
      for (var i = 0; i < 112; i++)
        {
          'id': i + 1,
          'tracking_number': 'ZEF-TRANSPORT-${i + 1}',
          'loaded': ready || status == 'Ready' || i == 0,
          'received': false,
        },
    ],
  };
}

void main() {
  setUp(() => SharedPreferences.setMockInitialValues({}));
  setUpAll(() async {
    for (final family in [kDisplayFontFamily, kBodyFontFamily]) {
      final filename = family == kDisplayFontFamily
          ? 'PlayfairDisplay'
          : 'Montserrat';
      final loader = FontLoader(family);
      for (final weight in ['Regular', 'Medium', 'SemiBold', 'Bold']) {
        loader.addFont(rootBundle.load('assets/fonts/$filename-$weight.ttf'));
      }
      await loader.load();
    }
    await (FontLoader(
      'MaterialIcons',
    )..addFont(rootBundle.load('fonts/MaterialIcons-Regular.otf'))).load();
  });
  testWidgets('account navigation keeps background trip errors observed', (
    tester,
  ) async {
    var unavailable = false;
    await _mount(
      tester,
      Brightness.light,
      const LinehaulPortal(),
      unavailable: () => unavailable,
    );
    await tester.tap(find.text('Profile'));
    await tester.pumpAndSettle();
    unavailable = true;
    await tester.tap(find.text('Trips'));
    await tester.pumpAndSettle();
    await tester.tap(find.byTooltip('Refresh trips'));
    await tester.tap(find.text('Profile'));
    await tester.pumpAndSettle();
    expect(find.text('Demo Pila Courier'), findsOneWidget);
    expect(tester.takeException(), isNull);
    await tester.tap(find.text('Trips'));
    await tester.pumpAndSettle();
    expect(find.text('Retry'), findsOneWidget);
    expect(find.text('Accept trip'), findsNothing);
    expect(tester.takeException(), isNull);
  });
  testWidgets('a failed refresh keeps the loaded earnings visibly stale', (
    tester,
  ) async {
    var unavailable = false;
    await _mount(
      tester,
      Brightness.light,
      const LinehaulPortal(),
      unavailable: () => unavailable,
    );
    await tester.tap(find.text('Earnings'));
    await tester.pumpAndSettle();
    expect(find.text('Awaiting confirmation'), findsOneWidget);
    expect(find.text('₱0.00'), findsNothing);

    unavailable = true;
    await tester.tap(find.text('Profile'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Earnings'));
    await tester.pumpAndSettle();
    expect(
      find.text('Showing the last loaded earnings for these dates.'),
      findsOneWidget,
    );
    expect(find.text('Awaiting confirmation'), findsOneWidget);

    unavailable = false;
    await tester.ensureVisible(find.text('Retry'));
    await tester.tap(find.text('Retry'));
    await tester.pumpAndSettle();
    expect(find.text('Retry'), findsNothing);
    expect(find.text('Awaiting confirmation'), findsOneWidget);
  });
  for (final brightness in Brightness.values) {
    for (final (name, status, control) in [
      ('assigned', 'Assigned', 'Accept trip'),
      ('loading', 'Loading', 'Scan or enter parcel'),
      ('scanner', 'Loading', 'Scan or enter parcel'),
      ('departure', 'Ready', 'Depart'),
      ('transit', 'In Transit', 'Report arrival'),
      ('breakdown', 'In Transit', 'Report breakdown'),
      ('arrival', 'Arrived', 'Arrived — Awaiting Logistics Receipt'),
      ('takeover', 'In Transit', 'Rescan parcel'),
    ]) {
      for (final (size, scale) in [
        (const Size(360, 740), 1.0),
        (const Size(360, 640), 1.3),
        (const Size(411, 890), 2.0),
      ]) {
        testWidgets('Transport Phase 4 $name $size ${brightness.name}', (
          tester,
        ) async {
          await _mount(
            tester,
            brightness,
            const LinehaulPortal(),
            activeTrip: _transportTrip(status, takeover: name == 'takeover'),
            size: size,
            textScale: scale,
            safeArea: const EdgeInsets.only(top: 24, bottom: 16),
          );
          final next = find.text(control);
          expect(next, findsOneWidget);
          if (name == 'assigned' || name == 'loading' || name == 'takeover') {
            expect(next.hitTestable(), findsOneWidget);
          }
          if (name == 'arrival') {
            expect(find.text('Completed'), findsNothing);
            expect(find.text('Report arrival'), findsNothing);
            expect(find.text('0 of 112 parcels received'), findsOneWidget);
          }
          if (['scanner', 'departure', 'breakdown'].contains(name)) {
            await tester.ensureVisible(next);
            await tester.tap(next);
            await tester.pump();
            await tester.pump(const Duration(milliseconds: 450));
            if (name == 'scanner') {
              expect(find.text('Trip #12 · Load parcels'), findsOneWidget);
              expect(find.text('111 remaining'), findsOneWidget);
              final manual = find.byKey(const ValueKey('qr-manual-entry'));
              await tester.ensureVisible(manual);
              expect(manual.hitTestable(), findsOneWidget);
              if (scale > 1) {
                tester.view.viewInsets = const FakeViewPadding(bottom: 270);
                await tester.enterText(manual, 'ZEF-TRANSPORT-2');
                await tester.pump(const Duration(milliseconds: 400));
              }
            }
            if (name == 'departure') {
              expect(find.text('Confirm departure'), findsOneWidget);
              expect(
                find.text('Trip #12 · 112 parcels loaded'),
                findsOneWidget,
              );
              if (scale > 1) {
                tester.view.viewInsets = const FakeViewPadding(bottom: 270);
                await tester.enterText(find.byType(TextField), 'BH-1234567');
                await tester.pump(const Duration(milliseconds: 400));
              }
            }
          }
          expect(tester.takeException(), isNull);
          if (scale == 1) await _picture(tester, 'p4-$name-${brightness.name}');
        });
      }
    }
    for (final (size, scale) in [
      (const Size(360, 740), 1.0),
      (const Size(360, 640), 2.0),
      (const Size(411, 890), 1.3),
    ]) {
      testWidgets('Transport Phase 4 records $size ${brightness.name}', (
        tester,
      ) async {
        await _mount(
          tester,
          brightness,
          const LinehaulPortal(),
          size: size,
          textScale: scale,
          safeArea: const EdgeInsets.only(top: 30, bottom: 22),
          earnings: {
            'page': 1,
            'last_page': 2,
            'timezone': 'Asia/Manila',
            'earned_minor': 987654321,
            'earned_trips': 2,
            'completed_trips': 3,
            'unconfirmed_trips': 1,
            'payout_recording_available': true,
            'trips': [
              {
                'trip_id': 11,
                'completed_at': '2026-11-08T05:00:00Z',
                'result': 'Completed',
                'trip_pay': {'status': 'earned', 'amount_minor': 987654321},
                'paid_on': '2026-11-09',
              },
            ],
          },
        );
        for (final (tab, label) in [
          ('History', 'Completed'),
          ('Earnings', '₱9,876,543.21'),
          ('Profile', 'Go On Duty'),
        ]) {
          final destination = find.text(tab);
          expect(destination.hitTestable(), findsOneWidget);
          await tester.tap(destination);
          await tester.pumpAndSettle();
          expect(find.text(label), findsOneWidget);
          expect(tester.takeException(), isNull);
          if (scale == 1) {
            await _picture(
              tester,
              'p4-${tab.toLowerCase()}-${brightness.name}',
            );
          }
        }
      });
    }
    testWidgets(
      'Courier navigation and server history fit ${brightness.name} at 360 and 1.3 text',
      (tester) async {
        await _mount(tester, brightness, const LinehaulPortal());
        expect(find.byType(FloatingNavBar), findsOneWidget);
        expect(find.text('Your journeys'), findsOneWidget);
        expect(find.text('Trip #12'), findsOneWidget);
        expect(find.text('Trip #11'), findsNothing);
        expect(tester.takeException(), isNull);
        await _picture(tester, 'trips-${brightness.name}');
        await tester.tap(find.text('History'));
        await tester.pumpAndSettle();
        expect(find.text('Trip #11'), findsOneWidget);
        expect(find.text('Trip #12'), findsNothing);
        expect(find.text('Completed'), findsOneWidget);
        expect(tester.takeException(), isNull);
        await _picture(tester, 'history-${brightness.name}');
        expect(tester.getSize(find.text('Earnings')).height, lessThan(25));
        await tester.tap(find.text('Earnings'));
        await tester.pumpAndSettle();
        expect(tester.getSize(find.text('Earnings')).height, lessThan(25));
        expect(find.text('Confirmed earned pay'), findsOneWidget);
        expect(tester.takeException(), isNull);
        await _picture(tester, 'earnings-${brightness.name}');
        await tester.tap(find.text('Profile'));
        await tester.pumpAndSettle();
        expect(find.text('Demo Pila Courier'), findsOneWidget);
        expect(find.text('courier@example.test'), findsOneWidget);
        expect(find.byTooltip('Settings'), findsOneWidget);
        expect(find.text('Log out'), findsNothing);
        expect(tester.takeException(), isNull);
        await _picture(tester, 'account-${brightness.name}');
      },
    );
    testWidgets('branded Courier login remains usable in ${brightness.name}', (
      tester,
    ) async {
      await _mount(tester, brightness, const LinehaulLoginScreen());
      expect(find.text('COURIER PORTAL'), findsOneWidget);
      await tester.ensureVisible(find.text('Login as Courier'));
      await tester.pumpAndSettle();
      expect(find.byType(TextField), findsNWidgets(2));
      expect(tester.takeException(), isNull);
      await tester.drag(find.byType(Scrollable).first, const Offset(0, 800));
      await tester.pumpAndSettle();
      await _picture(tester, 'login-${brightness.name}');
    });
    testWidgets('branded empty Courier state fits ${brightness.name}', (
      tester,
    ) async {
      await _mount(tester, brightness, const LinehaulPortal(), empty: true);
      expect(find.text('No trips assigned'), findsOneWidget);
      expect(tester.takeException(), isNull);
      await _picture(tester, 'empty-${brightness.name}');
    });
  }
}
