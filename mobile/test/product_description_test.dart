// test/product_description_test.dart
//
// S5.6: a long seller description is three lines with an explicit "Read more"
// rather than a wall of copy that pushes the price and the Add to Cart button
// off the screen. Short copy gets no toggle, because a control that reveals
// nothing is worse than no control at all.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:zefanya/data/mock_data.dart';
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

const _longDescription =
    'Hand-woven from reclaimed rattan and finished with a water-based sealant, '
    'this piece is made in small batches by a family workshop in Cebu. Each '
    'strand is soaked, bent by hand and left to dry for three days, so no two '
    'pieces carry exactly the same curve. The interior is lined with unbleached '
    'cotton canvas, and the leather handle softens with use. Wipe with a damp '
    'cloth; avoid direct sunlight for long periods. Because the weave is '
    'natural, small variations in tone and grain are expected and are not a '
    'fault. Packed in recycled kraft paper and shipped flat to reduce waste.';

Product _product({String description = _longDescription}) => Product(
  id: 'p-long',
  name: 'Hand-woven Rattan Tote',
  category: 'Home & Living',
  price: 699,
  sellerName: 'Rattan & Reed Co.',
  description: description,
);

Widget _app(Product product) {
  return MediaQuery(
    data: const MediaQueryData(
      disableAnimations: true,
      size: Size(390, 1800),
    ),
    child: MultiProvider(
      providers: [
        ChangeNotifierProvider(create: (_) => CartState()),
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

Text _description(WidgetTester tester) => tester.widget<Text>(
  find.byKey(const ValueKey('product-description')),
);

void main() {
  setUp(() {
    SharedPreferences.setMockInitialValues({});
    GoogleFonts.config.allowRuntimeFetching = false;
    AppColors.apply(Brightness.light);
  });

  testWidgets('long copy starts at three lines with a Read more control', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(390, 1800);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(_app(_product()));
    await tester.pump();

    final toggle = find.byKey(const ValueKey('description-toggle'));
    expect(toggle, findsOneWidget);
    expect(find.text('Read more'), findsOneWidget);
    expect(_description(tester).maxLines, 3);

    await tester.ensureVisible(toggle);
    await tester.pump();
    await tester.tap(toggle);
    await tester.pump();

    expect(find.text('Show less'), findsOneWidget);
    expect(_description(tester).maxLines, isNull);

    await tester.tap(toggle);
    await tester.pump();

    expect(find.text('Read more'), findsOneWidget);
    expect(_description(tester).maxLines, 3);

    await tester.pumpWidget(const SizedBox.shrink());
  });

  testWidgets('short copy gets no toggle', (tester) async {
    tester.view.physicalSize = const Size(390, 1800);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(
      _app(_product(description: 'Hand-woven rattan tote with a cotton lining.')),
    );
    await tester.pump();

    expect(find.byKey(const ValueKey('product-description')), findsOneWidget);
    expect(find.byKey(const ValueKey('description-toggle')), findsNothing);
    expect(find.text('Read more'), findsNothing);
    expect(find.text('Show less'), findsNothing);

    await tester.pumpWidget(const SizedBox.shrink());
  });

  testWidgets('an empty description says so and offers no toggle', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(390, 1800);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(_app(_product(description: '')));
    await tester.pump();

    expect(find.text('No description provided yet.'), findsOneWidget);
    expect(find.byKey(const ValueKey('description-toggle')), findsNothing);

    await tester.pumpWidget(const SizedBox.shrink());
  });
}
