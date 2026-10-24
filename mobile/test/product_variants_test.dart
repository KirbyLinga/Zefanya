// test/product_variants_test.dart
//
// Two-dimensional product variants. The old model was a flat list of labels
// with one product-wide price and stock, so it could not express the three
// things the buyer-facing case needs: every control must be answered, the
// chosen combination owns its price/stock/image, and an unavailable
// combination is disabled rather than silently sold.
//
// p9 is the fixture. It has Color × Size, Black/M is out of stock while White/M
// is available, and S/L carry different prices/stocks.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:zefanya/data/mock_data.dart';
import 'package:zefanya/screens/buyer/cart_screen.dart';
import 'package:zefanya/screens/buyer/checkout_screen.dart';
import 'package:zefanya/screens/buyer/product_details_screen.dart';
import 'package:zefanya/screens/buyer/widgets/product_card.dart';
import 'package:zefanya/state/cart_state.dart';
import 'package:zefanya/state/catalog_state.dart';
import 'package:zefanya/state/courier_state.dart';
import 'package:zefanya/state/order_state.dart';
import 'package:zefanya/state/recently_viewed_state.dart';
import 'package:zefanya/state/review_state.dart';
import 'package:zefanya/state/wishlist_state.dart';
import 'package:zefanya/theme/app_theme.dart';
import 'package:zefanya/utils/money_format.dart';

Product get _tee => mockProducts.firstWhere((product) => product.id == 'p9');

ProductVariant _sku(String color, String size) =>
    _tee.variantForSelections({'Color': color, 'Size': size})!;

Finder _galleryImage(String url) => find.byWidgetPredicate(
  (widget) => widget is ProductImage && widget.imageUrl == url,
);

Future<CartState> _pumpDetails(WidgetTester tester) async {
  AppColors.apply(Brightness.light);
  GoogleFonts.config.allowRuntimeFetching = false;
  SharedPreferences.setMockInitialValues({});
  tester.view.physicalSize = const Size(390, 1600);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final cart = CartState();
  await tester.pumpWidget(
    MediaQuery(
      data: const MediaQueryData(disableAnimations: true),
      child: MultiProvider(
        providers: [
          ChangeNotifierProvider.value(value: cart),
          ChangeNotifierProvider(create: (_) => WishlistState()),
          ChangeNotifierProvider(create: (_) => RecentlyViewedState()),
          ChangeNotifierProvider(create: (_) => ReviewState()),
          ChangeNotifierProvider(create: (_) => OrderState()),
          ChangeNotifierProvider(create: (_) => CourierState()),
          ChangeNotifierProvider(create: (_) => CatalogState()),
        ],
        child: MaterialApp(
          theme: AppTheme.light,
          home: ProductDetailsScreen(product: _tee),
        ),
      ),
    ),
  );
  await tester.pump();
  await tester.pump(const Duration(milliseconds: 300));
  return cart;
}

Future<void> _tapOption(WidgetTester tester, String group, String value) async {
  final finder = find.byKey(ValueKey('variant-$group-$value'));
  await tester.ensureVisible(finder);
  await tester.pump();
  await tester.tap(finder);
  await tester.pump();
}

Future<void> _pumpCart(WidgetTester tester, CartState cart) async {
  AppColors.apply(Brightness.light);
  GoogleFonts.config.allowRuntimeFetching = false;
  tester.view.physicalSize = const Size(390, 844);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  await tester.pumpWidget(
    MediaQuery(
      data: const MediaQueryData(disableAnimations: true),
      child: MultiProvider(
        providers: [
          ChangeNotifierProvider.value(value: CatalogState()),
          ChangeNotifierProvider.value(value: cart),
          ChangeNotifierProvider(create: (_) => CourierState()),
          ChangeNotifierProvider(create: (_) => OrderState()),
        ],
        child: MaterialApp(theme: AppTheme.light, home: const CartScreen()),
      ),
    ),
  );
  await tester.pump(const Duration(milliseconds: 500));
  await tester.pump(const Duration(milliseconds: 500));
}

void main() {
  setUp(() => SharedPreferences.setMockInitialValues({}));

  testWidgets('BUY-PDP-001: both variation types are required', (tester) async {
    final cart = await _pumpDetails(tester);

    // No preselection on a two-dimensional product: the price is the base
    // price, but neither action may add anything until both controls are set.
    expect(find.byIcon(Icons.check), findsNothing);

    await tester.tap(find.widgetWithText(ElevatedButton, 'Buy Now'));
    await tester.pump();
    expect(find.text('Please select a variation'), findsOneWidget);
    expect(find.byType(CheckoutScreen), findsNothing);

    await tester.tap(find.widgetWithText(OutlinedButton, 'Add to Cart'));
    await tester.pump();
    expect(find.text('Please select a variation'), findsOneWidget);
    expect(cart.items, isEmpty);

    // One selection is still not enough.
    await _tapOption(tester, 'Color', 'Black');
    await tester.tap(find.widgetWithText(OutlinedButton, 'Add to Cart'));
    await tester.pump();
    expect(find.text('Please select a variation'), findsOneWidget);
    expect(cart.items, isEmpty);

    await _tapOption(tester, 'Size', 'S');
    await tester.tap(find.widgetWithText(OutlinedButton, 'Add to Cart'));
    await tester.pump(const Duration(milliseconds: 800));

    expect(cart.items, hasLength(1));
    expect(cart.items.single.variant, 'Black / S');
    expect(cart.items.single.unitPrice, 199);
    expect(cart.items.single.quantity, 1);
  });

  testWidgets('BUY-PDP-002: price, stock and image follow the variant', (
    tester,
  ) async {
    await _pumpDetails(tester);

    await _tapOption(tester, 'Color', 'Black');
    await _tapOption(tester, 'Size', 'S');

    expect(find.text(formatPeso(199)), findsOneWidget);
    expect(find.text('10 available'), findsOneWidget);
    expect(_galleryImage(_sku('Black', 'S').image!), findsWidgets);

    await _tapOption(tester, 'Size', 'L');

    expect(find.text(formatPeso(299)), findsOneWidget);
    expect(find.text('Only 5 left'), findsOneWidget);
    expect(_galleryImage(_sku('Black', 'L').image!), findsWidgets);

    // Switching colour while L is selected resolves to White/L, whose price
    // and stock are the 199/10 pair again — and the gallery leads with the
    // white image rather than retaining the black one.
    await _tapOption(tester, 'Color', 'White');

    expect(find.text(formatPeso(199)), findsOneWidget);
    expect(find.text('10 available'), findsOneWidget);
    expect(_galleryImage(_sku('White', 'L').image!), findsWidgets);
  });

  testWidgets('BUY-PDP-003: Black/M is disabled, White/M is not', (
    tester,
  ) async {
    await _pumpDetails(tester);

    await _tapOption(tester, 'Color', 'Black');

    final blackMChip = tester.widget<ChoiceChip>(
      find.byKey(const ValueKey('variant-Size-M')),
    );
    expect(blackMChip.onSelected, isNull);

    // A disabled chip cannot be selected by tapping it.
    await _tapOption(tester, 'Size', 'M');
    final stillUnselected = tester.widget<ChoiceChip>(
      find.byKey(const ValueKey('variant-Size-M')),
    );
    expect(stillUnselected.selected, isFalse);
    expect(find.text(formatPeso(199)), findsOneWidget);

    await _tapOption(tester, 'Color', 'White');
    final whiteMChip = tester.widget<ChoiceChip>(
      find.byKey(const ValueKey('variant-Size-M')),
    );
    expect(whiteMChip.onSelected, isNotNull);

    await _tapOption(tester, 'Size', 'M');
    expect(
      tester
          .widget<ChoiceChip>(find.byKey(const ValueKey('variant-Size-M')))
          .selected,
      isTrue,
    );
    expect(find.text(formatPeso(249)), findsOneWidget);

    // Going back to Black cannot keep M selected: Black/M has no stock, so the
    // later control is cleared and disabled rather than left as a dead combo.
    await _tapOption(tester, 'Color', 'Black');
    final clearedM = tester.widget<ChoiceChip>(
      find.byKey(const ValueKey('variant-Size-M')),
    );
    expect(clearedM.selected, isFalse);
    expect(clearedM.onSelected, isNull);
  });

  testWidgets(
    'BUY-CART-002: different variants stay separate with own prices',
    (tester) async {
      final cart = CartState();
      final whiteM = _sku('White', 'M');
      final whiteS = _sku('White', 'S');

      cart.addItem(
        _tee,
        variant: whiteM.label,
        unitPrice: whiteM.price,
        variantStock: whiteM.stock,
        variantImage: whiteM.image,
      );
      cart.addItem(
        _tee,
        variant: whiteS.label,
        unitPrice: whiteS.price,
        variantStock: whiteS.stock,
        variantImage: whiteS.image,
      );

      expect(cart.items, hasLength(2));
      expect(cart.items.map((item) => item.variant), [
        'White / M',
        'White / S',
      ]);
      expect(cart.items.map((item) => item.unitPrice), [249, 199]);
      expect(cart.subtotal, 448);

      await _pumpCart(tester, cart);

      expect(find.text(_tee.name), findsNWidgets(2));
      expect(find.text(formatPeso(249)), findsOneWidget);
      expect(find.text(formatPeso(199)), findsOneWidget);
    },
  );
}
