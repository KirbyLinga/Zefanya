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
import 'package:zefanya/screens/buyer/categories_screen.dart';
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
  Size size = const Size(390, 780),
}) async {
  AppColors.apply(Brightness.light);
  GoogleFonts.config.allowRuntimeFetching = false;
  tester.view.physicalSize = size;
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

/// The labels the *server* curates, longest included. That is the point: the mock
/// had three short names, every fixture used them, and a tile built for one line
/// held them perfectly right up until a real catalogue arrived.
const List<String> _serverCategories = [
  'Books and Media',
  'Health and Beauty',
  'Sports and Outdoors',
  'Home and Garden',
  'Kids and Baby',
  "Men's Apparel",
  "Women's Apparel",
  'Electronics and Gadgets',
  'Pet Supplies',
  'Food and Gourmet',
  'Automotive & Motorcycle',
  'Furniture and Office Equipment',
  'Jewelry and Watches',
  'Office and School Supplies',
];

/// A product shaped like a server row, for the fixture below.
Product _row(String id, String category) => productFromJson({
      'id': id,
      'name': 'Item $id',
      'category': category,
      'price_minor': 10000,
      'in_stock': true,
      'seller': {'name': 'Loom & Larder'},
      'description': '',
      'images': const <String>[],
    });

/// A catalogue shaped like the server's: every curated label, and the **longest**
/// label holding the most products — so it lands in the feature panel at the top
/// *and* in the grid, which are the two places a one-line assumption overflowed.
class _ServerShapedCatalog implements CatalogRepository {
  List<Product> get _all => [
        _row('l1', 'Furniture and Office Equipment'),
        _row('l2', 'Furniture and Office Equipment'),
        _row('l3', 'Office and School Supplies'),
      ];

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
  Future<List<String>> categories() async => const ['All', ..._serverCategories];

  @override
  Future<String?> makerNote(String sellerName) async => null;

  @override
  Future<List<PromoSlide>> promoSlides() async => const [];
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

  test('every label the app can be handed a category has its own icon', () {
    // The server curates fourteen and the mock has its own set. A label with no
    // entry renders the neutral fallback — which is how most of a live build's tiles
    // looked, because the map only knew the mock's three short names.
    for (final label in [..._serverCategories, ...mockCategories.where((c) => c != 'All')]) {
      expect(categoryIcons[label], isNotNull, reason: '$label has no icon');
    }

    // And nothing invented: an entry for a label that cannot arrive is a typo that
    // would silently never render.
    final known = {..._serverCategories, ...mockCategories.where((c) => c != 'All')};

    for (final label in categoryIcons.keys) {
      expect(known, contains(label), reason: '$label is not a label the app can receive');
    }
  });

  testWidgets("the categories tab holds the server's own labels without overflowing",
      (tester) async {
    // Every existing fixture used the mock's three short names, which fit on one
    // line and hid this completely. "Furniture and Office Equipment" is what a real
    // label looks like, and a Flutter test fails on a RenderFlex overflow — so this
    // asserts the geometry rather than any copy.
    final catalog = CatalogState(repository: _ServerShapedCatalog());
    await catalog.load();

    await _pump(tester, const CategoriesScreen(), catalog: catalog);

    expect(find.text('Furniture and Office Equipment'), findsWidgets);
    expect(tester.takeException(), isNull);
  });

  testWidgets('and it still fits on a narrow phone', (tester) async {
    // 320 logical pixels: the tiles are ~132 wide there, where a two-line label is
    // the difference between fitting and not.
    final catalog = CatalogState(repository: _ServerShapedCatalog());
    await catalog.load();

    await _pump(tester, const CategoriesScreen(), catalog: catalog, size: const Size(320, 640));

    expect(tester.takeException(), isNull);
  });
}
