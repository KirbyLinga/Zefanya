// test/cart_count_test.dart
//
// S5.4: the cart badge counts distinct products, not units. Two colours of one
// shirt is one product; raising a line from one to three is still one product.
// A second Add to Cart on the same line is a quantity change, not a new
// arrival, so it must not bounce the badge or fly another copy.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:zefanya/data/mock_data.dart';
import 'package:zefanya/config/dev_flags.dart' show kUseLiveApi;
import 'package:zefanya/data/api/api_client.dart';
import 'package:zefanya/data/api/token_store.dart';
import 'package:zefanya/data/repositories/order_repository.dart';
import 'package:zefanya/screens/buyer/cart_screen.dart';
import 'package:zefanya/widgets/free_shipping_meter.dart';
import 'package:zefanya/screens/buyer/product_details_screen.dart';
import 'package:zefanya/state/cart_state.dart';
import 'package:zefanya/state/catalog_state.dart';
import 'package:zefanya/state/courier_state.dart';
import 'package:zefanya/state/order_state.dart';
import 'package:zefanya/state/recently_viewed_state.dart';
import 'package:zefanya/state/review_state.dart';
import 'package:zefanya/state/theme_state.dart';
import 'package:zefanya/state/wishlist_state.dart';
import 'package:zefanya/theme/app_theme.dart';
import 'package:zefanya/widgets/cart_button.dart';

Product _product(String id) => Product(
  id: id,
  name: 'Product $id',
  category: 'Home & Living',
  price: 100,
  sellerName: 'Test Shop',
);

Widget _cartButtonApp(CartState cart) {
  return ChangeNotifierProvider<CartState>.value(
    value: cart,
    child: MaterialApp(
      theme: AppTheme.light,
      home: const Scaffold(body: Center(child: CartButton())),
    ),
  );
}

Widget _detailsApp(CartState cart, Product product) {
  return MediaQuery(
    data: const MediaQueryData(disableAnimations: true),
    child: MultiProvider(
      providers: [
        ChangeNotifierProvider<CartState>.value(value: cart),
        ChangeNotifierProvider(create: (_) => OrderState()),
        ChangeNotifierProvider(create: (_) => WishlistState()),
        ChangeNotifierProvider(create: (_) => RecentlyViewedState()),
        ChangeNotifierProvider(create: (_) => ReviewState()),
        ChangeNotifierProvider(create: (_) => CourierState()),
        ChangeNotifierProvider(create: (_) => CatalogState()),
        ChangeNotifierProvider(create: (_) => ThemeState()),
      ],
      child: MaterialApp(
        theme: AppTheme.light,
        home: ProductDetailsScreen(product: product),
      ),
    ),
  );
}

double _badgeScale(WidgetTester tester, String text) {
  return tester
      .widgetList<ScaleTransition>(
        find.ancestor(
          of: find.text(text),
          matching: find.byType(ScaleTransition),
        ),
      )
      .first
      .scale
      .value;
}

void main() {
  setUp(() {
    SharedPreferences.setMockInitialValues({});
    GoogleFonts.config.allowRuntimeFetching = false;
    AppColors.apply(Brightness.light);
  });

  testWidgets(
    'live Cart shows merchandise and defers shipping to Laravel',
    (tester) async {
      final cart = CartState()..addItem(_product('live'));
      await tester.pumpWidget(
        MultiProvider(
          providers: [
            ChangeNotifierProvider.value(value: cart),
            ChangeNotifierProvider(
              create: (_) => OrderState(
                repository: LiveOrderRepository(
                  ApiClient(
                    baseUrl: 'https://example.test',
                    tokenStore: InMemoryTokenStore(),
                  ),
                ),
              ),
            ),
          ],
          child: MaterialApp(theme: AppTheme.light, home: const CartScreen()),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.byType(FreeShippingMeter), findsNothing);
      expect(find.text('Shipping is calculated at checkout.'), findsOneWidget);
      expect(find.text('Subtotal (1 product)'), findsOneWidget);
    },
    skip: !kUseLiveApi,
  );

  group('distinct product count', () {
    test('a repeated add and a second variant stay one product', () {
      final cart = CartState();
      final shirt = _product('shirt');

      expect(cart.addItem(shirt), CartAddOutcome.added);
      expect(cart.productCount, 1);

      expect(cart.addItem(shirt), CartAddOutcome.quantityUpdated);
      expect(cart.productCount, 1);
      expect(cart.items.single.quantity, 2);

      expect(cart.addItem(shirt, variant: 'Red'), CartAddOutcome.added);
      expect(cart.items, hasLength(2));
      expect(cart.productCount, 1);
    });

    test('two products count two, and removing one counts one', () {
      final cart = CartState();
      cart.addItem(_product('a'));
      cart.addItem(_product('b'));

      expect(cart.productCount, 2);
      expect(cart.selectedProductCount, 2);

      cart.removeItem(cart.items.first);

      expect(cart.productCount, 1);
      expect(cart.selectedProductCount, 1);
    });

    test('deselecting a product removes it from the selected count', () {
      final cart = CartState();
      cart.addItem(_product('a'));
      cart.addItem(_product('b'));

      cart.toggleSelected(cart.items.first, false);

      expect(cart.productCount, 2);
      expect(cart.selectedProductCount, 1);
    });

    test('a refused add changes nothing and says so', () {
      final cart = CartState();
      final soldOut = const Product(
        id: 'sold-out',
        name: 'Sold out',
        category: 'Home & Living',
        price: 100,
        sellerName: 'Test Shop',
        stock: 0,
      );

      expect(cart.addItem(soldOut), CartAddOutcome.rejected);
      expect(cart.addItem(_product('a'), quantity: 0), CartAddOutcome.rejected);
      expect(cart.productCount, 0);
      expect(cart.items, isEmpty);
    });
  });

  group('cart badge', () {
    testWidgets('is hidden at zero', (tester) async {
      await tester.pumpWidget(_cartButtonApp(CartState()));
      await tester.pump();

      expect(find.text('0'), findsNothing);
      expect(find.byKey(const ValueKey('no-badge')), findsOneWidget);
    });

    testWidgets('shows 9+ for ten distinct products', (tester) async {
      final cart = CartState();
      for (var i = 0; i < 10; i++) {
        cart.addItem(_product('p$i'));
      }

      await tester.pumpWidget(_cartButtonApp(cart));
      await tester.pump();

      expect(find.text('9+'), findsOneWidget);
    });

    testWidgets('a quantity update does not bounce the badge', (tester) async {
      final cart = CartState();
      final product = _product('a');
      cart.addItem(product);

      await tester.pumpWidget(_cartButtonApp(cart));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 400));

      expect(find.text('1'), findsOneWidget);
      expect(_badgeScale(tester, '1'), 1.0);

      final outcome = cart.addItem(product);
      expect(outcome, CartAddOutcome.quantityUpdated);
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 120));

      expect(cart.items.single.quantity, 2);
      expect(find.text('1'), findsOneWidget);
      expect(_badgeScale(tester, '1'), 1.0);
    });

    testWidgets('announces the distinct product count', (tester) async {
      final handle = tester.ensureSemantics();

      final cart = CartState();
      cart.addItem(_product('a'));
      cart.addItem(_product('b'));

      await tester.pumpWidget(_cartButtonApp(cart));
      await tester.pump();

      expect(find.bySemanticsLabel('Cart, 2 products'), findsOneWidget);
      handle.dispose();
    });
  });

  group('product details add to cart', () {
    testWidgets('a second add says Quantity updated without a new arrival', (
      tester,
    ) async {
      tester.view.physicalSize = const Size(390, 1600);
      tester.view.devicePixelRatio = 1.0;
      addTearDown(tester.view.reset);

      final cart = CartState();
      final product = _product('p1');
      await tester.pumpWidget(_detailsApp(cart, product));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 300));

      await tester.tap(find.widgetWithText(OutlinedButton, 'Add to Cart'));
      await tester.pump(const Duration(milliseconds: 800));

      expect(cart.productCount, 1);
      expect(cart.items.single.quantity, 1);
      expect(find.text('Quantity updated'), findsNothing);

      // Let the first confirmation go before the second tap, so the button is
      // not behind a SnackBar and the 700ms add cooldown has elapsed.
      await tester.pump(const Duration(seconds: 5));
      await tester.tap(find.widgetWithText(OutlinedButton, 'Add to Cart'));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 120));

      expect(cart.productCount, 1);
      expect(cart.items.single.quantity, 2);
      expect(find.text('Quantity updated'), findsOneWidget);

      // Let the add cooldown finish before the tree goes away.
      await tester.pump(const Duration(milliseconds: 800));
      await tester.pumpWidget(const SizedBox.shrink());
    });
  });
}
