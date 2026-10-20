import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:zefanya/data/mock_data.dart';
import 'package:zefanya/state/cart_state.dart';
import 'package:zefanya/state/courier_state.dart';
import 'package:zefanya/state/order_state.dart';
import 'package:zefanya/state/recently_viewed_state.dart';
import 'package:zefanya/state/review_state.dart';
import 'package:zefanya/state/wishlist_state.dart';
import 'package:zefanya/screens/buyer/product_details_screen.dart';
import 'package:zefanya/theme/app_theme.dart';

void main() {
  setUp(() => SharedPreferences.setMockInitialValues({}));

  test('AppType.eyebrow enforces minimum fontSize >= 11 and letterSpacing <= 1.2', () {
    expect(AppType.eyebrow.fontSize, greaterThanOrEqualTo(11.0));
    expect(AppType.eyebrow.letterSpacing, lessThanOrEqualTo(1.2));
  });

  testWidgets('Product details variant swatch shows checkmark when selected', (tester) async {
    AppColors.apply(Brightness.light);
    GoogleFonts.config.allowRuntimeFetching = false;
    tester.view.physicalSize = const Size(800, 1600);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(() {
      tester.view.resetPhysicalSize();
      tester.view.resetDevicePixelRatio();
    });

    const product = Product(
      id: 'color-test',
      name: 'Ceramic Mug',
      description: 'Handmade mug',
      price: 450,
      sellerName: 'Studio Potter',
      rating: 4.8,
      ratingCount: 12,
      category: 'Home & Living',
      images: ['https://example.com/mug.jpg'],
      variants: ['Black', 'White', 'Sage'],
      stock: 10,
    );

    await tester.pumpWidget(
      MultiProvider(
        providers: [
          ChangeNotifierProvider(create: (_) => CartState()),
          ChangeNotifierProvider(create: (_) => CourierState()),
          ChangeNotifierProvider(create: (_) => OrderState()),
          ChangeNotifierProvider(create: (_) => RecentlyViewedState()),
          ChangeNotifierProvider(create: (_) => WishlistState()),
          ChangeNotifierProvider(create: (_) => ReviewState()),
        ],
        child: MaterialApp(
          theme: AppTheme.light,
          home: const ProductDetailsScreen(product: product),
        ),
      ),
    );

    // Initial pump bare for animation ticker
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 300));

    // The first variant is selected by default (index 0)
    // Verify an Icons.check icon is visible for selected swatch
    expect(find.byIcon(Icons.check), findsOneWidget);
  });
}
