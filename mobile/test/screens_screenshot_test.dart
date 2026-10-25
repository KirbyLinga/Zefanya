// test/screens_screenshot_test.dart
//
// Baseline and verification screenshot generator for Zefanya.
// Uses Flutter golden file mechanism to render pixel-accurate screenshots
// at 360x740 and 411x890, in Light and Dark themes.
//
// Never uses pumpAndSettle (bounded pumps only).

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:zefanya/data/mock_data.dart';
import 'package:zefanya/data/repositories/repositories.dart';
import 'package:zefanya/screens/auth/login_screen.dart';
import 'package:zefanya/screens/buyer/buyer_shell.dart';
import 'package:zefanya/screens/buyer/cart_screen.dart';
import 'package:zefanya/screens/buyer/courier_tracker_screen.dart';
import 'package:zefanya/screens/buyer/orders_status_screen.dart';
import 'package:zefanya/screens/buyer/product_details_screen.dart';
import 'package:zefanya/screens/buyer/search_screen.dart';
import 'package:zefanya/screens/courier/courier_shell.dart';
import 'package:zefanya/screens/landing_screen.dart';
import 'package:zefanya/screens/shared/profile_screen.dart';
import 'package:zefanya/state/auth_state.dart';
import 'package:zefanya/state/cart_state.dart';
import 'package:zefanya/state/catalog_state.dart';
import 'package:zefanya/state/chat_state.dart';
import 'package:zefanya/state/courier_state.dart';
import 'package:zefanya/state/notification_state.dart';
import 'package:zefanya/state/order_state.dart';
import 'package:zefanya/state/recently_viewed_state.dart';
import 'package:zefanya/state/review_state.dart';
import 'package:zefanya/state/theme_state.dart';
import 'package:zefanya/state/wishlist_state.dart';
import 'package:zefanya/theme/app_theme.dart';

const _order = Order(
  id: 'ORD-55555',
  shopName: 'Zefanya Studio',
  status: OrderStatus.inTransit,
  eta: 'Today, 4–6 PM',
  itemSummary: 'Linen Blend Wrap Dress',
);

CourierState _dispatchedCourier() {
  final courier = CourierState();
  courier.dispatchForOrder(
    order: _order,
    buyerName: 'Ana',
    deliveryArea: 'Makati City',
    fee: 120,
  );
  final jobId = courier.jobForOrder(_order.id)!.id;
  courier.advanceMilestone(jobId, DeliveryMilestone.assignedToRider,
      courierName: 'Rico Delos Santos');
  return courier;
}

Future<void> _renderAndCapture(
  WidgetTester tester, {
  required String name,
  required Widget Function() builder,
  required Size size,
  required Brightness brightness,
  Duration advanceTime = const Duration(milliseconds: 600),
  bool disableAnimations = false,
  VoidCallback? preCaptureAction,
}) async {
  AppColors.apply(brightness);
  GoogleFonts.config.allowRuntimeFetching = false;

  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final repositories = Repositories.mock();
  final authState = AuthState(repository: repositories.auth);
  final cartState = CartState(
    catalog: repositories.catalog,
    vouchers: repositories.cart,
  );
  cartState.addItem(mockProducts.first);
  if (mockProducts.length > 1) {
    cartState.addItem(mockProducts[1]);
  }
  final catalogState = CatalogState(repository: repositories.catalog);
  final courierState = _dispatchedCourier();
  final notificationState = NotificationState();
  final orderState = OrderState(repository: repositories.order);
  final chatState = ChatState(repository: repositories.chat);
  final reviewState = ReviewState(repository: repositories.review);
  final wishlistState = WishlistState(catalog: repositories.catalog);
  final themeState = ThemeState();
  themeState.setMode(brightness == Brightness.dark ? ThemeMode.dark : ThemeMode.light);
  final recentlyViewedState = RecentlyViewedState(catalog: repositories.catalog);

  final testKey = GlobalKey();

  await tester.pumpWidget(
    MultiProvider(
      providers: [
        Provider<Repositories>.value(value: repositories),
        ChangeNotifierProvider<AuthState>.value(value: authState),
        ChangeNotifierProvider<CartState>.value(value: cartState),
        ChangeNotifierProvider<CatalogState>.value(value: catalogState),
        ChangeNotifierProvider<CourierState>.value(value: courierState),
        ChangeNotifierProvider<NotificationState>.value(value: notificationState),
        ChangeNotifierProvider<OrderState>.value(value: orderState),
        ChangeNotifierProvider<ChatState>.value(value: chatState),
        ChangeNotifierProvider<ReviewState>.value(value: reviewState),
        ChangeNotifierProvider<WishlistState>.value(value: wishlistState),
        ChangeNotifierProvider<ThemeState>.value(value: themeState),
        ChangeNotifierProvider<RecentlyViewedState>.value(value: recentlyViewedState),
      ],
      child: MaterialApp(
        theme: brightness == Brightness.dark ? AppTheme.dark : AppTheme.light,
        home: MediaQuery(
          data: MediaQueryData(
            size: size,
            disableAnimations: disableAnimations,
            textScaler: const TextScaler.linear(1.0),
          ),
          child: RepaintBoundary(
            key: testKey,
            child: ColoredBox(
              color: AppColors.background,
              child: SizedBox(
                width: size.width,
                height: size.height,
                child: builder(),
              ),
            ),
          ),
        ),
      ),
    ),
  );

  await tester.pump();
  if (advanceTime > Duration.zero) {
    await tester.pump(advanceTime);
  }

  if (preCaptureAction != null) {
    preCaptureAction();
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 300));
  }

  final widthInt = size.width.round();
  final heightInt = size.height.round();
  final modeStr = brightness == Brightness.dark ? 'dark' : 'light';
  final filename = 'goldens/${name}_${widthInt}x${heightInt}_$modeStr.png';

  await expectLater(
    find.byKey(testKey),
    matchesGoldenFile(filename),
  );

  // Unmount tree so stateful widgets' dispose() runs and cancels any timers.
  await tester.pumpWidget(const SizedBox.shrink());
  await tester.pump(const Duration(seconds: 5));
}

void main() {
  setUp(() {
    SharedPreferences.setMockInitialValues({});
  });

  const sizes = [
    Size(360, 740),
    Size(411, 890),
  ];

  for (final size in sizes) {
    for (final brightness in [Brightness.light, Brightness.dark]) {
      final sName = '${size.width.round()}x${size.height.round()}_${brightness.name}';

      testWidgets('Landing Beat 1 — $sName', (tester) async {
        await _renderAndCapture(
          tester,
          name: 'landing_beat1',
          builder: () => LandingScreen(
            onDone: () {},
            reduceMotion: false,
          ),
          size: size,
          brightness: brightness,
          advanceTime: const Duration(milliseconds: 100),
        );
      });

      testWidgets('Landing Beat 2 — $sName', (tester) async {
        await _renderAndCapture(
          tester,
          name: 'landing_beat2',
          builder: () => LandingScreen(
            onDone: () {},
            reduceMotion: false,
          ),
          size: size,
          brightness: brightness,
          advanceTime: const Duration(milliseconds: 3200),
        );
      });

      testWidgets('Login — $sName', (tester) async {
        await _renderAndCapture(
          tester,
          name: 'login',
          builder: () => const LoginScreen(),
          size: size,
          brightness: brightness,
          advanceTime: const Duration(milliseconds: 600),
        );
      });

      testWidgets('Buyer Home — $sName', (tester) async {
        await _renderAndCapture(
          tester,
          name: 'buyer_home',
          builder: () => BuyerShell(now: DateTime(2026, 1, 1, 18)),
          size: size,
          brightness: brightness,
          disableAnimations: true,
          advanceTime: const Duration(milliseconds: 600),
        );
      });

      testWidgets('Search — $sName', (tester) async {
        await _renderAndCapture(
          tester,
          name: 'search',
          builder: () => const SearchScreen(),
          size: size,
          brightness: brightness,
          advanceTime: const Duration(milliseconds: 600),
        );
      });

      testWidgets('Cart — $sName', (tester) async {
        await _renderAndCapture(
          tester,
          name: 'cart',
          builder: () => const CartScreen(),
          size: size,
          brightness: brightness,
          advanceTime: const Duration(milliseconds: 600),
        );
      });

      testWidgets('Orders — $sName', (tester) async {
        await _renderAndCapture(
          tester,
          name: 'orders',
          builder: () => const OrdersStatusScreen(),
          size: size,
          brightness: brightness,
          advanceTime: const Duration(milliseconds: 600),
        );
      });

      testWidgets('Profile — $sName', (tester) async {
        await _renderAndCapture(
          tester,
          name: 'profile',
          builder: () => const ProfileScreen(title: 'Buyer Profile'),
          size: size,
          brightness: brightness,
          advanceTime: const Duration(milliseconds: 600),
        );
      });

      testWidgets('Product Detail — $sName', (tester) async {
        await _renderAndCapture(
          tester,
          name: 'product_detail',
          builder: () => ProductDetailsScreen(product: mockProducts.first),
          size: size,
          brightness: brightness,
          advanceTime: const Duration(milliseconds: 600),
        );
      });

      testWidgets('Courier Home — $sName', (tester) async {
        await _renderAndCapture(
          tester,
          name: 'courier_home',
          builder: () => const CourierShell(),
          size: size,
          brightness: brightness,
          advanceTime: const Duration(milliseconds: 900),
        );
      });

      testWidgets('Courier Tracker — $sName', (tester) async {
        await _renderAndCapture(
          tester,
          name: 'courier_tracker',
          builder: () => const CourierTrackerScreen(order: _order),
          size: size,
          brightness: brightness,
          advanceTime: const Duration(milliseconds: 600),
        );
      });
    }
  }
}
