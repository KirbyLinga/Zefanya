// test/product_gallery_test.dart
//
// Every product in this catalogue had exactly one photo, while the product page
// rendered a 1.05-aspect gallery and Home rendered a "stacked" showcase — two
// layouts whose entire visual premise is *there is more than one photo of this
// thing*. The data was the thing lying, so Product.images is what changed; the
// pager below is the layout finally telling the truth.
//
// What's pinned here:
//
//  * a product with several photos gets a pager and one dot per photo;
//  * a product with one photo gets NO pager and NO dots — a swipeable page
//    that cannot be swiped, and a lone dot, are both worse than nothing;
//  * swiping actually changes which photo is shown and what gets announced.
//
// The third one is the reason this file exists rather than trusting the code:
// a PageView whose onPageChanged was never wired would render perfectly, show
// dots, swipe, and leave the indicator frozen at 1.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:zefanya/data/mock_data.dart';
import 'package:zefanya/screens/buyer/product_details_screen.dart';
import 'package:zefanya/screens/buyer/widgets/product_card.dart';
import 'package:zefanya/state/cart_state.dart';
import 'package:zefanya/state/courier_state.dart';
import 'package:zefanya/state/order_state.dart';
import 'package:zefanya/state/recently_viewed_state.dart';
import 'package:zefanya/state/review_state.dart';
import 'package:zefanya/state/wishlist_state.dart';
import 'package:zefanya/theme/app_theme.dart';

/// A product with more than one photo. p1 seeds four.
Product get _multiPhoto =>
    mockProducts.firstWhere((p) => p.id == 'p1');

/// A product with exactly one photo — p7 seeds one, so it is the only honest
/// way to exercise the no-pager branch against real catalogue data.
Product get _singlePhoto =>
    mockProducts.firstWhere((p) => p.id == 'p7');

/// The number of dots currently rendered.
int _dotCount(WidgetTester tester) => tester
    .widgetList(find.descendant(
      of: find.byKey(const ValueKey('gallery-dots')),
      matching: find.byType(AnimatedContainer),
    ))
    .length;

Future<void> _pump(WidgetTester tester, Product product) async {
  AppColors.apply(Brightness.light);
  GoogleFonts.config.allowRuntimeFetching = false;
  tester.view.physicalSize = const Size(390, 900);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

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
        home: ProductDetailsScreen(product: product),
      ),
    ),
  );
  await tester.pump();
}

void main() {
  setUp(() => SharedPreferences.setMockInitialValues({}));

  testWidgets('a multi-photo product gets a pager with one dot per photo',
      (tester) async {
    await _pump(tester, _multiPhoto);

    expect(find.byType(PageView), findsOneWidget,
        reason: 'the gallery should be swipeable when there is more than one '
            'photo to swipe through');
    expect(_dotCount(tester), _multiPhoto.photos.length,
        reason: 'one dot per photo — the count is the promise the dots make');
  });

  testWidgets('a single-photo product gets no pager and no dots',
      (tester) async {
    await _pump(tester, _singlePhoto);

    // The counter-check to the test above. If this ever starts finding a
    // PageView, someone has made the single-photo case impersonate a gallery.
    expect(_singlePhoto.hasGallery, isFalse,
        reason: 'fixture assumption — p7 seeds exactly one photo');
    expect(find.byType(PageView), findsNothing);
    expect(find.byKey(const ValueKey('gallery-dots')), findsNothing);
    // The photo itself is still there, just not in a pager.
    expect(find.byType(ProductImage), findsWidgets);
  });

  testWidgets('the indicator sits centred at the bottom of the gallery',
      (tester) async {
    await _pump(tester, _multiPhoto);

    final gallery = tester.getRect(find.byType(PageView));
    final dots = tester.getRect(find.byKey(const ValueKey('gallery-dots')));

    // Pins the placement of an element too small to eyeball in a screenshot:
    // centred, 10px off the gallery's bottom edge (the authored value), and
    // inside the gallery rather than over the title below it.
    expect(dots.center.dx, closeTo(gallery.center.dx, 1.0));
    expect(gallery.bottom - dots.bottom, closeTo(10.0, 1.0));
    expect(gallery.contains(dots.center), isTrue);
  });

  testWidgets('swiping advances the indicator and the announced position',
      (tester) async {
    await _pump(tester, _multiPhoto);

    expect(find.bySemanticsLabel('Photo 1 of ${_multiPhoto.photos.length}'),
        findsOneWidget);

    await tester.drag(find.byType(PageView), const Offset(-400, 0));
    await tester.pumpAndSettle();

    // The whole point: the photo AND the announced position both move. A
    // PageView with an unwired onPageChanged passes every other assertion in
    // this file and fails this one.
    expect(find.bySemanticsLabel('Photo 2 of ${_multiPhoto.photos.length}'),
        findsOneWidget);
    expect(find.bySemanticsLabel('Photo 1 of ${_multiPhoto.photos.length}'),
        findsNothing);
  });
}