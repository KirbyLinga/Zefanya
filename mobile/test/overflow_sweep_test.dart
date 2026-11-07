import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:zefanya/data/repositories/repositories.dart';
import 'package:zefanya/screens/auth/login_screen.dart';
import 'package:zefanya/screens/buyer/buyer_shell.dart';
import 'package:zefanya/screens/buyer/cart_screen.dart';
import 'package:zefanya/screens/buyer/product_details_screen.dart';
import 'package:zefanya/screens/courier/courier_shell.dart';
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

void main() {
  setUp(() {
    SharedPreferences.setMockInitialValues({});
    GoogleFonts.config.allowRuntimeFetching = false;
  });
  for (final brightness in Brightness.values) {
    for (final screen in ['buyer', 'rider', 'login', 'cart', 'product']) {
      testWidgets('$screen at 360x640, text 1.3, ${brightness.name}', (
        tester,
      ) async {
        final previousErrorHandler = FlutterError.onError;
        FlutterError.onError = (details) {
          FlutterError.dumpErrorToConsole(details, forceReport: true);
          previousErrorHandler?.call(details);
        };
        addTearDown(() => FlutterError.onError = previousErrorHandler);
        tester.view.physicalSize = const Size(360, 640);
        tester.view.devicePixelRatio = 1;
        addTearDown(tester.view.reset);
        AppColors.apply(brightness);
        final repos = Repositories.mock();
        final auth = AuthState(repository: repos.auth);
        if (screen != 'login') {
          await auth.login(
            role: screen == 'rider' ? UserRole.courier : UserRole.buyer,
            email: 'demo@example.ph',
            password: 'Password1!',
          );
        }
        final catalog = CatalogState(repository: repos.catalog);
        await catalog.load();
        final child = switch (screen) {
          'buyer' => const BuyerShell(),
          'rider' => const CourierShell(),
          'login' => const LoginScreen(),
          'cart' => const CartScreen(),
          _ => ProductDetailsScreen(product: catalog.products.first),
        };
        await tester.pumpWidget(
          MultiProvider(
            providers: [
              Provider<Repositories>.value(value: repos),
              ChangeNotifierProvider.value(value: auth),
              ChangeNotifierProvider.value(value: catalog),
              ChangeNotifierProvider(
                create: (_) =>
                    CartState(catalog: repos.catalog, vouchers: repos.cart),
              ),
              ChangeNotifierProvider(create: (_) => CourierState()),
              ChangeNotifierProvider(create: (_) => NotificationState()),
              ChangeNotifierProvider(
                create: (_) => OrderState(repository: repos.order),
              ),
              ChangeNotifierProvider(
                create: (_) => ChatState(repository: repos.chat),
              ),
              ChangeNotifierProvider(
                create: (_) => ReviewState(repository: repos.review),
              ),
              ChangeNotifierProvider(
                create: (_) => WishlistState(catalog: repos.catalog),
              ),
              ChangeNotifierProvider(
                create: (_) => RecentlyViewedState(catalog: repos.catalog),
              ),
              ChangeNotifierProvider(create: (_) => ThemeState()),
            ],
            child: MaterialApp(
              theme: brightness == Brightness.dark
                  ? AppTheme.dark
                  : AppTheme.light,
              builder: (context, child) => MediaQuery(
                data: MediaQuery.of(context).copyWith(
                  textScaler: const TextScaler.linear(1.3),
                  disableAnimations: true,
                ),
                child: child!,
              ),
              home: child,
            ),
          ),
        );
        await tester.pump();
        await tester.pump(const Duration(milliseconds: 900));
        expect(
          tester.takeException(),
          isNull,
          reason: '$screen initial layout',
        );
        // Exercise every real shell tab, rather than private/unreachable widgets.
        final tabs = screen == 'buyer'
            ? ['Search', 'Categories', 'Orders', 'Profile']
            : screen == 'rider'
            ? ['For Delivery', 'Earnings', 'Profile']
            : <String>[];
        for (final tab in tabs) {
          final target = find.text(tab);
          if (target.evaluate().isEmpty) {
            fail('$screen tab $tab is missing from the sweep');
          }
          await tester.tap(target.last);
          await tester.pump();
          await tester.pump(const Duration(milliseconds: 900));
          expect(tester.takeException(), isNull, reason: '$screen/$tab layout');
        }
        await tester.pumpWidget(const SizedBox.shrink());
        await tester.pump();
      });
    }
  }
}
