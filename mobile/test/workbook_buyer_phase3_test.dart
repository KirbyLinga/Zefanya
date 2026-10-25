// test/workbook_buyer_phase3_test.dart
//
// Case-named acceptance tests for the Buyer B-cluster gaps closed in Phase 3.
// Each group is named after its workbook row so the evidence maps mechanically:
//
//   BUY-ACCT-002  profile field validation
//   BUY-VOU-004   minimum spend at the boundary
//   BUY-VOU-006   per-buyer usage limit
//   BUY-VOU-007   removing an item drops below the minimum
//   BUY-VOU-008   percentage voucher cap
//   BUY-VOU-009   shipping voucher larger than shipping
//   BUY-PDP-007   Buy Now checks out only that item
//   BUY-CHK-001   worked example: merchandise, shipping, three vouchers, total
//   BUY-CHK-005   delivery note caps at 200 characters
//   BUY-CHK-006   Cash on Delivery is preselected
//   BUY-CHK-007   Place Order cannot be submitted twice
//   BUY-CHK-009   stock ran out during checkout
//   BUY-CHK-010   voucher no longer qualifies when Place Order is tapped
//   BUY-SRCH-004  typo suggestion and honest no-results state
//   BUY-SRCH-007  price range validation and inclusive bounds
//   BUY-SRCH-012  Search key closes the keyboard; pull-to-refresh reloads
//
// The money fixtures mirror the workbook's worked example (p2 + p6,
// MCH50 + PLAT100 + FREESHIP) so the asserted numbers are the workbook's own.

import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:zefanya/config/pricing.dart';
import 'package:zefanya/data/mock_data.dart';
import 'package:zefanya/data/repositories/cart_repository.dart';
import 'package:zefanya/data/repositories/catalog_repository.dart';
import 'package:zefanya/data/repositories/order_repository.dart';
import 'package:zefanya/screens/buyer/checkout_screen.dart';
import 'package:zefanya/screens/buyer/product_details_screen.dart';
import 'package:zefanya/screens/buyer/search_screen.dart';
import 'package:zefanya/screens/buyer/widgets/product_card.dart';
import 'package:zefanya/screens/shared/edit_profile_screen.dart';
import 'package:zefanya/state/auth_state.dart';
import 'package:zefanya/state/cart_state.dart';
import 'package:zefanya/state/catalog_state.dart';
import 'package:zefanya/state/courier_state.dart';
import 'package:zefanya/state/order_state.dart';
import 'package:zefanya/state/recently_viewed_state.dart';
import 'package:zefanya/state/review_state.dart';
import 'package:zefanya/state/theme_state.dart';
import 'package:zefanya/state/wishlist_state.dart';
import 'package:zefanya/theme/app_theme.dart';
import 'package:zefanya/utils/money_format.dart';
import 'package:zefanya/utils/search_filter.dart';

Product _mock(String id) => mockProducts.firstWhere((p) => p.id == id);

Product _line({
  required String id,
  required double price,
  int stock = 10,
  String seller = 'Rattan & Reed Co.',
  double? shippingFee,
  String category = 'Fashion',
}) => Product(
  id: id,
  name: 'Item $id',
  category: category,
  price: price,
  sellerName: seller,
  stock: stock,
  shippingFee: shippingFee,
);

/// A voucher catalogue with exactly the fixtures a boundary test needs.
class _FixedVouchers implements CartRepository {
  const _FixedVouchers(this.catalogue);

  final List<Voucher> catalogue;

  @override
  Future<List<Voucher>> vouchers() async => catalogue;
}

Voucher _storeVoucher() => Voucher(
  code: 'MCH50',
  title: 'Store ₱50 off',
  discountLabel: '₱50 off this store',
  minSpend: 300,
  expiryLabel: 'No expiry',
  fixedDiscount: 50,
  scope: VoucherScope.store,
  sellerName: 'Rattan & Reed Co.',
);

Voucher _percentVoucher() => Voucher(
  code: 'PCT10',
  title: '10% off',
  discountLabel: '10% off, up to ₱100',
  minSpend: 500,
  expiryLabel: 'No expiry',
  discountRate: 0.10,
  maxDiscount: 100,
);

Voucher _shippingVoucher() => Voucher(
  code: 'SHIP50',
  title: '₱50 off shipping',
  discountLabel: '₱50 off shipping',
  minSpend: 0,
  expiryLabel: 'No expiry',
  discountRate: 0,
  maxDiscount: 50,
  scope: VoucherScope.shipping,
);

Future<CartState> _cart({List<Voucher>? vouchers}) async {
  final state = CartState(
    vouchers: vouchers == null
        ? const MockCartRepository()
        : _FixedVouchers(vouchers),
  );
  await state.load();
  return state;
}

AuthState _seededAuth() {
  final auth = AuthState();
  auth.updateProfile(
    firstName: 'Marielle',
    lastName: 'Santos',
    sex: 'Female',
    phone: '09171234567',
    province: 'Metro Manila',
    municipality: 'Manila',
    barangay: 'Poblacion',
    street: '12 Mabini Street',
  );
  auth.ensureAddressBookSeeded();
  return auth;
}

void _useSurface(WidgetTester tester, {Size size = const Size(500, 1600)}) {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);
}

Future<void> _pumpCheckout(
  WidgetTester tester, {
  required AuthState auth,
  required CartState cart,
  OrderState? orders,
  List<CartItem>? checkoutItems,
}) async {
  _useSurface(tester);
  await tester.pumpWidget(
    MediaQuery(
      data: const MediaQueryData(disableAnimations: true),
      child: MultiProvider(
        providers: [
          ChangeNotifierProvider<AuthState>.value(value: auth),
          ChangeNotifierProvider<CartState>.value(value: cart),
          ChangeNotifierProvider<OrderState>.value(
            value: orders ?? OrderState(),
          ),
          ChangeNotifierProvider<CourierState>(create: (_) => CourierState()),
        ],
        child: MaterialApp(
          theme: AppTheme.light,
          home: CheckoutScreen(checkoutItems: checkoutItems),
        ),
      ),
    ),
  );
  await tester.pump();
  await tester.pump(const Duration(milliseconds: 400));
}

Future<void> _agreeToTerms(WidgetTester tester) async {
  await tester.scrollUntilVisible(
    find.byType(Checkbox),
    300,
    scrollable: find.byType(Scrollable).first,
  );
  await tester.pump();
  await tester.tap(find.byType(Checkbox));
  await tester.pump();
}

Future<void> _pumpSearch(
  WidgetTester tester,
  CatalogState catalog,
) async {
  _useSurface(tester, size: const Size(420, 1000));
  await tester.pumpWidget(
    MediaQuery(
      data: const MediaQueryData(disableAnimations: true),
      child: MultiProvider(
        providers: [
          ChangeNotifierProvider<CatalogState>.value(value: catalog),
          ChangeNotifierProvider(create: (_) => WishlistState()),
        ],
        child: MaterialApp(theme: AppTheme.light, home: const SearchScreen()),
      ),
    ),
  );
  await tester.pump(const Duration(milliseconds: 100));
}

/// Counts `products()` calls so pull-to-refresh can be proven to have run.
class _CountingCatalog extends MockCatalogRepository {
  int loads = 0;

  @override
  Future<List<Product>> products({CatalogQuery? query}) async {
    loads++;
    return super.products(query: query);
  }
}

/// An order repository that holds `place()` open until the test releases it,
/// so the double-tap window is observable rather than raced through.
class _ControlledOrders implements OrderRepository {
  _ControlledOrders({this.hold = false});

  final bool hold;
  final Completer<void> _gate = Completer<void>();
  final List<Order> placed = [];
  int placeCalls = 0;

  void release() => _gate.complete();

  @override
  Future<void> load() async {}

  @override
  Future<List<Order>> orders() async => List.unmodifiable(placed);

  @override
  Future<List<Order>> place(List<OrderLineRequest> lines) async {
    placeCalls++;
    if (hold) await _gate.future;
    if (lines.isEmpty) return const [];

    final first = lines.first;
    final quantities = <String, int>{};
    final ids = <String>[];
    for (final line in lines) {
      ids.add(line.productId);
      quantities.update(
        line.productId,
        (value) => value + line.quantity,
        ifAbsent: () => line.quantity,
      );
    }
    final order = Order(
      id: 'ORD-9000${placed.length + 1}',
      shopName: first.sellerName,
      status: OrderStatus.toShip,
      eta: 'Preparing your order',
      itemSummary: first.productName,
      productIds: ids,
      productQuantities: quantities,
    );
    placed.insert(0, order);
    return [order];
  }

  @override
  Future<void> saveBuyerState(Order order) async {}
}

void main() {
  setUp(() {
    SharedPreferences.setMockInitialValues({});
    GoogleFonts.config.allowRuntimeFetching = false;
    AppColors.apply(Brightness.light);
  });

  group('BUY-ACCT-002 profile field validation', () {
    testWidgets('an invalid name is refused with a field message', (
      tester,
    ) async {
      _useSurface(tester, size: const Size(420, 1200));
      final auth = _seededAuth();
      await tester.pumpWidget(
        ChangeNotifierProvider<AuthState>.value(
          value: auth,
          child: MaterialApp(
            theme: AppTheme.light,
            home: const EditProfileScreen(),
          ),
        ),
      );
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 300));

      await tester.enterText(find.byType(TextFormField).at(1), 'Juan123');
      await tester.pump();
      expect(
        find.text(
          'First name may only contain letters, spaces, hyphens, '
          "apostrophes and periods",
        ),
        findsOneWidget,
      );

      await tester.scrollUntilVisible(
        find.text('Save Changes'),
        300,
        scrollable: find.byType(Scrollable).first,
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('Save Changes'));
      await tester.pumpAndSettle();

      expect(auth.firstName, 'Marielle');
      expect(auth.name, contains('Marielle'));
    });
  });

  group('BUY-VOU-004 minimum spend at the boundary', () {
    test('₱299.99 is short by ₱0.01; ₱300.00 and ₱300.01 earn ₱50 off',
        () async {
      final carts = <double, CartState>{};
      for (final price in [299.99, 300.00, 300.01]) {
        final cart = await _cart(vouchers: [_storeVoucher()]);
        cart.addItem(_line(id: 'P-${price.toStringAsFixed(2)}', price: price));
        carts[price] = cart;
      }

      final short = carts[299.99]!;
      final result = short.applyVoucher('MCH50');
      expect(result.status, VoucherApplyStatus.minimumSpend);
      expect(
        result.message,
        'Spend ₱0.01 more at Rattan & Reed Co. to use "MCH50".',
      );
      expect(short.appliedVoucherCodes, isEmpty);
      expect(short.quote.merchandiseDiscount, 0);

      for (final price in [300.00, 300.01]) {
        final cart = carts[price]!;
        final applied = cart.applyVoucher('MCH50');
        expect(
          applied.status,
          VoucherApplyStatus.applied,
          reason: '₱${price.toStringAsFixed(2)} meets the inclusive minimum',
        );
        expect(cart.appliedVoucherCodes, ['MCH50']);
        expect(cart.quote.merchandiseDiscount, closeTo(50, 0.001));
      }
    });
  });

  group('BUY-VOU-006 per-buyer limit', () {
    test('a used-up code stays refused after a cart restart', () async {
      final first = await _cart();
      first.addItem(_mock('p2'));
      expect(first.applyVoucher('MCH50').status, VoucherApplyStatus.applied);
      await first.recordAppliedVouchersUsed();
      expect(first.buyerUsesFor('MCH50'), 1);
      expect(first.appliedVoucherCodes, isEmpty);

      final second = await _cart();
      second.addItem(_mock('p2'));
      final again = second.applyVoucher('MCH50');
      expect(again.status, VoucherApplyStatus.usageLimit);
      expect(again.message, contains('usage limit'));
      expect(second.appliedVoucherCodes, isEmpty);
    });
  });

  group('BUY-VOU-007 removing an item drops below the minimum', () {
    test('the store voucher is pruned and the total returns to ₱249.00',
        () async {
      final cart = await _cart(vouchers: [_storeVoucher()]);
      cart.addItem(
        _line(id: 'P-199', price: 199, shippingFee: 50),
      );
      final removed = _line(id: 'P-101', price: 101, shippingFee: 50);
      cart.addItem(removed);
      expect(cart.applyVoucher('MCH50').status, VoucherApplyStatus.applied);
      expect(cart.quote.total, closeTo(300 + 50 - 50, 0.001));

      expect(cart.updateQuantity(cart.items.last, -1), isTrue);
      final notices = cart.pruneAppliedVouchers();

      expect(notices, hasLength(1));
      expect(notices.single, contains('dropped below'));
      expect(notices.single, contains('₱300'));
      expect(cart.appliedVoucherCodes, isEmpty);
      expect(cart.quote.merchandise, closeTo(199, 0.001));
      expect(cart.quote.shipping, closeTo(50, 0.001));
      expect(cart.quote.discount, 0);
      expect(cart.quote.total, closeTo(249, 0.001));
      expect(formatPeso(cart.quote.total), '₱249');
    });
  });

  group('BUY-VOU-008 percentage voucher respects its cap', () {
    test('below ₱500 is refused; 10% applies and caps at ₱100', () async {
      Future<CartQuote> quoteFor(double price) async {
        final cart = await _cart(vouchers: [_percentVoucher()]);
        cart.addItem(_line(id: 'P-${price.toStringAsFixed(2)}', price: price));
        final result = cart.applyVoucher('PCT10');
        if (price < 500) {
          expect(result.status, VoucherApplyStatus.minimumSpend);
          expect(
            result.message,
            'Spend ₱0.01 more to use "PCT10".',
          );
          expect(cart.appliedVoucherCodes, isEmpty);
        } else {
          expect(result.status, VoucherApplyStatus.applied);
        }
        return cart.quote;
      }

      final below = await quoteFor(499.99);
      expect(below.discount, 0);

      final nineNinetyNine = await quoteFor(999);
      expect(nineNinetyNine.merchandiseDiscount, closeTo(99.90, 0.001));

      final thousand = await quoteFor(1000);
      expect(thousand.merchandiseDiscount, closeTo(100, 0.001));

      final cap = await quoteFor(1250);
      expect(cap.merchandiseDiscount, closeTo(100, 0.001));
    });
  });

  group('BUY-VOU-009 shipping voucher larger than shipping', () {
    test('₱50 shipping becomes free; ₱65 becomes ₱15; goods unchanged',
        () async {
      Future<CartQuote> quoteFor(double fee) async {
        final cart = await _cart(vouchers: [_shippingVoucher()]);
        cart.addItem(_line(id: 'P-$fee', price: 500, shippingFee: fee));
        expect(cart.applyVoucher('SHIP50').status, VoucherApplyStatus.applied);
        return cart.quote;
      }

      final fifty = await quoteFor(50);
      expect(fifty.merchandise, closeTo(500, 0.001));
      expect(fifty.shipping, closeTo(50, 0.001));
      expect(fifty.shippingDiscount, closeTo(50, 0.001));
      expect(fifty.total, closeTo(500, 0.001));

      final sixtyFive = await quoteFor(65);
      expect(sixtyFive.merchandise, closeTo(500, 0.001));
      expect(sixtyFive.shipping, closeTo(65, 0.001));
      expect(sixtyFive.shippingDiscount, closeTo(50, 0.001));
      expect(sixtyFive.total, closeTo(515, 0.001));
    });
  });

  group('BUY-PDP-007 Buy Now checks out only that item', () {
    testWidgets('checkout receives p1 alone while p6 stays in the cart',
        (tester) async {
      _useSurface(tester, size: const Size(420, 1200));
      final cart = await _cart();
      cart.addItem(_mock('p6'));
      final auth = _seededAuth();
      final orders = OrderState();
      await orders.load();

      await tester.pumpWidget(
        MediaQuery(
          data: const MediaQueryData(disableAnimations: true),
          child: MultiProvider(
            providers: [
              ChangeNotifierProvider<CartState>.value(value: cart),
              ChangeNotifierProvider<OrderState>.value(value: orders),
              ChangeNotifierProvider<AuthState>.value(value: auth),
              ChangeNotifierProvider(create: (_) => WishlistState()),
              ChangeNotifierProvider(create: (_) => RecentlyViewedState()),
              ChangeNotifierProvider(create: (_) => ReviewState()),
              ChangeNotifierProvider(create: (_) => CourierState()),
              ChangeNotifierProvider(create: (_) => CatalogState()),
              ChangeNotifierProvider(create: (_) => ThemeState()),
            ],
            child: MaterialApp(
              theme: AppTheme.light,
              home: ProductDetailsScreen(product: _mock('p1')),
            ),
          ),
        ),
      );
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 300));

      await tester.tap(find.text('Buy Now'));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 500));
      // The 700ms double-tap cooldown on Product Details is still live;
      // drain it so the test does not end with a pending timer.
      await tester.pump(const Duration(milliseconds: 300));

      final checkout = tester.widget<CheckoutScreen>(
        find.byType(CheckoutScreen),
      );
      expect(checkout.checkoutItems, hasLength(1));
      expect(checkout.checkoutItems!.single.product.id, 'p1');
      expect(checkout.checkoutItems!.single.quantity, 1);
      expect(cart.items.map((item) => item.product.id), ['p6']);
    });
  });

  group('BUY-CHK-001 checkout with the worked example', () {
    testWidgets('summary shows ₱1,098 + ₱185 − ₱200 = ₱1,083', (tester) async {
      final cart = await _cart();
      cart.addItem(_mock('p2'));
      cart.addItem(_mock('p6'));
      expect(cart.applyVoucher('MCH50').status, VoucherApplyStatus.applied);
      expect(cart.applyVoucher('PLAT100').status, VoucherApplyStatus.applied);
      expect(cart.applyVoucher('FREESHIP').status, VoucherApplyStatus.applied);

      await _pumpCheckout(tester, auth: _seededAuth(), cart: cart);

      await tester.tap(find.text('Order Summary'));
      await tester.pump(const Duration(milliseconds: 250));

      expect(find.text('Subtotal'), findsOneWidget);
      expect(find.text('₱1,098'), findsOneWidget);
      expect(find.text('Shipping fee'), findsOneWidget);
      expect(find.text('₱185'), findsOneWidget);
      expect(find.text('Store voucher (MCH50)'), findsOneWidget);
      expect(find.text('Platform voucher (PLAT100)'), findsOneWidget);
      expect(find.text('Shipping voucher (FREESHIP)'), findsOneWidget);
      expect(find.text('- ₱50'), findsNWidgets(2));
      expect(find.text('- ₱100'), findsOneWidget);
      expect(find.text('₱1,083'), findsWidgets);
    });

    test('the cart becomes one order per seller and empties', () async {
      final cart = await _cart();
      cart.addItem(_mock('p2'));
      cart.addItem(_mock('p6'));
      final orders = OrderState();
      await orders.load();

      final created = await orders.placeOrders(cart.items);
      expect(created, hasLength(2));
      expect(
        created.map((order) => order.shopName).toSet(),
        {'Rattan & Reed Co.', 'Sage & Clay Studio'},
      );

      cart.clearSelected();
      expect(cart.items, isEmpty);
    });
  });

  group('BUY-CHK-005 delivery note caps at 200 characters', () {
    testWidgets('the field accepts 200 and refuses the 201st', (tester) async {
      final cart = await _cart();
      cart.addItem(_mock('p2'));
      await _pumpCheckout(tester, auth: _seededAuth(), cart: cart);

      final field = tester.widget<TextField>(find.byType(TextField));
      expect(field.maxLength, 200);

      await tester.ensureVisible(find.byType(TextField));
      await tester.enterText(find.byType(TextField), 'a' * 201);
      await tester.pump();

      final rendered = tester.widget<TextField>(find.byType(TextField));
      expect(rendered.controller!.text.length, 200);
    });
  });

  group('BUY-CHK-006 payment method', () {
    testWidgets('Cash on Delivery is preselected', (tester) async {
      final cart = await _cart();
      cart.addItem(_mock('p2'));
      await _pumpCheckout(tester, auth: _seededAuth(), cart: cart);

      final group = tester.widget<RadioGroup<PaymentMethod>>(
        find.byType(RadioGroup<PaymentMethod>),
      );
      expect(group.groupValue, PaymentMethod.cod);
    });
  });

  group('BUY-CHK-007 double-tap Place Order', () {
    testWidgets('the button disables and the repository is called once',
        (tester) async {
      final cart = await _cart();
      cart.addItem(_mock('p2'));
      final repository = _ControlledOrders(hold: true);
      final orders = OrderState(repository: repository);
      await orders.load();

      await _pumpCheckout(
        tester,
        auth: _seededAuth(),
        cart: cart,
        orders: orders,
      );
      await _agreeToTerms(tester);

      await tester.tap(find.text('Place Order'));
      await tester.pump();

      expect(repository.placeCalls, 1);
      final button = tester.widget<ElevatedButton>(
        find.byType(ElevatedButton),
      );
      expect(button.onPressed, isNull);

      await tester.tap(find.byType(ElevatedButton));
      await tester.pump();
      expect(repository.placeCalls, 1);

      repository.release();
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 500));

      expect(orders.orders, hasLength(1));
      expect(orders.orders.single.shopName, 'Rattan & Reed Co.');
    });
  });

  group('BUY-CHK-009 stock ran out during checkout', () {
    testWidgets('an out-of-stock line blocks the order', (tester) async {
      final cart = await _cart();
      final repository = _ControlledOrders();
      final orders = OrderState(repository: repository);
      await orders.load();
      final soldOut = CartItem(product: _mock('p5'));

      await _pumpCheckout(
        tester,
        auth: _seededAuth(),
        cart: cart,
        orders: orders,
        checkoutItems: [soldOut],
      );
      await _agreeToTerms(tester);

      await tester.tap(find.text('Place Order'));
      await tester.pump(const Duration(milliseconds: 300));

      expect(
        find.text(
          'Dried Botanical Bouquet is no longer available in that quantity.',
        ),
        findsOneWidget,
      );
      expect(repository.placeCalls, 0);
    });
  });

  group('BUY-CHK-010 voucher becomes invalid during checkout', () {
    testWidgets('the code is removed, the message is shown, and the total moves',
        (tester) async {
      final cart = await _cart();
      cart.addItem(_line(id: 'P-199', price: 199));
      cart.addItem(_line(id: 'P-101', price: 101));
      expect(cart.applyVoucher('MCH50').status, VoucherApplyStatus.applied);

      cart.clearAll();
      cart.addItem(_line(id: 'P-199', price: 199));
      expect(cart.appliedVoucherCodes, ['MCH50']);

      await _pumpCheckout(tester, auth: _seededAuth(), cart: cart);
      await _agreeToTerms(tester);

      await tester.tap(find.text('Place Order'));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 100));

      expect(
        find.text('Voucher "MCH50" no longer meets its ₱300 minimum.'),
        findsOneWidget,
      );
      expect(cart.appliedVoucherCodes, isEmpty);
      expect(cart.quote.total, closeTo(259, 0.001));

      // Drain the timed snackbar so the test leaves no live timer behind.
      await tester.pump(const Duration(seconds: 4));
    });
  });

  group('BUY-SRCH-004 typo and no results', () {
    testWidgets('a one-letter typo offers the catalogue word, zzqxw does not',
        (tester) async {
      final catalog = CatalogState();
      await catalog.load();
      await _pumpSearch(tester, catalog);

      await tester.enterText(find.byType(TextField), 'ratan');
      await tester.pump(const Duration(milliseconds: 300));

      expect(find.byKey(const ValueKey('search-no-results')), findsOneWidget);
      expect(find.text('Did you mean “rattan”?'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('search-suggestion')));
      await tester.pump(const Duration(milliseconds: 300));

      expect(find.byKey(const ValueKey('search-results')), findsOneWidget);
      expect(find.byType(ProductCard), findsWidgets);
      expect((tester.widget<TextField>(find.byType(TextField))).controller!.text,
          'rattan');

      await tester.enterText(find.byType(TextField), 'zzqxw');
      await tester.pump(const Duration(milliseconds: 300));

      expect(find.byKey(const ValueKey('search-no-results')), findsOneWidget);
      expect(find.byKey(const ValueKey('search-suggestion')), findsNothing);
      expect(find.textContaining('No results for'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  });

  group('BUY-SRCH-007 price range filter', () {
    testWidgets('min > max and negatives are rejected without filtering',
        (tester) async {
      final catalog = CatalogState();
      await catalog.load();
      await _pumpSearch(tester, catalog);

      await tester.enterText(find.byType(TextField), 'linen');
      await tester.pump(const Duration(milliseconds: 300));
      final before = tester.widgetList<ProductCard>(find.byType(ProductCard)).length;

      await tester.tap(find.text('Price range'));
      await tester.pumpAndSettle();

      await tester.enterText(find.byKey(const ValueKey('min-price')), '5000');
      await tester.enterText(find.byKey(const ValueKey('max-price')), '100');
      await tester.tap(find.text('Apply'));
      await tester.pumpAndSettle();

      expect(
        find.text('Minimum price cannot be greater than maximum.'),
        findsOneWidget,
      );
      // The chip behind the dialog also reads "Price range"; the dialog is
      // still open, which is the point of the refusal.
      expect(find.text('Price range'), findsWidgets);

      await tester.enterText(find.byKey(const ValueKey('min-price')), '-5');
      await tester.tap(find.text('Apply'));
      await tester.pumpAndSettle();

      expect(find.text('Price cannot be negative.'), findsOneWidget);

      await tester.tap(find.text('Cancel'));
      await tester.pumpAndSettle();
      expect(
        tester.widgetList<ProductCard>(find.byType(ProductCard)).length,
        before,
      );
    });

    test('both ends are inclusive', () {
      final products = [
        _line(id: 'below', price: 99.99),
        _line(id: 'at-min', price: 100),
        _line(id: 'at-max', price: 200),
        _line(id: 'above', price: 200.01),
      ];
      final filtered = filterProducts(
        products,
        const SearchFilter(minPrice: 100, maxPrice: 200),
      );
      expect(filtered.map((p) => p.id), ['at-min', 'at-max']);
      expect(priceRangeError('100', '100'), isNull);
      expect(priceRangeError('-1', '100'), 'Price cannot be negative.');
      expect(
        priceRangeError('500', '100'),
        'Minimum price cannot be greater than maximum.',
      );
    });
  });

  group('BUY-SRCH-012 Search key and pull-to-refresh', () {
    testWidgets('Search closes the keyboard and pull-to-refresh reloads once',
        (tester) async {
      final repository = _CountingCatalog();
      final catalog = CatalogState(repository: repository);
      await catalog.load();
      await _pumpSearch(tester, catalog);

      final field = tester.widget<TextField>(find.byType(TextField));
      await tester.tap(find.byType(TextField));
      await tester.enterText(find.byType(TextField), 'linen');
      await tester.testTextInput.receiveAction(TextInputAction.search);
      await tester.pump(const Duration(milliseconds: 300));

      expect(find.byKey(const ValueKey('search-results')), findsOneWidget);
      expect(field.focusNode!.hasFocus, isFalse);
      expect(find.byType(ProductCard), findsWidgets);

      final seen = tester
          .widgetList<ProductCard>(find.byType(ProductCard))
          .map((card) => card.product.id)
          .toList();
      expect(seen.toSet().length, seen.length);

      final before = repository.loads;
      await tester.drag(
        find.byKey(const ValueKey('search-results')),
        const Offset(0, 400),
      );
      await tester.pump();
      await tester.pump(const Duration(seconds: 1));
      await tester.pump(const Duration(seconds: 1));

      expect(repository.loads, before + 1);
      final after = tester
          .widgetList<ProductCard>(find.byType(ProductCard))
          .map((card) => card.product.id)
          .toList();
      expect(after.toSet().length, after.length);
      expect(after, containsAll(seen));
      expect(tester.takeException(), isNull);
    });
  });
}
