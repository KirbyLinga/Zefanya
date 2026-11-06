// test/cart_placeholder_test.dart
//
// A catalogue product may legitimately have no photo yet (see Product.photos).
// The cart row renders the same ProductImage as the grid and detail screens,
// so it must show the neutral placeholder rather than a blank box or a network
// error. The card and gallery already have their own tests; this closes the
// third surface the buyer sees a product on.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:zefanya/data/mock_data.dart';
import 'package:zefanya/screens/buyer/cart_screen.dart';
import 'package:zefanya/state/cart_state.dart';
import 'package:zefanya/state/catalog_state.dart';
import 'package:zefanya/state/courier_state.dart';
import 'package:zefanya/state/order_state.dart';
import 'package:zefanya/theme/app_theme.dart';

const _noPhoto = Product(
  id: 'no-photo-cart',
  name: 'Unphotographed Linen Wrap',
  category: 'Dresses',
  price: 980,
  sellerName: 'Willow & Bloom Atelier',
);

void main() {
  testWidgets('a cart line with no photo paints the placeholder, not a crash', (
    tester,
  ) async {
    SharedPreferences.setMockInitialValues({});
    AppColors.apply(Brightness.light);
    GoogleFonts.config.allowRuntimeFetching = false;
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    final cart = CartState();
    await cart.load();
    cart.addItem(_noPhoto);
    expect(cart.items, hasLength(1));

    await tester.pumpWidget(
      MultiProvider(
        providers: [
          ChangeNotifierProvider(create: (_) => CatalogState()),
          ChangeNotifierProvider.value(value: cart),
          ChangeNotifierProvider(create: (_) => CourierState()),
          ChangeNotifierProvider(create: (_) => OrderState()),
        ],
        child: MaterialApp(theme: AppTheme.light, home: const CartScreen()),
      ),
    );
    await tester.pump(const Duration(milliseconds: 500));

    expect(_noPhoto.photos, isEmpty);
    expect(find.text(_noPhoto.name), findsOneWidget);
    expect(find.byKey(const ValueKey('product-placeholder')), findsOneWidget);
    expect(find.byType(Image), findsNothing);
    expect(tester.takeException(), isNull);
  });
}
