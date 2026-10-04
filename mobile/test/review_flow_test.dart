// test/review_flow_test.dart
//
// The review flow, which exists because "Leave a Review" was a live, enabled,
// primary-styled button with `onPressed: () {}` in two files — on `toRate`,
// the delivered status the whole Orders tab is named after.
//
// So the assertions below are mostly about *behaviour that was missing*:
// that the button opens something, that a rating is required, that what was
// written comes back, that an order can't be reviewed twice, and that the
// place it lands (the product page) actually shows it.
//
// One test here is not about the new flow at all: it asserts the *deleted*
// fabricated reviews stay deleted. Those were two hardcoded tiles and a
// five-entry sheet naming invented buyers, and "we removed it" is exactly the
// kind of claim that quietly stops being true.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:zefanya/data/mock_data.dart';
import 'package:zefanya/screens/buyer/order_detail_screen.dart';
import 'package:zefanya/screens/buyer/product_details_screen.dart';
import 'package:zefanya/screens/buyer/review_screen.dart';
import 'package:zefanya/screens/buyer/widgets/review_button.dart';
import 'package:zefanya/state/cart_state.dart';
import 'package:zefanya/state/catalog_state.dart';
import 'package:zefanya/state/courier_state.dart';
import 'package:zefanya/state/order_state.dart';
import 'package:zefanya/state/recently_viewed_state.dart';
import 'package:zefanya/state/review_state.dart';
import 'package:zefanya/state/wishlist_state.dart';
import 'package:zefanya/theme/app_theme.dart';

/// The seeded delivered order (ORD-09876, containing p4).
Order get _deliveredOrder =>
    mockOrders.firstWhere((o) => o.status == OrderStatus.toRate);

Product get _deliveredProduct =>
    mockProducts.firstWhere((p) => p.id == _deliveredOrder.productIds.first);

/// An order whose contents can't be resolved — the "placed before
/// productIds existed" case.
const _contentsUnknown = Order(
  id: 'ORD-00001',
  shopName: 'Unknown Shop',
  status: OrderStatus.toRate,
  eta: 'Delivered',
  itemSummary: 'Something',
);

/// Wraps [child] in the providers the buyer screens read. [reviews] is passed
/// in so a test can pre-seed a review and watch what changes.
Future<void> _pump(
  WidgetTester tester,
  Widget child, {
  ReviewState? reviews,
  Size size = const Size(390, 780),
  double textScale = 1.0,
}) async {
  AppColors.apply(Brightness.light);
  GoogleFonts.config.allowRuntimeFetching = false;
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  // The catalogue every browsing screen and the review button now read. Loaded,
  // because an unloaded `CatalogState` holds nothing: the button's join from an
  // order to the catalogue would find no products and silently render nothing,
  // which is the very failure mode this state exists to make visible.
  final catalog = CatalogState();
  await catalog.load();

  await tester.pumpWidget(
    MultiProvider(
      providers: [
        ChangeNotifierProvider(create: (_) => CartState()),
        ChangeNotifierProvider.value(value: catalog),
        ChangeNotifierProvider(create: (_) => CourierState()),
        ChangeNotifierProvider(create: (_) => OrderState()),
        ChangeNotifierProvider(create: (_) => RecentlyViewedState()),
        ChangeNotifierProvider(create: (_) => WishlistState()),
        ChangeNotifierProvider(create: (_) => reviews ?? ReviewState()),
      ],
      child: MaterialApp(
        theme: AppTheme.light,
        home: MediaQuery(
          data: MediaQueryData(size: size, textScaler: TextScaler.linear(textScale)),
          child: child,
        ),
      ),
    ),
  );
  await tester.pump();
}

void main() {
  setUp(() => SharedPreferences.setMockInitialValues({}));

  group('ReviewState', () {
    test('a submitted review is readable by product and by order', () async {
      final state = ReviewState();
      await state.submit(ProductReview(
        productId: 'p4',
        orderId: 'ORD-09876',
        stars: 4,
        comment: 'Lovely scarf.',
        createdAt: DateTime(2026, 9, 27),
      ));

      expect(state.forProduct('p4').single.stars, 4);
      expect(state.forProduct('p4').single.comment, 'Lovely scarf.');
      expect(state.forOrder('ORD-09876').length, 1);
      expect(state.hasReviewedOrder('ORD-09876'), isTrue);
      expect(state.hasReviewedOrder('ORD-00001'), isFalse);
      expect(state.forProduct('p1'), isEmpty);
    });

    test('re-submitting the same purchase replaces it, never duplicates',
        () async {
      final state = ReviewState();
      await state.submit(ProductReview(
        productId: 'p4',
        orderId: 'ORD-09876',
        stars: 2,
        comment: 'First impression.',
        createdAt: DateTime(2026, 9, 27),
      ));
      await state.submit(ProductReview(
        productId: 'p4',
        orderId: 'ORD-09876',
        stars: 5,
        comment: 'Second thoughts, it grew on me.',
        createdAt: DateTime(2026, 9, 28),
      ));

      // This is what makes "Edit Your Review" an edit rather than a second
      // opinion on one purchase.
      expect(state.forProduct('p4').length, 1);
      expect(state.forProduct('p4').single.stars, 5);
    });

    test('reviews survive a restart', () async {
      await ReviewState().submit(ProductReview(
        productId: 'p4',
        orderId: 'ORD-09876',
        stars: 5,
        comment: 'Would buy again.',
        photoPath: '/tmp/photo.jpg',
        createdAt: DateTime(2026, 9, 27),
      ));
      // _save() is fire-and-forget, as in every state class here.
      await pumpEventQueue();

      final restarted = ReviewState();
      await restarted.load();

      final restored = restarted.forOrderProduct('ORD-09876', 'p4')!;
      expect(restored.stars, 5);
      expect(restored.comment, 'Would buy again.');
      expect(restored.photoPath, '/tmp/photo.jpg');
    });

    test('corrupt stored data costs the reviews, not the app', () async {
      SharedPreferences.setMockInitialValues({
        'product_reviews': '[{"productId": 12, "nonsense": true}, "oops"]',
      });

      final state = ReviewState();
      await state.load();

      // No throw, no half-built reviews.
      expect(state.all, isEmpty);
    });
  });

  group('Review form', () {
    testWidgets('cannot submit unrated, and says what is missing',
        (tester) async {
      await _pump(
        tester,
        ReviewScreen(order: _deliveredOrder, products: [_deliveredProduct]),
      );

      final button = tester.widget<ElevatedButton>(
        find.widgetWithText(ElevatedButton, 'Submit Review'),
      );
      expect(button.onPressed, isNull,
          reason: 'a review with no stars is a comment, not a rating');
      expect(find.text('Tap a star to rate this item.'), findsOneWidget);
    });

    testWidgets('rating enables submit and states the rating in words',
        (tester) async {
      await _pump(
        tester,
        ReviewScreen(order: _deliveredOrder, products: [_deliveredProduct]),
      );

      await tester.tap(find.bySemanticsLabel('4 stars'));
      await tester.pump();

      // The rating is confirmed in language as well as in gold.
      expect(find.text('Great'), findsOneWidget);
      final button = tester.widget<ElevatedButton>(
        find.widgetWithText(ElevatedButton, 'Submit Review'),
      );
      expect(button.onPressed, isNotNull);
      expect(find.text('Tap a star to rate this item.'), findsNothing);
    });

    testWidgets('submitting stores the review and confirms it', (tester) async {
      final reviews = ReviewState();
      await _pump(
        tester,
        ReviewScreen(order: _deliveredOrder, products: [_deliveredProduct]),
        reviews: reviews,
      );

      await tester.tap(find.bySemanticsLabel('5 stars'));
      await tester.pump();
      await tester.enterText(find.byType(TextField), 'Exactly as pictured.');
      await tester.pump();
      await tester.tap(find.widgetWithText(ElevatedButton, 'Submit Review'));
      await tester.pumpAndSettle();

      final stored = reviews.forOrderProduct(_deliveredOrder.id, _deliveredProduct.id)!;
      expect(stored.stars, 5);
      expect(stored.comment, 'Exactly as pictured.');

      // The confirmation states a fact rather than only thanking.
      expect(find.text('Thank you'), findsOneWidget);
      expect(find.textContaining(_deliveredProduct.name), findsOneWidget);
    });

    testWidgets('an existing review opens pre-filled', (tester) async {
      final reviews = ReviewState();
      await reviews.submit(ProductReview(
        productId: _deliveredProduct.id,
        orderId: _deliveredOrder.id,
        stars: 3,
        comment: 'It is fine.',
        createdAt: DateTime(2026, 9, 27),
      ));

      await _pump(
        tester,
        ReviewScreen(order: _deliveredOrder, products: [_deliveredProduct]),
        reviews: reviews,
      );

      // Editing must not silently blank what was written before.
      expect(find.text('It is fine.'), findsOneWidget);
      expect(find.text('Good'), findsOneWidget);
      expect(find.widgetWithText(ElevatedButton, 'Submit Review'), findsOneWidget);
    });

    testWidgets('two items do not overflow a short screen at 1.3x text',
        (tester) async {
      // The worst case the app clamps itself to: a two-item order on a short
      // phone with large system text. This is why the body scrolls.
      await _pump(
        tester,
        ReviewScreen(
          order: _deliveredOrder,
          products: mockProducts.take(2).toList(),
        ),
        size: const Size(360, 300),
        textScale: 1.3,
      );

      expect(tester.takeException(), isNull);

      // And the scroll has to be doing real work — if this content fitted in
      // 300px the ListView would be decoration, and the test above would be
      // passing for the wrong reason.
      final position =
          tester.state<ScrollableState>(find.byType(Scrollable).first).position;
      expect(position.maxScrollExtent, greaterThan(0),
          reason: 'content must actually exceed the viewport at 1.3x');
    });
  });

  group('The button that used to do nothing', () {
    testWidgets('a delivered order now opens the review form', (tester) async {
      await _pump(tester, OrderDetailScreen(order: _deliveredOrder));

      await tester.tap(find.text('Leave a Review'));
      await tester.pumpAndSettle();

      expect(find.byType(ReviewScreen), findsOneWidget);
      // And it arrived knowing what it is reviewing.
      expect(find.textContaining(_deliveredProduct.name), findsWidgets);
    });

    testWidgets('an order with unresolvable items offers no button',
        (tester) async {
      await _pump(tester, OrderDetailScreen(order: _contentsUnknown));

      // Not a disabled button and not a button that opens an empty form —
      // nothing. There is no product to rate, so there is no action.
      expect(find.text('Leave a Review'), findsNothing);
    });

    testWidgets('once reviewed, the button becomes an edit', (tester) async {
      final reviews = ReviewState();
      await reviews.submit(ProductReview(
        productId: _deliveredProduct.id,
        orderId: _deliveredOrder.id,
        stars: 4,
        createdAt: DateTime(2026, 9, 27),
      ));

      await _pump(
        tester,
        ReviewButton(order: _deliveredOrder, expanded: false),
        reviews: reviews,
      );

      expect(find.text('Edit Your Review'), findsOneWidget);
      expect(find.text('Leave a Review'), findsNothing);
      // Quieter than the un-reviewed call to action: the job is done.
      expect(find.byType(OutlinedButton), findsOneWidget);
    });

    testWidgets('a multi-item order is labelled as plural', (tester) async {
      final twoItems = Order(
        id: 'ORD-10246',
        shopName: 'Willow & Bloom Atelier',
        status: OrderStatus.toRate,
        eta: 'Delivered today',
        itemSummary: 'Two things',
        productIds: ['p1', 'p2'],
      );

      await _pump(
        tester,
        ReviewButton(order: twoItems, expanded: true),
      );

      expect(find.text('Review 2 Items'), findsOneWidget);
    });
  });

  group('Where the review lands', () {
    testWidgets('the product page shows it, badged as yours', (tester) async {
      final reviews = ReviewState();
      await reviews.submit(ProductReview(
        productId: _deliveredProduct.id,
        orderId: _deliveredOrder.id,
        stars: 4,
        comment: 'Softer than I expected, in a good way.',
        createdAt: DateTime(2026, 9, 27),
      ));

      await _pump(
        tester,
        ProductDetailsScreen(product: _deliveredProduct),
        size: const Size(390, 3000),
        reviews: reviews,
      );
      await tester.pumpAndSettle();

      expect(find.text('YOUR REVIEW'), findsOneWidget);
      expect(find.text('Softer than I expected, in a good way.'), findsOneWidget);
    });

    testWidgets('no invented reviewers are rendered anywhere', (tester) async {
      // The guard for content that was deleted rather than fixed. These names
      // and lines came from two hardcoded tiles and a five-entry sheet; if
      // anyone reinstates placeholder review copy, this fails.
      await _pump(
        tester,
        ProductDetailsScreen(product: _deliveredProduct),
        size: const Size(390, 3000),
      );
      await tester.pumpAndSettle();

      for (final invented in [
        'Marielle S.',
        'Jonah P.',
        'Celine R.',
        'Marco T.',
        'Ana G.',
      ]) {
        expect(find.text(invented), findsNothing);
      }
      expect(find.textContaining('Would repurchase'), findsNothing);
    });
  });
}