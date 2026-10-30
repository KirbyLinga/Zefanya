// test/phase_e_remaining_screens_test.dart
//
// Phase E: Orders, Rider Tracker, Checkout, and Profile screen polish assertions.
// Verifies no overflow at 1.3x text scale in light and dark themes.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:zefanya/data/mock_data.dart';
import 'package:zefanya/data/repositories/repositories.dart';
import 'package:zefanya/screens/buyer/checkout_screen.dart';
import 'package:zefanya/screens/buyer/courier_tracker_screen.dart';
import 'package:zefanya/screens/buyer/orders_status_screen.dart';
import 'package:zefanya/screens/shared/profile_screen.dart';
import 'package:zefanya/state/auth_state.dart';
import 'package:zefanya/state/cart_state.dart';
import 'package:zefanya/state/catalog_state.dart';
import 'package:zefanya/state/courier_state.dart';
import 'package:zefanya/state/notification_state.dart';
import 'package:zefanya/state/order_state.dart';
import 'package:zefanya/state/recently_viewed_state.dart';
import 'package:zefanya/state/review_state.dart';
import 'package:zefanya/state/theme_state.dart';
import 'package:zefanya/state/wishlist_state.dart';
import 'package:zefanya/theme/app_theme.dart';

Widget _buildHarness({
  required Widget child,
  Brightness brightness = Brightness.light,
  double textScaleFactor = 1.0,
}) {
  return MediaQuery(
    data: MediaQueryData(
      disableAnimations: true,
      textScaler: TextScaler.linear(textScaleFactor),
    ),
    child: MultiProvider(
      providers: [
        Provider<Repositories>.value(value: Repositories.mock()),
        ChangeNotifierProvider(create: (_) => AuthState()),
        ChangeNotifierProvider(create: (_) => CartState()),
        ChangeNotifierProvider(create: (_) => CatalogState()),
        ChangeNotifierProvider(create: (_) => CourierState()),
        ChangeNotifierProvider(create: (_) => NotificationState()),
        ChangeNotifierProvider(create: (_) => OrderState()),
        ChangeNotifierProvider(create: (_) => RecentlyViewedState()),
        ChangeNotifierProvider(create: (_) => ReviewState()),
        ChangeNotifierProvider(create: (_) => WishlistState()),
        ChangeNotifierProvider(create: (_) => ThemeState()),
      ],
      child: MaterialApp(
        theme: brightness == Brightness.light ? AppTheme.light : AppTheme.dark,
        home: child,
      ),
    ),
  );
}

void main() {
  setUp(() {
    SharedPreferences.setMockInitialValues({});
    GoogleFonts.config.allowRuntimeFetching = false;
  });

  // ----------------------------------------------------------------
  // Orders screen
  // ----------------------------------------------------------------
  testWidgets('Phase E: OrdersStatusScreen no overflow at 1.3x light', (tester) async {
    tester.view.physicalSize = const Size(390, 900);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(() { tester.view.resetPhysicalSize(); tester.view.resetDevicePixelRatio(); });

    AppColors.apply(Brightness.light);
    await tester.pumpWidget(_buildHarness(
      child: const OrdersStatusScreen(),
      brightness: Brightness.light,
      textScaleFactor: 1.3,
    ));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 600));
    expect(tester.takeException(), isNull);
  });

  testWidgets('Phase E: OrdersStatusScreen no overflow at 1.3x dark', (tester) async {
    tester.view.physicalSize = const Size(390, 900);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(() { tester.view.resetPhysicalSize(); tester.view.resetDevicePixelRatio(); });

    AppColors.apply(Brightness.dark);
    await tester.pumpWidget(_buildHarness(
      child: const OrdersStatusScreen(),
      brightness: Brightness.dark,
      textScaleFactor: 1.3,
    ));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 600));
    expect(tester.takeException(), isNull);
  });

  // ----------------------------------------------------------------
  // Rider Tracker screen
  // ----------------------------------------------------------------
  testWidgets('Phase E: CourierTrackerScreen no overflow at 1.3x light', (tester) async {
    tester.view.physicalSize = const Size(390, 900);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(() { tester.view.resetPhysicalSize(); tester.view.resetDevicePixelRatio(); });

    const order = Order(
      id: 'ORD-12345',
      shopName: 'Zefanya Studio',
      itemSummary: '1 item',
      status: OrderStatus.inTransit,
      eta: 'Today, 4–6 PM',
    );

    AppColors.apply(Brightness.light);
    await tester.pumpWidget(_buildHarness(
      child: const CourierTrackerScreen(order: order),
      brightness: Brightness.light,
      textScaleFactor: 1.3,
    ));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 600));
    expect(tester.takeException(), isNull);
  });

  testWidgets('Phase E: CourierTrackerScreen no overflow at 1.3x dark', (tester) async {
    tester.view.physicalSize = const Size(390, 900);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(() { tester.view.resetPhysicalSize(); tester.view.resetDevicePixelRatio(); });

    const order = Order(
      id: 'ORD-12345',
      shopName: 'Zefanya Studio',
      itemSummary: '1 item',
      status: OrderStatus.inTransit,
      eta: 'Today, 4–6 PM',
    );

    AppColors.apply(Brightness.dark);
    await tester.pumpWidget(_buildHarness(
      child: const CourierTrackerScreen(order: order),
      brightness: Brightness.dark,
      textScaleFactor: 1.3,
    ));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 600));
    expect(tester.takeException(), isNull);
  });

  // ----------------------------------------------------------------
  // Checkout screen
  // ----------------------------------------------------------------
  testWidgets('Phase E: CheckoutScreen no overflow at 1.3x light', (tester) async {
    tester.view.physicalSize = const Size(390, 900);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(() { tester.view.resetPhysicalSize(); tester.view.resetDevicePixelRatio(); });

    AppColors.apply(Brightness.light);
    await tester.pumpWidget(_buildHarness(
      child: CheckoutScreen(
        checkoutItems: [
          CartItem(
            product: mockProducts.first,
            variant: '',
            quantity: 1,
          ),
        ],
      ),
      brightness: Brightness.light,
      textScaleFactor: 1.3,
    ));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 600));
    expect(tester.takeException(), isNull);
  });

  // ----------------------------------------------------------------
  // Profile screen
  // ----------------------------------------------------------------
  testWidgets('Phase E: ProfileScreen no overflow at 1.3x light', (tester) async {
    tester.view.physicalSize = const Size(390, 900);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(() { tester.view.resetPhysicalSize(); tester.view.resetDevicePixelRatio(); });

    AppColors.apply(Brightness.light);
    await tester.pumpWidget(_buildHarness(
      child: const ProfileScreen(title: 'Buyer Profile'),
      brightness: Brightness.light,
      textScaleFactor: 1.3,
    ));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 600));
    expect(tester.takeException(), isNull);
  });

  testWidgets('Phase E: ProfileScreen no overflow at 1.3x dark', (tester) async {
    tester.view.physicalSize = const Size(390, 900);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(() { tester.view.resetPhysicalSize(); tester.view.resetDevicePixelRatio(); });

    AppColors.apply(Brightness.dark);
    await tester.pumpWidget(_buildHarness(
      child: const ProfileScreen(title: 'Buyer Profile'),
      brightness: Brightness.dark,
      textScaleFactor: 1.3,
    ));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 600));
    expect(tester.takeException(), isNull);
  });
}
