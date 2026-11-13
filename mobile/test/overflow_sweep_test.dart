import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:zefanya/data/repositories/repositories.dart';
import 'package:zefanya/data/repositories/order_repository.dart';
import 'package:zefanya/data/mock_data.dart' show Order;
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

class _EmptyOrders extends MockOrderRepository {
  @override
  Future<List<Order>> orders() async => const [];
}

void main() {
  for (final theme in [AppTheme.light, AppTheme.dark]) {
    test('primary button text meets 4.5:1 in ${theme.brightness.name}', () {
      final style = theme.filledButtonTheme.style!;
      final foreground = style.foregroundColor!.resolve({})!.computeLuminance();
      final background = style.backgroundColor!.resolve({})!.computeLuminance();
      expect(
        (foreground + 0.05) / (background + 0.05),
        greaterThanOrEqualTo(4.5),
      );
    });
  }
  setUp(() {
    SharedPreferences.setMockInitialValues({});
    GoogleFonts.config.allowRuntimeFetching = false;
  });
  for (final brightness in Brightness.values) {
    for (final size in [
      const Size(360, 640),
      const Size(360, 740),
      const Size(411, 890),
    ]) {
      for (final screen in ['buyer', 'rider', 'login', 'cart', 'product']) {
        testWidgets('$screen at $size, text 1.3, ${brightness.name}', (
          tester,
        ) async {
          final previousErrorHandler = FlutterError.onError;
          FlutterError.onError = (details) {
            FlutterError.dumpErrorToConsole(details, forceReport: true);
            previousErrorHandler?.call(details);
          };
          addTearDown(() => FlutterError.onError = previousErrorHandler);
          tester.view.physicalSize = size;
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
          final orderState = OrderState(repository: _EmptyOrders());
          await orderState.load();
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
                ChangeNotifierProvider.value(value: orderState),
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
          if (screen == 'login' && size.height == 640) {
            await tester.showKeyboard(find.byType(TextFormField).first);
            tester.view.viewInsets = const FakeViewPadding(bottom: 280);
            await tester.pump();
            await tester.scrollUntilVisible(
              find.text('Login').hitTestable(),
              120,
              scrollable: find.byType(Scrollable).first,
            );
            expect(find.text('Login').hitTestable(), findsOneWidget);
            expect(
              tester.takeException(),
              isNull,
              reason: 'Login with keyboard',
            );
            tester.view.resetViewInsets();
          }
          // Exercise every real shell tab, rather than private/unreachable widgets.
          final tabs = screen == 'buyer'
              ? ['Search', 'Browse', 'Orders', 'Profile']
              : screen == 'rider'
              ? ['Deliveries', 'Earnings', 'Profile']
              : <String>[];
          for (final tab in tabs) {
            final target = find.text(tab);
            if (target.evaluate().isEmpty) {
              fail('$screen tab $tab is missing from the sweep');
            }
            await tester.tap(target.last);
            await tester.pump();
            await tester.pump(const Duration(milliseconds: 900));
            expect(
              tester.takeException(),
              isNull,
              reason: '$screen/$tab layout',
            );
            if (tab == 'Orders') {
              await tester.tap(find.text('Browse products'));
              await tester.pumpAndSettle();
              expect(find.text('Search products, brands...'), findsOneWidget);
            }
            if (tab == 'Deliveries') {
              expect(
                find.byKey(const ValueKey('availability-switch')),
                findsOneWidget,
              );
            }
          }
          await tester.pumpWidget(const SizedBox.shrink());
          await tester.pump();
        });
      }
    }
  }
}
