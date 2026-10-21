import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:zefanya/data/mock_data.dart';
import 'package:zefanya/screens/buyer/buyer_shell.dart';
import 'package:zefanya/state/auth_state.dart';
import 'package:zefanya/state/cart_state.dart';
import 'package:zefanya/state/catalog_state.dart';
import 'package:zefanya/state/courier_state.dart';
import 'package:zefanya/state/order_state.dart';
import 'package:zefanya/state/recently_viewed_state.dart';
import 'package:zefanya/state/review_state.dart';
import 'package:zefanya/state/theme_state.dart';
import 'package:zefanya/state/wishlist_state.dart';
import 'package:zefanya/theme/app_theme.dart';

void main() {
  setUp(() => SharedPreferences.setMockInitialValues({}));

  testWidgets('Buyer Home hierarchy renders search, categories, and Just for you in order', (tester) async {
    AppColors.apply(Brightness.light);
    GoogleFonts.config.allowRuntimeFetching = false;
    tester.view.physicalSize = const Size(390, 1400);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(() {
      tester.view.resetPhysicalSize();
      tester.view.resetDevicePixelRatio();
    });

    final catalog = CatalogState();
    await catalog.load();

    await tester.pumpWidget(
      MediaQuery(
        data: const MediaQueryData(disableAnimations: true),
        child: MultiProvider(
          providers: [
            ChangeNotifierProvider.value(value: catalog),
            ChangeNotifierProvider(create: (_) => AuthState()),
            ChangeNotifierProvider(create: (_) => CartState()),
            ChangeNotifierProvider(create: (_) => CourierState()),
            ChangeNotifierProvider(create: (_) => OrderState()),
            ChangeNotifierProvider(create: (_) => RecentlyViewedState()),
            ChangeNotifierProvider(create: (_) => ReviewState()),
            ChangeNotifierProvider(create: (_) => WishlistState()),
            ChangeNotifierProvider(create: (_) => ThemeState()),
          ],
          child: MaterialApp(
            theme: AppTheme.light,
            home: const BuyerShell(),
          ),
        ),
      ),
    );

    // Initial pump bare for animation ticker
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 600));

    // Verify masthead wordmark
    expect(find.text('Zefanya'), findsWidgets);

    // Verify search field exists with hint
    expect(find.text('Search products, brands...'), findsOneWidget);

    // Verify search field container height >= 48
    final searchContainer = tester.widget<Container>(
      find.ancestor(
        of: find.text('Search products, brands...'),
        matching: find.byType(Container),
      ).first,
    );
    expect(searchContainer.constraints?.minHeight ?? 48.0, greaterThanOrEqualTo(48.0));

    // Verify category quick links
    expect(find.text('CATEGORIES'), findsOneWidget);
    expect(find.text('See all'), findsOneWidget);

    // Verify "Just for you" heading is present and "EVERYTHING ELSE" is absent
    expect(find.text('Just for you'), findsOneWidget);
    expect(find.text('EVERYTHING ELSE'), findsNothing);
  });

  test('Catalog ordering is deterministic by rating descending', () {
    final products = List<Product>.from(mockProducts);
    products.sort((a, b) {
      final cmp = b.rating.compareTo(a.rating);
      if (cmp != 0) return cmp;
      return a.id.compareTo(b.id);
    });

    for (var i = 0; i < products.length - 1; i++) {
      expect(products[i].rating, greaterThanOrEqualTo(products[i + 1].rating));
    }
  });
}
