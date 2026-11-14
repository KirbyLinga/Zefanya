// test/widget_test.dart
//
// App-boot smoke test.
//
// This replaces the Flutter counter template that shipped here: it
// asserted `find.text('0')` and tapped `Icons.add` against an app that has
// never had a counter, so it failed on every run and made `flutter test`
// useless — a real regression was indistinguishable from standing noise.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:zefanya/main.dart';
import 'package:zefanya/state/auth_state.dart';
import 'package:zefanya/state/cart_state.dart';
import 'package:zefanya/state/chat_state.dart';
import 'package:zefanya/state/courier_state.dart';
import 'package:zefanya/state/notification_state.dart';
import 'package:zefanya/state/order_state.dart';
import 'package:zefanya/state/recently_viewed_state.dart';
import 'package:zefanya/state/theme_state.dart';
import 'package:zefanya/state/wishlist_state.dart';

Widget _bootApp() {
  return MultiProvider(
    providers: [
      ChangeNotifierProvider(create: (_) => AuthState()),
      ChangeNotifierProvider(create: (_) => CartState()),
      ChangeNotifierProvider(create: (_) => ChatState()),
      ChangeNotifierProvider(create: (_) => CourierState()),
      ChangeNotifierProvider(create: (_) => NotificationState()),
      ChangeNotifierProvider(create: (_) => OrderState()),
      ChangeNotifierProvider(create: (_) => RecentlyViewedState()),
      ChangeNotifierProvider(create: (_) => WishlistState()),
      ChangeNotifierProvider(create: (_) => ThemeState()),
    ],
    child: const MarketplaceApp(),
  );
}

void main() {
  setUp(() {
    SharedPreferences.setMockInitialValues({});
    // Tests have no network: google_fonts has to fall back rather than try
    // to fetch Playfair/Montserrat over HTTP.
    GoogleFonts.config.allowRuntimeFetching = false;
  });

  testWidgets('a signed-out cold start boots to the landing screen',
      (tester) async {
    await tester.pumpWidget(_bootApp());
    await tester.pump();

    expect(find.text('BUY. SELL. DELIVER.'), findsOneWidget);
    await tester.pump(const Duration(milliseconds: 3200));
    expect(find.text('BEGIN'), findsOneWidget);

    // Park off-stage: the rail's 6s loop is cancelled, clean teardown.
    await tester.pumpWidget(const SizedBox.shrink());
    await tester.pump(const Duration(milliseconds: 100));
  });
}
