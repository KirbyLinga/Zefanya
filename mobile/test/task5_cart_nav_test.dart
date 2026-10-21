import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:zefanya/main.dart';
import 'package:zefanya/screens/buyer/categories_screen.dart';
import 'package:zefanya/screens/buyer/search_screen.dart';
import 'package:zefanya/state/auth_state.dart';
import 'package:zefanya/state/cart_state.dart';
import 'package:zefanya/state/catalog_state.dart';
import 'package:zefanya/state/theme_state.dart';
import 'package:zefanya/state/wishlist_state.dart';
import 'package:zefanya/theme/app_theme.dart';
import 'package:zefanya/widgets/cart_button.dart';

void main() {
  setUp(() => SharedPreferences.setMockInitialValues({}));

  testWidgets('MarketplaceApp uses title Zefanya', (tester) async {
    AppColors.apply(Brightness.light);
    GoogleFonts.config.allowRuntimeFetching = false;

    await tester.pumpWidget(
      MultiProvider(
        providers: [
          ChangeNotifierProvider(create: (_) => ThemeState()),
          ChangeNotifierProvider(create: (_) => AuthState()),
        ],
        child: const MarketplaceApp(),
      ),
    );
    await tester.pump();

    final app = tester.widget<MaterialApp>(find.byType(MaterialApp));
    expect(app.title, equals('Zefanya'));
  });

  testWidgets('CartButton renders shopping bag icon and badge', (tester) async {
    final cart = CartState();

    await tester.pumpWidget(
      MultiProvider(
        providers: [
          ChangeNotifierProvider.value(value: cart),
        ],
        child: const MaterialApp(
          home: Scaffold(
            body: CartButton(),
          ),
        ),
      ),
    );
    await tester.pump();

    expect(find.byIcon(Icons.shopping_bag_outlined), findsOneWidget);
    // Initially empty cart, no badge text
    expect(find.byType(CartButton), findsOneWidget);
  });

  testWidgets('SearchScreen contains CartButton in its header', (tester) async {
    final catalog = CatalogState();
    await catalog.load();

    await tester.pumpWidget(
      MediaQuery(
        data: const MediaQueryData(disableAnimations: true),
        child: MultiProvider(
          providers: [
            ChangeNotifierProvider.value(value: catalog),
            ChangeNotifierProvider(create: (_) => CartState()),
            ChangeNotifierProvider(create: (_) => WishlistState()),
          ],
          child: const MaterialApp(
            home: SearchScreen(),
          ),
        ),
      ),
    );
    await tester.pump(const Duration(milliseconds: 100));

    expect(find.byType(CartButton), findsOneWidget);
  });

  testWidgets('CategoriesScreen contains CartButton in its header', (tester) async {
    final catalog = CatalogState();
    await catalog.load();

    await tester.pumpWidget(
      MultiProvider(
        providers: [
          ChangeNotifierProvider.value(value: catalog),
          ChangeNotifierProvider(create: (_) => CartState()),
        ],
        child: const MaterialApp(
          home: CategoriesScreen(),
        ),
      ),
    );
    await tester.pump();

    expect(find.byType(CartButton), findsOneWidget);
  });
}
