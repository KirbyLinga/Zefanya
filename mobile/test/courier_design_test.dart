import 'dart:convert';
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
}) async {
  AppColors.apply(brightness);
  tester.view.physicalSize = const Size(360, 640);
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
                'data': empty
                    ? []
                    : [
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
        home: MediaQuery(
          data: const MediaQueryData(textScaler: TextScaler.linear(1.3)),
          child: RepaintBoundary(
            key: _boundary,
            child: ColoredBox(color: AppColors.background, child: screen),
          ),
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
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
    await tester.tap(find.text('Account'));
    await tester.pumpAndSettle();
    unavailable = true;
    await tester.tap(find.byTooltip('Refresh trips'));
    await tester.pumpAndSettle();
    expect(find.text('Demo Pila Courier'), findsOneWidget);
    expect(tester.takeException(), isNull);
    await tester.tap(find.text('Trips'));
    await tester.pumpAndSettle();
    expect(find.text('Retry'), findsOneWidget);
    expect(find.text('Accept trip'), findsNothing);
    expect(tester.takeException(), isNull);
  });
  for (final brightness in Brightness.values) {
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
        await tester.tap(find.text('Account'));
        await tester.pumpAndSettle();
        expect(find.text('Demo Pila Courier'), findsOneWidget);
        expect(find.text('courier@example.test'), findsOneWidget);
        expect(find.text('Log out'), findsOneWidget);
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
      await tester.drag(find.byType(ListView), const Offset(0, 800));
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
