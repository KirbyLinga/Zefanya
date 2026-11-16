// test/webzefanya_ui_gating_test.dart
//
// What a Rider sees in WebZefanya mode: return pickups read from the jobs
// response (read-only), and the screens for features WebZefanya lacks (COD
// ledger, payouts) replaced by a pointer to the web portal instead of an
// error. WebZefanya itself is never contacted: the transport is in memory.

import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:provider/provider.dart';
import 'package:zefanya/data/api/token_store.dart';
import 'package:zefanya/data/api/webzefanya_contract.dart';
import 'package:zefanya/data/repositories/repositories.dart';
import 'package:zefanya/screens/courier/courier_profit_screen.dart';
import 'package:zefanya/screens/courier/pickup_dashboard_screen.dart';
import 'package:zefanya/state/auth_state.dart';
import 'package:zefanya/state/courier_state.dart';
import 'package:zefanya/state/order_state.dart';
import 'package:zefanya/state/recently_viewed_state.dart';
import 'package:zefanya/state/theme_state.dart';
import 'package:zefanya/state/wishlist_state.dart';
import 'package:zefanya/theme/app_theme.dart';

Future<CourierState> _webzefanyaCourier(List<String> requests) async {
  final client = MockClient.streaming((request, stream) async {
    await stream.toBytes();
    requests.add('${request.method} ${request.url.path}');
    return http.StreamedResponse(
      Stream.value(
        utf8.encode(
          jsonEncode({
            'success': true,
            'data': {
              'online': true,
              'winding_down': false,
              'pickups': <Object?>[],
              'deliveries': <Object?>[],
              'return_pickups': [
                {
                  'tracking_number': 'RET-555',
                  'status_label': 'Assigned return pickup',
                },
              ],
            },
          }),
        ),
      ),
      200,
      headers: {'content-type': 'application/json; charset=utf-8'},
    );
  });
  final repos = Repositories.webzefanya(
    guardedWebZefanyaClient(
      baseUrl: 'https://wz.test/api',
      tokenStore: InMemoryTokenStore()..write('token'),
      httpClient: client,
    ),
  );
  final state = CourierState(
    repository: repos.courier,
    capabilities: repos.capabilities,
  );
  await state.refreshJobs();
  return state;
}

Widget _app(CourierState courier, Widget home) => MultiProvider(
  providers: [
    ChangeNotifierProvider<AuthState>(create: (_) => AuthState()),
    ChangeNotifierProvider<CourierState>.value(value: courier),
    ChangeNotifierProvider(create: (_) => OrderState()),
    ChangeNotifierProvider(create: (_) => RecentlyViewedState()),
    ChangeNotifierProvider(create: (_) => WishlistState()),
    ChangeNotifierProvider(create: (_) => ThemeState()),
  ],
  child: MaterialApp(theme: AppTheme.light, home: home),
);

void _phone(WidgetTester tester) {
  AppColors.apply(Brightness.light);
  GoogleFonts.config.allowRuntimeFetching = false;
  tester.view.physicalSize = const Size(390, 1600);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);
}

void main() {
  testWidgets('return pickups appear read-only with no collect action', (
    tester,
  ) async {
    _phone(tester);
    final requests = <String>[];
    final courier = await tester.runAsync(() => _webzefanyaCourier(requests));

    await tester.pumpWidget(_app(courier!, const PickupDashboardScreen()));
    await tester.pump(const Duration(milliseconds: 800));

    expect(find.text('RETURN ASSIGNMENTS'), findsOneWidget);
    expect(find.byKey(const ValueKey('return-pickup-RET-555')), findsOneWidget);
    expect(find.text('RET-555'), findsOneWidget);
    expect(find.text('Assigned return pickup'), findsOneWidget);
    expect(
      find.text('View this return in the Rider web portal.'),
      findsOneWidget,
    );
    expect(find.text('0 items'), findsNothing);
    expect(
      find.byKey(const ValueKey('return-web-portal-RET-555')),
      findsOneWidget,
    );
    expect(find.byKey(const ValueKey('collect-return-RET-555')), findsNothing);
    // Only the jobs read ever left the device for returns.
    expect(requests.where((r) => r.contains('return-pickups')), isEmpty);
  });

  testWidgets('COD and payouts show a web-portal pointer, not an error', (
    tester,
  ) async {
    _phone(tester);
    final courier = await tester.runAsync(() => _webzefanyaCourier([]));

    await tester.pumpWidget(_app(courier!, const CourierProfitScreen()));
    await tester.pump(const Duration(milliseconds: 800));

    expect(find.byKey(const ValueKey('rider-cod-unavailable')), findsOneWidget);
    expect(find.byKey(const ValueKey('rider-payouts-card')), findsNothing);
    expect(courier.cashFailure, isNull);
    expect(courier.payoutsFailure, isNull);
  });
}
