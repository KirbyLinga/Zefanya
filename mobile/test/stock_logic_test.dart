import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:zefanya/data/mock_data.dart';
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

Future<void> _pumpDetails(WidgetTester tester, Product product) async {
  AppColors.apply(Brightness.light);
  GoogleFonts.config.allowRuntimeFetching = false;
  SharedPreferences.setMockInitialValues({});
  tester.view.physicalSize = const Size(390, 844);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  await tester.pumpWidget(
    MultiProvider(
      providers: [
        ChangeNotifierProvider(create: (_) => CartState()),
        ChangeNotifierProvider(create: (_) => WishlistState()),
        ChangeNotifierProvider(create: (_) => RecentlyViewedState()),
        ChangeNotifierProvider(create: (_) => ReviewState()),
        ChangeNotifierProvider(create: (_) => OrderState()),
        ChangeNotifierProvider(create: (_) => CourierState()),
        ChangeNotifierProvider(create: (_) => CatalogState()),
      ],
      child: MaterialApp(
        theme: AppTheme.light,
        home: ProductDetailsScreen(product: product),
      ),
    ),
  );
  await tester.pump();
  await tester.pump(const Duration(milliseconds: 300));
}

void main() {
  testWidgets('shows Only N left when stock is <= 5', (tester) async {
    const lowStockProduct = Product(
      id: 'test-low',
      name: 'Low Stock Item',
      category: 'Fashion',
      price: 299,
      sellerName: 'Test Shop',
      stock: 3,
    );

    await _pumpDetails(tester, lowStockProduct);

    expect(find.text('Only 3 left'), findsOneWidget);

    // Quantity stepper clamps to stock (3)
    final plusButton = find.byIcon(Icons.add);
    await tester.tap(plusButton); // qty 2
    await tester.pump();
    await tester.tap(plusButton); // qty 3
    await tester.pump();
    await tester.tap(plusButton); // should clamp at 3
    await tester.pump();

    expect(find.text('3'), findsOneWidget);
  });

  testWidgets('shows Out of stock and disables actions when stock is 0', (tester) async {
    const outOfStockProduct = Product(
      id: 'test-zero',
      name: 'Out of Stock Item',
      category: 'Fashion',
      price: 299,
      sellerName: 'Test Shop',
      stock: 0,
    );

    await _pumpDetails(tester, outOfStockProduct);

    // Shows Out of stock label
    expect(find.text('Out of stock'), findsWidgets);

    // Add to Cart and Buy Now buttons are disabled (onPressed is null)
    final addToCartBtn = tester.widget<OutlinedButton>(
      find.widgetWithText(OutlinedButton, 'Add to Cart'),
    );
    expect(addToCartBtn.onPressed, isNull);

    final buyNowBtn = tester.widget<ElevatedButton>(
      find.widgetWithText(ElevatedButton, 'Out of Stock'),
    );
    expect(buyNowBtn.onPressed, isNull);
  });

  testWidgets('ProductCard displays Sold out overlay when stock is 0', (tester) async {
    AppColors.apply(Brightness.light);
    GoogleFonts.config.allowRuntimeFetching = false;
    SharedPreferences.setMockInitialValues({});

    const outOfStockProduct = Product(
      id: 'test-card-zero',
      name: 'Sold Out Item',
      category: 'Fashion',
      price: 199,
      sellerName: 'Test Shop',
      stock: 0,
    );

    await tester.pumpWidget(
      MultiProvider(
        providers: [
          ChangeNotifierProvider(create: (_) => WishlistState()),
        ],
        child: MaterialApp(
          theme: AppTheme.light,
          home: Scaffold(
            body: ProductCard(
              product: outOfStockProduct,
              heroTag: 'card-test-zero',
              onTap: () {},
            ),
          ),
        ),
      ),
    );
    await tester.pump();

    expect(find.text('Sold out'), findsOneWidget);
  });
}
