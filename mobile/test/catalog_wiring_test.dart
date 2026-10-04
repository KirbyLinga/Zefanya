// test/catalog_wiring_test.dart
//
// The browsing screens render the catalogue, not `data/mock_data.dart`.
//
// The tests that were missing. Each one hands a screen a catalogue built from the
// wire mapper — products that are not in `mockProducts` at all — and asserts the
// screen renders those. With the old code every one of these would have failed by
// rendering the mock products instead, which is exactly what a live build did on a
// phone while the server held thirteen real ones.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:zefanya/data/dto/remote_catalog.dart';
import 'package:zefanya/data/mock_data.dart';
import 'package:zefanya/data/repositories/catalog_repository.dart';
import 'package:zefanya/screens/buyer/category_products_screen.dart';
import 'package:zefanya/screens/buyer/seller_screen.dart';
import 'package:zefanya/screens/buyer/widgets/review_button.dart';
import 'package:zefanya/state/cart_state.dart';
import 'package:zefanya/state/catalog_state.dart';
import 'package:zefanya/state/courier_state.dart';
import 'package:zefanya/state/order_state.dart';
import 'package:zefanya/state/recently_viewed_state.dart';
import 'package:zefanya/state/review_state.dart';
import 'package:zefanya/state/wishlist_state.dart';
import 'package:zefanya/theme/app_theme.dart';

final Product _bodyOil = productFromJson({
  'id': 'l14',
  'name': 'Ilang-Ilang Body Oil',
  'category': 'Health and Beauty',
  'price_minor': 76000,
  'in_stock': true,
  'seller': {'name': 'Alon Botanicals'},
  'description': 'Ilang-ilang distilled in Bohol.',
  'images': ['https://picsum.photos/seed/zef-ilang-ilang-body-oil-0/1000/1000'],
});

/// A catalogue that shares nothing with `mockProducts`.
class _ForeignCatalog implements CatalogRepository {
  List<Product> get _all => [_bodyOil];

  @override
  Future<List<Product>> products({CatalogQuery? query}) async => _all;

  @override
  Future<List<Product>> productsInCategory(String category) async =>
      [for (final product in _all) if (product.category == category) product];

  @override
  Future<Product?> productById(String id) async {
    for (final product in _all) {
      if (product.id == id) return product;
    }

    return null;
  }

  @override
  Future<List<String>> categories() async => const ['All', 'Health and Beauty'];

  @override
  Future<String?> makerNote(String sellerName) async => null;

  @override
  Future<List<PromoSlide>> promoSlides() async => const [];
}

Future<void> _pump(
  WidgetTester tester,
  Widget child, {
  required CatalogState catalog,
  ReviewState? reviews,
}) async {
  AppColors.apply(Brightness.light);
  GoogleFonts.config.allowRuntimeFetching = false;
  tester.view.physicalSize = const Size(390, 780);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  await tester.pumpWidget(
    MultiProvider(
      providers: [
        // The set the buyer screens read: the catalogue for the products, and the
        // rest because the cards on them carry a heart, a cart tag and a
        // purchased-before rail. Same harness as `review_flow_test`.
        ChangeNotifierProvider(create: (_) => CartState()),
        ChangeNotifierProvider.value(value: catalog),
        ChangeNotifierProvider(create: (_) => CourierState()),
        ChangeNotifierProvider(create: (_) => OrderState()),
        ChangeNotifierProvider(create: (_) => RecentlyViewedState()),
        ChangeNotifierProvider(create: (_) => WishlistState()),
        ChangeNotifierProvider(create: (_) => reviews ?? ReviewState()),
      ],
      child: MaterialApp(theme: AppTheme.light, home: child),
    ),
  );
  await tester.pump();
}

void main() {
  setUp(() => SharedPreferences.setMockInitialValues({}));

  testWidgets('the category grid renders the catalogue it was handed', (tester) async {
    final catalog = CatalogState(repository: _ForeignCatalog());
    await catalog.load();

    await _pump(tester, const CategoryProductsScreen(category: 'Health and Beauty'), catalog: catalog);
    await tester.pump(const Duration(milliseconds: 800));

    expect(find.text('Ilang-Ilang Body Oil'), findsOneWidget);
    // Nothing from the mock file: rendering any of those is the bug, and it is what
    // a live build did before this wiring existed.
    expect(find.text(mockProducts.first.name), findsNothing);
  });

  testWidgets('the seller screen lists what that shop sells, from the catalogue', (tester) async {
    final catalog = CatalogState(repository: _ForeignCatalog());
    await catalog.load();

    await _pump(tester, const SellerScreen(sellerName: 'Alon Botanicals'), catalog: catalog);
    await tester.pump(const Duration(milliseconds: 800));

    expect(find.text('Ilang-Ilang Body Oil'), findsOneWidget);
  });

  testWidgets('the review button joins an order to the catalogue, not to the mock file',
      (tester) async {
    final catalog = CatalogState(repository: _ForeignCatalog());
    await catalog.load();

    // An order whose only product exists in the catalogue and nowhere else. With a
    // mock-only lookup the button found no products and rendered nothing at all,
    // so a reviewable order in a live build silently lost its review button.
    const order = Order(
      id: 'ORD-L14',
      shopName: 'Alon Botanicals',
      status: OrderStatus.toRate,
      eta: 'Delivered',
      itemSummary: 'Ilang-Ilang Body Oil',
      productIds: ['l14'],
    );

    await _pump(tester, const ReviewButton(order: order), catalog: catalog);

    expect(find.textContaining('Review'), findsWidgets);
  });
}
