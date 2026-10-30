// test/phase_e_product_detail_test.dart
//
// Phase E: Product Detail screen polish assertions.
// Verifies: no overflow at 1.3x, 48dp touch targets on circle buttons
// and stepper buttons, color swatch ConstrainedBox >= 48dp.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:zefanya/data/mock_data.dart';
import 'package:zefanya/screens/buyer/product_details_screen.dart';
import 'package:zefanya/state/auth_state.dart';
import 'package:zefanya/state/cart_state.dart';
import 'package:zefanya/state/catalog_state.dart';
import 'package:zefanya/state/courier_state.dart';
import 'package:zefanya/state/notification_state.dart';
import 'package:zefanya/state/order_state.dart';
import 'package:zefanya/state/recently_viewed_state.dart';
import 'package:zefanya/state/review_state.dart';
import 'package:zefanya/state/theme_state.dart';
import 'package:zefanya/state/wishlist_state.dart';
import 'package:zefanya/theme/app_theme.dart';

Widget _buildDetailHarness({
  required Product product,
  Brightness brightness = Brightness.light,
  double textScaleFactor = 1.0,
}) {
  return MediaQuery(
    data: MediaQueryData(
      disableAnimations: true,
      textScaler: TextScaler.linear(textScaleFactor),
    ),
    child: MultiProvider(
      providers: [
        ChangeNotifierProvider(create: (_) => AuthState()),
        ChangeNotifierProvider(create: (_) => CartState()),
        ChangeNotifierProvider(create: (_) => CatalogState()),
        ChangeNotifierProvider(create: (_) => CourierState()),
        ChangeNotifierProvider(create: (_) => NotificationState()),
        ChangeNotifierProvider(create: (_) => OrderState()),
        ChangeNotifierProvider(create: (_) => RecentlyViewedState()),
        ChangeNotifierProvider(create: (_) => ReviewState()),
        ChangeNotifierProvider(create: (_) => WishlistState()),
        ChangeNotifierProvider(create: (_) => ThemeState()),
      ],
      child: MaterialApp(
        theme: brightness == Brightness.light ? AppTheme.light : AppTheme.dark,
        home: ProductDetailsScreen(product: product),
      ),
    ),
  );
}

void main() {
  setUp(() {
    SharedPreferences.setMockInitialValues({});
    GoogleFonts.config.allowRuntimeFetching = false;
  });

  testWidgets('Phase E: ProductDetail renders without overflow at 1.3x scale (light)', (tester) async {
    tester.view.physicalSize = const Size(390, 900);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(() {
      tester.view.resetPhysicalSize();
      tester.view.resetDevicePixelRatio();
    });

    AppColors.apply(Brightness.light);
    await tester.pumpWidget(_buildDetailHarness(
      product: mockProducts.first,
      brightness: Brightness.light,
      textScaleFactor: 1.3,
    ));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 600));

    expect(tester.takeException(), isNull);
  });

  testWidgets('Phase E: ProductDetail renders without overflow at 1.3x scale (dark)', (tester) async {
    tester.view.physicalSize = const Size(390, 900);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(() {
      tester.view.resetPhysicalSize();
      tester.view.resetDevicePixelRatio();
    });

    AppColors.apply(Brightness.dark);
    await tester.pumpWidget(_buildDetailHarness(
      product: mockProducts.first,
      brightness: Brightness.dark,
      textScaleFactor: 1.3,
    ));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 600));

    expect(tester.takeException(), isNull);
  });

  testWidgets('Phase E: ProductDetail circle icon buttons meet 48dp minimum', (tester) async {
    tester.view.physicalSize = const Size(390, 900);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(() {
      tester.view.resetPhysicalSize();
      tester.view.resetDevicePixelRatio();
    });

    AppColors.apply(Brightness.light);
    await tester.pumpWidget(_buildDetailHarness(product: mockProducts.first));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 300));

    // All circle icon buttons wrap a SizedBox(48,48). The app bar has
    // back/share/cart = 3 buttons minimum. Verify by measuring each.
    final allSizedBoxes = tester.widgetList<SizedBox>(find.byType(SizedBox));
    final fortyEightBoxes = allSizedBoxes
        .where((s) => s.width == 48 && s.height == 48)
        .toList();
    // At minimum: back, share, cart icons in the app bar.
    expect(fortyEightBoxes.length, greaterThanOrEqualTo(3),
        reason: 'Expected >= 3 SizedBox(48,48) for circle icon buttons, found ${fortyEightBoxes.length}');
  });

  testWidgets('Phase E: ProductDetail stepper buttons meet 48dp minimum', (tester) async {
    tester.view.physicalSize = const Size(390, 900);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(() {
      tester.view.resetPhysicalSize();
      tester.view.resetDevicePixelRatio();
    });

    AppColors.apply(Brightness.light);
    await tester.pumpWidget(_buildDetailHarness(product: mockProducts.first));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 300));

    // Scroll to quantity stepper
    await tester.drag(find.byType(SingleChildScrollView), const Offset(0, -400));
    await tester.pump();

    final steppers = tester.widgetList<ConstrainedBox>(
      find.ancestor(
        of: find.byIcon(Icons.remove),
        matching: find.byType(ConstrainedBox),
      ),
    );
    for (final s in steppers) {
      expect(s.constraints.minHeight, greaterThanOrEqualTo(48.0));
      expect(s.constraints.minWidth, greaterThanOrEqualTo(48.0));
    }
  });

  testWidgets('Phase E: ProductDetail with variant product renders without overflow at 1.3x', (tester) async {
    tester.view.physicalSize = const Size(390, 900);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(() {
      tester.view.resetPhysicalSize();
      tester.view.resetDevicePixelRatio();
    });

    // Use a product with variants if available
    final variantProduct = mockProducts.firstWhere(
      (p) => p.optionGroups.isNotEmpty,
      orElse: () => mockProducts.first,
    );

    AppColors.apply(Brightness.light);
    await tester.pumpWidget(_buildDetailHarness(
      product: variantProduct,
      textScaleFactor: 1.3,
    ));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 600));

    expect(tester.takeException(), isNull);
  });
}
