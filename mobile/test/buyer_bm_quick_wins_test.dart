// test/buyer_bm_quick_wins_test.dart
//
// Focused acceptance tests for the Buyer B-M quick-win cluster:
//   BUY-VOU-001, BUY-VOU-003, BUY-VOU-005, BUY-VOU-011,
//   BUY-PDP-005, BUY-PDP-010, BUY-CHAT-002, BUY-CHAT-005,
//   BUY-CART-007, BUY-SRCH-011.
//
// The money assertions use the workbook's worked example: p2 (Rattan & Reed,
// ₱699, ₱65 shipping), p6 (Sage & Clay, ₱399, ₱120 shipping), MCH50 + PLAT100
// + FREESHIP. Those fixtures are the mock-mode stand-ins for P-02/P-06.

import 'dart:convert';

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
import 'package:zefanya/screens/buyer/cart_screen.dart';
import 'package:zefanya/screens/buyer/notifications_screen.dart';
import 'package:zefanya/screens/buyer/product_details_screen.dart';
import 'package:zefanya/screens/buyer/product_link_screen.dart';
import 'package:zefanya/screens/buyer/vouchers_screen.dart';
import 'package:zefanya/screens/buyer/widgets/product_card.dart';
import 'package:zefanya/screens/shared/chat_screen.dart';
import 'package:zefanya/state/cart_state.dart';
import 'package:zefanya/state/catalog_state.dart';
import 'package:zefanya/state/chat_state.dart';
import 'package:zefanya/state/courier_state.dart';
import 'package:zefanya/state/notification_state.dart';
import 'package:zefanya/state/order_state.dart';
import 'package:zefanya/state/recently_viewed_state.dart';
import 'package:zefanya/state/theme_state.dart';
import 'package:zefanya/state/review_state.dart';
import 'package:zefanya/state/wishlist_state.dart';
import 'package:zefanya/theme/app_theme.dart';
import 'package:zefanya/utils/chat_validation.dart';
import 'package:zefanya/utils/product_link.dart';
import 'package:zefanya/widgets/notification_bell.dart';

Product _product(String id) => mockProducts.firstWhere((p) => p.id == id);

Future<CartState> _loadCart({CatalogRepository? catalog}) async {
  final cart = CartState(
    catalog: catalog,
    vouchers: const MockCartRepository(),
  );
  await cart.load();
  return cart;
}

void _useSurface(WidgetTester tester, Size size) {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);
}

Future<void> _pump(
  WidgetTester tester,
  Widget home,
  List<ChangeNotifierProvider<dynamic>> providers,
) async {
  await tester.pumpWidget(
    MediaQuery(
      data: const MediaQueryData(disableAnimations: true),
      child: MultiProvider(
        providers: providers,
        child: MaterialApp(theme: AppTheme.light, home: home),
      ),
    ),
  );
  await tester.pump();
  await tester.pump(const Duration(milliseconds: 400));
}

/// The providers ProductDetailsScreen reads while rendering.
List<ChangeNotifierProvider<dynamic>> _allCommerceProviders({
  required CartState cart,
  OrderState? orders,
}) => [
  ChangeNotifierProvider<CartState>(create: (_) => cart),
  ChangeNotifierProvider<OrderState>.value(value: orders ?? OrderState()),
  ChangeNotifierProvider<WishlistState>(create: (_) => WishlistState()),
  ChangeNotifierProvider<RecentlyViewedState>(
    create: (_) => RecentlyViewedState(),
  ),
  ChangeNotifierProvider<ReviewState>(create: (_) => ReviewState()),
  ChangeNotifierProvider<CourierState>(create: (_) => CourierState()),
  ChangeNotifierProvider<CatalogState>(create: (_) => CatalogState()),
  ChangeNotifierProvider<ThemeState>(create: (_) => ThemeState()),
];

/// Alias for backwards compatibility.
List<ChangeNotifierProvider<dynamic>> _commerceProviders({
  required CartState cart,
  OrderState? orders,
}) => _allCommerceProviders(cart: cart, orders: orders);

/// A catalogue where p2 and p5 have just gone out of stock while p6 is still
/// sellable. The cart rehydrates against this, which is the exact race
/// BUY-CART-007 describes.
class _StockOverrideCatalog extends MockCatalogRepository {
  const _StockOverrideCatalog();

  static const _p2 = Product(
    id: 'p2',
    name: 'Woven Rattan Tote Bag',
    category: 'Fashion',
    price: 699,
    sellerName: 'Rattan & Reed Co.',
    stock: 0,
    shippingFee: 65,
  );

  static const _p5 = Product(
    id: 'p5',
    name: 'Dried Botanical Bouquet',
    category: 'Home & Living',
    price: 429,
    sellerName: 'Field & Fold Florals',
    stock: 0,
  );

  static const _p6 = Product(
    id: 'p6',
    name: 'Linen Throw Pillow Cover',
    category: 'Home & Living',
    price: 399,
    sellerName: 'Sage & Clay Studio',
    stock: 4,
    shippingFee: 120,
  );

  static const _products = [_p2, _p5, _p6];

  @override
  Future<List<Product>> products({CatalogQuery? query}) async => _products;

  @override
  Future<Product?> productById(String id) async {
    for (final product in _products) {
      if (product.id == id) return product;
    }
    return null;
  }
}

void main() {
  setUp(() {
    SharedPreferences.setMockInitialValues({});
    GoogleFonts.config.allowRuntimeFetching = false;
    AppColors.apply(Brightness.light);
  });

  group('BUY-VOU-001 claim a voucher', () {
    test('claim is one-way and survives a reload', () async {
      final cart = await _loadCart();
      expect(cart.isClaimed('MCH50'), isFalse);

      expect(await cart.claimVoucher('MCH50'), isNull);
      expect(cart.isClaimed('MCH50'), isTrue);
      expect(await cart.claimVoucher('MCH50'), contains('already claimed'));

      final reloaded = await _loadCart();
      expect(reloaded.isClaimed('MCH50'), isTrue);
    });

    testWidgets('wallet shows Claim then a disabled Claimed button', (
      tester,
    ) async {
      _useSurface(tester, const Size(500, 2800));
      final cart = await _loadCart();
      await _pump(tester, const VouchersScreen(), [
        ChangeNotifierProvider<CartState>.value(value: cart),
      ]);

      final claim = find.byKey(const ValueKey('claim-MCH50'));
      expect(claim, findsOneWidget);
      expect(tester.widget<ElevatedButton>(claim).onPressed, isNotNull);

      await tester.tap(claim);
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 300));

      expect(cart.isClaimed('MCH50'), isTrue);
      expect(tester.widget<ElevatedButton>(claim).onPressed, isNull);
      expect(find.byKey(const ValueKey('use-MCH50')), findsOneWidget);
      expect(find.text('Claimed'), findsOneWidget);
    });
  });

  group('BUY-VOU-003 stacking', () {
    test('store + platform + shipping stack once per slot', () async {
      final cart = await _loadCart();
      cart.addItem(_product('p2'));
      cart.addItem(_product('p6'));

      expect(cart.applyVoucher('MCH50').status, VoucherApplyStatus.applied);
      expect(cart.applyVoucher('PLAT100').status, VoucherApplyStatus.applied);
      expect(cart.applyVoucher('FREESHIP').status, VoucherApplyStatus.applied);
      expect(cart.appliedVoucherCodes, ['MCH50', 'PLAT100', 'FREESHIP']);

      final quote = cart.quote;
      expect(quote.merchandise, 1098);
      expect(quote.shipping, 185);
      expect(quote.merchandiseDiscount, 150);
      expect(quote.shippingDiscount, 50);
      expect(quote.total, closeTo(1083, 0.001));

      final replacement = cart.applyVoucher('MCH10');
      expect(replacement.status, VoucherApplyStatus.replaced);
      expect(replacement.replaced?.code, 'MCH50');
      expect(cart.appliedVoucherCodes.contains('MCH50'), isFalse);
      expect(cart.appliedVoucherCodes.contains('MCH10'), isTrue);
      expect(
        quoteCart(cart.items, cart.appliedVouchers).total,
        closeTo(1063.10, 0.001),
      );

      // RVSECRET belongs to Sage & Clay, whose only line is ₱399 — below its
      // ₱1,000 minimum, so it is rejected without disturbing the stack.
      final rv = cart.applyVoucher('RVSECRET');
      expect(rv.status, VoucherApplyStatus.minimumSpend);
      expect(cart.appliedVoucherCodes.contains('RVSECRET'), isFalse);
      expect(cart.quote.total, closeTo(1063.10, 0.001));
    });
  });

  group('BUY-VOU-005 distinct rejections', () {
    test(
      'invalid, expired, not started and used-up are separate messages',
      () async {
        final cart = await _loadCart();
        cart.addItem(_product('p2'));

        final invalid = cart.applyVoucher('NOPE50');
        expect(invalid.status, VoucherApplyStatus.invalid);
        expect(invalid.message, contains("isn't a valid voucher code"));

        final expired = cart.applyVoucher('EXPIRED50');
        expect(expired.status, VoucherApplyStatus.expired);
        expect(expired.message, contains('expired'));

        final early = cart.applyVoucher('NOTYET50');
        expect(early.status, VoucherApplyStatus.notStarted);
        expect(early.message, contains('not active yet'));

        final usedUp = cart.applyVoucher('LIMIT50');
        expect(usedUp.status, VoucherApplyStatus.usageLimit);
        expect(usedUp.message, contains('usage limit'));

        expect(cart.appliedVoucherCodes, isEmpty);
        expect(cart.quote.discount, 0);
      },
    );
  });

  group('BUY-VOU-011 sale items', () {
    test(
      'the voucher discounts the paid sale price, not the old price',
      () async {
        final cart = await _loadCart();
        final sale = _product('p10');
        expect(sale.price, 360);
        expect(sale.compareAtPrice, 450);
        cart.addItem(sale);

        expect(cart.applyVoucher('MCH50').status, VoucherApplyStatus.applied);
        final quote = cart.quote;
        expect(quote.merchandise, 360);
        expect(quote.merchandiseDiscount, 50);
        expect(quote.shipping, 50);
        expect(quote.total, closeTo(360, 0.001));
      },
    );

    testWidgets('cart shows the sale price, saving and payable total', (
      tester,
    ) async {
      _useSurface(tester, const Size(420, 1100));
      final cart = await _loadCart();
      cart.addItem(_product('p10'));
      cart.applyVoucher('MCH50');

      await _pump(tester, const CartScreen(), [
        ChangeNotifierProvider<CartState>.value(value: cart),
      ]);

      expect(find.text('Saved ₱50'), findsOneWidget);
      expect(find.text('₱360'), findsWidgets);
    });
  });

  group('BUY-PDP-005 purchase limit', () {
    testWidgets('a third unit is blocked in a fresh cart', (tester) async {
      _useSurface(tester, const Size(420, 1700));
      final cart = CartState();
      final orders = OrderState(repository: MockOrderRepository());
      await orders.placeOrders([
        CartItem(product: _product('p7'), quantity: 2),
      ]);
      expect(orders.purchasedQuantity('p7'), 2);

      await tester.pumpWidget(
        MediaQuery(
          data: const MediaQueryData(disableAnimations: true),
          child: MultiProvider(
            providers: [
              ChangeNotifierProvider.value(value: cart),
              ChangeNotifierProvider.value(value: orders),
              ChangeNotifierProvider(create: (_) => WishlistState()),
              ChangeNotifierProvider(create: (_) => RecentlyViewedState()),
              ChangeNotifierProvider(create: (_) => ReviewState()),
              ChangeNotifierProvider(create: (_) => CourierState()),
              ChangeNotifierProvider(create: (_) => CatalogState()),
            ],
            child: MaterialApp(
              theme: AppTheme.light,
              home: ProductDetailsScreen(product: _product('p7')),
            ),
          ),
        ),
      );
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 400));

      expect(find.textContaining('Purchase limit reached'), findsOneWidget);

      await tester.tap(find.widgetWithText(OutlinedButton, 'Add to Cart'));
      await tester.pump();
      expect(
        find.text(
          'You can buy at most 2 of this item per buyer. '
          'You have reached that limit.',
        ),
        findsOneWidget,
      );
      expect(cart.quantityInCart('p7'), 0);
      expect(cart.items, isEmpty);
    });
  });

  group('BUY-PDP-010 share link', () {
    test('share URL round-trips through the parser', () {
      final p2 = _product('p2');
      final url = productShareUrl(p2);
      expect(url, endsWith('/buyer/products/p2'));
      expect(productIdFromLink(Uri.parse(url)), 'p2');
      expect(
        productIdFromLink(
          Uri.parse('https://zefanya.test/buyer/products/p2?utm=chat'),
        ),
        'p2',
      );
      expect(productIdFromLink(Uri.parse('zefanya://product/p2')), 'p2');
      expect(productIdFromLink(Uri.parse('https://zefanya.test/cart')), isNull);
    });

    testWidgets('a product link resolves to the product page', (tester) async {
      _useSurface(tester, const Size(420, 1700));
      final catalog = CatalogState();
      await catalog.load();
      final cart = CartState();
      final orders = OrderState();

      await tester.pumpWidget(
        MediaQuery(
          data: const MediaQueryData(disableAnimations: true),
          child: MultiProvider(
            providers: [
              ChangeNotifierProvider<CatalogState>.value(value: catalog),
              ChangeNotifierProvider.value(value: cart),
              ChangeNotifierProvider.value(value: orders),
              ChangeNotifierProvider(create: (_) => WishlistState()),
              ChangeNotifierProvider(create: (_) => RecentlyViewedState()),
              ChangeNotifierProvider(create: (_) => ReviewState()),
              ChangeNotifierProvider(create: (_) => CourierState()),
            ],
            child: MaterialApp(
              theme: AppTheme.light,
              home: const ProductLinkScreen(productId: 'p2'),
            ),
          ),
        ),
      );
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 400));

      expect(find.byType(ProductDetailsScreen), findsOneWidget);
      expect(find.text('Woven Rattan Tote Bag'), findsWidgets);
    });

    testWidgets('an unknown id shows an unavailable page, not a blank one', (
      tester,
    ) async {
      _useSurface(tester, const Size(420, 1000));
      final catalog = CatalogState();
      await catalog.load();

      await tester.pumpWidget(
        MediaQuery(
          data: const MediaQueryData(disableAnimations: true),
          child: MultiProvider(
            providers: [
              ChangeNotifierProvider<CatalogState>.value(value: catalog),
            ],
            child: MaterialApp(
              theme: AppTheme.light,
              home: const ProductLinkScreen(productId: 'gone'),
            ),
          ),
        ),
      );
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 400));

      expect(find.text('This product is unavailable'), findsOneWidget);
      expect(find.text('Continue Shopping'), findsOneWidget);
    });
  });

  group('BUY-CHAT-002 attachments and length', () {
    test('validation boundaries are exact', () {
      expect(validateChatMessage('a' * 1000), isNull);
      expect(validateChatMessage('a' * 1001), contains('1000'));
      expect(validateChatMessage(''), contains('Type a message'));
      expect(validateChatMessage('', hasAttachment: true), isNull);

      expect(
        validateChatAttachment(
          const ChatAttachment(name: 'virus.exe', sizeBytes: 100),
        ),
        ChatAttachmentProblem.unsupportedType,
      );
      expect(
        validateChatAttachment(
          const ChatAttachment(name: 'photo.png', sizeBytes: 6 * 1024 * 1024),
        ),
        ChatAttachmentProblem.tooLarge,
      );
      expect(
        validateChatAttachment(
          const ChatAttachment(name: 'receipt.PDF', sizeBytes: 1000),
        ),
        isNull,
      );
    });

    testWidgets('an invalid file and a 1001-character message are not sent', (
      tester,
    ) async {
      _useSurface(tester, const Size(420, 1000));
      final chat = ChatState();
      await _pump(
        tester,
        ChatScreen(
          contactName: 'Test Shop',
          contactRole: 'Seller',
          attachmentPicker: (_) async =>
              const ChatAttachment(name: 'virus.exe', sizeBytes: 100),
        ),
        [
          ChangeNotifierProvider<ChatState>.value(value: chat),
          ChangeNotifierProvider(create: (_) => CartState()),
          ChangeNotifierProvider(create: (_) => CatalogState()),
        ],
      );

      final before = chat.messagesFor('Test Shop').length;
      expect(before, greaterThan(0));

      await tester.tap(find.byTooltip('Attach file'));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 100));

      expect(
        find.text('Attachments must be JPG, PNG, or PDF.'),
        findsOneWidget,
      );
      expect(find.text('virus.exe'), findsNothing);
      expect(chat.messagesFor('Test Shop').length, before);

      await tester.enterText(find.byType(TextField), 'a' * 1001);
      await tester.tap(find.byIcon(Icons.send_rounded));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 300));

      expect(
        find.text('Messages can be up to 1000 characters.'),
        findsOneWidget,
      );
      expect(chat.messagesFor('Test Shop').length, before);
    });
  });

  group('BUY-CHAT-005 notification unread count', () {
    testWidgets('badge and screen go 3 -> 2 -> 0', (tester) async {
      _useSurface(tester, const Size(420, 1000));
      final notifications = NotificationState();
      await notifications.load();
      expect(notifications.unreadCount, 3);

      await _pump(
        tester,
        const Scaffold(
          body: SafeArea(
            child: Align(
              alignment: Alignment.topRight,
              child: NotificationBell(),
            ),
          ),
        ),
        [
          ChangeNotifierProvider<NotificationState>.value(value: notifications),
          ChangeNotifierProvider(create: (_) => CartState()),
          ChangeNotifierProvider(create: (_) => CatalogState()),
        ],
      );
      expect(find.text('3'), findsOneWidget);

      await tester.tap(find.byTooltip('Notifications'));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 500));

      expect(find.byType(NotificationsScreen), findsOneWidget);
      expect(find.text('3 unread'), findsOneWidget);

      await tester.tap(find.text(kSeedNotifications.first.title));
      await tester.pump();
      expect(notifications.unreadCount, 2);
      expect(find.text('2 unread'), findsOneWidget);

      await tester.tap(find.text('Mark all as read'));
      await tester.pump();
      expect(notifications.unreadCount, 0);
      expect(find.text('All caught up'), findsOneWidget);
    });
  });

  group('BUY-CART-007 stock changes while in cart', () {
    testWidgets('out-of-stock lines are visible but excluded from checkout', (
      tester,
    ) async {
      SharedPreferences.setMockInitialValues({
        'cart_items': jsonEncode([
          {
            'productId': 'p2',
            'productName': 'Woven Rattan Tote Bag',
            'sellerName': 'Rattan & Reed Co.',
            'category': 'Fashion',
            'quantity': 1,
            'selected': true,
            'unitPrice': 699,
          },
          {
            'productId': 'p5',
            'productName': 'Dried Botanical Bouquet',
            'sellerName': 'Field & Fold Florals',
            'category': 'Home & Living',
            'quantity': 1,
            'selected': true,
            'unitPrice': 429,
          },
          {
            'productId': 'p6',
            'productName': 'Linen Throw Pillow Cover',
            'sellerName': 'Sage & Clay Studio',
            'category': 'Home & Living',
            'quantity': 1,
            'selected': true,
            'unitPrice': 399,
          },
        ]),
      });

      final cart = await _loadCart(catalog: const _StockOverrideCatalog());
      expect(cart.items, hasLength(3));

      final p2Line = cart.items.firstWhere((i) => i.product.id == 'p2');
      final p5Line = cart.items.firstWhere((i) => i.product.id == 'p5');
      final p6Line = cart.items.firstWhere((i) => i.product.id == 'p6');

      expect(p2Line.isAvailable, isFalse);
      expect(p2Line.unavailableLabel, 'Out of stock');
      expect(p2Line.selected, isFalse);
      expect(p5Line.isAvailable, isFalse);
      expect(p5Line.unavailableLabel, 'Out of stock');
      expect(p6Line.isAvailable, isTrue);

      cart.toggleSelected(p2Line, true);
      expect(p2Line.selected, isFalse);
      expect(cart.selectedItems.map((i) => i.product.id), ['p6']);

      final quote = cart.quote;
      expect(quote.merchandise, 399);
      expect(quote.shipping, 120);
      expect(quote.total, 519);

      _useSurface(tester, const Size(420, 1200));
      await _pump(tester, const CartScreen(), [
        ChangeNotifierProvider<CartState>.value(value: cart),
      ]);

      expect(find.text('Out of stock'), findsNWidgets(2));
      expect(find.text('₱519'), findsOneWidget);
    });
  });

  group('BUY-SRCH-011 vacation store', () {
    test('a vacation product cannot enter the cart', () async {
      final cart = await _loadCart();
      cart.addItem(_product('p8'));
      expect(cart.items, isEmpty);
    });

    testWidgets('the card carries the label and the PDP disables actions', (
      tester,
    ) async {
      _useSurface(tester, const Size(420, 1700));

      await _pump(
        tester,
        const Scaffold(
          body: SizedBox(
            width: 190,
            height: 280,
            child: ProductCard(
              product: _vacationProduct,
              heroTag: 'vacation-card',
              onTap: _noop,
            ),
          ),
        ),
        [
          ChangeNotifierProvider<CartState>(create: (_) => CartState()),
          ChangeNotifierProvider<CatalogState>(create: (_) => CatalogState()),
          ChangeNotifierProvider<WishlistState>(create: (_) => WishlistState()),
        ],
      );
      expect(find.text('On vacation'), findsOneWidget);

      final cart = CartState();
      await _pump(tester, ProductDetailsScreen(product: _product('p8')), [
        ..._allCommerceProviders(cart: cart),
      ]);

      expect(find.text('Store on vacation'), findsOneWidget);
      expect(find.text('On vacation · not accepting orders'), findsOneWidget);

      final add = tester.widget<OutlinedButton>(
        find.widgetWithText(OutlinedButton, 'Add to Cart'),
      );
      final buy = tester.widget<ElevatedButton>(
        find.widgetWithText(ElevatedButton, 'On Vacation'),
      );
      expect(add.onPressed, isNull);
      expect(buy.onPressed, isNull);
    });
  });
}

// Top-level constants/functions so the ProductCard can stay const in the test.
const _vacationProduct = Product(
  id: 'p8',
  name: 'Botanical Hand Soap Set',
  category: 'Beauty',
  price: 459,
  sellerName: 'Field & Fold Florals',
  stock: 4,
  sellerOnVacation: true,
);

void _noop() {}
