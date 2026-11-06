// test/review_limits_test.dart
//
// The review rules the workbook checks separately from "can I leave one":
// five photos, 500 characters, and one edit inside a 30-day window.
//
// The form half is a widget test because the limit lives on the TextField. The
// edit half is state logic, including the boundary day — a rule that only works
// on day 29 and 31, but not 30, is the kind of off-by-one a UI walkthrough
// misses.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:zefanya/data/mock_data.dart';
import 'package:zefanya/screens/buyer/review_screen.dart';
import 'package:zefanya/state/cart_state.dart';
import 'package:zefanya/state/catalog_state.dart';
import 'package:zefanya/state/courier_state.dart';
import 'package:zefanya/state/order_state.dart';
import 'package:zefanya/state/recently_viewed_state.dart';
import 'package:zefanya/state/review_state.dart';
import 'package:zefanya/state/wishlist_state.dart';
import 'package:zefanya/theme/app_theme.dart';

Order get _deliveredOrder =>
    mockOrders.firstWhere((o) => o.status == OrderStatus.toRate);

Product get _deliveredProduct =>
    mockProducts.firstWhere((p) => p.id == _deliveredOrder.productIds.first);

Future<void> _pumpReview(
  WidgetTester tester, {
  ReviewState? reviews,
}) async {
  AppColors.apply(Brightness.light);
  GoogleFonts.config.allowRuntimeFetching = false;
  tester.view.physicalSize = const Size(390, 900);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

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
          data: const MediaQueryData(
            size: Size(390, 900),
            disableAnimations: true,
          ),
          child: ReviewScreen(
            order: _deliveredOrder,
            products: [_deliveredProduct],
          ),
        ),
      ),
    ),
  );
  await tester.pump(const Duration(milliseconds: 100));
}

ProductReview _review({
  String comment = 'Fine.',
  int stars = 4,
  DateTime? createdAt,
  int editCount = 0,
}) =>
    ProductReview(
      productId: 'p4',
      orderId: 'ORD-09876',
      stars: stars,
      comment: comment,
      photoPath: '/tmp/one.jpg',
      photoPaths: const ['/tmp/one.jpg', '/tmp/two.jpg'],
      editCount: editCount,
      createdAt: createdAt ?? DateTime.now().subtract(const Duration(days: 10)),
    );

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() {
    SharedPreferences.setMockInitialValues({});
  });

  group('BUY-RATE-004 stars, text and photo limits', () {
    testWidgets('the form uses the shared 500/5 limits', (tester) async {
      await _pumpReview(tester);

      final field = tester.widget<TextField>(find.byType(TextField));
      expect(field.maxLength, reviewCommentLimit);
      expect(reviewCommentLimit, 500);
      expect(reviewPhotoLimit, 5);
      expect(
        find.byKey(ValueKey('review-photo-add-${_deliveredProduct.id}')),
        findsOneWidget,
      );
    });

    testWidgets('0 stars disables Submit; 501 characters stop at 500',
        (tester) async {
      await _pumpReview(tester);

      ElevatedButton submit() => tester.widget<ElevatedButton>(
            find.widgetWithText(ElevatedButton, 'Submit Review'),
          );
      expect(submit().onPressed, isNull);
      expect(find.text('Tap a star to rate this item.'), findsOneWidget);

      await tester.ensureVisible(find.byType(TextField));
      await tester.enterText(find.byType(TextField), 'a' * 501);
      await tester.pump();
      expect(
        tester.widget<TextField>(find.byType(TextField)).controller!.text.length,
        500,
      );

      await tester.tap(find.byIcon(Icons.star_border_rounded).at(3));
      await tester.pump();
      expect(submit().onPressed, isNotNull);
    });

    testWidgets('five stored photos fill the strip and hide the add tile',
        (tester) async {
      const paths = [
        '/tmp/one.jpg',
        '/tmp/two.jpg',
        '/tmp/three.jpg',
        '/tmp/four.jpg',
        '/tmp/five.jpg',
      ];
      final reviews = ReviewState();
      await reviews.submit(
        ProductReview(
          productId: _deliveredProduct.id,
          orderId: _deliveredOrder.id,
          stars: 4,
          comment: 'Five photos',
          photoPath: paths.first,
          photoPaths: paths,
          createdAt: DateTime.now(),
        ),
      );

      await _pumpReview(tester, reviews: reviews);

      expect(
        reviews.forOrderProduct(_deliveredOrder.id, _deliveredProduct.id)!
            .photos,
        hasLength(5),
      );
      expect(find.byIcon(Icons.close), findsNWidgets(5));
      expect(
        find.byKey(
          ValueKey('review-photo-add-${_deliveredProduct.id}'),
        ),
        findsNothing,
      );
    });

    test('a legacy single photo and the new photo list both resolve', () {
      final legacy = ProductReview(
        productId: 'p4',
        orderId: 'ORD-09876',
        stars: 5,
        photoPath: '/tmp/legacy.jpg',
        createdAt: DateTime(2026),
      );
      expect(legacy.photos, ['/tmp/legacy.jpg']);

      final many = _review();
      expect(many.photos, ['/tmp/one.jpg', '/tmp/two.jpg']);
    });
  });

  group('BUY-RATE-005 edit once within the window', () {
    test('the first edit is allowed and preserves the original date', () async {
      final state = ReviewState();
      final original = DateTime.now().subtract(const Duration(days: 10));
      await state.submit(_review(comment: 'First', createdAt: original));

      await state.submit(
        _review(comment: 'Edited once', createdAt: DateTime.now()),
      );

      final stored = state.forOrderProduct('ORD-09876', 'p4')!;
      expect(stored.comment, 'Edited once');
      expect(stored.editCount, 1);
      expect(stored.createdAt, original);
      expect(
        state.editRefusal('ORD-09876', 'p4'),
        'This review has already been edited once.',
      );
    });

    test('a second edit is refused without changing the stored review',
        () async {
      final state = ReviewState();
      await state.submit(_review(comment: 'First'));
      await state.submit(_review(comment: 'Edited once'));

      await expectLater(
        state.submit(_review(comment: 'Edited twice')),
        throwsA(isA<ReviewEditException>()),
      );
      expect(
        state.forOrderProduct('ORD-09876', 'p4')!.comment,
        'Edited once',
      );
    });

    test('day 30 is outside the window; day 29 is inside', () async {
      final state = ReviewState();
      final now = DateTime(2026, 10, 23, 12);
      await state.submit(
        _review(createdAt: now.subtract(const Duration(days: 29))),
      );

      expect(
        state.editRefusal('ORD-09876', 'p4', now: now),
        isNull,
      );
      expect(
        state.editRefusal(
          'ORD-09876',
          'p4',
          now: now.add(const Duration(days: 1)),
        ),
        'Reviews can only be edited within 30 days.',
      );
    });
  });
}
